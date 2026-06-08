<?php

namespace App\Console\Commands\Microsoft;

use App\Models\MicrosoftMailToken;
use App\Models\MicrosoftToken;
use App\Services\MicrosoftCalendarService;
use App\Services\MicrosoftMailService;
use Illuminate\Console\Command;

class RefreshTokens extends Command
{
    protected $signature = 'microsoft:refresh-tokens';

    protected $description = 'Refresh Microsoft Outlook calendar and mail OAuth tokens';

    public function handle(
        MicrosoftCalendarService $calendarService,
        MicrosoftMailService $mailService,
    ): int {
        $calendarRefreshed = 0;
        $calendarFailed = 0;
        $mailRefreshed = 0;
        $mailFailed = 0;

        MicrosoftToken::query()
            ->whereNotNull('refresh_token')
            ->where('refresh_token', '!=', '')
            ->orderBy('id')
            ->each(function (MicrosoftToken $token) use ($calendarService, &$calendarRefreshed, &$calendarFailed): void {
                $label = $token->microsoft_email ?? ('token #' . $token->id);

                if ($calendarService->proactivelyRefresh($token)) {
                    $calendarRefreshed++;
                    $this->info("Calendar token refreshed: {$label}");

                    return;
                }

                $calendarFailed++;
                $this->warn("Calendar token refresh failed: {$label}");
            });

        MicrosoftMailToken::query()
            ->whereNotNull('refresh_token')
            ->where('refresh_token', '!=', '')
            ->orderBy('id')
            ->each(function (MicrosoftMailToken $token) use ($mailService, &$mailRefreshed, &$mailFailed): void {
                $label = $token->microsoft_email ?? ('token #' . $token->id);

                if ($mailService->proactivelyRefresh($token)) {
                    $mailRefreshed++;
                    $this->info("Mail token refreshed: {$label}");

                    return;
                }

                $mailFailed++;
                $this->warn("Mail token refresh failed: {$label}");
            });

        $this->info(sprintf(
            'Done. Calendar: %d refreshed, %d failed. Mail: %d refreshed, %d failed.',
            $calendarRefreshed,
            $calendarFailed,
            $mailRefreshed,
            $mailFailed,
        ));

        return self::SUCCESS;
    }
}
