<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Widgets\SupplierPurchaseDocumentsWidget;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Grid;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use App\Filament\Resources\SupplierResource\Pages\ListSuppliers;
use App\Filament\Resources\SupplierResource\Pages\CreateSupplier;
use App\Filament\Resources\SupplierResource\Pages\EditSupplier;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Text;
use App\Models\Country;
use App\Models\Supplier;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use App\Models\ExactGLAccount;
use App\Models\ExactPaymentCondition;
use App\Models\ExactVATCode;
use App\Filament\Support\PurchaseAuthorization;
use App\Traits\Company\PostcodeValidatorTrait;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Utilities\Set;

class SupplierResource extends Resource
{
    use PostcodeValidatorTrait;
    protected static ?string $model = Supplier::class;

    protected static ?string $breadcrumb = 'Leveranciers';

    protected static ?string $modelLabel = 'Leverancier';
    protected static ?string $pluralModelLabel = 'Leveranciers';
    protected static ?string $slug = 'suppliers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function canViewAny(): bool
    {
        return PurchaseAuthorization::canManage();
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

    public function getDefaultTableRecordsPerPageSelectOption(): int
    {
        return 50;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->extraAttributes(['class' => 'companySection-wrapper'])
            ->components([

                View::make('filament.components.back-to-overview-with-heading')
                    ->viewData([
                        'title' => 'Leverancier-overzicht',
                        'url' => route('filament.app.resources.suppliers.index'),
                    ]),

                Tabs::make('Tabs')
                    ->tabs([
                        Tab::make('Leveranciergegevens')
                            ->schema([
                                Grid::make(12)
                                    ->schema([
                                        Grid::make(1)
                                            ->columnSpan(6)
                                            ->schema([

                                                Section::make('Algemeen')
                                                    ->extraAttributes(['class' => 'exactSection'])
                                                    ->schema([
                                                        TextInput::make('name')
                                                            ->required()
                                                            ->maxLength(255)
                                                            ->label('Naam leverancier')
                                                            ->inlineLabel()
                                                            ->columnSpan(3),

                                                        Hidden::make('is_active')
                                                            ->default(true),

                                                        TextInput::make('reference')
                                                            ->inlineLabel()
                                                            ->required()
                                                            ->maxLength(255)
                                                            ->label('Referentie')
                                                            ->columnSpan(3),

                                                        TextInput::make('email')
                                                            ->inlineLabel()
                                                            ->label('E-mailadres')
                                                            ->email()
                                                            ->required()
                                                            ->maxLength(255)
                                                            ->columnSpan(3),

                                                        TextInput::make('street')
                                                            ->inlineLabel()
                                                            ->label('Straat')
                                                            ->required()
                                                            ->extraAttributes(fn (Get $get, Field $component): array => self::postcodeValidationExtraAttributes($get, $component))
                                                            ->columnSpan(3),

                                                        TextInput::make('house_number')
                                                            ->inlineLabel()
                                                            ->label('Huisnummer')
                                                            ->required()
                                                            ->numeric()
                                                            ->live(onBlur: true)
                                                            ->debounce(1000)
                                                            ->partiallyRenderComponentsAfterStateUpdated(['street', 'city'])
                                                            ->afterStateUpdated(fn (Get $get, Set $set, Field $component) => self::validatePostcode($get, $set, field: $component))
                                                            ->extraAttributes(fn (Get $get, Field $component): array => self::postcodeValidationExtraAttributes($get, $component))
                                                            ->columnSpan(3),

                                                        TextInput::make('postcode')
                                                            ->inlineLabel()
                                                            ->label('Postcode')
                                                            ->required()
                                                            ->live(onBlur: true)
                                                            ->debounce(100)
                                                            ->partiallyRenderComponentsAfterStateUpdated(['street', 'city'])
                                                            ->afterStateUpdated(fn (Get $get, Set $set, Field $component) => self::validatePostcode($get, $set, field: $component))
                                                            ->extraAttributes(fn (Get $get, Field $component): array => self::postcodeValidationExtraAttributes($get, $component))
                                                            ->columnSpan(3),

                                                        TextInput::make('city')
                                                            ->inlineLabel()
                                                            ->label('Plaats')
                                                            ->required()
                                                            ->extraAttributes(fn (Get $get, Field $component): array => self::postcodeValidationExtraAttributes($get, $component))
                                                            ->columnSpan(3),

                                                        Select::make('country_id')
                                                            ->inlineLabel()
                                                            ->label('Land')
                                                            ->required()
                                                            ->relationship('country', 'name', fn($query) => $query)
                                                            ->default(Country::NL_ID)
                                                            ->live()
                                                            ->extraAttributes(fn (Get $get, Field $component): array => self::postcodeValidationExtraAttributes($get, $component))
                                                            ->columnSpan(3),

                                                        TextInput::make('kvk_number')
                                                            ->inlineLabel()
                                                            ->columnSpan(3)
                                                            ->label('KvK-nummer')
                                                            ->maxLength(255),

                                                        TextInput::make('vat_number')
                                                            ->inlineLabel()
                                                            ->columnSpan(3)
                                                            ->label('BTW-nummer')
                                                            ->maxLength(255)
                                                            ->regex('/^[A-Z]{2}[0-9A-Za-z+*.]{2,12}$/')
                                                            ->validationMessages([
                                                                'regex' => 'Voer een geldig BTW-nummer in (bijv. NL000000000B00).',
                                                            ]),
                                                    ]),
                                            ]),

                                        Grid::make(1)
                                            ->columnSpan(6)
                                            ->schema([
                                                Section::make('Contactpersoon')
                                                    ->extraAttributes(['class' => 'exactSection'])
                                                    ->schema([
                                                        TextInput::make('first_name')
                                                            ->inlineLabel()
                                                            ->label('Voornaam')
                                                            ->maxLength(255)
                                                            ->columnSpan(3),

                                                        TextInput::make('middle_name')
                                                            ->inlineLabel()
                                                            ->label('Tussenvoegsel')
                                                            ->maxLength(255)
                                                            ->columnSpan(3),

                                                        TextInput::make('last_name')
                                                            ->inlineLabel()
                                                            ->label('Achternaam')
                                                            ->maxLength(255)
                                                            ->columnSpan(3),

                                                        TextInput::make('contact_email')
                                                            ->inlineLabel()
                                                            ->label('E-mailadres')
                                                            ->email()
                                                            ->maxLength(255)
                                                            ->columnSpan(3),

                                                        TextInput::make('phone_number')
                                                            ->inlineLabel()
                                                            ->label('Telefoonnummer')
                                                            ->tel()
                                                            ->maxLength(255)
                                                            ->columnSpan(3),

                                                        TextInput::make('mobile_number')
                                                            ->inlineLabel()
                                                            ->label('Mobielnummer')
                                                            ->tel()
                                                            ->maxLength(255)
                                                            ->columnSpan(3),
                                                    ]),
                                            ]),
                                    ]),
                            ]),
                        Tab::make('documents')
                            ->label('Documenten')
                            ->visibleOn('edit')
                            ->schema([
                                Livewire::make(SupplierPurchaseDocumentsWidget::class)
                                    ->visibleOn('edit')
                                    ->columnSpanFull(),
                            ]),



                        Tab::make('Mail-koppeling')
                            ->schema([
                                Grid::make(12)
                                    ->schema([
                                        Grid::make(1)
                                            ->columnSpan(12)
                                            ->schema([
                                                Section::make('Mail sync: Inkooporder bevestigingen')
                                                    ->extraAttributes(['class' => 'exactSection'])
                                                    ->schema([
                                                        TextInput::make('admin_fields.po_confirmation_from_email')
                                                            ->label('E-mail afzender (leverancier)')
                                                            ->inlineLabel()
                                                            ->default('')
                                                            ->tooltip('Het e-mailadres van de leverancier van waaruit de inkooporderbevestigingen afkomstig zijn. Meerdere adressen kunnen worden gescheiden door een komma. Wildcards kunnen worden gebruikt met een asterisk (*).'),

                                                        TextInput::make('admin_fields.po_confirmation_subject_regex')
                                                            ->label('Onderwerp filter regex')
                                                            ->inlineLabel()
                                                            ->default('')
                                                            ->tooltip('Regular expression om op onderwerp te filteren.')
                                                            ->notRegex('/\\(\\?<[!=]|\\\\[1-9]/'),

                                                        Select::make('admin_fields.po_confirmation_delivery_time_type')
                                                            ->label('Leverdatum')
                                                            ->inlineLabel()
                                                            ->default('')
                                                            ->options([
                                                                'delivery_date' => 'Verwachte leverdatum regex',
                                                                'delivery_week' => 'Verwachte leverweek regex',
                                                                'delivery_time_days' => 'Levertijd (dagen)',
                                                            ])
                                                            ->live(),

                                                        TextInput::make('admin_fields.po_confirmation_expected_delivery_date_regex')
                                                            ->label('Verwachte leverdatum regex')
                                                            ->inlineLabel()
                                                            ->default('')
                                                            ->visible(fn(Get $get) => $get('admin_fields.po_confirmation_delivery_time_type') === 'delivery_date')
                                                            ->tooltip('Regular expression om deze waarde uit de PDF op te halen. Zorg ervoor dat de regex een groep bevat met de naam "date" die de factuurdatum bevat.'),

                                                        TextInput::make('admin_fields.po_confirmation_expected_delivery_week_regex')
                                                            ->label('Verwachte leverweek regex')
                                                            ->inlineLabel()
                                                            ->default('')
                                                            ->visible(fn(Get $get) => $get('admin_fields.po_confirmation_delivery_time_type') === 'delivery_week')
                                                            ->tooltip('Regular expression om deze waarde uit de PDF op te halen. Zorg ervoor dat de regex een groep bevat met de naam "week" die de verwachte leverweek bevat.'),

                                                        TextInput::make('admin_fields.po_confirmation_delivery_time_days')
                                                            ->label('Levertijd (dagen)')
                                                            ->inlineLabel()
                                                            ->default('')
                                                            ->numeric()
                                                            ->visible(fn(Get $get) => $get('admin_fields.po_confirmation_delivery_time_type') === 'delivery_time_days')
                                                            ->tooltip('Levertijd in dagen.'),
                                                    ]),

                                                Section::make('Mail sync: Inkoopfacturen')
                                                    ->extraAttributes(['class' => 'exactSection'])
                                                    ->schema([
                                                        TextInput::make('admin_fields.po_invoice_from_email')
                                                            ->label('E-mail afzender (leverancier)')
                                                            ->inlineLabel()
                                                            ->default('')
                                                            ->tooltip('Het e-mailadres van de leverancier waaruit de inkoopfacturen afkomstig zijn. Meerdere adressen kunnen worden gescheiden door een komma. Wildcards kunnen worden gebruikt met een asterisk (*).'),

                                                        TextInput::make('admin_fields.po_invoice_subject_regex')
                                                            ->label('Onderwerp filter regex')
                                                            ->inlineLabel()
                                                            ->default('')
                                                            ->tooltip('Regular expression om op onderwerp te filteren.')
                                                            ->notRegex('/\\(\\?<[!=]|\\\\[1-9]/'),

                                                        TextInput::make('admin_fields.po_invoice_invoice_number_regex')
                                                            ->label('Inkoopfactuurnummer regex')
                                                            ->inlineLabel()
                                                            ->default('')
                                                            ->tooltip('Regular expression om deze waarde uit de PDF op te halen.')
                                                            ->notRegex('/\\(\\?<[!=]|\\\\[1-9]/'),

                                                        TextInput::make('admin_fields.po_invoice_invoice_date_regex')
                                                            ->label('Factuurdatum regex')
                                                            ->inlineLabel()
                                                            ->default('')
                                                            ->tooltip('Regular expression om deze waarde uit de PDF op te halen.')
                                                            ->notRegex('/\\(\\?<[!=]|\\\\[1-9]/'),

                                                        TextInput::make('admin_fields.po_invoice_due_date_regex')
                                                            ->label('Vervaldatum/betalingstermijn regex')
                                                            ->inlineLabel()
                                                            ->default('')
                                                            ->tooltip('Regular expression om deze waarde uit de PDF op te halen.')
                                                            ->notRegex('/\\(\\?<[!=]|\\\\[1-9]/'),

                                                        TextInput::make('admin_fields.po_invoice_totals_regex')
                                                            ->label('Totaalbedragen regex')
                                                            ->inlineLabel()
                                                            ->default('')
                                                            ->tooltip('Regular expression om deze waardes uit de PDF op te halen. Zorg ervoor dat de regex drie groepen bevat: "total" -> Totaalbedrag, "vat" -> BTW-bedrag, "total_inc_vat" -> Totaalbedrag incl. BTW.')
                                                            ->notRegex('/\\(\\?<[!=]|\\\\[1-9]/'),
                                                    ]),
                                            ]),
                                    ]),
                            ]),


                        Tab::make('Exact-koppeling')
                            ->schema([

                                Grid::make(12)
                                    ->schema([
                                        Grid::make(1)
                                            ->columnSpan(6)
                                            ->schema([

                                                Section::make('Exact koppeling')
                                                    ->description(
                                                        fn(?Supplier $record) =>
                                                        !empty($record)
                                                            ? 'Laatst gesynchroniseerd: ' . ($record?->last_synced_at ? $record->last_synced_at->translatedFormat('d M Y H:i') : 'nooit')
                                                            : ''
                                                    )
                                                    ->extraAttributes(['class' => 'exactSection leverexact'])
                                                    //->visible(fn($state) => $state['sync_with_exact'] ?? true)
                                                    ->schema([

                                                        Text::make('Als een leverancier wordt aangemaakt of ge-update, volgt directe synchronisatie met Exact en wordt een crediteur aangemaakt.')
                                                            ->columnSpanFull()
                                                            ->extraAttributes(['style' => '']),

                                                        Select::make('exact_payment_condition_id')
                                                            ->inlineLabel()
                                                            ->label('Betalingsconditie')
                                                            ->columnSpan(3)
                                                            ->relationship(
                                                                'exactPaymentCondition',
                                                                'name',
                                                                modifyQueryUsing: fn ($query) => $query->orderBy('code'),
                                                            )
                                                            ->getOptionLabelFromRecordUsing(
                                                                fn (ExactPaymentCondition $record): string => "{$record->code} : {$record->name}"
                                                            ),

                                                        Select::make('exact_gl_account_id')
                                                            ->label('Grootboekrekening: Inkoop')
                                                            ->inlineLabel()
                                                            ->columnSpan(3)
                                                            ->relationship(
                                                                'exactGlAccount',
                                                                'name',
                                                                modifyQueryUsing: fn (Builder $query): Builder => $query
                                                                    ->forSupplierPurchaseSelect(),
                                                            )
                                                            ->getOptionLabelFromRecordUsing(
                                                                fn (ExactGLAccount $record): string => "{$record->code} : {$record->name}"
                                                            ),

                                                        Select::make('exact_vat_code_id')
                                                            ->label('BTW-code: Inkoop')
                                                            ->inlineLabel()
                                                            ->columnSpan(3)
                                                            ->relationship(
                                                                'exactVatCode',
                                                                'name',
                                                                modifyQueryUsing: fn ($query) => $query
                                                                    ->whereIn('vat_transaction_type', [
                                                                        ExactVATCode::VAT_TRANSACTION_TYPES['purchase'],
                                                                        ExactVATCode::VAT_TRANSACTION_TYPES['both'],
                                                                    ])
                                                                    ->where('is_blocked', false)
                                                                    ->orderBy('code'),
                                                            )
                                                            ->getOptionLabelFromRecordUsing(
                                                                fn (ExactVATCode $record): string => "{$record->code} : {$record->name}"
                                                            ),


                                                        TextInput::make('exact_code')
                                                            ->columnSpan(3)
                                                            ->label('Relatienummer')
                                                            ->inlineLabel()
                                                            ->disabled()
                                                            ->placeholder('(wordt automatisch ingevuld)'),

                                                        TextInput::make('exact_id')
                                                            ->columnSpan(3)
                                                            ->label('Exact Online ID')
                                                            ->inlineLabel()
                                                            ->disabled()
                                                            ->placeholder('(wordt automatisch ingevuld)'),

                                                    ])
                                            ]),
                                    ]),
                            ]),

                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->headerActions([
                Action::make('create')
                    ->label('Leverancier aanmaken')
                    ->icon('heroicon-s-plus-circle')
                    ->url(route('filament.app.resources.suppliers.create'))
                    ->extraAttributes(['class' => 'supplier-create-custom']),
            ])
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back',
            ]))
            ->columns([
                TextColumn::make('name')
                    ->label('Leverancier')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Supplier $record): ?string => PurchaseAuthorization::canManage()
                        ? static::getUrl('edit', ['record' => $record])
                        : null)
                    ->color(fn (): ?string => PurchaseAuthorization::canManage() ? 'primary' : null),
                TextColumn::make('contactpersoon')
                    ->label('Contactpersoon')
                    ->state(fn(Supplier $record): string => $record->getFullName())
                    ->searchable(['first_name', 'middle_name', 'last_name'])
                    ->sortable(['first_name', 'last_name']),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('country.name')
                    ->label('Land')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('last_synced_at')
                    ->label('Laatst gesynchroniseerd')
                    ->dateTime()
                    ->sortable()

            ])
            ->defaultSort('name', 'asc')
            ->deferFilters(false)
            ->filters(
                [
                    \App\Filament\Resources\Resource::getDateFilter(),
                ],
                layout: FiltersLayout::AboveContent
            )
            ->recordActions([
                //  Tables\Actions\EditAction::make(),
            ])
            ->toolbarActions([
                //Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
