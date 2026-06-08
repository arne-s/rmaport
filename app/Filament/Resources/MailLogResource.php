<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Support\Carbon;
use App\Filament\Resources\EmailTemplateResource;
use App\Filament\Resources\MailLogResource\Pages\ListMailLogs;
use App\Filament\Resources\Mains\MainResource;
use App\Enums\MailLogStatus;
use App\Helpers\FileHelper;
use App\Models\MailLog;
use App\Support\MailAddressFormatter;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MailLogResource extends Resource
{
    protected static ?string $breadcrumb = 'Contentbeheer';

    protected static ?string $modelLabel = 'E-mail log';
    protected static ?string $pluralLabel = 'E-mail logs';
    protected static ?string $slug = 'mail-logs';
    protected static ?string $model = MailLog::class;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage settings') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    // Used by view modal
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('subject')
                    ->label('Onderwerp')
                    ->state(fn(?MailLog $record) => $record?->subject),

                TextEntry::make('created_at')
                    ->label('Verzonden op')
                    ->state(fn (?MailLog $record) => $record?->created_at instanceof Carbon
                        ? $record->created_at->translatedFormat('j M Y (H:i)')
                        : null),

                TextEntry::make('from')
                    ->label('Van')
                    ->state(fn (?MailLog $record) => MailAddressFormatter::decodeAddressHeader($record?->from)),

                TextEntry::make('to')
                    ->label('Aan')
                    ->state(fn (?MailLog $record) => MailAddressFormatter::decodeAddressHeader($record?->to)),

                TextEntry::make('cc')
                    ->label('CC')
                    ->state(fn (?MailLog $record) => MailAddressFormatter::decodeAddressHeader($record?->cc)),

                TextEntry::make('bcc')
                    ->label('BCC')
                    ->state(fn (?MailLog $record) => MailAddressFormatter::decodeAddressHeader($record?->bcc)),

                TextEntry::make('status')
                    ->label('Status')
                    ->state(fn (?MailLog $record) => $record?->status?->getLabel())
                    ->extraAttributes(fn (?MailLog $record) => [
                        'class' => 'min-h-6 inline-flex items-center justify-center space-x-1 whitespace-nowrap rounded-xl px-2 py-0.5 text-sm font-medium tracking-tight rtl:space-x-reverse ' . match ($record?->status) {
                            MailLogStatus::Sending => 'text-warning-700 bg-warning-500/10',
                            MailLogStatus::Sent    => 'text-success-700 bg-success-500/10',
                            MailLogStatus::Failed  => 'text-danger-700 bg-danger-500/10',
                            default                => '',
                        },
                    ]),

                Textarea::make('body')
                    ->label('Body')
                    ->view('filament.partials.view-email')
                    ->columnSpanFull(),

                Repeater::make('attachmentsJson')
                    ->label('Bijlagen')
                    ->formatStateUsing(fn(?MailLog $record): array => $record?->getAttachmentsJsonAttribute() ?? [])
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('filename')
                                    ->label('Bestandsnaam'),
                                TextEntry::make('size')
                                    ->label('Grootte')
                                    ->formatStateUsing(fn(string $state): string =>  FileHelper::formatFileSize($state)),
                                TextEntry::make('contentType')
                                    ->label('Content type'),
                            ])
                    ])
                    ->columnSpanFull(),

                Textarea::make('headers')
                    ->label('Headers')
                    ->rows(6)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(50)
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Email-Overzicht',
                'url' => route('filament.app.resources.email-templates.index'),
                'class' => 'quote-overview-back',
            ]))
            ->columns([
                TextColumn::make('status')
                    ->badge(true)
                    ->width('130px')
                    ->extraAttributes(fn (?MailLog $record) => [
                        'class' => match ($record?->status) {
                            MailLogStatus::Sending => 'mailLogPending',
                            MailLogStatus::Sent    => 'mailLogSuccess',
                            MailLogStatus::Failed  => 'mailLogFailed',
                            default                => '',
                        },
                    ])
                    ->extraCellAttributes(['class' => 'mail-log-status-column'])
                    ->formatStateUsing(fn ($state, ?MailLog $record): string => $record?->status?->getLabel() ?? '')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Verzonden op')
                    ->width('170px')
                    ->formatStateUsing(fn ($state): ?string => $state instanceof Carbon
                        ? $state->translatedFormat('j M Y (H:i)')
                        : null)
                    ->extraCellAttributes(['class' => 'mail-log-date-column'])
                    ->sortable(),

                TextColumn::make('from')
                    ->label('Van')
                    ->limit(40)
                    ->width('250px')
                    ->formatStateUsing(fn (?string $state): ?string => MailAddressFormatter::decodeAddressHeader($state))
                    ->extraCellAttributes(['class' => 'mail-log-field-column'])
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();
                        if (! is_string($state) || strlen($state) <= 40) {
                            return null;
                        }

                        return $state;
                    })
                    ->sortable(),

                TextColumn::make('to')
                    ->label('Aan')
                    ->limit(40)
                    ->width('250px')
                    ->formatStateUsing(fn (?string $state): ?string => MailAddressFormatter::decodeAddressHeader($state))
                    ->extraCellAttributes(['class' => 'mail-log-field-column'])
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();
                        if (! is_string($state) || strlen($state) <= 40) {
                            return null;
                        }

                        return $state;
                    })
                    ->searchable()
                    ->sortable(),

                TextColumn::make('subject')
                    ->label('Onderwerp')
                    ->limit(50)
                    ->width('250px')
                    ->extraCellAttributes(['class' => 'mail-log-field-column'])
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();
                        if (strlen($state) <= 50) {
                            return null;
                        }
                        return $state;
                    })
                    ->searchable()
                    ->sortable(),

                ViewColumn::make('view')
                    ->label('')
                    ->width('40px')
                    ->view('filament.tables.columns.mail-log-view-action')
                    ->action(static::makeViewAction())
                    ->extraCellAttributes(['class' => 'mail-log-view-column']),

                TextColumn::make('email_template_id')
                    ->label('ID')
                    ->width('40px')
                    ->state(fn (MailLog $record): ?int => $record->resolveEmailTemplateId())
                    ->url(fn (MailLog $record): ?string => ($templateId = $record->resolveEmailTemplateId()) !== null
                        ? EmailTemplateResource::getUrl('edit', ['record' => $templateId])
                        : null)
                    ->openUrlInNewTab()
                    ->placeholder('–')
                    ->extraCellAttributes(['class' => 'mail-log-id-column'])
                    ->sortable(false),

                TextColumn::make('aanvraagnummer')
                    ->label('Aanvraagnummer')
                    ->width('120px')
                    ->disabledClick()
                    ->html()
                    ->state(function (MailLog $record): string {
                        $main = $record->resolveMain();
                        $uid = $main?->getUid();

                        if ($main === null || $uid === null || $uid === '') {
                            return '<span class="text-gray-400">–</span>';
                        }

                        return '<a class="main-request-number-link" href="'
                            . e(MainResource::getUrl('view', ['record' => $main->getId()]))
                            . '" onclick="event.stopPropagation()">'
                            . e($uid)
                            . '</a>';
                    })
                    ->extraCellAttributes(['class' => 'mail-log-aanvraagnummer-column'])
                    ->sortable(false),
            ])
            ->deferFilters(false)
            ->filters([
                self::getDateFilter(),
                self::getMailLogStatusFilter(),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([]);
    }

    public static function makeViewAction(): ViewAction
    {
        return ViewAction::make('view')
            ->label('')
            ->iconButton()
            ->color('gray')
            ->extraAttributes(['class' => 'mail-log-view-action'])
            ->modalCancelAction(fn (Action $action) => $action->label(__('filament-actions::view.single.modal.actions.close.label'))->extraAttributes(['class' => 'bg-white']));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMailLogs::route('/'),
        ];
    }
}
