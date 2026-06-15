<?php

namespace App\Filament\Resources;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Enums\RmaStatus;
use App\Filament\Resources\RmaResource\Pages;
use App\Filament\Support\SalesAuthorization;
use App\Models\Customer;
use App\Models\Rma;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RmaResource extends Resource
{
    protected static ?string $model = Rma::class;

    protected static ?string $slug = 'rmas';

    protected static ?string $modelLabel = 'RMA';

    protected static ?string $pluralModelLabel = 'RMA\'s';

    protected static ?string $breadcrumb = 'Retouren';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer', 'product', 'importRow'])
            ->where('is_draft', false);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Algemeen')->schema(self::generalFields())->columns(2),
                Section::make('Product')->schema(self::productFields())->columns(2),
                Section::make('Retour')->schema(self::returnFields())->columns(2),
                Section::make('Inhoud')->schema(self::contentFields()),
                Section::make('Flags & datums')->schema(self::flagsAndDatesFields())->columns(2),
            ]);
    }

    public static function editForm(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->extraAttributes(['class' => 'companySection-wrapper'])
            ->components([
                View::make('filament.components.back-to-overview-with-heading')
                    ->viewData([
                        'title' => 'Retouren-overzicht',
                        'url' => route('filament.app.resources.rmas.index'),
                    ]),

                Tabs::make('Tabs')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Algemeen')
                            ->key('algemeen')
                            ->schema([
                                Section::make('')
                                    ->extraAttributes(['class' => 'customerSection'])
                                    ->schema([
                                        Grid::make(12)
                                            ->schema([
                                                Grid::make(1)
                                                    ->columnSpan(4)
                                                    ->schema([
                                                        self::editSection(
                                                            'Algemeen',
                                                            self::generalFields(),
                                                            'beheer-bedrijfsgegevensSection header-bedrijfsgegevens',
                                                        ),
                                                    ]),
                                                Grid::make(1)
                                                    ->columnSpan(4)
                                                    ->schema([
                                                        self::editSection('Product', self::productFields(), 'beheer-factuurgegevensSection'),
                                                    ]),
                                                Grid::make(1)
                                                    ->columnSpan(4)
                                                    ->schema([
                                                        self::editSection('Retour', self::returnWithContentFields()),
                                                    ]),
                                            ]),
                                    ]),
                            ]),
                        Tab::make('Eigenschappen')
                            ->key('eigenschappen')
                            ->schema([
                                Section::make('')
                                    ->extraAttributes(['class' => 'customerSection'])
                                    ->schema([
                                        Grid::make(12)
                                            ->schema([
                                                Grid::make(1)
                                                    ->columnSpan(4)
                                                    ->schema([
                                                        self::editSection('Algemeen', self::flagsAndDatesFields(), 'beheer-bedrijfsgegevensSection header-bedrijfsgegevens'),
                                                    ]),
                                            ]),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    /**
     * @return array<int, Field>
     */
    private static function generalFields(): array
    {
        return [
            TextInput::make('uid')
                ->label('RMA Nummer')
                ->required()
                ->maxLength(20)
                ->unique(ignoreRecord: true),
            Select::make('customer_id')
                ->label('Klant')
                ->searchable()
                ->preload()
                ->options(fn (): array => self::customerOptions())
                ->getSearchResultsUsing(fn (string $search): array => self::searchCustomerOptions($search))
                ->getOptionLabelUsing(fn ($value): string => self::customerOptionLabel($value)),
            Select::make('import_row_id')
                ->label('Importregel')
                ->searchable()
                ->relationship('importRow', 'reference')
                ->preload(),
            Select::make('status')
                ->label('Status')
                ->options(RmaStatus::labels())
                ->default(RmaStatus::Open->value)
                ->required(),
            TextInput::make('quantity')
                ->label('Aantal')
                ->numeric()
                ->default(1)
                ->minValue(1),
            TextInput::make('packing_slip_number')->label('Pakbon')->maxLength(100),
            Select::make('payment_method')
                ->label('Betalingsmethode')
                ->options(PaymentMethod::labels()),
        ];
    }

    /**
     * @return array<int, Field>
     */
    private static function productFields(): array
    {
        return [
            Select::make('product_id')
                ->label('Product')
                ->searchable()
                ->relationship('product', 'name')
                ->preload(),
            Textarea::make('accessories')->label('Accessoires')->rows(2),
        ];
    }

    /**
     * @return array<int, Field>
     */
    private static function returnFields(): array
    {
        return [
            Textarea::make('return_reason')->label('Retourreden')->rows(4),
        ];
    }

    /**
     * @return array<int, Field>
     */
    private static function contentFields(): array
    {
        return [
            Textarea::make('complaint')->label('Klacht')->rows(4),
            Textarea::make('service')->label('Werkzaamheden')->rows(4),
            Textarea::make('notes')->label('Interne notities')->rows(4),
        ];
    }

    /**
     * @return array<int, Field>
     */
    private static function returnWithContentFields(): array
    {
        return [
            ...self::returnFields(),
            Textarea::make('complaint')->label('Klacht')->rows(10),
            Textarea::make('service')->label('Werkzaamheden')->rows(4),
            Textarea::make('notes')->label('Interne notities')->rows(4),
        ];
    }

    /**
     * @return array<int, Field>
     */
    private static function flagsAndDatesFields(): array
    {
        return [
            Toggle::make('reminder')->label('Herinnering'),
            Toggle::make('is_warranty')->label('Garantie'),
            Toggle::make('is_processed')->label('Behandeld'),
            Toggle::make('is_refurbish')->label('Refurbish'),
            Toggle::make('is_invoiced')->label('Gefactureerd'),
            DateTimePicker::make('received_at')->label('Ontvangen'),
            DateTimePicker::make('reminded_at')->label('Herinnering verstuurd'),
            DateTimePicker::make('processed_at')->label('Afgehandeld'),
        ];
    }

    /**
     * @param  array<int, Field>  $fields
     */
    private static function editSection(string $title, array $fields, string $class = 'beheer-bedrijfsgegevensSection'): Section
    {
        return Section::make($title)
            ->extraAttributes(['class' => $class])
            ->schema(self::editFieldLayout($fields));
    }

    /**
     * @param  array<int, Field>  $fields
     * @return array<int, Field>
     */
    private static function editFieldLayout(array $fields): array
    {
        return array_map(
            fn (Field $field): Field => $field->inlineLabel()->columnSpan(3),
            $fields,
        );
    }

    /**
     * @return array<int|string, string>
     */
    private static function customerOptions(): array
    {
        return Customer::query()
            ->where('status', '!=', CustomerStatus::Initial->value)
            ->whereIn('type', array_keys(CustomerType::visibleLabels()))
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->mapWithKeys(fn (Customer $customer): array => [$customer->id => $customer->getName()])
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    private static function searchCustomerOptions(string $search): array
    {
        return Customer::query()
            ->where('status', '!=', CustomerStatus::Initial->value)
            ->whereIn('type', array_keys(CustomerType::visibleLabels()))
            ->where(fn ($query) => $query
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Customer $customer): array => [$customer->id => $customer->getName()])
            ->all();
    }

    private static function customerOptionLabel(mixed $value): string
    {
        if (! filled($value)) {
            return '';
        }

        $customer = Customer::query()->find((int) $value);

        return $customer ? $customer->getName() : '';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back',
            ]))
            ->columns([
                TextColumn::make('uid')
                    ->label('RMA Nummer')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Rma $record): string => static::getUrl('view', ['record' => $record]))
                    ->color('primary'),
                TextColumn::make('importRow.assignment_nr')
                    ->label('Ordernummer')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->getLabel())
                    ->color(fn ($state) => $state?->getColor() ?? 'gray')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Datum')
                    ->date('d-m-Y')
                    ->sortable(),
                TextColumn::make('customer.debtor_number')
                    ->label('Debiteurnummer')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('importRow.reference')
                    ->label('Referentie')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('received_at')
                    ->label('Ontvangen')
                    ->date('d-m-Y')
                    ->sortable()
                    ->placeholder('—'),
                IconColumn::make('reminder')
                    ->label('Herinnering')
                    ->boolean(),
                IconColumn::make('is_processed')
                    ->label('Afgehandeld')
                    ->boolean(),
                TextColumn::make('payment_method')
                    ->label('Betaalmethode')
                    ->formatStateUsing(fn ($state) => $state?->getLabel())
                    ->placeholder('—'),
                TextColumn::make('packing_slip_number')
                    ->label('Pakbon')
                    ->searchable()
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                self::createStatusFilter(
                    'status',
                    'status',
                    'Status',
                    RmaStatus::labels(),
                ),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->searchable()
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRmas::route('/'),
            'create' => Pages\CreateRma::route('/create'),
            'view' => Pages\ViewRma::route('/{record}'),
            'edit' => Pages\EditRma::route('/{record}/edit'),
        ];
    }
}
