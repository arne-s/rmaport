<?php

namespace App\Jobs;

use App\Models\AppSyncMessage;
use App\Models\Customer;
use App\Models\User;
use App\Services\ExactOnlineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued push of customers to Exact CRM after save (excludes test accounts and RD stamgegevens).
 */
class SyncCustomerToExactJob implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $customerId,
        public int $userId,
    ) {}

    public function handle(): void
    {
        $customer = Customer::query()->find($this->customerId);
        if ($customer === null) {
            Log::driver('exact-online')->warning('SyncCustomerToExactJob skipped: customer not found', [
                'customer_id' => $this->customerId,
            ]);

            return;
        }

        if (! $customer->shouldPushCustomerToExact()) {
            return;
        }

        $user = User::query()->find($this->userId);
        if ($user === null) {
            Log::driver('exact-online')->warning('SyncCustomerToExactJob: user not found', [
                'customer_id' => $this->customerId,
                'user_id' => $this->userId,
            ]);
        }

        /** @var ExactOnlineService $exact */
        $exact = app('exact');

        try {
            if ($customer->exact_id) {
                $success = $exact->updateCustomer($customer);

                if (! $success) {
                    $this->logSyncFailure($user, $customer, 'Update naar Exact mislukt.');

                    return;
                }
            } else {
                $exactId = $exact->createCustomer($customer);

                if (empty($exactId)) {
                    $this->logSyncFailure($user, $customer, 'Aanmaken in Exact mislukt.');

                    return;
                }

                $exactAccount = $exact->getCompany($exactId);
                $customer->exact_id = $exactId;
                $customer->debtor_number = $exactAccount ? trim($exactAccount['Code']) : null;
            }

            $customer->exact_synced_at = now();
            $customer->save();

            Log::driver('exact-online')->info('SyncCustomerToExactJob succeeded', [
                'customer_id' => $customer->id,
                'user_id' => $this->userId,
            ]);

            if ($user !== null) {
                AppSyncMessage::queueForUser(
                    $this->userId,
                    AppSyncMessage::KIND_EXACT_CUSTOMER_SYNC,
                    AppSyncMessage::STATUS_SUCCESS,
                    'Klant gesynchroniseerd met Exact',
                    "\"{$customer->getName()}\" is succesvol gesynchroniseerd.",
                    ['customer_id' => $customer->id],
                );
            }
        } catch (Throwable $e) {
            Log::driver('exact-online')->error("SyncCustomerToExactJob failed for customer {$this->customerId}: {$e->getMessage()}");

            $this->logSyncFailure($user, $customer, $e->getMessage());
        }
    }

    private function logSyncFailure(?User $user, Customer $customer, string $error): void
    {
        Log::driver('exact-online')->error('SyncCustomerToExactJob failure', [
            'customer_id' => $customer->id,
            'customer_name' => $customer->getName(),
            'user_id' => $this->userId,
            'acting_user_found' => $user !== null,
            'error' => $error,
        ]);

        if ($user !== null) {
            AppSyncMessage::queueForUser(
                $this->userId,
                AppSyncMessage::KIND_EXACT_CUSTOMER_SYNC,
                AppSyncMessage::STATUS_FAILURE,
                'Let op! Klant niet gesynchroniseerd met Exact',
                "\"{$customer->getName()}\": {$error}",
                ['customer_id' => $customer->id],
            );
        }
    }
}
