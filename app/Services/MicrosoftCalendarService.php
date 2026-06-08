<?php

namespace App\Services;

use App\Models\MicrosoftToken;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MicrosoftCalendarService
{
    private const AUTH_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    private const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    private const GRAPH_URL = 'https://graph.microsoft.com/v1.0';

    private const SCOPES = [
        'Calendars.ReadWrite',
        'MailboxSettings.Read',
        'offline_access',
        'User.Read',
    ];

    private const CATEGORIES_CACHE_TTL_SECONDS = 300;

    private const WEEK_EVENTS_CACHE_TTL_SECONDS = 60;

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private Client $client;

    public function __construct()
    {
        $this->clientId = config('services.microsoft.client_id', '');
        $this->clientSecret = config('services.microsoft.client_secret', '');
        $this->redirectUri = config('services.microsoft.redirect', '');
        $this->client = new Client(['timeout' => 30]);
    }

    public function getAuthorizationUrl(?string $redirectUri = null, ?string $state = null): string
    {
        $resolvedRedirectUri = $redirectUri ?? $this->redirectUri;

        $query = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $resolvedRedirectUri,
            'scope' => implode(' ', self::SCOPES),
            'response_mode' => 'query',
            'prompt' => 'select_account',
        ];

        if ($state !== null && $state !== '') {
            $query['state'] = $state;
        }

        return self::AUTH_URL . '?' . http_build_query($query);
    }

    public function saveAccessToken(string $code, ?string $redirectUri = null): ?string
    {
        $resolvedRedirectUri = $redirectUri ?? $this->redirectUri;

        $result = $this->requestToken([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $resolvedRedirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (! $result['ok']) {
            return $this->formatTokenError('token exchange', $result);
        }

        $response = $result['data'];

        if (empty($response['access_token'])) {
            Log::error('Microsoft OAuth: no access_token in response', $response);

            return 'Microsoft gaf geen access token terug.';
        }

        $email = $this->resolveEmail($response['access_token']);
        $timezone = $this->resolveTimezone($response['access_token']);

        if ($email === null) {
            Log::error('Microsoft OAuth: could not resolve email after token exchange');

            return 'Microsoft-account kon niet worden bepaald.';
        }

        MicrosoftToken::updateOrCreate(
            ['microsoft_email' => $email],
            [
                'access_token' => $response['access_token'],
                'refresh_token' => $response['refresh_token'] ?? null,
                'expires_at' => now()->addSeconds((int) ($response['expires_in'] ?? 3600)),
                'timezone' => $timezone,
            ],
        );

        return null;
    }

    public function disconnect(int $tokenId): void
    {
        MicrosoftToken::where('id', $tokenId)->delete();
    }

    public function proactivelyRefresh(MicrosoftToken $token): bool
    {
        if (empty($token->refresh_token)) {
            Log::warning('Microsoft Calendar: proactive refresh skipped, no refresh_token', [
                'token_id' => $token->id,
                'microsoft_email' => $token->microsoft_email,
            ]);

            return false;
        }

        return $this->refreshToken($token) !== null;
    }

    public function getValidToken(int $tokenId): ?MicrosoftToken
    {
        $token = MicrosoftToken::find($tokenId);

        if (! $token) {
            return null;
        }

        if ($token->isExpired()) {
            $token = $this->refreshToken($token);
        }

        return $token;
    }

    /**
     * @return array<int, array{id: string, name: string, isDefault: bool}>
     */
    public function getCalendars(int $tokenId): array
    {
        $token = $this->getValidToken($tokenId);
        if (! $token) {
            return [];
        }

        try {
            $response = json_decode($this->client->get(self::GRAPH_URL . '/me/calendars', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token->access_token,
                ],
                'query' => ['$select' => 'id,name,isDefaultCalendar'],
            ])->getBody()->getContents(), true);

            return array_map(fn (array $cal) => [
                'id' => $cal['id'],
                'name' => $cal['name'],
                'isDefault' => $cal['isDefaultCalendar'] ?? false,
            ], $response['value'] ?? []);
        } catch (GuzzleException $e) {
            Log::error('Microsoft Calendar: getCalendars failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Fetch all calendar events for a given date range.
     * When a specific calendar is selected, only that calendar is queried.
     * Otherwise, all calendars are queried and results are merged.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getWeekEvents(int $tokenId, Carbon $start, Carbon $end): array
    {
        $cacheKey = $this->weekEventsCacheKey($tokenId, $start, $end);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $token = $this->getValidToken($tokenId);
        if (! $token) {
            return [];
        }

        // Always use the app timezone so datetimes come back in a known IANA zone,
        // avoiding Windows-timezone guessing on the token (e.g. Pacific Standard Time).
        $appTz = config('app.timezone');
        $preferTz = (is_string($appTz) && $appTz !== '') ? self::toIanaTimezone($appTz) : 'Europe/Amsterdam';

        $headers = [
            'Authorization' => 'Bearer ' . $token->access_token,
            'Prefer'        => 'outlook.timezone="' . $preferTz . '"',
        ];

        $query = [
            'startDateTime' => $start->utc()->toIso8601String(),
            'endDateTime'   => $end->utc()->toIso8601String(),
            '$select'       => 'id,subject,bodyPreview,start,end,categories,isAllDay',
            '$top'          => 200,
        ];

        $calendarViewSegment = $token->calendar_id
            ? '/me/calendars/' . urlencode($token->calendar_id) . '/calendarView'
            : '/me/calendar/calendarView';

        $events = $this->fetchCalendarViewEvents($calendarViewSegment, $headers, $query);

        Cache::put($cacheKey, $events, now()->addSeconds(self::WEEK_EVENTS_CACHE_TTL_SECONDS));
        $this->rememberWeekEventsCacheKey($tokenId, $cacheKey);

        return $events;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function fetchCalendarViewEvents(string $segment, array $headers, array $query): array
    {
        $values = [];
        $url = self::GRAPH_URL . $segment;
        $queryForRequest = $query;

        try {
            while ($url !== '') {
                $options = [
                    'headers' => $headers,
                ];
                if ($queryForRequest !== null) {
                    $options['query'] = $queryForRequest;
                }

                $response = json_decode($this->client->get($url, $options)->getBody()->getContents(), true);
                if (! is_array($response)) {
                    break;
                }

                $values = array_merge($values, $response['value'] ?? []);
                $next = $response['@odata.nextLink'] ?? null;
                $url = is_string($next) && $next !== '' ? $next : '';
                $queryForRequest = null;
            }
        } catch (GuzzleException $e) {
            Log::error('Microsoft Calendar: fetchCalendarViewEvents failed', [
                'segment' => $segment,
                'error'   => $e->getMessage(),
            ]);

            return [];
        }

        return $values;
    }

    /**
     * Fetch all Outlook master categories for the connected account.
     *
     * @return array<int, array{id: string, displayName: string, color: string}>
     */
    public function getCategories(int $tokenId): array
    {
        $cacheKey = $this->categoriesCacheKey($tokenId);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $token = $this->getValidToken($tokenId);
        if (! $token) {
            return [];
        }

        try {
            $response = json_decode($this->client->get(self::GRAPH_URL . '/me/outlook/masterCategories', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token->access_token,
                ],
            ])->getBody()->getContents(), true);

            $categories = array_values(array_filter(array_map(function (array $cat): ?array {
                $id = $cat['id'] ?? null;
                $displayName = trim((string) ($cat['displayName'] ?? ''));

                if (! is_string($id) || $id === '' || $displayName === '') {
                    return null;
                }

                return [
                    'id' => $id,
                    'displayName' => $displayName,
                    'color' => $cat['color'] ?? 'none',
                ];
            }, $response['value'] ?? [])));

            Cache::put($cacheKey, $categories, now()->addSeconds(self::CATEGORIES_CACHE_TTL_SECONDS));

            return $categories;
        } catch (GuzzleException $e) {
            Log::error('Microsoft Calendar: getCategories failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function masterCategoryExists(int $tokenId, string $categoryName): bool
    {
        return $this->resolveMasterCategoryDisplayName($tokenId, $categoryName) !== null;
    }

    public function resolveMasterCategoryDisplayName(int $tokenId, string $categoryName): ?string
    {
        $needle = mb_strtolower(trim($categoryName));

        if ($needle === '') {
            return null;
        }

        foreach ($this->getCategories($tokenId) as $category) {
            if (mb_strtolower(trim($category['displayName'])) === $needle) {
                return $category['displayName'];
            }
        }

        return null;
    }

    /**
     * @return array{success: bool, id: ?string, displayName: ?string, color: ?string, error: ?string}
     */
    public function createMasterCategory(int $tokenId, string $displayName, string $color): array
    {
        $token = $this->getValidToken($tokenId);

        if (! $token) {
            return ['success' => false, 'id' => null, 'displayName' => null, 'color' => null, 'error' => 'Geen geldige Microsoft-token.'];
        }

        try {
            $response = json_decode($this->client->post(self::GRAPH_URL . '/me/outlook/masterCategories', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token->access_token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'displayName' => $displayName,
                    'color' => $color,
                ],
            ])->getBody()->getContents(), true);

            $id = $response['id'] ?? null;

            if (! is_string($id) || $id === '') {
                return ['success' => false, 'id' => null, 'displayName' => null, 'color' => null, 'error' => 'Outlook gaf geen categorie-id terug.'];
            }

            $this->forgetCategoriesCache($tokenId);

            return [
                'success' => true,
                'id' => $id,
                'displayName' => (string) ($response['displayName'] ?? $displayName),
                'color' => (string) ($response['color'] ?? $color),
                'error' => null,
            ];
        } catch (GuzzleException $e) {
            Log::error('Microsoft Calendar: createMasterCategory failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'id' => null, 'displayName' => null, 'color' => null, 'error' => $this->graphErrorMessage($e)];
        }
    }

    /**
     * @return array{success: bool, error: ?string}
     */
    public function updateMasterCategoryColor(int $tokenId, string $outlookCategoryId, string $color): array
    {
        $token = $this->getValidToken($tokenId);

        if (! $token) {
            return ['success' => false, 'error' => 'Geen geldige Microsoft-token.'];
        }

        try {
            $this->client->patch(self::GRAPH_URL . '/me/outlook/masterCategories/' . urlencode($outlookCategoryId), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token->access_token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'color' => $color,
                ],
            ]);

            $this->forgetCategoriesCache($tokenId);

            return ['success' => true, 'error' => null];
        } catch (GuzzleException $e) {
            Log::error('Microsoft Calendar: updateMasterCategoryColor failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $this->graphErrorMessage($e)];
        }
    }

    /**
     * @return array{success: bool, error: ?string}
     */
    public function deleteMasterCategory(int $tokenId, string $outlookCategoryId): array
    {
        $token = $this->getValidToken($tokenId);

        if (! $token) {
            return ['success' => false, 'error' => 'Geen geldige Microsoft-token.'];
        }

        try {
            $this->client->delete(self::GRAPH_URL . '/me/outlook/masterCategories/' . urlencode($outlookCategoryId), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token->access_token,
                ],
            ]);

            $this->forgetCategoriesCache($tokenId);

            return ['success' => true, 'error' => null];
        } catch (GuzzleException $e) {
            Log::error('Microsoft Calendar: deleteMasterCategory failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $this->graphErrorMessage($e)];
        }
    }

    private function graphErrorMessage(GuzzleException $e): string
    {
        $message = $e->getMessage();

        if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
            $body = (string) $e->getResponse()->getBody();
            $decoded = json_decode($body, true);

            if (is_array($decoded) && filled($decoded['error']['message'] ?? null)) {
                return (string) $decoded['error']['message'];
            }
        }

        return $message !== '' ? $message : 'Onbekende fout bij communicatie met Outlook.';
    }

    public function saveCalendarSelection(int $tokenId, string $calendarId, string $calendarName): bool
    {
        return (bool) MicrosoftToken::where('id', $tokenId)->update([
            'calendar_id' => $calendarId,
            'calendar_name' => $calendarName,
        ]);
    }

    /**
     * Check if a token's account is available in a given time window.
     * Returns true if the account is free (no overlapping events).
     */
    public function isAvailable(int $tokenId, Carbon $start, Carbon $end): bool
    {
        return empty($this->getConflictingEvents($tokenId, $start, $end));
    }

    /**
     * @return array<int, array{subject: string, start: string, end: string}>
     */
    public function getConflictingEvents(int $tokenId, Carbon $start, Carbon $end): array
    {
        $token = $this->getValidToken($tokenId);
        if (! $token) {
            return [];
        }

        $tz = self::toIanaTimezone($token->timezone ?? 'Europe/Amsterdam');

        $headers = [
            'Authorization' => 'Bearer ' . $token->access_token,
            'Prefer'        => 'outlook.timezone="' . $tz . '"',
        ];

        $query = [
            'startDateTime' => $start->utc()->toIso8601String(),
            'endDateTime'   => $end->utc()->toIso8601String(),
            '$select'       => 'subject,start,end,showAs',
            '$filter'       => "showAs ne 'free'",
        ];

        if ($token->calendar_id) {
            return $this->fetchCalendarViewEvents(
                '/me/calendars/' . urlencode($token->calendar_id) . '/calendarView',
                $headers,
                $query
            );
        }

        $calendars = $this->getCalendars($tokenId);
        $all = [];

        foreach ($calendars as $calendar) {
            $events = $this->fetchCalendarViewEvents(
                '/me/calendars/' . urlencode($calendar['id']) . '/calendarView',
                $headers,
                $query
            );
            $all = array_merge($all, $events);
        }

        return $all;
    }

    /**
     * Create a calendar event in the token's selected calendar (or default).
     *
     * @param  array<int, string>  $attendeeEmails
     * @param  array<int, string>  $categories     Outlook category names (must exist as master categories)
     * @return array{id: ?string, error: ?string}  `error` is set when the event was not created
     */
    public function createEvent(
        int $tokenId,
        string $subject,
        Carbon $startAt,
        Carbon $endAt,
        string $body = '',
        array $attendeeEmails = [],
        array $categories = [],
        ?string $locationDisplay = null,
        ?string $relocationReason = null,
        ?string $extraBodyText = null,
    ): array {
        $token = $this->getValidToken($tokenId);
        if (! $token) {
            return ['id' => null, 'error' => 'Geen geldige Microsoft-token.'];
        }

        $calendarSegment = $token->calendar_id
            ? '/me/calendars/' . urlencode($token->calendar_id) . '/events'
            : '/me/events';

        $appTz = config('app.timezone');
        if (! is_string($appTz) || $appTz === '') {
            $appTz = 'Europe/Amsterdam';
        }
        try {
            new \DateTimeZone($appTz);
        } catch (\Exception) {
            $appTz = 'Europe/Amsterdam';
        }
        $graphTz = self::toIanaTimezone($appTz);

        $payload = [
            'subject' => $subject,
            'start'   => [
                'dateTime' => $startAt->copy()->timezone($graphTz)->format('Y-m-d\TH:i:s.0000000'),
                'timeZone' => $graphTz,
            ],
            'end' => [
                'dateTime' => $endAt->copy()->timezone($graphTz)->format('Y-m-d\TH:i:s.0000000'),
                'timeZone' => $graphTz,
            ],
        ];

        $contentParts = [];
        $bodyTrimmed = trim($body);
        if ($bodyTrimmed !== '') {
            $contentParts[] = $bodyTrimmed;
        }

        if ($locationDisplay !== null) {
            $locTrim = trim($locationDisplay);
            if ($locTrim !== '') {
                $contentParts[] = 'Locatie: ' . $locTrim;
            }
        }
        if ($extraBodyText !== null) {
            $extraTrim = trim($extraBodyText);
            if ($extraTrim !== '') {
                $contentParts[] = $extraTrim;
            }
        }
        if ($relocationReason !== null) {
            $reasonTrim = trim($relocationReason);
            if ($reasonTrim !== '') {
                $contentParts[] = 'Reden wijziging: ' . $reasonTrim;
            }
        }

        if ($contentParts !== []) {
            $payload['body'] = ['contentType' => 'text', 'content' => implode("\n\n", $contentParts)];
        }

        if ($locationDisplay !== null) {
            $locForField = trim($locationDisplay);
            if ($locForField !== '') {
                $payload['location'] = ['displayName' => mb_substr($locForField, 0, 255)];
            }
        }

        if ($attendeeEmails !== []) {
            $payload['attendees'] = array_map(fn (string $email) => [
                'emailAddress' => ['address' => $email],
                'type'         => 'required',
            ], $attendeeEmails);
        }

        if ($categories !== []) {
            $payload['categories'] = $categories;
        }

        $result = $this->postCalendarEventWithFallbacks($token, $tokenId, $calendarSegment, $payload, $categories);

        if ($result['id'] !== null) {
            return ['id' => $result['id'], 'error' => null];
        }

        $message = $result['body']['error']['message'] ?? ('HTTP ' . (string) $result['status']);
        if ($result['status'] === 0) {
            $message = 'Geen verbinding met Microsoft (timeout of netwerkfout).';
        }

        return ['id' => null, 'error' => $message];
    }

    /**
     * @param  array<int, string>  $categories
     * @return array{id: ?string, status: int, body: array<string, mixed>}
     */
    private function postCalendarEventWithFallbacks(
        MicrosoftToken $token,
        int $tokenId,
        string $calendarSegment,
        array $payload,
        array $categories,
    ): array {
        $strategies = [
            ['segment' => $calendarSegment, 'withCategories' => true],
        ];

        if ($token->calendar_id) {
            $strategies[] = ['segment' => '/me/events', 'withCategories' => true];
        }

        if ($categories !== []) {
            $strategies[] = ['segment' => $calendarSegment, 'withCategories' => false];

            if ($token->calendar_id) {
                $strategies[] = ['segment' => '/me/events', 'withCategories' => false];
            }
        }

        $lastResult = ['id' => null, 'status' => 0, 'body' => []];
        $clearedStaleCalendar = false;

        foreach ($strategies as $index => $strategy) {
            if ($index > 0) {
                $reason = $strategy['withCategories']
                    ? 'retry on default calendar'
                    : 'retry without categories';

                if (! $strategy['withCategories']) {
                    $reason = ($token->calendar_id && $strategy['segment'] === '/me/events')
                        ? 'retry without categories on default calendar'
                        : 'retry without categories';
                } elseif ($token->calendar_id && $strategy['segment'] === '/me/events') {
                    $reason = 'retry on default calendar';
                }

                Log::warning('Microsoft Calendar: createEvent ' . $reason, [
                    'token_id' => $tokenId,
                    'calendar_id' => $token->calendar_id,
                    'previous_status' => $lastResult['status'],
                    'previous_error' => $lastResult['body']['error']['message'] ?? null,
                ]);
            }

            $attemptPayload = $payload;

            if ($strategy['withCategories'] && $categories !== []) {
                $attemptPayload['categories'] = $categories;
            } else {
                unset($attemptPayload['categories']);
            }

            $lastResult = $this->postCalendarEvent($token, $strategy['segment'], $attemptPayload);

            if ($lastResult['id'] !== null) {
                if ($token->calendar_id && $strategy['segment'] === '/me/events' && ! $clearedStaleCalendar) {
                    MicrosoftToken::query()
                        ->where('id', $tokenId)
                        ->update(['calendar_id' => null, 'calendar_name' => null]);

                    Log::warning('Microsoft Calendar: cleared invalid calendar_id after fallback to default calendar', [
                        'token_id' => $tokenId,
                    ]);
                }

                return $lastResult;
            }

            if (! $this->shouldRetryCreateEvent($lastResult, $categories, $index, count($strategies) - 1)) {
                break;
            }
        }

        return $lastResult;
    }

    /**
     * @param  array<int, string>  $categories
     */
    private function shouldRetryCreateEvent(array $result, array $categories, int $attemptIndex, int $lastIndex): bool
    {
        if ($attemptIndex >= $lastIndex) {
            return false;
        }

        if ($this->isGraphNotFoundError($result)) {
            return true;
        }

        return $categories !== [] && ($result['status'] === 400 || $result['status'] === 404);
    }

    /**
     * @param  array{id: ?string, status: int, body: array<string, mixed>}  $result
     */
    private function isGraphNotFoundError(array $result): bool
    {
        if ($result['status'] === 404) {
            return true;
        }

        $code = strtolower((string) ($result['body']['error']['code'] ?? ''));
        $message = strtolower((string) ($result['body']['error']['message'] ?? ''));

        return $code === 'erroritemnotfound'
            || str_contains($message, 'not found in the store');
    }

    /**
     * @return array{id: ?string, status: int, body: array<string, mixed>}
     */
    private function postCalendarEvent(MicrosoftToken $token, string $calendarSegment, array $payload): array
    {
        $url = self::GRAPH_URL . $calendarSegment;

        try {
            $response = $this->client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token->access_token,
                    'Content-Type'  => 'application/json',
                ],
                'json'         => $payload,
                'http_errors'  => false,
            ]);
        } catch (GuzzleException $e) {
            Log::error('Microsoft Calendar: createEvent request failed', ['error' => $e->getMessage()]);

            return ['id' => null, 'status' => 0, 'body' => []];
        }

        $status = $response->getStatusCode();
        $body   = json_decode((string) $response->getBody(), true) ?? [];

        if ($status >= 200 && $status < 300) {
            $id = is_array($body) ? ($body['id'] ?? null) : null;
            if ($id === null) {
                Log::error('Microsoft Calendar: createEvent success but no id', ['status' => $status, 'body' => $body]);
            }

            $this->forgetWeekEventsCache((int) $token->id);

            return ['id' => is_string($id) ? $id : null, 'status' => $status, 'body' => is_array($body) ? $body : []];
        }

        Log::error('Microsoft Calendar: createEvent rejected', ['status' => $status, 'body' => $body]);

        return ['id' => null, 'status' => $status, 'body' => is_array($body) ? $body : []];
    }

    /**
     * Delete a calendar event by its Outlook event ID. Silent on failure.
     */
    public function deleteEvent(int $tokenId, string $eventId): void
    {
        $token = $this->getValidToken($tokenId);
        if (! $token) {
            return;
        }

        try {
            $this->client->delete(self::GRAPH_URL . '/me/events/' . urlencode($eventId), [
                'headers' => ['Authorization' => 'Bearer ' . $token->access_token],
            ]);
            $this->forgetWeekEventsCache($tokenId);
        } catch (GuzzleException $e) {
            Log::error('Microsoft Calendar: deleteEvent failed', ['error' => $e->getMessage(), 'eventId' => $eventId]);
        }
    }

    public function flushWeekEventsCache(int $tokenId): void
    {
        $this->forgetWeekEventsCache($tokenId);
    }

    private function categoriesCacheKey(int $tokenId): string
    {
        return 'ms_calendar_categories:' . $tokenId;
    }

    private function weekEventsCacheKey(int $tokenId, Carbon $start, Carbon $end): string
    {
        return 'ms_calendar_week_events:' . $tokenId . ':' . $start->utc()->toIso8601String() . ':' . $end->utc()->toIso8601String();
    }

    private function forgetCategoriesCache(int $tokenId): void
    {
        Cache::forget($this->categoriesCacheKey($tokenId));
    }

    private function forgetWeekEventsCache(int $tokenId): void
    {
        $prefix = 'ms_calendar_week_events:' . $tokenId . ':';

        foreach (Cache::get('ms_calendar_week_events_keys:' . $tokenId, []) as $key) {
            if (is_string($key) && str_starts_with($key, $prefix)) {
                Cache::forget($key);
            }
        }

        Cache::forget('ms_calendar_week_events_keys:' . $tokenId);
    }

    private function rememberWeekEventsCacheKey(int $tokenId, string $cacheKey): void
    {
        $indexKey = 'ms_calendar_week_events_keys:' . $tokenId;
        $keys = Cache::get($indexKey, []);

        if (! is_array($keys)) {
            $keys = [];
        }

        if (! in_array($cacheKey, $keys, true)) {
            $keys[] = $cacheKey;
        }

        Cache::put($indexKey, $keys, now()->addSeconds(self::WEEK_EVENTS_CACHE_TTL_SECONDS));
    }

    private function refreshToken(MicrosoftToken $token): ?MicrosoftToken
    {
        if (empty($token->refresh_token)) {
            return null;
        }

        $result = $this->requestToken([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $token->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (! $result['ok']) {
            $this->logTokenError('refresh', $result, [
                'token_id' => $token->id,
                'microsoft_email' => $token->microsoft_email,
            ]);

            return null;
        }

        $response = $result['data'];

        if (empty($response['access_token'])) {
            Log::error('Microsoft OAuth: token refresh failed', [
                'token_id' => $token->id,
                'response' => $response,
            ]);

            return null;
        }

        $token->update([
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? $token->refresh_token,
            'expires_at' => now()->addSeconds((int) ($response['expires_in'] ?? 3600)),
        ]);

        return $token->fresh();
    }

    /**
     * @param  array<string, string>  $formParams
     * @return array{ok: bool, status: int, data: array<string, mixed>}
     */
    private function requestToken(array $formParams): array
    {
        try {
            $httpResponse = $this->client->post(self::TOKEN_URL, [
                'form_params' => $formParams,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            Log::error('Microsoft OAuth: token request transport error', [
                'error' => $e->getMessage(),
                'grant_type' => $formParams['grant_type'] ?? null,
            ]);

            return ['ok' => false, 'status' => 0, 'data' => ['error' => 'transport_error', 'error_description' => $e->getMessage()]];
        }

        $status = $httpResponse->getStatusCode();
        $data = json_decode($httpResponse->getBody()->getContents(), true) ?? [];

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => is_array($data) ? $data : [],
        ];
    }

    /**
     * @param  array{ok: bool, status: int, data: array<string, mixed>}  $result
     */
    private function logTokenError(string $context, array $result, array $extra = []): void
    {
        Log::error('Microsoft OAuth: ' . $context . ' failed', [
            ...$extra,
            'status' => $result['status'],
            'error' => $result['data']['error'] ?? null,
            'error_description' => $result['data']['error_description'] ?? null,
            'response' => $result['data'],
            'redirect_uri' => $this->redirectUri,
            'client_id_prefix' => substr($this->clientId, 0, 8),
        ]);
    }

    /**
     * @param  array{ok: bool, status: int, data: array<string, mixed>}  $result
     */
    private function formatTokenError(string $context, array $result): string
    {
        $this->logTokenError($context, $result);

        $description = $result['data']['error_description'] ?? null;

        if (is_string($description) && $description !== '') {
            return $description;
        }

        $error = $result['data']['error'] ?? null;

        if (is_string($error) && $error !== '') {
            return 'Microsoft OAuth fout: ' . $error;
        }

        return 'Koppelen mislukt. Controleer MICROSOFT_REDIRECT_URI (moet exact https://rd.afsd.nl/microsoft/callback zijn).';
    }

    private function resolveTimezone(string $accessToken): string
    {
        try {
            $response = json_decode($this->client->get(self::GRAPH_URL . '/me/mailboxSettings', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => ['$select' => 'timeZone'],
            ])->getBody()->getContents(), true);

            return $response['timeZone'] ?? 'Europe/Amsterdam';
        } catch (\Exception) {
            return 'Europe/Amsterdam';
        }
    }

    private function resolveEmail(string $accessToken): ?string
    {
        try {
            $response = json_decode($this->client->get(self::GRAPH_URL . '/me', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => ['$select' => 'mail,userPrincipalName'],
            ])->getBody()->getContents(), true);

            return $response['mail'] ?? $response['userPrincipalName'] ?? null;
        } catch (GuzzleException $e) {
            return null;
        }
    }

    /**
     * Map a Windows timezone name (mailbox / Graph) to IANA for Prefer headers and parsing.
     * If the value is already a valid IANA name, it is returned unchanged.
     */
    public static function toIanaTimezone(string $tz): string
    {
        $map = [
            'Pacific Standard Time' => 'America/Los_Angeles',
            'Pacific Daylight Time' => 'America/Los_Angeles',
            'Mountain Standard Time' => 'America/Denver',
            'Mountain Daylight Time' => 'America/Denver',
            'Central Standard Time' => 'America/Chicago',
            'Central Daylight Time' => 'America/Chicago',
            'Eastern Standard Time' => 'America/New_York',
            'Eastern Daylight Time' => 'America/New_York',
            'UTC' => 'UTC',
            'GMT Standard Time' => 'Europe/London',
            'Greenwich Standard Time' => 'Africa/Monrovia',
            'W. Europe Standard Time' => 'Europe/Amsterdam',
            'Central Europe Standard Time' => 'Europe/Budapest',
            'Romance Standard Time' => 'Europe/Paris',
            'E. Europe Standard Time' => 'Asia/Nicosia',
            'FLE Standard Time' => 'Europe/Helsinki',
            'GTB Standard Time' => 'Europe/Athens',
            'Turkey Standard Time' => 'Europe/Istanbul',
            'Russia Time Zone 3' => 'Europe/Samara',
            'Russian Standard Time' => 'Europe/Moscow',
            'Arab Standard Time' => 'Asia/Riyadh',
            'India Standard Time' => 'Asia/Calcutta',
            'China Standard Time' => 'Asia/Shanghai',
            'Tokyo Standard Time' => 'Asia/Tokyo',
            'AUS Eastern Standard Time' => 'Australia/Sydney',
            'New Zealand Standard Time' => 'Pacific/Auckland',
        ];

        $ianaName = $map[$tz] ?? $tz;

        try {
            new \DateTimeZone($ianaName);
        } catch (\Exception) {
            $ianaName = 'UTC';
        }

        return $ianaName;
    }
}
