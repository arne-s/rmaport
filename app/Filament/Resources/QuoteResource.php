<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuoteResource\Pages\CreateQuote;
use App\Filament\Resources\QuoteResource\Pages\CreateQuoteFromMain;
use App\Filament\Resources\QuoteResource\Pages\EditQuote;
use App\Filament\Resources\QuoteResource\Pages\ListQuotes;
use App\Filament\Resources\QuoteResource\Pages;
use App\Filament\Support\SalesAuthorization;
use App\Models\Order\Quote;
use App\Traits\Company\PostcodeValidatorTrait;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class QuoteResource extends Resource
{
    use PostcodeValidatorTrait;

    protected static ?string $model = Quote::class;
    protected static ?string $breadcrumb = 'Offertes';
    protected static ?string $modelLabel = 'Overzicht';
    protected static ?string $pluralModelLabel = 'offertes';
    protected static ?string $slug = 'quotes';
    protected static ?string $recordTitleAttribute = 'uid';


    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function canViewAny(): bool
    {
        return SalesAuthorization::canManage();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->whereNotNull('main_id')
            ->whereNotNull('uid')
            ->with(['main.customer', 'main', 'customer']);
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'uid',
            'rev',
            'reference',
            'main.uid',
            'customer.first_name',
            'customer.last_name',
            'customer.email',
            'billingCustomer.name',
        ];
    }

    public static function getGlobalSearchResultTitle(Model $record): string | Htmlable
    {
        /** @var Quote $record */
        $uid = $record->getUid();

        return $uid !== null && $uid !== '' ? "Offerte {$uid}" : 'Offerte #' . $record->getKey();
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Quote $record */
        $details = [];

        if ($record->main?->getUid()) {
            $details['Aanvraag'] = $record->main->getUid();
        }

        if ($record->customer) {
            $details['Klant'] = $record->customer->getName() ?? '';
        }

        return array_filter($details, fn (string $v): bool => $v !== '');
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        /** @var Quote $record */
        $mainId = $record->main_id;

        if ($mainId === null) {
            return parent::getGlobalSearchResultUrl($record);
        }

        if (! static::canEdit($record) && ! static::canView($record)) {
            return null;
        }

        return route('filament.app.resources.mains.view', ['record' => $mainId]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotes::route('/'),
            'create' => CreateQuote::route('/create'),
            'from-main' => CreateQuoteFromMain::route('/from-main/{main}'),
            'edit' => EditQuote::route('/edit/{record}'),
        ];
    }
}
