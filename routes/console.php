<?php

use Illuminate\Support\Facades\Schedule;

//Schedule::command('orders:expire-quotes')->everyFourHours();
//
//Schedule::command('sync:process')->everyThirtySeconds();
//Schedule::command('sync:send')->everyMinute();
//
//Schedule::command('orders:update-order-status')->everyThirtySeconds();
//
// Schedule::command('app:deposit-payment-reminder')->everyFourHours();

//Schedule::command('emails:process')->everyMinute();

// only on production
//if (app()->environment('production')) {
//    Schedule::command('orders:send-invoices')->everyThirtySeconds();
//
    Schedule::command('exact-online:refresh-tokens')->everyMinute();
    Schedule::command('microsoft:refresh-tokens')->dailyAt('04:00');
    Schedule::command('exact-online:sync-payments')->everyFifteenMinutes();
    Schedule::command('exact-online:sync-po-invoices')->hourly();
    Schedule::command('exact-online:import-purchase-invoices')->everySixHours();
    Schedule::command('orders:send-invoices')->everyMinute();
    Schedule::command('appointments:send-reminders')->hourly();
    Schedule::command('unit:send-verify-customer-payment-mails')->hourly();
    Schedule::command('invoices:send-payment-reminders')->dailyAt('01:00');
    Schedule::command('recurring-invoices:issue-due')->dailyAt('09:00');
    Schedule::command('main-reports:refresh')->dailyAt('09:00');
    Schedule::command('main-reports:refresh')->dailyAt('13:00');
    Schedule::command('main-reports:refresh')->dailyAt('17:00');
    Schedule::command('orders:remove-initial')->dailyAt('02:30');
    Schedule::command('customers:cleanup-initial')->dailyAt('02:30');
    Schedule::command('serial-numbers:link-owners-from-debtor-numbers')->dailyAt('03:05');
    Schedule::command('app:prune-app-sync-messages')->dailyAt('03:15');
    Schedule::command('exact-online:sync-gl-accounts')->dailyAt('00:00');
    Schedule::command('exact-online:sync-article-groups')->dailyAt('00:00');
    Schedule::command('exact-online:sync-vat-codes')->dailyAt('00:00');
    Schedule::command('exact-online:sync-payment-conditions')->dailyAt('00:00');
    Schedule::command('mailchimp:sync-subscribers')->daily();
//    Schedule::command('exact-online:sync-new-products')->hourly();
//    Schedule::command('exact-online:sync-updated-products')->hourly();
//
//    // Gathers potential errors (unsynced invoices and products) and stores them in the database and logs them
//
//    Schedule::command('mailchimp:sync-subscribers')->daily();
//
//    Schedule::command('orders:send-invoices-download-reminder')->quarterlyOn(1, '09:00');
//}

// Send mail for cancelled orders, with credit invoice(s) if applicable. Run after sync invoices (when credit invoices are submitted to Exact).
//Schedule::command('orders:send-cancellations')->everyMinute();
