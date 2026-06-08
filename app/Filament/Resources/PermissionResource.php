<?php

namespace App\Filament\Resources;

use Althinect\FilamentSpatieRolesPermissions\Resources\PermissionResource as BasePermissionResource;
use App\Filament\Resources\PermissionResource\Pages\CreatePermission;
use App\Filament\Resources\PermissionResource\Pages\EditPermission;
use App\Filament\Resources\PermissionResource\Pages\ListPermissions;
use App\Filament\Resources\PermissionResource\Pages\ViewPermission;
use App\Models\Permission;
use App\Models\Role;
use Filament\Facades\Filament;
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
use Illuminate\Validation\Rules\Unique;

class PermissionResource extends BasePermissionResource
{
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->schema([
                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => 'Permissies',
                        'url' => static::getUrl('index'),
                    ]),

                Section::make()
                    ->extraAttributes(['class' => 'settingspage-profile-section'])
                    ->columns(1)
                    ->schema([
                        Grid::make(1)
                            ->extraAttributes(['class' => 'custom-form-design'])
                            ->schema([
                                TextInput::make('display_name')
                                    ->label('Omschrijving')
                                    ->inlineLabel()
                                    ->maxLength(255)
                                    ->columnSpanFull(),

                                TextInput::make('name')
                                    ->label('Interne naam')
                                    ->inlineLabel()
                                    ->required(fn (string $operation): bool => $operation === 'create'
                                        || (auth()->user()?->can('manage users') ?? false))
                                    ->visible(fn (string $operation): bool => $operation === 'create'
                                        || (auth()->user()?->can('manage users') ?? false))
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
                            ]),
                    ]),

                Section::make()
                    ->extraAttributes(['class' => 'settingspage-profile-section custom-form-design'])
                    ->columns(1)
                    ->schema([
                        CheckboxList::make('roles')
                            ->label(__('filament-spatie-roles-permissions::filament-spatie.field.roles'))
                            ->relationship(
                                name: 'roles',
                                modifyQueryUsing: function (Builder $query): Builder {
                                    $query->where('guard_name', 'web')
                                        ->orderBy('display_name')
                                        ->orderBy('name');

                                    if (config('permission.teams', false) && Filament::hasTenancy()) {
                                        return $query->where(config('permission.column_names.team_foreign_key'), Filament::getTenant()->id);
                                    }

                                    return $query;
                                },
                            )
                            ->getOptionLabelFromRecordUsing(fn (Model $record): string => $record instanceof Role
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
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable(),
                TextColumn::make('display_name')
                    ->label('Omschrijving')
                    ->searchable(['display_name', 'name']),
                TextColumn::make('name')
                    ->label('Interne naam')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => auth()->user()?->can('manage users') ?? false),
            ])
            ->columnManager(false)
            ->filters([])
            ->actions([
                \Filament\Actions\EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ])->color('primary'),
                \Filament\Actions\BulkAction::make('Attach to roles')
                    ->label(__('filament-spatie-roles-permissions::filament-spatie.action.attach_to_roles'))
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data): void {
                        \App\Models\Role::whereIn('id', $data['roles'])->each(function (\App\Models\Role $role) use ($records): void {
                            $records->each(fn (Permission $permission) => $role->givePermissionTo($permission));
                        });
                    })
                    ->form([
                        Select::make('roles')
                            ->multiple()
                            ->label(__('filament-spatie-roles-permissions::filament-spatie.field.role'))
                            ->options(\App\Models\Role::query()->pluck('display_name', 'id'))
                            ->required(),
                    ])
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateActions([]);
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        if (config('filament-spatie-roles-permissions.should_use_simple_modal_resource.permissions')) {
            return [
                'index' => ListPermissions::route('/'),
            ];
        }

        return [
            'index' => ListPermissions::route('/'),
            'create' => CreatePermission::route('/create'),
            'edit' => EditPermission::route('/{record}/edit'),
            'view' => ViewPermission::route('/{record}'),
        ];
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
}
