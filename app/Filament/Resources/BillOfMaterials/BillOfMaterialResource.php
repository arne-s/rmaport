<?php

namespace App\Filament\Resources\BillOfMaterials;

use App\Filament\Resources\BillOfMaterials\Pages\CreateBillOfMaterial;
use App\Filament\Resources\BillOfMaterials\Pages\EditBillOfMaterial;
use App\Filament\Resources\BillOfMaterials\Pages\ListBillOfMaterials;
use App\Models\BillOfMaterial;
use App\Filament\Forms\Components\ProductSelect;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use App\Filament\Forms\Components\OrderProductsRepeater;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BillOfMaterialResource extends Resource
{
    protected static ?string $model = BillOfMaterial::class;
    protected static ?string $breadcrumb = 'Stuklijsten';
    protected static ?string $modelLabel = 'stuklijst';
    protected static ?string $pluralModelLabel = 'stuklijsten';
    protected static ?string $slug = 'bill-of-materials';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage products') ?? false;
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

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->extraAttributes(['class' => 'companySection-wrapper'])
            ->components([
                View::make('filament.components.back-to-overview-with-heading')
                    ->viewData([
                        'title' => 'Dashboard',
                        'url' => route('filament.app.pages.dashboard'),
                        'class' => 'quote-overview-back',
                    ]),

                Section::make('')
                    ->columns(12)
                    ->extraAttributes(['class' => 'order-createSection'])
                    ->schema([
                        Grid::make(3)
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'custom-form-design'])
                            ->schema([
                                Group::make()
                                    ->schema([
                                        TextInput::make('name')
                                            ->statePath('name')
                                            ->label('Naam')
                                            ->inlineLabel()
                                            ->required(),
                                    ]),
                            ]),

                        OrderProductsRepeater::make('billOfMaterialProducts')
                            ->relationship()
                            ->label('Artikelen')
                            ->orderColumn('sort')
                            ->minItems(1)
                            ->extraAttributes(['class' => 'orderProductsRepeater bomArticlesRepeater'])
                            ->table([
                                TableColumn::make('Aantal')->hiddenHeaderLabel()->width('70px'),
                                TableColumn::make('Artikel'),
                            ])
                            ->schema([
                                Hidden::make('id'),

                                TextInput::make('qty')
                                    ->label('Aantal')
                                    ->extraFieldWrapperAttributes(['class' => 'input-qty'])
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(0.01)
                                    ->required(),

                                ProductSelect::make('product_id')
                                    ->required()
                                    ->extraAttributes(['class' => 'input-value'])
                                    ->extraFieldWrapperAttributes(['class' => 'product-select']),
                            ])
                            ->addActionLabel('Artikel toevoegen')
                            ->addAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']))
                            ->deleteAction(fn (Action $action) =>
                                $action
                                    ->label('Artikel verwijderen')
                                    ->icon('heroicon-o-trash')
                                    ->color('danger')
                                    ->size(Size::ExtraSmall)
                                    ->requiresConfirmation()
                                    ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white'])),
                            )
                            ->columnSpanFull(),
                    ]),
            ]);
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
                TextColumn::make('name')
                    ->label('Stuklijst naam')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('creator.name')
                    ->label('Aangemaakt door')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Aangemaakt op')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Laatst bijgewerkt')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('name', 'asc')
            ->filters([])
            ->deferFilters(false)
            ->headerActions([
                Action::make('bom')
                    ->label('Stuklijst aanmaken')
                    ->url(route('filament.app.resources.bill-of-materials.create'))
                    ->icon('heroicon-s-plus-circle')
            ])
            ->extraAttributes(['class' => 'searchAlignLeft']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBillOfMaterials::route('/'),
            'create' => CreateBillOfMaterial::route('/create'),
            'edit' => EditBillOfMaterial::route('/{record}/edit'),
        ];
    }
}
