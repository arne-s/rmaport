<?php

namespace App\Jobs;

use App\Models\AppSyncMessage;
use App\Models\Product;
use App\Models\User;
use App\Services\ExactOnlineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncProductToExactJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $productId,
        public int $userId,
    ) {}

    public function handle(): void
    {
        $product = Product::query()->find($this->productId);
        if ($product === null) {
            return;
        }

        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        /** @var ExactOnlineService $exact */
        $exact = app('exact');

        try {
            if ($product->exact_id) {
                $success = $exact->updateProductInExact($product);

                if (! $success) {
                    $this->notifyFailure($user, $product, 'Update naar Exact mislukt.');

                    return;
                }
            } else {
                $newId = $exact->createProductInExact($product);

                if ($newId === null) {
                    $this->notifyFailure($user, $product, 'Aanmaken in Exact mislukt.');

                    return;
                }

                $product->exact_id = $newId;
            }

            $product->exact_synced_at = now();
            $product->save();

            AppSyncMessage::queueForUser(
                $this->userId,
                AppSyncMessage::KIND_EXACT_PRODUCT_SYNC,
                AppSyncMessage::STATUS_SUCCESS,
                'Product gesynchroniseerd met Exact',
                "Product \"{$product->getName()}\" is succesvol gesynchroniseerd.",
                ['product_id' => $product->id],
            );
        } catch (Throwable $e) {
            Log::driver('exact-online')->error("SyncProductToExactJob failed for product {$this->productId}: {$e->getMessage()}");

            $this->notifyFailure($user, $product, $e->getMessage());
        }
    }

    private function notifyFailure(User $user, Product $product, string $error): void
    {
        AppSyncMessage::queueForUser(
            $user->id,
            AppSyncMessage::KIND_EXACT_PRODUCT_SYNC,
            AppSyncMessage::STATUS_FAILURE,
            'Product niet gesynchroniseerd met Exact',
            "Product \"{$product->getName()}\": {$error}",
            ['product_id' => $product->id],
        );
    }
}
