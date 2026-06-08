<?php

namespace App\Filament\Resources;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderSubtype;
use App\Filament\Resources\UnitInvoicingResource\Pages\ListUnitInvoicing;
use App\Models\Order\Main;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UnitInvoicingResource extends Resource
{
    protected static ?string $model = Main::class;

    protected static ?string $breadcrumb = 'Reporting';

    protected static ?string $modelLabel = 'Unit factuuroverzicht';

    protected static ?string $pluralLabel = 'Unit factuuroverzicht';

    protected static ?string $navigationLabel = 'Unit factuuroverzicht';

    protected static ?string $slug = 'unit-invoicing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static bool $shouldRegisterNavigation = false;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage reporting') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('subtype', [
                OrderSubtype::Unit,
                OrderSubtype::Part,
                OrderSubtype::Service,
            ])
            ->where('is_test', 0)
            ->whereNotIn('order_status', [OrderGeneralStatus::Initial->value])
            ->with(['depositInvoice', 'invoice', 'billingCustomer', 'customer']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUnitInvoicing::route('/'),
        ];
    }
}
