<?php

namespace App\Services;

use App\Models\OutlookExternalConnectInvite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class OutlookExternalConnectInviteService
{
    public function resolveInviteFromPlainToken(string $plainToken): ?OutlookExternalConnectInvite
    {
        return OutlookExternalConnectInvite::query()
            ->where('token_hash', $this->hashToken($plainToken))
            ->first();
    }

    public function beginExternalConnect(OutlookExternalConnectInvite $invite): RedirectResponse
    {
        $this->clearInviteFlowCompleted($invite->id);

        return redirect(app(MicrosoftExternalConnectService::class)->getAuthorizationUrl(
            $this->buildState($invite->id, 'connect'),
        ));
    }

    public function handleExternalConnectCallback(OutlookExternalConnectInvite $invite, string $code): RedirectResponse
    {
        return $this->withOAuthCodeLock('connect', $code, function () use ($invite, $code): RedirectResponse {
            if ($redirect = $this->redirectIfOAuthCodeAlreadyHandled('connect', $code, $invite)) {
                return $redirect;
            }

            if ($this->isInviteFlowCompleted($invite->id)) {
                return $this->redirectToResult(
                    true,
                    'Koppeling geslaagd. Het account is nu beschikbaar voor Outlook e-mailaccounts.',
                );
            }

            $error = app(MicrosoftExternalConnectService::class)->saveAccessTokens($code);

            if ($error !== null) {
                if ($this->isReusedAuthorizationCodeError($error)
                    && ($redirect = $this->redirectIfOAuthCodeAlreadyHandled('connect', $code, $invite)) !== null) {
                    return $redirect;
                }

                if ($this->isReusedAuthorizationCodeError($error) && $this->isInviteFlowCompleted($invite->id)) {
                    return $this->redirectToResult(
                        true,
                        'Koppeling geslaagd. Het account is nu beschikbaar voor Outlook e-mailaccounts.',
                    );
                }

                return $this->redirectToResult(false, $error);
            }

            $this->rememberOAuthCodeOutcome('connect', $code, 'connect_completed');
            $this->markInviteFlowCompleted($invite->id);

            return $this->redirectToResult(
                true,
                'Koppeling geslaagd. Het account is nu beschikbaar voor Outlook e-mailaccounts.',
            );
        });
    }

    /**
     * @deprecated Legacy two-step flow (calendar then mail). Kept for in-flight OAuth states.
     */
    public function handleExternalCalendarCallback(OutlookExternalConnectInvite $invite, string $code): RedirectResponse
    {
        return $this->redirectToResult(false, 'Outlook-agenda koppeling is uitgeschakeld.');
    }

    /**
     * @deprecated Legacy two-step flow (calendar then mail). Kept for in-flight OAuth states.
     */
    public function handleExternalMailCallback(OutlookExternalConnectInvite $invite, string $code): RedirectResponse
    {
        return $this->withOAuthCodeLock('mail', $code, function () use ($invite, $code): RedirectResponse {
            if ($redirect = $this->redirectIfOAuthCodeAlreadyHandled('mail', $code, $invite)) {
                return $redirect;
            }

            if ($this->isInviteFlowCompleted($invite->id)) {
                return $this->redirectToResult(
                    true,
                    'Koppeling geslaagd. Het account is nu beschikbaar voor Outlook e-mailaccounts.',
                );
            }

            $error = app(MicrosoftMailService::class)->saveAccessToken($code);

            if ($error !== null) {
                if ($this->isReusedAuthorizationCodeError($error)
                    && ($redirect = $this->redirectIfOAuthCodeAlreadyHandled('mail', $code, $invite)) !== null) {
                    return $redirect;
                }

                if ($this->isReusedAuthorizationCodeError($error) && $this->isInviteFlowCompleted($invite->id)) {
                    return $this->redirectToResult(
                        true,
                        'Koppeling geslaagd. Het account is nu beschikbaar voor Outlook e-mailaccounts.',
                    );
                }

                return $this->redirectToResult(false, $error);
            }

            $this->rememberOAuthCodeOutcome('mail', $code, 'mail_completed');
            $this->markInviteFlowCompleted($invite->id);

            return $this->redirectToResult(
                true,
                'Koppeling geslaagd. Het account is nu beschikbaar voor Outlook e-mailaccounts.',
            );
        });
    }

    public function redirectToResult(bool $success, string $message): RedirectResponse
    {
        if ($success) {
            return redirect()->route('microsoft.external.connect.result', ['completed' => 1]);
        }

        return redirect()
            ->route('microsoft.external.connect.result', ['failed' => 1])
            ->with('external_outlook_connect_message', $message);
    }

    public function getConnectUrl(): string
    {
        $invite = $this->getOrCreateInvite();
        $plainToken = $invite->context['plain_token'] ?? null;

        if (! is_string($plainToken) || $plainToken === '') {
            throw new \RuntimeException('Externe koppellink heeft geen token.');
        }

        return route('microsoft.external.connect', ['token' => $plainToken]);
    }

    public function getOrCreateInvite(): OutlookExternalConnectInvite
    {
        $invite = OutlookExternalConnectInvite::query()->oldest('id')->first();

        if ($invite !== null) {
            $plainToken = $invite->context['plain_token'] ?? null;
            if (is_string($plainToken) && $plainToken !== '') {
                return $invite;
            }

            $invite->delete();
        }

        $plainToken = Str::random(64);

        return OutlookExternalConnectInvite::create([
            'token_hash' => $this->hashToken($plainToken),
            'created_by_user_id' => auth()->id(),
            'context' => ['plain_token' => $plainToken],
        ]);
    }

    public function buildState(int $inviteId, string $step): string
    {
        $nonce = Str::random(32);
        $payload = $inviteId . '|' . $step . '|' . $nonce;
        $signature = hash_hmac('sha256', $payload, config('app.key'));

        return base64_encode($payload . '|' . $signature);
    }

    /**
     * @return array{inviteId: int, step: string}|null
     */
    public function parseAndValidateState(?string $state): ?array
    {
        if (! is_string($state) || $state === '') {
            return null;
        }

        $decoded = base64_decode($state, true);
        if (! is_string($decoded) || $decoded === '') {
            return null;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 4) {
            return null;
        }

        [$inviteIdRaw, $step, $nonce, $signature] = $parts;
        if ($inviteIdRaw === '' || $step === '' || $nonce === '' || $signature === '') {
            return null;
        }

        $payload = $inviteIdRaw . '|' . $step . '|' . $nonce;
        $expectedSignature = hash_hmac('sha256', $payload, config('app.key'));

        if (! hash_equals($expectedSignature, $signature)) {
            return null;
        }

        return [
            'inviteId' => (int) $inviteIdRaw,
            'step' => $step,
        ];
    }

    private function hashToken(string $plainToken): string
    {
        return hash_hmac('sha256', $plainToken, config('app.key'));
    }

    private function markInviteFlowCompleted(int $inviteId): void
    {
        Cache::put($this->inviteFlowCompletedCacheKey($inviteId), true, now()->addDay());
    }

    private function clearInviteFlowCompleted(int $inviteId): void
    {
        Cache::forget($this->inviteFlowCompletedCacheKey($inviteId));
    }

    private function isInviteFlowCompleted(int $inviteId): bool
    {
        return Cache::get($this->inviteFlowCompletedCacheKey($inviteId)) === true;
    }

    private function inviteFlowCompletedCacheKey(int $inviteId): string
    {
        return 'outlook_external_invite_flow_completed:' . $inviteId;
    }

    /**
     * @param  callable(): RedirectResponse  $callback
     */
    private function withOAuthCodeLock(string $step, string $code, callable $callback): RedirectResponse
    {
        $lock = Cache::lock($this->oauthCodeLockKey($step, $code), 30);

        return $lock->block(10, $callback);
    }

    private function redirectIfOAuthCodeAlreadyHandled(
        string $step,
        string $code,
        OutlookExternalConnectInvite $invite,
    ): ?RedirectResponse {
        if ($this->isInviteFlowCompleted($invite->id)) {
            return $this->redirectToResult(
                true,
                'Koppeling geslaagd. Het account is nu beschikbaar voor Outlook e-mailaccounts.',
            );
        }

        $outcome = Cache::get($this->oauthCodeResultCacheKey($step, $code));

        if ($outcome === 'connect_completed' && $step === 'connect') {
            return $this->redirectToResult(
                true,
                'Koppeling geslaagd. Het account is nu beschikbaar voor Outlook e-mailaccounts.',
            );
        }

        if ($outcome === 'calendar_completed' && $step === 'calendar') {
            return redirect(app(MicrosoftMailService::class)->getAuthorizationUrl(
                state: $this->buildState($invite->id, 'mail'),
            ));
        }

        if ($outcome === 'mail_completed' && $step === 'mail') {
            $this->markInviteFlowCompleted($invite->id);

            return $this->redirectToResult(
                true,
                'Koppeling geslaagd. Het account is nu beschikbaar voor Outlook e-mailaccounts.',
            );
        }

        return null;
    }

    private function rememberOAuthCodeOutcome(string $step, string $code, string $outcome): void
    {
        Cache::put(
            $this->oauthCodeResultCacheKey($step, $code),
            $outcome,
            now()->addDay(),
        );
    }

    private function oauthCodeLockKey(string $step, string $code): string
    {
        return 'outlook_external_oauth_lock:' . $step . ':' . hash('sha256', $code);
    }

    private function oauthCodeResultCacheKey(string $step, string $code): string
    {
        return 'outlook_external_oauth_result:' . $step . ':' . hash('sha256', $code);
    }

    private function isReusedAuthorizationCodeError(string $error): bool
    {
        $needles = [
            'AADSTS70000',
            'authorization_code',
            'invalid_grant',
            'code has expired',
            'code parameter is not valid',
        ];

        foreach ($needles as $needle) {
            if (stripos($error, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
