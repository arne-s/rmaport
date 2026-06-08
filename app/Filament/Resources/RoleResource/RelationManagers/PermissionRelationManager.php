<?php

namespace App\Filament\Resources\RoleResource\RelationManagers;

use App\Models\Permission;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

class PermissionRelationManager extends RelationManager
{
    protected static string $relationship = 'permissions';

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('filament-spatie-roles-permissions::filament-spatie.section.permissions') ?? (string) str(static::getRelationshipName())
            ->kebab()
            ->replace('-', ' ')
            ->headline();
    }

    protected static function getModelLabel(): string
    {
        return __('filament-spatie-roles-permissions::filament-spatie.section.permission');
    }

    protected static function getPluralModelLabel(): string
    {
        return __('filament-spatie-roles-permissions::filament-spatie.section.permissions');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('filament-spatie-roles-permissions::filament-spatie.section.permissions'))
            ->columns([
                TextColumn::make('display_name')
                    ->searchable(['display_name', 'name'])
                    ->label('Omschrijving'),
                TextColumn::make('name')
                    ->searchable()
                    ->label('Interne naam')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => auth()->user()?->can('manage users') ?? false),
            ])
            ->filters([])
            ->headerActions([
                AttachAction::make('Attach Permission')
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn ($query) => $query->where('guard_name', 'web')->orderBy('display_name')->orderBy('name'))
                    ->recordSelectSearchColumns(['display_name', 'name'])
                    ->recordTitle(fn (Permission $record): string => $record->getDisplayName())
                    ->after(fn () => app()->make(PermissionRegistrar::class)->forgetCachedPermissions()),
            ])
            ->actions([
                DetachAction::make()->after(fn () => app()->make(PermissionRegistrar::class)->forgetCachedPermissions()),
            ])
            ->bulkActions([
                DetachBulkAction::make()->after(fn () => app()->make(PermissionRegistrar::class)->forgetCachedPermissions()),
            ]);
    }
}
