<?php

namespace App\Services;

use App\Enums\RmaStatus;
use App\Filament\Resources\RmaResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class RmaOverviewQueries
{
    public static function base(): Builder
    {
        return RmaResource::getEloquentQuery();
    }

    public static function forStatus(RmaStatus $status): Builder
    {
        return self::base()->where('status', $status);
    }

    /**
     * @return Collection<int, array{label: string, value: int, date: string}>
     */
    public static function purchasedAtDayCounts(int $limit = 14): Collection
    {
        return self::base()
            ->whereNotNull('purchased_at')
            ->selectRaw('DATE(purchased_at) as purchased_day, COUNT(*) as count')
            ->groupBy('purchased_day')
            ->orderByDesc('purchased_day')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (object $row): array => [
                'label' => Carbon::parse($row->purchased_day)->translatedFormat('d M'),
                'value' => (int) $row->count,
                'date' => $row->purchased_day,
            ]);
    }

    public static function indexUrlForStatus(RmaStatus $status): string
    {
        return RmaResource::getUrl('index', [
            'tableFilters' => [
                'status' => [
                    'status' => [$status->value],
                ],
            ],
        ]);
    }
}
