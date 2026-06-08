<?php

namespace App\Support;

use App\Filament\Support\SalesAuthorization;
use App\Models\Order\Main;

final class MainRequestNumberLinkifier
{
    private const UID_PATTERN = '/#?(A-\d{4}-\d+)\b/u';

    /** @var array<string, Main|null>|null */
    private static ?array $resolvedMains = null;

    /** @var array<string, true> */
    private static array $pendingUids = [];

    public static function linkify(string $text): string
    {
        if ($text === '') {
            return '';
        }

        if (! preg_match(self::UID_PATTERN, $text)) {
            return e($text);
        }

        if (! SalesAuthorization::canManage()) {
            return e($text);
        }

        self::registerUidsFromText($text);
        self::flushPendingLookups();

        $escaped = e($text);

        return (string) preg_replace_callback(
            self::UID_PATTERN,
            static function (array $match): string {
                $uid = $match[1];
                $main = self::$resolvedMains[$uid] ?? null;

                if ($main === null) {
                    return e($match[0]);
                }

                $href = e(route('filament.app.resources.mains.view', ['record' => $main->getId()]));

                return '<a href="'.$href.'" class="'.NavigationLink::CSS_CLASS.'" target="_blank" rel="noopener noreferrer">'.e($uid).'</a>';
            },
            $escaped,
        );
    }

    private static function registerUidsFromText(string $text): void
    {
        if (! preg_match_all(self::UID_PATTERN, $text, $matches)) {
            return;
        }

        foreach ($matches[1] as $uid) {
            if (! is_string($uid) || $uid === '') {
                continue;
            }

            if (self::$resolvedMains !== null && array_key_exists($uid, self::$resolvedMains)) {
                continue;
            }

            self::$pendingUids[$uid] = true;
        }
    }

    private static function flushPendingLookups(): void
    {
        if (self::$pendingUids === []) {
            return;
        }

        if (self::$resolvedMains === null) {
            self::$resolvedMains = [];
        }

        $uids = array_keys(self::$pendingUids);
        self::$pendingUids = [];

        foreach (Main::query()->whereIn('uid', $uids)->get() as $main) {
            $uid = (string) $main->getUid();

            if ($uid !== '') {
                self::$resolvedMains[$uid] = $main;
            }
        }

        foreach ($uids as $uid) {
            self::$resolvedMains[$uid] ??= null;
        }
    }
}
