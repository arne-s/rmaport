<?php

namespace App\Filament\Resources\OrderResource\Actions;

use App\Actions\SendOrderCustomerMessageMailAction;
use App\Filament\Forms\Components\EmailRecipientSelect;
use App\Models\Order\BaseOrder;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use App\Filament\Resources\OrderResource\Support\FinancialDocumentMailAttachments;
use App\Filament\Resources\OrderResource\Support\OrderCustomerMailRecipients;
use App\Filament\Resources\OrderResource\Support\OrderUploadedDocumentMailAttachments;
use App\Filament\Resources\PurchaseOrderResource\Actions\ApprovePurchaseOrderEmailAction;
use App\Models\MailSenderProfile;

class SendOrderEmailAction extends Action
{
    public const SENDER_PROFILE_UID = 'orders';

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
            ->icon('heroicon-o-envelope')
            ->label('Mailen')
            ->modalHeading('Klant mailen')
            ->closeModalByEscaping(false)
            ->closeModalByClickingAway(false)
            ->schema([
                Html::make('<span tabindex="0" aria-hidden="true" style="position:absolute;opacity:0;width:0;height:0;overflow:hidden;"></span>'),

                Group::make()
                    ->extraAttributes(['class' => 'custom-form-design'])
                    ->schema([
                        TextInput::make('from')
                            ->label('Vanaf')
                            ->required()
                            ->disabled()
                            ->default(fn (): string => MailSenderProfile::modalFromDisplayLabel(self::SENDER_PROFILE_UID)),

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

                CheckboxList::make('attachments')
                    ->label('Financiële documenten meesturen')
                    ->extraFieldWrapperAttributes(['class' => 'checkbox-compact'])
                    ->options(fn ($livewire) => self::getAttachableMailsForOrder($livewire->record))
                    ->columns(2),

                CheckboxList::make('uploaded_attachments')
                    ->label('Documenten meesturen')
                    ->options(fn ($livewire) => self::getUploadedDocumentsForOrder(OrderCustomerMailRecipients::documentOwnerForRecord($livewire->record)))
                    ->extraFieldWrapperAttributes(['class' => 'checkbox-compact'])
                    ->columns(2),

                ViewField::make('order_documents_upload')
                    ->view('filament.resources.orders.partials.mail-modal-document-upload')
                    ->viewData(fn ($livewire): array => [
                        'hasAttachableDocuments' => $livewire->record !== null
                            && self::getUploadedDocumentsForOrder(
                                OrderCustomerMailRecipients::documentOwnerForRecord($livewire->record),
                            ) !== [],
                    ])
                    ->label('')
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, $livewire): void {
                $order = $livewire->record;
                $documentOwner = OrderCustomerMailRecipients::documentOwnerForRecord($order);
                $toEmails = OrderCustomerMailRecipients::resolveEmails($order, $data['to'] ?? []);
                if (empty($toEmails)) {
                    Notification::make()
                        ->title('Minimaal één ontvanger (To) is verplicht')
                        ->danger()
                        ->send();
                    return;
                }

                $ccEmails = OrderCustomerMailRecipients::resolveEmails($order, $data['cc'] ?? []);
                $bccEmails = OrderCustomerMailRecipients::resolveEmails($order, $data['bcc'] ?? []);
                $attachmentKeys = $data['attachments'] ?? [];
                $uploadedIds = $data['uploaded_attachments'] ?? [];
                $parsedFinancial = FinancialDocumentMailAttachments::parseSelectedKeys($attachmentKeys);

                app(SendOrderCustomerMessageMailAction::class)->execute(
                    order: $order,
                    toAddress: $toEmails,
                    subject: (string) $data['subject'],
                    body: (string) $data['message'],
                    attachmentData: [],
                    attachmentMediaIds: $uploadedIds,
                    attachmentFinancialMediaIds: $parsedFinancial['financial_media_ids'],
                    attachmentFinancialOrderIds: $parsedFinancial['financial_order_ids'],
                    cc: array_map(fn (string $email): array => ['name' => null, 'email' => $email], $ccEmails),
                    bcc: array_map(fn (string $email): array => ['name' => null, 'email' => $email], $bccEmails),
                    microsoftMailTokenId: MailSenderProfile::tokenIdByUid(self::SENDER_PROFILE_UID),
                );

                Notification::make()
                    ->title('E-mail verzonden')
                    ->body('E-mail is verzonden naar: ' . implode(', ', $toEmails))
                    ->success()
                    ->send();
            })
            ->modalSubmitActionLabel('Versturen')
            ->modalCancelAction(fn(Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    /**
     * @return array<int, string>
     */
    private static function getDefaultToRecipients($livewire): array
    {
        $record = $livewire->record;

        if ($record === null) {
            return [];
        }

        // Same contact e-mail as Details → Customer → E-mail (not the invoice/dealer party).
        $customerEmail = OrderCustomerMailRecipients::resolveCustomerContactEmailForModal($record, $livewire);
        if (filled($customerEmail) && $record->customer !== null) {
            return ['customer'];
        }

        $invoiceEmail = $record->billingCustomer?->getEmail();

        return filled($invoiceEmail) ? ['billing_company'] : [];
    }

    /**
     * @return array<string, string>
     */
    private static function getRecipientOptions($livewire): array
    {
        $record = $livewire->record;

        $options = ApprovePurchaseOrderEmailAction::getRecipientOptions();

        if ($record?->customer !== null) {
            $label = OrderCustomerMailRecipients::customerRecipientOptionLabel($record, $livewire);
            if ($label !== null) {
                $options['customer'] = $label;
            }
        }

        $billingCustomer = $record?->billingCustomer;
        if ($billingCustomer !== null && $billingCustomer->getType()?->isBusiness()) {
            $options['billing_company'] = 'Dealer (factuur): ' . $billingCustomer->getName() . ' <' . ($billingCustomer->getEmail() ?: '—') . '>';
        }

        return $options;
    }

    /**
     * Mails that can be attached when sending to customer. Keys are form values, values are labels.
     * Later: add conditions per key based on order status.
     */
    public static function getAttachableMailsForOrder(BaseOrder $order): array
    {
        return FinancialDocumentMailAttachments::attachmentOptions($order);
    }

    /**
     * Order that owns the documents collection (main order when viewing a fitting). Used for checklist and mail attachments.
     */
    public static function getDocumentOwnerForRecord(BaseOrder $record): BaseOrder
    {
        return OrderCustomerMailRecipients::documentOwnerForRecord($record);
    }

    /**
     * Uploaded documents (media collection 'documents') that can be attached. Keys are media ids, values are file names.
     */
    public static function getUploadedDocumentsForOrder(BaseOrder $order): array
    {
        return OrderUploadedDocumentMailAttachments::attachmentOptions($order);
    }
}
