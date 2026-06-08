<?php

namespace App\Filament\Resources\Concerns;

use App\Enums\OrderGeneralStatus;
use App\Filament\Forms\Components\ToggleFilter;
use Filament\Forms\Components\CheckboxList;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

trait GeneralStatusFilter
{
    /**
     * Base query for distinct {@see OrderGeneralStatus} values (does not mutate the table query instance).
     */
    protected function generalStatusFilterQuery(): Builder
    {
        return static::getResource()::getEloquentQuery();
    }

    /**
     * Distinct `status` values on the given query, mapped to labels.
     *
     * @return array<string, string>
     */
    protected static function orderGeneralStatusOptionsForQuery(Builder $query): array
    {
        $rawStatuses = (clone $query)
            ->reorder()
            ->distinct()
            ->pluck('status');

        $values = Collection::make($rawStatuses)
            ->map(fn (mixed $raw): string => $raw instanceof OrderGeneralStatus
                ? $raw->value
                : (string) $raw)
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $options = [];
        foreach ($values as $value) {
            $options[$value] = OrderGeneralStatus::tryFrom($value)?->getLabel() ?? $value;
        }

        return $options;
    }

    /**
     * @param  array<string, string>  $options  Status value => label
     */
    public static function makeGeneralStatusTableFilter(array $options): Filter
    {
        $checkboxlist = CheckboxList::make('status')
            ->label('')
            ->options($options);

        $toggle = ToggleFilter::make()
            ->label('Status')
            ->schema([$checkboxlist]);

        return Filter::make('order_general_status')
            ->label('Status')
            ->schema([$toggle])
            ->indicateUsing(function (array $data) use ($options): ?string {
                if (empty($data['status'])) {
                    return null;
                }

                return 'Status: '.implode(', ', array_map(
                    fn (string $s): string => $options[$s] ?? $s,
                    $data['status']
                ));
            })
            ->query(fn (Builder $query, array $data): Builder => $query->when(
                $data['status'] ?? null,
                fn (Builder $q, $ids) => $q->whereIn('status', $ids)
            ));
    }

    /**
     * @return list<Filter>
     */
    public static function tableFiltersForQuery(Builder $query): array
    {
        $options = static::orderGeneralStatusOptionsForQuery($query);

        if ($options === []) {
            return [];
        }

        return [static::makeGeneralStatusTableFilter($options)];
    }

    /**
     * @return list<Filter>
     */
    protected function getGeneralStatusTableFilters(): array
    {
        return static::tableFiltersForQuery($this->generalStatusFilterQuery());
    }
}
