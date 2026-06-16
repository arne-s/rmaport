<?php

namespace App\Filament\Resources\ImportTasks\Actions;

use App\Actions\SendImportBatchExportMailAction;
use App\Filament\Forms\Components\EmailRecipientSelect;
use App\Filament\Resources\ImportTasks\Support\ImportBatchMailRecipients;
use App\Filament\Resources\ImportTasks\Support\ImportBatchUploadedDocumentMailAttachments;
use App\Filament\Support\EmailRecipientResolver;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\MailSenderProfile;
use App\Services\Export\CreateImportBatchExportAction;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class SendImportBatchExportAction extends Action
{
    public const SENDER_PROFILE_UID = 'orders';

    public static function getDefaultName(): ?string
    {
        return 'sendExport';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Aanmaken')
            ->icon(Heroicon::PlusCircle)
            ->color('gray')
            ->extraAttributes(['onclick' => 'event.stopPropagation()'])
            ->hidden(fn (ImportBatch $record): bool => $record->export !== null)
            ->modalHeading('Sheet retour sturen')
            ->modalWidth('4xl')
            ->closeModalByEscaping(false)
            ->closeModalByClickingAway(false)
            ->fillForm(fn (ImportBatch $record): array => [
                'from' => MailSenderProfile::modalFromDisplayLabel(self::SENDER_PROFILE_UID),
                'to' => ImportBatchMailRecipients::defaultToRecipients($record),
            ])
            ->schema([
                Html::make('<span tabindex="0" aria-hidden="true" style="position:absolute;opacity:0;width:0;height:0;overflow:hidden;"></span>'),

                Group::make()
                    ->extraAttributes(['class' => 'custom-form-design', 'style' => 'margin-top: -20px'])
                    ->schema([
                        TextInput::make('from')
                            ->label('Vanaf')
                            ->required()
                            ->disabled(),

                        EmailRecipientSelect::make('to')
                            ->label('To')
                            ->options(fn (ImportBatch $record): array => ImportBatchMailRecipients::recipientOptions($record))
                            ->columnSpanFull(),

                        EmailRecipientSelect::make('cc')
                            ->label('CC')
                            ->options(fn (ImportBatch $record): array => ImportBatchMailRecipients::recipientOptions($record))
                            ->columnSpanFull(),

                        EmailRecipientSelect::make('bcc')
                            ->label('BCC')
                            ->options(fn (ImportBatch $record): array => ImportBatchMailRecipients::recipientOptions($record))
                            ->columnSpanFull(),

                        TextInput::make('subject')
                            ->label('Onderwerp')
                            ->required(),
                    ]),

                Section::make('Bericht')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        RichEditor::make('message')
                            ->hiddenLabel()
                            ->label('Bericht')
                            ->extraAttributes(['class' => 'rich-editor-min-height'])
                            ->required()
                            ->toolbarButtons([
                                'bold',
                                'italic',
                                'underline',
                                'link',
                                'alignStart', 'alignCenter', 'alignEnd',
                                'bulletList',
                                'orderedList',
                                'table',
                                'redo',
                                'undo',
                            ])
                            ->disableToolbarButtons(['attachFiles'])
                            ->columnSpanFull(),
                    ]),

                Section::make('RMA\'s')
                    ->schema([
                        ViewField::make('rmas_table')
                            ->hiddenLabel()
                            ->view('filament.resources.import-tasks.partials.send-export-rmas-table')
                            ->viewData(fn (ImportBatch $record): array => [
                                'rows' => $record->importRows
                                    ->filter(fn (ImportRow $row): bool => $row->rma !== null)
                                    ->values(),
                            ])
                            ->columnSpanFull(),
                    ]),

                CheckboxList::make('uploaded_attachments')
                    ->label('Documenten meesturen')
                    ->options(fn (ImportBatch $record): array => ImportBatchUploadedDocumentMailAttachments::attachmentOptions($record))
                    ->extraFieldWrapperAttributes(['class' => 'checkbox-compact'])
                    ->columns(2),

                ViewField::make('import_batch_documents_upload')
                    ->view('filament.resources.orders.partials.mail-modal-document-upload')
                    ->viewData(fn (ImportBatch $record): array => [
                        'hasAttachableDocuments' => ImportBatchUploadedDocumentMailAttachments::attachmentOptions($record) !== [],
                    ])
                    ->label('')
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, ImportBatch $record): void {
                $record->loadMissing([
                    'export',
                    'importTemplate.exportTemplate',
                    'importRows.rma',
                    'importRows.customer',
                    'importRows.source.customer',
                ]);

                $toEmails = ImportBatchMailRecipients::resolveEmails($record, $data['to'] ?? []);

                if ($toEmails === []) {
                    Notification::make()
                        ->title('Minimaal één ontvanger (To) is verplicht')
                        ->danger()
                        ->send();

                    return;
                }

                $user = auth()->user();

                if ($user === null) {
                    return;
                }

                try {
                    $export = app(CreateImportBatchExportAction::class)($record, $user);
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title('Sheet retour aanmaken mislukt')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                if ($export === null) {
                    return;
                }

                $ccEmails = EmailRecipientResolver::resolveRecipients($data['cc'] ?? []);
                $bccEmails = EmailRecipientResolver::resolveRecipients($data['bcc'] ?? []);
                $uploadedIds = $data['uploaded_attachments'] ?? [];

                app(SendImportBatchExportMailAction::class)->execute(
                    export: $export,
                    toAddress: $toEmails,
                    subject: (string) $data['subject'],
                    body: (string) $data['message'],
                    ccEmails: $ccEmails,
                    bccEmails: $bccEmails,
                    microsoftMailTokenId: MailSenderProfile::tokenIdByUid(self::SENDER_PROFILE_UID),
                    attachmentMediaIds: $uploadedIds,
                );

                $export->update(['sent_at' => now()]);

                Notification::make()
                    ->title('Sheet retour verzonden')
                    ->body('Export aangemaakt en e-mail verzonden naar: '.implode(', ', $toEmails))
                    ->success()
                    ->send();
            })
            ->modalSubmitActionLabel('Versturen')
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }
}
