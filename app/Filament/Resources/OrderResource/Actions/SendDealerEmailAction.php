<?php

namespace App\Filament\Resources\OrderResource\Actions;

use App\Filament\Resources\PurchaseOrderResource\Actions\ApprovePurchaseOrderEmailAction;
use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\RichEditor;
use App\Filament\Forms\Components\EmailRecipientSelect;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Mail;
use App\Models\MicrosoftMailToken;

class SendDealerEmailAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'send_dealer_email';
    }

    public function getLabel(): string
    {
        return 'Dealer mailen';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Dealer mailen')
            ->modalHeading('Dealer mailen')
            ->closeModalByEscaping(false)
            ->schema([
                Html::make('<span tabindex="0" aria-hidden="true" style="position:absolute;opacity:0;width:0;height:0;overflow:hidden;"></span>'),

                Group::make()
                    ->extraAttributes(['class' => 'custom-form-design'])
                    ->schema([
                        TextInput::make('from')
                            ->label('Vanaf')
                            ->required()
                            ->disabled()
                            ->email()
                            ->default(fn () => MicrosoftMailToken::defaultEmail() ?? ''),

                        EmailRecipientSelect::make('to')
                            ->label('To')
                            ->options(fn ($livewire) => self::getRecipientOptions($livewire))
                            ->default(fn ($livewire) => self::getDefaultToRecipients($livewire))
                            ->columnSpanFull(),

                        EmailRecipientSelect::make('cc')
                            ->label('CC')
                            ->options(fn ($livewire) => self::getRecipientOptions($livewire))
                            ->columnSpanFull(),

                        EmailRecipientSelect::make('bcc')
                            ->label('BCC')
                            ->options(fn ($livewire) => self::getRecipientOptions($livewire))
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

                CheckboxList::make('uploaded_attachments')
                    ->label('Documenten meesturen')
                    ->options(fn ($livewire) => self::getUploadedDocumentsForDealer($livewire->record))
                    ->extraFieldWrapperAttributes(['class' => 'checkbox-compact'])
                    ->columns(2),

                ViewField::make('dealer_documents_upload')
                    ->view('filament.resources.orders.partials.mail-modal-document-upload')
                    ->viewData(fn ($livewire): array => [
                        'hasAttachableDocuments' => $livewire->record !== null
                            && self::getUploadedDocumentsForDealer($livewire->record) !== [],
                    ])
                    ->label('')
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, $livewire): void {
                /** @var Customer $dealer */
                $dealer = $livewire->record;
                $toEmails = self::resolveRecipientsForDealer($dealer, $data['to'] ?? []);
                if ($toEmails === []) {
                    Notification::make()
                        ->title('Minimaal één ontvanger (To) is verplicht')
                        ->danger()
                        ->send();
                    return;
                }

                $ccEmails = self::resolveRecipientsForDealer($dealer, $data['cc'] ?? []);
                $bccEmails = self::resolveRecipientsForDealer($dealer, $data['bcc'] ?? []);
                $uploadedIds = $data['uploaded_attachments'] ?? [];

                Mail::send([], [], function ($message) use ($data, $toEmails, $ccEmails, $bccEmails, $dealer, $uploadedIds): void {
                    $message
                        ->to($toEmails)
                        ->subject((string) $data['subject'])
                        ->view('emails.order-customer-message', [
                            'body' => (string) $data['message'],
                        ]);

                    if ($ccEmails !== []) {
                        $message->cc($ccEmails);
                    }

                    if ($bccEmails !== []) {
                        $message->bcc($bccEmails);
                    }

                    $mediaById = $dealer->getMedia('documents')->keyBy(fn ($media) => (string) $media->id);
                    foreach ((array) $uploadedIds as $id) {
                        $media = $mediaById->get((string) $id);
                        if ($media === null) {
                            continue;
                        }

                        $message->attach($media->getPath(), [
                            'as' => $media->file_name,
                            'mime' => $media->mime_type,
                        ]);
                    }
                });

                Notification::make()
                    ->title('E-mail verzonden')
                    ->body('E-mail is verzonden naar: ' . implode(', ', $toEmails))
                    ->success()
                    ->send();
            })
            ->modalSubmitActionLabel('Versturen')
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    /**
     * @return array<int, string>
     */
    private static function getDefaultToRecipients($livewire): array
    {
        $email = $livewire->record?->getEmail();

        return filled($email) ? ['dealer'] : [];
    }

    /**
     * @return array<string, string>
     */
    private static function getRecipientOptions($livewire): array
    {
        $dealer = $livewire->record;

        $options = ApprovePurchaseOrderEmailAction::getRecipientOptions();

        if ($dealer instanceof Customer) {
            $options['dealer'] = 'Dealer: ' . $dealer->getName() . ' <' . ($dealer->getEmail() ?: '—') . '>';
        }

        return $options;
    }

    /**
     * @param array<int, string> $selectedKeys
     * @return array<int, string>
     */
    private static function resolveRecipientsForDealer(Customer $dealer, array $selectedKeys): array
    {
        $emails = [];

        foreach ($selectedKeys as $key) {
            if ($key === 'dealer') {
                $email = $dealer->getEmail();
                if (filled($email)) {
                    $emails[] = $email;
                }

                continue;
            }

            $emails = array_merge($emails, ApprovePurchaseOrderEmailAction::resolveRecipients([$key]));
        }

        return array_values(array_unique(array_filter($emails, fn ($v): bool => is_string($v) && $v !== '')));
    }

    public static function getUploadedDocumentsForDealer(Customer $dealer): array
    {
        return $dealer->getMedia('documents')
            ->mapWithKeys(fn ($media) => [
                (string) $media->id => $media->file_name ?: ($media->name ? $media->name . '.' . $media->extension : 'document-' . $media->id . '.' . $media->extension),
            ])
            ->all();
    }
}

