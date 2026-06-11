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
    public static function purchasedAtDayCounts(int $days = 31): Collection
    {
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();

        $counts = self::base()
            ->whereNotNull('purchased_at')
            ->whereBetween('purchased_at', [$start, $end])
            ->selectRaw('DATE(purchased_at) as purchased_day, COUNT(*) as count')
            ->groupBy('purchased_day')
            ->pluck('count', 'purchased_day');

        return collect(range(0, $days - 1))
            ->map(function (int $offset) use ($start, $counts): array {
                $date = $start->copy()->addDays($offset);
                $dateKey = $date->toDateString();

                return [
                    'label' => $date->translatedFormat('d M'),
                    'value' => (int) ($counts[$dateKey] ?? 0),
                    'date' => $dateKey,
                ];
            });
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
