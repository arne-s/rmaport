<?php

namespace App\Filament\Resources\OrderResource\Actions;

use App\Actions\SendCustomerMessageMailAction;
use App\Filament\Forms\Components\EmailRecipientSelect;
use App\Models\Customer;
use App\Models\MailSenderProfile;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use App\Filament\Resources\PurchaseOrderResource\Actions\ApprovePurchaseOrderEmailAction;

class SendCustomerEmailAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'send_customer_email';
    }

    public function getLabel(): string
    {
        return 'Mailen';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Mailen')
            ->icon('heroicon-o-envelope')
            ->modalHeading('Klant mailen')
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
                            ->default(fn (): string => MailSenderProfile::modalFromDisplayLabel('default')),

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
                    ->options(fn($livewire) => self::getUploadedDocumentsForCustomer($livewire->record))
                    ->extraFieldWrapperAttributes(['class' => 'checkbox-compact'])
                    ->columns(2),

                ViewField::make('order_documents_upload')
                    ->view('filament.resources.orders.partials.mail-modal-document-upload')
                    ->viewData(fn ($livewire): array => [
                        'hasAttachableDocuments' => $livewire->record !== null
                            && self::getUploadedDocumentsForCustomer($livewire->record) !== [],
                    ])
                    ->label('')
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, $livewire): void {
                /** @var Customer $customer */
                $customer = $livewire->record;
                $toEmails = self::resolveRecipientsForCustomer($customer, $data['to'] ?? []);
                if (empty($toEmails)) {
                    Notification::make()
                        ->title('Minimaal één ontvanger (To) is verplicht')
                        ->danger()
                        ->send();
                    return;
                }

                $ccEmails = self::resolveRecipientsForCustomer($customer, $data['cc'] ?? []);
                $bccEmails = self::resolveRecipientsForCustomer($customer, $data['bcc'] ?? []);
                $uploadedIds = $data['uploaded_attachments'] ?? [];

                app(SendCustomerMessageMailAction::class)->execute(
                    customer: $customer,
                    toAddress: $toEmails,
                    subject: (string) $data['subject'],
                    body: (string) $data['message'],
                    attachmentMediaIds: $uploadedIds,
                    cc: array_map(fn (string $email): array => ['name' => null, 'email' => $email], $ccEmails),
                    bcc: array_map(fn (string $email): array => ['name' => null, 'email' => $email], $bccEmails),
                    microsoftMailTokenId: MailSenderProfile::tokenIdByUid('default'),
                );

                Notification::make()
                    ->title('E-mail verzonden')
                    ->body('E-mail is verzonden naar: ' . implode(', ', $toEmails))
                    ->success()
                    ->send();

                // Clear uploaded documents after sending, as they are only meant for one-time use in the email and not to be kept permanently
                $customer->clearMediaCollection('documents');
            })
            ->modalSubmitActionLabel('Versturen')
            ->modalCancelAction(fn(Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    /**
     * @return array<int, string>
     */
    private static function getDefaultToRecipients($livewire): array
    {
        $email = $livewire->record?->getEmail();

        return filled($email) ? ['customer'] : [];
    }

    /**
     * @return array<string, string>
     */
    private static function getRecipientOptions($livewire): array
    {
        $customer = $livewire->record;

        $options = ApprovePurchaseOrderEmailAction::getRecipientOptions();

        if ($customer instanceof Customer) {
            $options['customer'] = 'Klant: ' . $customer->getName() . ' <' . ($customer->getEmail() ?: '—') . '>';
        }

        return $options;
    }

    /**
     * @param array<int, string> $selectedKeys
     * @return array<int, string>
     */
    private static function resolveRecipientsForCustomer(Customer $customer, array $selectedKeys): array
    {
        $emails = [];

        foreach ($selectedKeys as $key) {
            if (filter_var($key, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $key;
                continue;
            }

            if ($key === 'customer') {
                $email = $customer->getEmail();
                if (filled($email)) {
                    $emails[] = $email;
                }

                continue;
            }

            $emails = array_merge($emails, ApprovePurchaseOrderEmailAction::resolveRecipients([$key]));
        }

        return array_values(array_unique(array_filter($emails, fn ($v): bool => is_string($v) && $v !== '')));
    }

    /**
     * Uploaded documents (media collection 'documents') that can be attached. Keys are media ids, values are file names.
     */
    public static function getUploadedDocumentsForCustomer(Customer $customer): array
    {
        return $customer->getMedia('documents')
            ->mapWithKeys(fn($media) => [
                (string)$media->id => $media->file_name ?: ($media->name ? $media->name . '.' . $media->extension : 'document-' . $media->id . '.' . $media->extension),
            ])
            ->all();
    }
}
