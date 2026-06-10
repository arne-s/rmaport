<?php

namespace App\Jobs;

use App\Models\AppSyncMessage;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ExactOnlineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncSupplierToExactJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $supplierId,
        public int $userId,
    ) {}

    public function handle(): void
    {
        $supplier = Supplier::query()->find($this->supplierId);
        if ($supplier === null) {
            return;
        }

        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        /** @var ExactOnlineService $exact */
        $exact = app('exact');

        try {
            if ($supplier->exact_id) {
                $success = $exact->updateSupplier($supplier);

                if (! $success) {
                    $this->notifyFailure($user, $supplier, 'Update naar Exact mislukt.');

                    return;
                }
            } else {
                $exactId = $exact->createSupplier($supplier);

                if (empty($exactId)) {
                    $this->notifyFailure($user, $supplier, 'Aanmaken in Exact mislukt.');

                    return;
                }

                $exactSupplier = $exact->getSupplier($exactId);
                $supplier->exact_id = $exactId;
                $supplier->exact_code = $exactSupplier ? trim($exactSupplier['Code']) : null;
            }

            $supplier->last_synced_at = now();
            $supplier->save();

            AppSyncMessage::queueForUser(
                $this->userId,
                AppSyncMessage::KIND_EXACT_SUPPLIER_SYNC,
                AppSyncMessage::STATUS_SUCCESS,
                'Leverancier gesynchroniseerd met Exact',
                "Leverancier \"{$supplier->getName()}\" is succesvol gesynchroniseerd.",
                ['supplier_id' => $supplier->id],
            );
        } catch (Throwable $e) {
            Log::driver('exact-online')->error("SyncSupplierToExactJob failed for supplier {$this->supplierId}: {$e->getMessage()}");

            $this->notifyFailure($user, $supplier, $e->getMessage());
        }
    }

    private function notifyFailure(User $user, Supplier $supplier, string $error): void
    {
        AppSyncMessage::queueForUser(
            $user->id,
            AppSyncMessage::KIND_EXACT_SUPPLIER_SYNC,
            AppSyncMessage::STATUS_FAILURE,
            'Leverancier niet gesynchroniseerd met Exact',
            "Leverancier \"{$supplier->getName()}\": {$error}",
            ['supplier_id' => $supplier->id],
        );
    }
}
