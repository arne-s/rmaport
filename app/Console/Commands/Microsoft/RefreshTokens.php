<?php

namespace App\Console\Commands\Microsoft;

use App\Models\MicrosoftMailToken;
use App\Services\MicrosoftMailService;
use Illuminate\Console\Command;

class RefreshTokens extends Command
{
    protected $signature = 'microsoft:refresh-tokens';

    protected $description = 'Refresh Microsoft Outlook mail OAuth tokens';

    public function handle(MicrosoftMailService $mailService): int
    {
        $mailRefreshed = 0;
        $mailFailed = 0;

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
            'Done. Mail: %d refreshed, %d failed.',
            $mailRefreshed,
            $mailFailed,
        ));

        return self::SUCCESS;
    }
}
