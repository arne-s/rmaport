<?php

namespace App\Console\Commands;

use App\Services\MailChimpService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ListMailchimpSegments extends Command
{
    protected $signature = 'mailchimp:list-segments
                            {--audience= : Override MAILCHIMP_AUDIENCE_ID for this request}';

    protected $description = 'List Mailchimp audience segments (optional setup helper)';

    public function handle(MailChimpService $mailchimpService): int
    {
        $apiKey = config('mailchimp.key') ?? config('services.mailchimp.key');
        $dataCenter = config('mailchimp.data_center') ?? config('services.mailchimp.data_center');
        $defaultAudience = config('mailchimp.audience_id') ?? config('services.mailchimp.audience_id');
        $audience = $this->option('audience') ?: $defaultAudience;

        if (! is_string($apiKey) || $apiKey === '' || ! is_string($dataCenter) || $dataCenter === '') {
            $this->error('Set MAILCHIMP_API_KEY and MAILCHIMP_DATA_CENTER in your environment.');

            return self::FAILURE;
        }

        if (! is_string($audience) || $audience === '') {
            $this->error('Set MAILCHIMP_AUDIENCE_ID or pass --audience=<list_id>.');

            return self::FAILURE;
        }

        try {
            $segments = $mailchimpService->listAllAudienceSegments($audience);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($segments === []) {
            $this->warn('No segments returned for this audience.');

            return self::SUCCESS;
        }

        $rows = array_map(static fn (array $row): array => [
            (string) $row['id'],
            $row['name'],
            $row['type'],
            (string) $row['member_count'],
        ], $segments);

        $this->table(['ID', 'Name', 'Type', 'Members'], $rows);

        return self::SUCCESS;
    }
}
