<?php

namespace App\Filament\Resources;

use Althinect\FilamentSpatieRolesPermissions\Resources\RoleResource as BaseRoleResource;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Models\Permission;
use App\Models\Role;
use Filament\Facades\Filament;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class RoleResource extends BaseRoleResource
{
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->schema([
                View::make('filament.components.back-to-overview-with-heading')
                    ->viewData([
                        'title' => 'Dashboard',
                        'url' => route('filament.app.pages.dashboard'),
                    ]),

                Section::make()
                    ->extraAttributes(['class' => 'settingspage-profile-section'])
                    ->columns(1)
                    ->schema([
                        Grid::make(1)
                            ->extraAttributes(['class' => 'custom-form-design'])
                            ->schema([
                                TextInput::make('display_name')
                                    ->label('Naam')
                                    ->inlineLabel()
                                    ->required(fn (string $operation): bool => $operation === 'create')
                                    ->maxLength(255)
                                    ->columnSpanFull(),

                                TextInput::make('name')
                                    ->label('Interne naam')
                                    ->inlineLabel()
                                    ->required(fn (string $operation): bool => $operation === 'edit'
                                        && (auth()->user()?->can('manage users') ?? false))
                                    ->visible(fn (string $operation): bool => $operation === 'edit'
                                        && (auth()->user()?->can('manage users') ?? false))
                                    ->disabledOn('edit')
                                    ->dehydrated()
                                    ->columnSpanFull()
                                    ->unique(ignoreRecord: true, modifyRuleUsing: function (Unique $rule): Unique {
                                        if (config('permission.teams', false) && Filament::hasTenancy()) {
                                            $rule->where(
                                                config('permission.column_names.team_foreign_key', 'team_id'),
                                                Filament::getTenant()->id,
                                            );
                                        }

                                        return $rule;
                                    }),

                                Select::make(config('permission.column_names.team_foreign_key', 'team_id'))
                                    ->label(__('filament-spatie-roles-permissions::filament-spatie.field.team'))
                                    ->inlineLabel()
                                    ->hidden(fn (): bool => ! config('permission.teams', false) || Filament::hasTenancy())
                                    ->options(
                                        fn () => config('filament-spatie-roles-permissions.team_model', \App\Models\Team::class)::pluck('name', 'id'),
                                    )
                                    ->dehydrated(fn ($state): bool => (int) $state > 0)
                                    ->columnSpanFull()
                                    ->placeholder(__('filament-spatie-roles-permissions::filament-spatie.select-team'))
                                    ->hint(__('filament-spatie-roles-permissions::filament-spatie.select-team-hint')),
                            ]),
                    ]),

                Section::make()
                    ->extraAttributes(['class' => 'settingspage-profile-section custom-form-design'])
                    ->visible(config('filament-spatie-roles-permissions.should_show_permissions_for_roles'))
                    ->columns(1)
                    ->schema([
                        CheckboxList::make('permissions')
                            ->label(__('filament-spatie-roles-permissions::filament-spatie.field.permissions'))
                            ->relationship(
                                name: 'permissions',
                                modifyQueryUsing: fn (Builder $query): Builder => $query
                                    ->where('guard_name', 'web')
                                    ->orderBy('display_name')
                                    ->orderBy('name'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Model $record): string => $record instanceof Permission
                                ? $record->getDisplayName()
                                : (string) $record->name)
                            ->searchable()
                            ->bulkToggleable()
                            ->columns(1),
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
            ->headerActions([
                Action::make('create')
                    ->label('Rol aanmaken')
                    ->icon('heroicon-s-plus-circle')
                    ->url(route('filament.app.resources.roles.create')),
            ])
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable(),
                TextColumn::make('display_name')
                    ->label('Naam')
                    ->searchable(['display_name', 'name']),
                TextColumn::make('permissions_count')
                    ->counts('permissions')
                    ->label(__('filament-spatie-roles-permissions::filament-spatie.field.permissions_count'))
                    ->toggleable(isToggledHiddenByDefault: config('filament-spatie-roles-permissions.toggleable_guard_names.roles.isToggledHiddenByDefault', true)),
            ])
            ->defaultSort('id', 'asc')
            ->columnManager(false)
            ->filters([])
            ->actions([
                \Filament\Actions\EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ])->color('primary'),
            ])
            ->emptyStateActions(
                config('filament-spatie-roles-permissions.should_remove_empty_state_actions.roles') ? [] : [
                    \Filament\Actions\CreateAction::make(),
                ],
            );
    }

    public static function getPages(): array
    {
        if (config('filament-spatie-roles-permissions.should_use_simple_modal_resource.roles')) {
            return [
                'index' => ListRoles::route('/'),
            ];
        }

        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function ensureWebGuardName(array $data): array
    {
        $data['guard_name'] = 'web';

        return $data;
    }

    public static function generateInternalNameFromDisplayName(?string $displayName): string
    {
        $base = Str::slug((string) $displayName);

        if ($base === '') {
            $base = 'role';
        }

        $name = $base;
        $counter = 2;

        while (Role::query()->where('name', $name)->where('guard_name', 'web')->exists()) {
            $name = $base . '-' . $counter;
            $counter++;
        }

        return $name;
    }
}
