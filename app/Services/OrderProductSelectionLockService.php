<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Enums\ReleaseOrderStatus;
use App\Models\OrderProduct;
use App\Models\PurchaseOrder;
use App\Models\RecordLock;
use App\Models\ReleaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class OrderProductSelectionLockService
{
    public function __construct(
        private readonly RecordLockService $recordLockService,
    ) {}

    /**
     * Lock open order lines while a concept inkooporder is being edited.
     */
    public function lockLinesForConceptPurchaseOrder(PurchaseOrder $purchaseOrder, User $user): void
    {
        if ($purchaseOrder->getStatus() !== PurchaseOrderStatus::Initial) {
            return;
        }

        $purchaseOrder->loadMissing('orderProducts');

        foreach ($purchaseOrder->orderProducts as $orderProduct) {
            $this->recordLockService->acquire($orderProduct, $user);
        }
    }

    /**
     * Lock open order lines while a concept afroep is being edited.
     */
    public function lockLinesForConceptReleaseOrder(ReleaseOrder $releaseOrder, User $user): void
    {
        if ($releaseOrder->getStatus() !== ReleaseOrderStatus::Initial) {
            return;
        }

        $releaseOrder->loadMissing('orderProducts');

        foreach ($releaseOrder->orderProducts as $orderProduct) {
            $this->recordLockService->acquire($orderProduct, $user);
        }
    }

    /**
     * Reserve selected lines for inkopen/afroepen, or return blocking lock details.
     *
     * @param  Collection<int, OrderProduct>  $selected
     * @return array{holderName: string, lockedAt: string, expiresAt: string, backUrl: string, productLabel: string}|null
     */
    public function acquireBulkSelectionOrGetBlockingDetails(
        Collection $selected,
        User $user,
        string $backUrl,
    ): ?array {
        if ($selected->isEmpty()) {
            return null;
        }

        $ids = $selected->pluck('id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();

        /** @var EloquentCollection<int, OrderProduct> $fresh */
        $fresh = OrderProduct::query()
            ->whereIn('id', $ids)
            ->with(['product'])
            ->get()
            ->keyBy('id');

        if ($fresh->count() !== count($ids)) {
            return $this->unavailableDetails($backUrl, 'een of meer artikelen');
        }

        $ordered = collect($ids)->map(fn (int $id): OrderProduct => $fresh->get($id));

        foreach ($ordered as $orderProduct) {
            if ($this->lineIsUnavailableForBulkSelection($orderProduct)) {
                return $this->unavailableDetails($backUrl, $this->lineLabel($orderProduct));
            }

            $blocking = $this->recordLockService->getBlockingLock($orderProduct, $user);

            if ($blocking !== null) {
                return $this->blockingDetails($blocking, $backUrl, $this->lineLabel($orderProduct));
            }
        }

        $blocking = $this->recordLockService->acquireAllOrGetBlocking($ordered, $user);

        if ($blocking !== null) {
            $blockedLine = $ordered->first(
                fn (OrderProduct $line): bool => ! $this->recordLockService->isHeldByCurrentUser($line, $user),
            ) ?? $ordered->first();

            return $this->blockingDetails(
                $blocking,
                $backUrl,
                $blockedLine !== null ? $this->lineLabel($blockedLine) : 'een artikel',
            );
        }

        return null;
    }

    /**
     * @param  Collection<int, OrderProduct>  $selected
     */
    public function releaseBulkSelection(Collection $selected, User $user): void
    {
        $this->recordLockService->releaseAll($selected, $user);
    }

    private function lineIsUnavailableForBulkSelection(OrderProduct $orderProduct): bool
    {
        if ($orderProduct->purchase_order_id !== null) {
            $purchaseOrder = PurchaseOrder::query()->find($orderProduct->purchase_order_id);

            if ($purchaseOrder !== null && $purchaseOrder->getStatus() !== PurchaseOrderStatus::Initial) {
                return true;
            }
        }

        if ($orderProduct->release_order_id !== null) {
            $releaseOrder = ReleaseOrder::query()->find($orderProduct->release_order_id);

            if ($releaseOrder !== null && $releaseOrder->getStatus() !== ReleaseOrderStatus::Initial) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{holderName: string, lockedAt: string, expiresAt: string, backUrl: string, productLabel: string}
     */
    private function blockingDetails(RecordLock $lock, string $backUrl, string $productLabel): array
    {
        return array_merge(
            $this->recordLockService->toBlockedDetails($lock, $backUrl),
            ['productLabel' => $productLabel],
        );
    }

    /**
     * @return array{holderName: string, lockedAt: string, expiresAt: string, backUrl: string, productLabel: string}
     */
    private function unavailableDetails(string $backUrl, string $productLabel): array
    {
        return [
            'holderName' => '',
            'lockedAt' => '',
            'expiresAt' => '',
            'backUrl' => $backUrl,
            'productLabel' => $productLabel,
        ];
    }

    private function lineLabel(OrderProduct $orderProduct): string
    {
        $name = $orderProduct->getValue();

        if ($name !== '') {
            return $name;
        }

        return $orderProduct->product?->name ?? 'artikel #' . $orderProduct->getKey();
    }
}
