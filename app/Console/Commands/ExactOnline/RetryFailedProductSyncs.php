<?php

namespace App\Console\Commands\ExactOnline;

use App\Jobs\SyncProductToExactJob;
use App\Models\AppSyncMessage;
use App\Models\Product;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RetryFailedProductSyncs extends Command
{
    protected $signature = 'exact-online:retry-product-syncs
                            {--sync : Run immediately instead of dispatching queue jobs}
                            {--include-never-synced : Also retry eligible products that were never synced to Exact}
                            {--product=* : Limit to specific local product ID(s)}
                            {--user= : User ID for sync notifications (defaults to first user)}
                            {--limit= : Maximum number of products to process}';

    protected $description = 'Retry pushing products to Exact Online after a failed or missing sync job';

    public function handle(): int
    {
        if (! config('exact.enabled')) {
            $this->error('Exact Online integration is disabled (exact.enabled).');

            return self::FAILURE;
        }

        $userId = $this->resolveUserId();
        if ($userId === null) {
            $this->error('No user found for sync notifications. Pass --user=<id>.');

            return self::FAILURE;
        }

        $productIds = $this->resolveProductIdsToRetry();

        if ($productIds->isEmpty()) {
            $this->info('No products found that need an Exact sync retry.');

            return self::SUCCESS;
        }

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->whereNotNull('exact_article_group_id')
            ->orderBy('id')
            ->get()
            ->filter(fn (Product $product): bool => $product->shouldBeSyncedToExact());

        if ($products->isEmpty()) {
            $this->info('No eligible products found after applying sync rules.');

            return self::SUCCESS;
        }

        $limit = $this->option('limit');
        if ($limit !== null && (int) $limit > 0) {
            $products = $products->take((int) $limit);
        }

        $this->info(sprintf('Retrying Exact sync for %d product(s)...', $products->count()));

        $queued = 0;
        $failed = 0;

        foreach ($products as $product) {
            $label = sprintf('#%d %s', $product->id, $product->getName() ?? '-');

            try {
                if ($this->option('sync')) {
                    (new SyncProductToExactJob($product->id, $userId))->handle();
                    $this->line("  Synced: {$label}");
                } else {
                    SyncProductToExactJob::dispatch($product->id, $userId);
                    $this->line("  Queued: {$label}");
                }

                $queued++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  Failed: {$label} — {$e->getMessage()}");
            }
        }

        if ($this->option('sync')) {
            $this->info("Done. Synced: {$queued}, failed: {$failed}.");
        } else {
            $this->info("Done. Queued: {$queued}, failed to queue: {$failed}.");
            $this->comment('Ensure a queue worker is running: php artisan queue:work');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, int>
     */
    private function resolveProductIdsToRetry(): Collection
    {
        $explicitIds = collect($this->option('product'))
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($explicitIds->isNotEmpty()) {
            return $explicitIds;
        }

        $ids = collect()
            ->merge($this->productIdsFromFailedSyncMessages())
            ->merge($this->productIdsFromFailedQueueJobs())
            ->unique()
            ->values();

        if ($this->option('include-never-synced')) {
            $ids = $ids->merge($this->productIdsNeverSyncedToExact())->unique()->values();
        }

        return $ids;
    }

    /**
     * @return Collection<int, int>
     */
    private function productIdsFromFailedSyncMessages(): Collection
    {
        return AppSyncMessage::query()
            ->where('kind', AppSyncMessage::KIND_EXACT_PRODUCT_SYNC)
            ->where('status', AppSyncMessage::STATUS_FAILURE)
            ->whereNotNull('metadata->product_id')
            ->orderByDesc('id')
            ->get()
            ->pluck('metadata')
            ->map(fn (mixed $metadata): ?int => is_array($metadata) ? (int) ($metadata['product_id'] ?? 0) : null)
            ->filter(fn (?int $id): bool => $id !== null && $id > 0)
            ->unique()
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    private function productIdsFromFailedQueueJobs(): Collection
    {
        if (! $this->failedJobsTableExists()) {
            return collect();
        }

        return DB::table('failed_jobs')
            ->where('payload', 'like', '%SyncProductToExactJob%')
            ->pluck('payload')
            ->map(function (mixed $payload): ?int {
                if (! is_string($payload)) {
                    return null;
                }

                if (preg_match('/"productId";i:(\d+)/', $payload, $matches) === 1) {
                    return (int) $matches[1];
                }

                if (preg_match('/productId\\\\";i:(\d+)/', $payload, $matches) === 1) {
                    return (int) $matches[1];
                }

                return null;
            })
            ->filter(fn (?int $id): bool => $id !== null && $id > 0)
            ->unique()
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    private function productIdsNeverSyncedToExact(): Collection
    {
        return Product::query()
            ->whereNotNull('exact_article_group_id')
            ->whereNull('exact_id')
            ->where('name', 'not like', '%-kopie')
            ->pluck('id');
    }

    private function failedJobsTableExists(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable('failed_jobs');
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveUserId(): ?int
    {
        $userOption = $this->option('user');
        if ($userOption !== null && (int) $userOption > 0) {
            return User::query()->whereKey((int) $userOption)->exists()
                ? (int) $userOption
                : null;
        }

        $id = User::query()->orderBy('id')->value('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
