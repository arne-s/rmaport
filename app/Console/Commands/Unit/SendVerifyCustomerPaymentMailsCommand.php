<?php

namespace App\Console\Commands\Unit;

use Illuminate\Console\Command;

class SendVerifyCustomerPaymentMailsCommand extends Command
{
    protected $signature = 'unit:send-verify-customer-payment-mails';

    protected $description = 'Send internal verify-payment mail once per unit main while a delivery is between configured min/max hours away and invoices remain unpaid.';

    public function handle(): int
    {
        $this->info('Payment verify mails skipped: delivery appointments are disabled.');

        return self::SUCCESS;
    }
}
