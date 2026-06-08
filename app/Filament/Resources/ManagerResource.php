<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\ToggleFilter;
use App\Filament\Resources\ManagerResource\Pages\CreateManager;
use App\Filament\Resources\ManagerResource\Pages\EditManager;
use App\Filament\Resources\ManagerResource\Pages\ListManagers;
use App\Models\Role;
use App\Models\User;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ManagerResource extends Resource
{
    protected static ?string $model = User::class;
    public static bool $shouldRegisterNavigation = false;
    protected static ?string $breadcrumb = 'Admin';
    protected static ?string $modelLabel = 'Gebruiker';
    protected static ?string $pluralModelLabel = 'Gebruikers';
    protected static ?string $slug = 'manager';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage users') ?? false;
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('roles');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->extraAttributes(['class' => 'companySection-wrapper manage-user-form'])
            ->components([

                View::make('filament.components.back-to-overview-with-heading')
                    ->viewData([
                        'title' => 'Gebruikers-overzicht',
                        'url' => route('filament.app.resources.manager.index'),
                        'class' => 'extraMargin',
                    ]),

                Section::make()
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Grid::make(1)
                                    ->extraAttributes(['class' => 'manage-user-grid'])
                                    ->schema([
                                        TextInput::make('first_name')
                                            ->required()
                                            ->columnSpan(1)
                                            ->inlineLabel()
                                            ->label('Voornaam')
                                            ->maxLength(255),
                                        TextInput::make('last_name')
                                            ->required()
                                            ->columnSpan(1)
                                            ->inlineLabel()
                                            ->label('Achternaam')
                                            ->maxLength(255),
                                        TextInput::make('email')
                                            ->email()
                                            ->columnSpan(1)
                                            ->label('E-mail')
                                            ->required()
                                            ->inlineLabel()
                                            ->unique(User::class, 'email', ignoreRecord: true)
                                            ->maxLength(255),
                                        CheckboxList::make('role_ids')
                                            ->label('Rollen')
                                            ->extraFieldWrapperAttributes(['class' => 'checkbox-compact'])
                                            ->inlineLabel()
                                            ->options(fn (): array => Role::query()
                                                ->where('guard_name', 'web')
                                                ->orderBy('display_name')
                                                ->orderBy('name')
                                                ->get()
                                                ->mapWithKeys(fn (Role $role): array => [
                                                    $role->getKey() => $role->getDisplayName(),
                                                ])
                                                ->all())
                                            ->columns(1),
                                        Toggle::make('requires_app_2fa')
                                            ->label('Authenticator-app (TOTP) verplicht')
                                            ->helperText('Na inloggen moet deze gebruiker een eenmalige code uit een authenticator-app invoeren. Uitschakelen wist de gekoppelde geheimen.')
                                            ->default(true)
                                            ->inlineLabel()
                                            ->columnSpanFull()
                                            ->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false)
                                            ->dehydrated(fn (): bool => auth()->user()?->isSuperAdmin() ?? false),
                                    ])
                            ])
                    ])
            ]);
    }

    /**
     * @throws Exception
     */
    public static function table(Table $table): Table
    {
        return $table
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back',
            ]))
            ->headerActions([
                Action::make('manager')
                    ->label('Gebruiker aanmaken')
                    ->url(route('filament.app.resources.manager.create'))
                    ->icon('heroicon-s-plus-circle'),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label('Naam')
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('roles_list')
                    ->label('Rollen')
                    ->state(fn (User $record): string => $record->roles
                        ->sortBy(fn (Role $role): string => $role->getDisplayName())
                        ->map(fn (Role $role): string => $role->getDisplayName())
                        ->values()
                        ->join(', '))
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

                        return $query->orderByRaw(
                            '(SELECT MIN(COALESCE(r.display_name, r.name))
                              FROM model_has_roles mhr
                              INNER JOIN roles r ON r.id = mhr.role_id
                              WHERE mhr.model_id = users.id
                              AND mhr.model_type = ?) ' . $direction,
                            [User::class],
                        );
                    }),
                TextColumn::make('created_at')
                    ->label('Gemaakt op')
                    ->dateTime(),
                TextColumn::make('updated_at')
                    ->label('Laatst bijgewerkt')
                    ->dateTime(),
            ])
            ->defaultSort('email', 'asc')
            ->deferFilters(false)
            ->filters([
                static::getRoleFilter(),
            ], layout: FiltersLayout::AboveContent)
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Verwijderen')
                        ->before(function (Collection $records): void {
                            if ($records->contains('id', auth()->id())) {
                                Notification::make()
                                    ->danger()
                                    ->title('Je kunt je eigen account niet verwijderen.')
                                    ->send();

                                halt();
                            }
                        }),
                ]),
            ]);
    }

    protected static function getRoleFilter(): Filter
    {
        return Filter::make('role_id')
            ->label('Rol')
            ->indicateUsing(function (array $data): ?string {
                if (empty($data['role_id'])) {
                    return null;
                }

                $roles = Role::query()
                    ->where('guard_name', 'web')
                    ->whereIn('id', $data['role_id'])
                    ->get();

                $list = $roles->map(fn (Role $role): string => $role->getDisplayName())->values();

                if ($list->count() > 1) {
                    $str = $list->first() . ' (+' . ($list->count() - 1) . ')';
                } else {
                    $str = $list->join(', ');
                }

                return 'Rol: ' . $str;
            })
            ->schema([
                ToggleFilter::make()
                    ->label('Rol')
                    ->schema([
                        CheckboxList::make('role_id')
                            ->label('')
                            ->options(fn (): array => Role::query()
                                ->where('guard_name', 'web')
                                ->orderBy('display_name')
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (Role $role): array => [
                                    $role->getKey() => $role->getDisplayName(),
                                ])
                                ->all()),
                    ]),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when(
                    $data['role_id'] ?? null,
                    fn (Builder $query, array $roleIds): Builder => $query->whereHas(
                        'roles',
                        fn (Builder $roleQuery): Builder => $roleQuery->whereIn('roles.id', $roleIds),
                    ),
                ));
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * @return EloquentCollection<int, Role>
     */
    public static function resolveWebRolesFromSelectState(mixed $roleIds): EloquentCollection
    {
        $ids = collect(is_array($roleIds) ? $roleIds : [$roleIds])
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return new EloquentCollection;
        }

        return Role::query()
            ->where('guard_name', 'web')
            ->whereIn('id', $ids)
            ->get();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListManagers::route('/'),
            'create' => CreateManager::route('/create'),
            'edit' => EditManager::route('/{record}/edit'),
        ];
    }
}
