<?php

namespace App\Services;

use App\Enums\OrderProductStatus;
use App\Enums\ProductType;
use App\Models\Order\Main;
use App\Models\Order\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PurchaseProductService
{
    public const ACTION_ADD_TO_MAIN = 'add_to_main';

    public const ACTION_INCREASE_PRIMARY_QTY = 'increase_primary_qty';

    public const ACTION_ADD_DELTA_LINE = 'add_delta_line';

    public const ACTION_PARTIAL_DOWN_SPLIT = 'partial_down_split';

    public const ACTION_FULL_CANCEL_ACTIVE_LINES = 'full_cancel_active_lines';

    public const ACTION_CANCEL_ADDITIONAL_ACTIVE_LINES = 'cancel_additional_active_lines';

    public function update(Order $order): void
    {
        $this->sync($order);
    }

    public function sync(Order $order): void
    {
        if ($order->getMainId() === null) {
            return;
        }

        $main = $order->getMain();
        if (! $main instanceof Main) {
            return;
        }

        if (! $main->orderProducts()->exists()) {
            $this->copyOrderProductsToMain($order, $main);

            return;
        }

        $actions = $this->planActions($order, $main, null);

        if ($actions === []) {
            return;
        }

        DB::transaction(function () use ($order, $main, $actions): void {
            $this->applyActions($order, $main, $actions);
        });
    }

    /**
     * @param  list<array{product_id: int, qty: float}>|null  $proposedLines
     * @return array{
     *     has_impact: bool,
     *     actions: list<array{
     *         type: string,
     *         product_id: int,
     *         qty: float,
     *         canceled_qty?: float,
     *         primary_cancelable?: bool,
     *     }>
     * }
     */
    public function preview(Order $order, ?array $proposedLines = null, ?int $onlyProductId = null): array
    {
        if ($order->getMainId() === null) {
            return $this->emptyPreview();
        }

        $main = $order->getMain();
        if (! $main instanceof Main) {
            return $this->emptyPreview();
        }

        $actions = $this->planActions($order, $main, $proposedLines);

        if ($onlyProductId !== null && $onlyProductId > 0) {
            $actions = array_values(array_filter(
                $actions,
                fn (array $action): bool => (int) $action['product_id'] === $onlyProductId,
            ));
        }

        return [
            'has_impact' => $actions !== [],
            'actions' => $actions,
        ];
    }

    /**
     * Preview when an order line was removed from the form (qty 0) but main still has active lines.
     *
     * @return array{has_impact: bool, actions: list<array{type: string, product_id: int, qty: float, canceled_qty?: float, primary_cancelable?: bool}>}
     */
    public function previewForRemovedOrderProduct(Order $order, int $productId): array
    {
        if ($order->getMainId() === null || $productId <= 0) {
            return $this->emptyPreview();
        }

        $product = Product::query()->find($productId);
        if ($product?->getType() === ProductType::Service) {
            return $this->emptyPreview();
        }

        $main = $order->getMain();
        if (! $main instanceof Main) {
            return $this->emptyPreview();
        }

        $activeByProduct = $this->resolveActiveMainLinesByProductId($main);
        /** @var Collection<int, OrderProduct> $activeLines */
        $activeLines = $activeByProduct[$productId] ?? collect();

        if ($activeLines->isEmpty()) {
            return $this->emptyPreview();
        }

        $primary = $this->resolvePrimaryLine($activeLines);
        $sumActive = (float) $activeLines->sum(fn (OrderProduct $line): float => (float) $line->getQty());

        return [
            'has_impact' => true,
            'actions' => [
                $this->action(
                    self::ACTION_FULL_CANCEL_ACTIVE_LINES,
                    $productId,
                    $sumActive,
                    $primary->getStatus()?->isCancelable() ?? false,
                ),
            ],
        ];
    }

    /**
     * @param  array{has_impact: bool, actions: list<array{type: string, product_id: int, qty: float, canceled_qty?: float, primary_cancelable?: bool}>}  $preview
     * @return array{title: string, description: string, lines: list<string>}
     */
    public function describeActionsForModal(array $preview): array
    {
        $lines = [];
        foreach ($preview['actions'] as $action) {
            $primaryCancelable = (bool) ($action['primary_cancelable'] ?? false);

            $message = match ($action['type']) {
                self::ACTION_ADD_TO_MAIN => 'Je voegt een artikel toe. Deze wordt toegevoegd aan de Artikelen/Bucket tab (Openstaand) om in te kopen.',
                self::ACTION_INCREASE_PRIMARY_QTY => 'Je hebt het aantal verhoogd. Het artikel is nog <u>niet</u> ingekocht. Het aantal wordt gewijzigd in de Artikelen/Bucket tab (Openstaand) om in te kopen.',
                self::ACTION_ADD_DELTA_LINE => 'Je hebt het aantal verhoogd. Het artikel is ingekocht. De extra te bestellen artikelen worden toegevoegd in de Artikelen/Bucket tab (Openstaand) om extra in te kopen.',
                self::ACTION_PARTIAL_DOWN_SPLIT => $primaryCancelable
                    ? 'Je hebt het aantal verlaagd. Het artikel is nog <u>niet</u> ingekocht. Dit aantal wordt in mindering gebracht in de Artikelen/Bucket tab (Openstaand).'
                    : 'Je hebt het aantal verlaagd. Het artikel is al ingekocht. De afgeboekte artikelen worden naar de TAB "Geannuleerd" verplaatst. Zorg dat je daar aangeeft of de artikelen geannuleerd zijn of geleverd worden.',
                self::ACTION_FULL_CANCEL_ACTIVE_LINES => $primaryCancelable
                    ? 'Je hebt het artikel verwijderd. Het artikel is nog <u>niet</u> ingekocht. Het wordt verplaatst naar Geannuleerd in de Artikelen/Bucket tab.'
                    : 'Je hebt het artikel verwijderd. Het artikel is al ingekocht. Het wordt verplaatst naar Geannuleerd in de Artikelen/Bucket tab. Zorg dat je daar aangeeft of de artikelen geannuleerd zijn of geleverd worden.',
                self::ACTION_CANCEL_ADDITIONAL_ACTIVE_LINES => null,
                default => null,
            };

            if ($message !== null) {
                $lines[] = $message;
            }
        }

        return [
            'title' => 'Let op',
            'description' => '',
            'lines' => array_values(array_unique($lines)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAdditionalMetadata(Order $order): array
    {
        return [
            'updated_by' => Auth::user()?->name,
            'order_rev' => $order->getRev(),
            'order_id' => $order->getId(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    private function mergeAdditionalMetadata(Order $order, ?array $existing): array
    {
        return array_merge($existing ?? [], $this->buildAdditionalMetadata($order));
    }

    /**
     * @param  list<array{product_id: int, qty: float}>|null  $proposedLines
     * @return list<array{type: string, product_id: int, qty: float, canceled_qty?: float, primary_cancelable?: bool}>
     */
    private function planActions(Order $order, Main $main, ?array $proposedLines): array
    {
        $orderQtyByProduct = $this->resolveOrderQtyByProductId($order, $proposedLines);

        if (! $main->orderProducts()->exists()) {
            $actions = [];
            foreach ($orderQtyByProduct as $productId => $qty) {
                if ($qty > 0) {
                    $actions[] = $this->action(self::ACTION_ADD_TO_MAIN, (int) $productId, $qty);
                }
            }

            return $actions;
        }

        $activeByProduct = $this->resolveActiveMainLinesByProductId($main);
        $actions = [];

        $allProductIds = array_values(array_unique(array_merge(
            array_keys($orderQtyByProduct),
            array_keys($activeByProduct),
        ), SORT_NUMERIC));

        foreach ($allProductIds as $productId) {
            $productId = (int) $productId;
            $orderQty = (float) ($orderQtyByProduct[$productId] ?? 0);
            /** @var Collection<int, OrderProduct> $activeLines */
            $activeLines = $activeByProduct[$productId] ?? collect();
            $sumActive = (float) $activeLines->sum(fn (OrderProduct $line): float => (float) $line->getQty());

            if ($activeLines->isEmpty()) {
                if ($orderQty > 0) {
                    $actions[] = $this->action(self::ACTION_ADD_TO_MAIN, $productId, $orderQty);
                }

                continue;
            }

            if ($orderQty > $sumActive) {
                $delta = $orderQty - $sumActive;
                $primary = $this->resolvePrimaryLine($activeLines);
                $status = $primary->getStatus();

                if ($status !== null && $status->isCancelable()) {
                    $actions[] = $this->action(self::ACTION_INCREASE_PRIMARY_QTY, $productId, $delta, true);
                } else {
                    $actions[] = $this->action(self::ACTION_ADD_DELTA_LINE, $productId, $delta, false);
                }

                continue;
            }

            if ($orderQty < $sumActive) {
                $primary = $this->resolvePrimaryLine($activeLines);
                $primaryCancelable = $primary->getStatus()?->isCancelable() ?? false;

                if ($orderQty <= 0) {
                    $actions[] = $this->action(
                        self::ACTION_FULL_CANCEL_ACTIVE_LINES,
                        $productId,
                        $sumActive,
                        $primaryCancelable,
                    );

                    continue;
                }

                $primaryQty = (float) $primary->getQty();

                if ($primaryQty > $orderQty) {
                    $actions[] = $this->action(
                        self::ACTION_PARTIAL_DOWN_SPLIT,
                        $productId,
                        $orderQty,
                        $primaryCancelable,
                        $primaryQty - $orderQty,
                    );
                }

                if ($activeLines->count() > 1) {
                    $actions[] = $this->action(self::ACTION_CANCEL_ADDITIONAL_ACTIVE_LINES, $productId, 0);
                }
            }
        }

        return $actions;
    }

    /**
     * @return array{type: string, product_id: int, qty: float, canceled_qty?: float, primary_cancelable?: bool}
     */
    private function action(
        string $type,
        int $productId,
        float $qty,
        ?bool $primaryCancelable = null,
        ?float $canceledQty = null,
    ): array {
        $action = [
            'type' => $type,
            'product_id' => $productId,
            'qty' => $qty,
        ];

        if ($primaryCancelable !== null) {
            $action['primary_cancelable'] = $primaryCancelable;
        }

        if ($canceledQty !== null) {
            $action['canceled_qty'] = $canceledQty;
        }

        return $action;
    }

    /**
     * @param  list<array{type: string, product_id: int, qty: float, canceled_qty?: float, primary_cancelable?: bool}>  $actions
     */
    private function applyActions(Order $order, Main $main, array $actions): void
    {
        $main->load(['orderProducts.product']);

        foreach ($actions as $action) {
            $productId = (int) $action['product_id'];
            $activeByProduct = $this->resolveActiveMainLinesByProductId($main);
            /** @var Collection<int, OrderProduct> $activeLines */
            $activeLines = $activeByProduct[$productId] ?? collect();
            $template = $this->resolveOrderProductTemplate($order, $productId);

            match ($action['type']) {
                self::ACTION_ADD_TO_MAIN => $this->addToMain(
                    $order,
                    $main,
                    $template,
                    (float) $action['qty'],
                ),
                self::ACTION_INCREASE_PRIMARY_QTY => $this->increasePrimaryQty(
                    $activeLines,
                    (float) $action['qty'],
                ),
                self::ACTION_ADD_DELTA_LINE => $this->addDeltaLine(
                    $order,
                    $main,
                    $template,
                    (float) $action['qty'],
                ),
                self::ACTION_PARTIAL_DOWN_SPLIT => $this->partialDownSplit(
                    $order,
                    $main,
                    $template,
                    $activeLines,
                    (float) $action['qty'],
                    (float) ($action['canceled_qty'] ?? 0),
                ),
                self::ACTION_FULL_CANCEL_ACTIVE_LINES => $this->cancelActiveLines($order, $activeLines),
                self::ACTION_CANCEL_ADDITIONAL_ACTIVE_LINES => $this->cancelAdditionalActiveLines(
                    $order,
                    $activeLines,
                ),
                default => null,
            };
        }
    }

    /**
     * @return array{has_impact: bool, actions: list<array{type: string, product_id: int, qty: float, canceled_qty?: float, primary_cancelable?: bool}>}
     */
    private function emptyPreview(): array
    {
        return [
            'has_impact' => false,
            'actions' => [],
        ];
    }

    private function formatQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2, ',', ''), '0'), ',') ?: '0';
    }

    private function addToMain(Order $order, Main $main, ?OrderProduct $template, float $qty): void
    {
        if ($template === null || $qty <= 0) {
            return;
        }

        $copy = $template->replicate();
        $copy->setOrderId($main->getId());
        $copy->setQty($qty);
        $copy->additional = $this->mergeAdditionalMetadata($order, null);
        $copy->save();
    }

    /**
     * @param  Collection<int, OrderProduct>  $activeLines
     */
    private function increasePrimaryQty(Collection $activeLines, float $delta): void
    {
        $primary = $this->resolvePrimaryLine($activeLines);
        $primary->setQty((float) $primary->getQty() + $delta);
        $primary->save();
    }

    private function addDeltaLine(
        Order $order,
        Main $main,
        ?OrderProduct $template,
        float $delta,
    ): void {
        if ($template === null || $delta <= 0) {
            return;
        }

        $copy = $template->replicate();
        $copy->setOrderId($main->getId());
        $copy->setQty($delta);
        $copy->setStatus(OrderProductStatus::Initial);
        $copy->additional = $this->mergeAdditionalMetadata($order, null);
        $copy->save();
    }

    /**
     * @param  Collection<int, OrderProduct>  $activeLines
     */
    private function partialDownSplit(
        Order $order,
        Main $main,
        ?OrderProduct $template,
        Collection $activeLines,
        float $orderQty,
        float $canceledQty,
    ): void {
        $primary = $this->resolvePrimaryLine($activeLines);
        $primary->setQty($orderQty);
        $primary->save();

        if ($canceledQty <= 0 || $template === null) {
            return;
        }

        $this->createCanceledLine($order, $main, $template, $primary, $canceledQty);
    }

    /**
     * @param  Collection<int, OrderProduct>  $activeLines
     */
    private function cancelActiveLines(Order $order, Collection $activeLines): void
    {
        foreach ($activeLines as $line) {
            $line->setStatus(OrderProductStatus::Canceled);
            $line->additional = $this->mergeAdditionalMetadata(
                $order,
                is_array($line->additional) ? $line->additional : null,
            );
            $line->save();
        }
    }

    /**
     * @param  Collection<int, OrderProduct>  $activeLines
     */
    private function cancelAdditionalActiveLines(Order $order, Collection $activeLines): void
    {
        $primary = $this->resolvePrimaryLine($activeLines);

        foreach ($activeLines as $line) {
            if ($line->getId() === $primary->getId()) {
                continue;
            }

            $line->setStatus(OrderProductStatus::Canceled);
            $line->additional = $this->mergeAdditionalMetadata(
                $order,
                is_array($line->additional) ? $line->additional : null,
            );
            $line->save();
        }
    }

    private function createCanceledLine(
        Order $order,
        Main $main,
        OrderProduct $template,
        OrderProduct $source,
        float $qty,
    ): void {
        $copy = $template->replicate();
        $copy->setOrderId($main->getId());
        $copy->setQty($qty);
        $copy->setStatus(OrderProductStatus::Canceled);
        $copy->additional = $this->mergeAdditionalMetadata($order, null);
        $copy->delivered_at = $source->delivered_at;
        $copy->purchased_at = $source->purchased_at ?? $template->purchased_at;
        $copy->save();
    }

    /**
     * @param  list<array{product_id: int, qty: float}>|null  $proposedLines
     * @return array<int, float>
     */
    private function resolveOrderQtyByProductId(Order $order, ?array $proposedLines): array
    {
        if ($proposedLines !== null) {
            return $this->resolveOrderQtyByProductIdFromProposedLines($proposedLines);
        }

        $byProduct = [];

        $orderProducts = $order->orderProducts()
            ->with('product')
            ->whereNotNull('product_id')
            ->whereHas('product', fn (Builder $q): Builder => $q->where('type', '!=', ProductType::Service->value))
            ->get();

        foreach ($orderProducts as $orderProduct) {
            $productId = (int) $orderProduct->product_id;
            $byProduct[$productId] = ($byProduct[$productId] ?? 0) + (float) $orderProduct->getQty();
        }

        return $byProduct;
    }

    /**
     * @param  list<array{product_id: int, qty: float}>  $proposedLines
     * @return array<int, float>
     */
    private function resolveOrderQtyByProductIdFromProposedLines(array $proposedLines): array
    {
        $productIds = collect($proposedLines)
            ->pluck('product_id')
            ->unique()
            ->filter()
            ->map(fn (mixed $id): int => (int) $id);

        if ($productIds->isEmpty()) {
            return [];
        }

        $nonServiceProductIds = Product::query()
            ->whereIn('id', $productIds)
            ->where('type', '!=', ProductType::Service->value)
            ->pluck('id')
            ->mapWithKeys(fn (mixed $id): array => [(int) $id => true]);

        $byProduct = [];
        foreach ($proposedLines as $line) {
            $productId = (int) $line['product_id'];
            if (! $nonServiceProductIds->has($productId)) {
                continue;
            }

            $byProduct[$productId] = ($byProduct[$productId] ?? 0) + (float) $line['qty'];
        }

        return $byProduct;
    }

    /**
     * @return array<int, Collection<int, OrderProduct>>
     */
    private function resolveActiveMainLinesByProductId(Main $main): array
    {
        $lines = $main->orderProducts()
            ->whereNotIn('status', [
                OrderProductStatus::Canceled->value,
                OrderProductStatus::AddToStock->value,
            ])
            ->whereHas('product', fn (Builder $q): Builder => $q->where('type', '!=', ProductType::Service->value))
            ->whereNotNull('product_id')
            ->orderBy('id')
            ->get();

        /** @var array<int, Collection<int, OrderProduct>> $grouped */
        $grouped = [];

        foreach ($lines as $line) {
            $productId = (int) $line->product_id;
            if (! isset($grouped[$productId])) {
                $grouped[$productId] = collect();
            }
            $grouped[$productId]->push($line);
        }

        return $grouped;
    }

    /**
     * @param  Collection<int, OrderProduct>  $activeLines
     */
    private function resolvePrimaryLine(Collection $activeLines): OrderProduct
    {
        return $activeLines->sortBy('id')->first();
    }

    private function resolveOrderProductTemplate(Order $order, int $productId): ?OrderProduct
    {
        return $order->orderProducts()
            ->where('product_id', $productId)
            ->whereHas('product', fn (Builder $q): Builder => $q->where('type', '!=', ProductType::Service->value))
            ->first();
    }

    private function copyOrderProductsToMain(Order $order, Main $main): void
    {
        $orderProducts = $order->orderProducts()
            ->whereHas('product', fn (Builder $q): Builder => $q->where('type', '!=', ProductType::Service->value))
            ->get();

        if ($orderProducts->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($order, $orderProducts, $main): void {
            foreach ($orderProducts as $orderProduct) {
                $copy = $orderProduct->replicate();
                $copy->setOrderId($main->getId());
                $copy->additional = $this->buildAdditionalMetadata($order);
                $copy->save();
            }
        });
    }
}
