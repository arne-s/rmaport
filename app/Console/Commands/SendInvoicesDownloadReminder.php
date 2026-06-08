<?php

namespace App\Console\Commands;

use App\Actions\SendInvoicesDownloadReminderMailAction;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use Throwable;
use Illuminate\Console\Command;

class SendInvoicesDownloadReminder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:send-invoices-download-reminder';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send reminder emails to dealers to download their invoices.';

    /**
     * Execute the console command.
     * @throws Throwable
     */
    public function handle(): void
    {
        $dealers = Customer::where('status', CustomerStatus::Active)
            ->where('type', CustomerType::Dealer)
            ->get();

        $count = 0;

        foreach ($dealers as $dealer) {
            $this->info("Sending reminder to {$dealer->getName()} <{$dealer->getEmail()}> ({$dealer->getId()}).");

            try {
                (new SendInvoicesDownloadReminderMailAction($dealer, $dealer->getEmail()))->execute();
            } catch (Throwable $e) {
                $this->error($e->getMessage());
            }

            $count++;
        }

        $this->info($count . ' reminders sent.');
    }
}
