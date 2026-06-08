<?php

namespace App\Filament\Resources\MailSenderProfiles;

use App\Filament\Resources\MailSenderProfiles\Pages\ManageMailSenderProfiles;
use App\Models\MailSenderProfile;
use App\Models\MicrosoftMailToken;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MailSenderProfileResource extends Resource
{
    protected static ?string $model = MailSenderProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $breadcrumb = 'Contentbeheer';

    protected static ?string $modelLabel = 'Verzendprofiel';
    protected static ?string $pluralLabel = 'Verzendprofielen';
    protected static ?string $slug = 'mail-sender-profiles';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Naam')
                    ->required()
                    ->maxLength(255),
                Select::make('microsoft_mail_token_id')
                    ->label('Outlook-account')
                    ->placeholder('Geen (alleen log)')
                    ->options(
                        MicrosoftMailToken::orderBy('microsoft_email')
                            ->get()
                            ->mapWithKeys(fn (MicrosoftMailToken $t) => [
                                $t->id => $t->microsoft_email ?? ('Account ' . $t->id),
                            ])
                            ->all()
                    )
                    ->native(false)
                    ->searchable(false),
                Toggle::make('is_default')
                    ->label('Standaard (voor systeem-mails)')
                    ->helperText('Slechts één profiel kan standaard zijn. Andere profielen worden automatisch uitgeschakeld.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Naam')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('microsoftMailToken.microsoft_email')
                    ->label('Outlook-account')
                    ->placeholder('Geen (alleen log)')
                    ->sortable(),
                IconColumn::make('is_default')
                    ->label('Standaard')
                    ->boolean(),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMailSenderProfiles::route('/'),
        ];
    }
}
