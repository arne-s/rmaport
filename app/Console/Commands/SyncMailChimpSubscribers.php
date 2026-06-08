<?php

namespace App\Console\Commands;

use App\Services\MailChimpCustomerTypeSample;
use App\Services\MailChimpService;
use App\Services\NewsletterSubscriptionWriter;
use Illuminate\Console\Command;

class SyncMailChimpSubscribers extends Command
{
    protected $signature = 'mailchimp:sync-subscribers
                            {--local-only : Rebuild newsletter_subscriptions from the ERP only; no Mailchimp API calls}
                            {--pull-only : Only pull Mailchimp unsubscribes into the ERP (no pushes)}
                            {--limit= : Maximum number of subscription rows to process per push/pull step}
                            {--customer-type-sample=5 : Push subscribed rows for N customers per visible customer type (for tag verification)}
                            {--with-pull : Also pull unsubscribes after push (default with --customer-type-sample: push only)}';

    protected $description = 'Sync Mailchimp newsletter subscriptions with the ERP';

    public function handle(MailChimpService $mailchimpService): int
    {
        if ($this->option('local-only') && $this->option('pull-only')) {
            $this->error('Choose either --local-only or --pull-only, not both.');

            return self::INVALID;
        }

        $limit = $this->resolveLimitOption();
        if ($limit === false) {
            return self::INVALID;
        }

        $samplePerType = $this->resolveCustomerTypeSampleOption();
        if ($samplePerType === false) {
            return self::INVALID;
        }

        if ($samplePerType !== null && $limit !== null) {
            $this->warn('Both --limit and --customer-type-sample were passed; --customer-type-sample takes precedence.');
            $limit = null;
        }

        if ($this->option('local-only')) {
            if ($limit !== null || $samplePerType !== null) {
                $this->warn('The --limit and --customer-type-sample options are ignored with --local-only.');

                return self::INVALID;
            }

            NewsletterSubscriptionWriter::populateAllFromErp();
            $this->info('Newsletter subscription rows rebuilt from the ERP (no Mailchimp calls).');

            return self::SUCCESS;
        }

        if (! $mailchimpService->isConfigured()) {
            $this->warn('Mailchimp is not configured; skipping remote sync.');

            return self::FAILURE;
        }

        $onlySubscriptionIds = null;
        if ($samplePerType !== null) {
            $sample = MailChimpCustomerTypeSample::resolve($samplePerType);
            $onlySubscriptionIds = $sample['subscription_ids'];

            $this->info("Customer type sample ({$samplePerType} per type):");
            $this->table(
                ['Type', 'Customers', 'Subscription rows', 'Segments'],
                collect($sample['summary'])->map(
                    fn (array $row, string $type): array => [
                        $type,
                        (string) $row['customers'],
                        (string) $row['subscriptions'],
                        $row['segments'],
                    ],
                )->values()->all(),
            );

            if ($onlySubscriptionIds === []) {
                $this->warn('No subscribed sample rows found for any customer type.');
                $this->line('Tip: run `php artisan mailchimp:sync-subscribers --local-only` to rebuild subscription rows from the ERP.');

                return self::FAILURE;
            }

            if (($sample['pending_deactivations'] ?? 0) > 0) {
                $this->line("Including {$sample['pending_deactivations']} pending tag deactivation(s) for sampled customers.");
            }

            if (! $this->option('with-pull')) {
                $this->line('Pull skipped (use --with-pull to check Mailchimp unsubscribes after push).');
            }
        }

        if ($this->option('pull-only')) {
            return $this->runPull($mailchimpService, $limit, $onlySubscriptionIds);
        }

        $pushResult = $this->runPush($mailchimpService, $limit, $onlySubscriptionIds);

        $skipPull = $samplePerType !== null && ! $this->option('with-pull');
        if (! $skipPull) {
            $this->newLine();
            $pullResult = $this->runPull($mailchimpService, $limit, $onlySubscriptionIds);

            if ($pullResult === self::FAILURE) {
                return self::FAILURE;
            }
        }

        if ($pushResult === self::FAILURE) {
            return self::FAILURE;
        }

        $this->info('Mailchimp sync finished.');

        return self::SUCCESS;
    }

    /**
     * @param  list<int>|null  $onlySubscriptionIds
     */
    private function runPush(MailChimpService $mailchimpService, ?int $limit, ?array $onlySubscriptionIds): int
    {
        $total = $mailchimpService->countPushCandidates($limit, $onlySubscriptionIds);

        if ($total === 0) {
            $this->info('No subscription rows to push.');

            return self::SUCCESS;
        }

        $this->info("Pushing {$total} subscription row(s) to Mailchimp…");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $result = $mailchimpService->pushPendingNewsletterSubscriptions(
            limit: $limit,
            onlySubscriptionIds: $onlySubscriptionIds,
            onProgress: static fn (): mixed => $bar->advance(),
        );

        $bar->finish();
        $this->newLine();
        $this->info("Push complete: {$result['processed']} processed, {$result['succeeded']} succeeded, {$result['failed']} failed.");

        if ($result['failed'] > 0) {
            $this->line('See storage/logs/mailchimp.log or newsletter_subscriptions.last_error for details.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<int>|null  $onlySubscriptionIds
     */
    private function runPull(MailChimpService $mailchimpService, ?int $limit, ?array $onlySubscriptionIds): int
    {
        $total = $mailchimpService->countPullCandidates($limit, $onlySubscriptionIds);

        if ($total === 0) {
            $this->info('No subscription rows to pull.');

            return self::SUCCESS;
        }

        $this->info("Checking {$total} subscription row(s) for Mailchimp unsubscribes…");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $result = $mailchimpService->pullUnsubscribesFromMailchimp(
            limit: $limit,
            onlySubscriptionIds: $onlySubscriptionIds,
            onProgress: static fn (): mixed => $bar->advance(),
        );

        $bar->finish();
        $this->newLine();
        $this->info("Pull complete: {$result['processed']} checked, {$result['unsubscribed']} unsubscribed in ERP.");

        return self::SUCCESS;
    }

    private function resolveLimitOption(): int|false|null
    {
        $limit = $this->option('limit');
        if ($limit === null || $limit === '') {
            return null;
        }

        if (! is_numeric($limit) || (int) $limit < 1) {
            $this->error('The --limit option must be a positive integer.');

            return false;
        }

        return (int) $limit;
    }

    private function resolveCustomerTypeSampleOption(): int|false|null
    {
        if (! $this->input->hasParameterOption('--customer-type-sample', true)) {
            return null;
        }

        $sample = $this->option('customer-type-sample');
        if ($sample === null || $sample === '') {
            return 5;
        }

        if (! is_numeric($sample) || (int) $sample < 1) {
            $this->error('The --customer-type-sample option must be a positive integer.');

            return false;
        }

        return (int) $sample;
    }
}
