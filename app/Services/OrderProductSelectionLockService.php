<?php

namespace App\Services;

use App\Models\OrderProduct;
use App\Models\RecordLock;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class OrderProductSelectionLockService
{
    public function __construct(
        private readonly RecordLockService $recordLockService,
    ) {}

    /**
     * Reserve selected lines for bulk actions, or return blocking lock details.
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
