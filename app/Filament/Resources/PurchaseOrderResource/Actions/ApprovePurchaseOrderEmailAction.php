<?php

namespace App\Filament\Resources\PurchaseOrderResource\Actions;

use App\Actions\SendPurchaseOrderConfirmMailAction;
use App\Mail\PurchaseOrderConfirmMail;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\MailSenderProfile;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\RichEditor;
use App\Filament\Forms\Components\EmailRecipientSelect;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;

class ApprovePurchaseOrderEmailAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'send_purchase_order_email';
    }

    public function getLabel(): string
    {
        return 'Verzenden';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('heroicon-o-envelope')
            ->modalHeading('Inkooporder verzenden')
            ->closeModalByEscaping(false)
            ->schema([
                Html::make('<span tabindex="0" aria-hidden="true" style="position:absolute;opacity:0;width:0;height:0;overflow:hidden;"></span>'),

                Group::make()
                    ->extraAttributes(['class' => 'custom-form-design', 'style' => 'margin-top: -25px'])
                    ->schema([
                        TextInput::make('from')
                            ->label('Vanaf')
                            ->required()
                            ->disabled()
                            ->default(fn (): string => MailSenderProfile::modalFromDisplayLabel(SendPurchaseOrderConfirmMailAction::SENDER_PROFILE_UID)),

                        EmailRecipientSelect::make('to')
                            ->label('To')
                            ->options(fn () => self::getRecipientOptions())
                            ->default(fn ($livewire) => self::getDefaultToRecipients($livewire))
                            ->columnSpanFull(),

                        EmailRecipientSelect::make('cc')
                            ->label('CC')
                            ->options(fn () => self::getRecipientOptions())
                            ->columnSpanFull(),

                        EmailRecipientSelect::make('bcc')
                            ->label('BCC')
                            ->options(fn () => self::getRecipientOptions())
                            ->columnSpanFull(),

                        TextInput::make('subject')
                            ->label('Onderwerp')
                            ->required()
                            ->default(fn ($livewire) => $livewire->record instanceof PurchaseOrder
                                ? (new PurchaseOrderConfirmMail($livewire->record))->getTemplateSubject()
                                : ''),
                    ]),

                Section::make('Bericht')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        RichEditor::make('message')
                            ->hiddenLabel()
                            ->label('Bericht')
                            ->required()
                            ->disableToolbarButtons(['attachFiles'])
                            ->default(fn ($livewire) => $livewire->record instanceof PurchaseOrder
                                ? (new PurchaseOrderConfirmMail($livewire->record))->getTemplateContent()
                                : '')
                            ->columnSpanFull(),
                    ]),

                CheckboxList::make('attachments')
                    ->label('Documenten meesturen')
                    ->options(fn ($livewire) => self::getAttachmentOptions($livewire->record))
                    ->default([])
                    ->extraFieldWrapperAttributes(['class' => 'checkbox-compact'])
                    ->columns(2)
                    ->columnSpanFull(),

                ViewField::make('po_documents_upload')
                    ->view('filament.resources.purchase-orders.partials.mail-modal-document-upload')
                    ->viewData(fn ($livewire): array => [
                        'hasAttachableDocuments' => $livewire->record instanceof PurchaseOrder
                            && self::getAttachmentOptions($livewire->record) !== [],
                    ])
                    ->label('')
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, $livewire): void {
                $purchaseOrder = $livewire->record;
                if (!$purchaseOrder instanceof PurchaseOrder) {
                    return;
                }

                $purchaseOrder->setAuthorId(Auth::id());
                $purchaseOrder->saveQuietly();

                $toEmails = self::resolveRecipients($data['to'] ?? []);
                if (empty($toEmails)) {
                    Notification::make()
                        ->title('Minimaal één ontvanger (To) is verplicht')
                        ->danger()
                        ->send();
                    return;
                }

                $data['to'] = $toEmails;
                $data['cc'] = self::resolveRecipients($data['cc'] ?? []);
                $data['bcc'] = self::resolveRecipients($data['bcc'] ?? []);

                $livewire->placePurchaseOrderWithEmail($data);
            })
            ->modalSubmitActionLabel('Verzenden')
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    /**
     * @return array<string, string>
     */
    public static function getRecipientOptions(): array
    {
        $options = [];

        foreach (User::query()->whereNotNull('email')->orderBy('first_name')->orderBy('last_name')->get() as $user) {
            $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email;
            $options['user_' . $user->id] = 'Gebruiker: ' . $name . ' <' . $user->email . '>';
        }

        foreach (Customer::query()->where('type', CustomerType::Dealer->value)->whereNotNull('email')->orderBy('name')->get() as $dealer) {
            $options['dealer_' . $dealer->id] = 'Dealer: ' . $dealer->getName() . ' <' . $dealer->getEmail() . '>';
        }

        foreach (Supplier::query()->whereNotNull('email_supplier')->orderBy('name')->get() as $supplier) {
            $options['supplier_' . $supplier->id] = 'Leverancier: ' . $supplier->name . ' <' . $supplier->getEmail() . '>';
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private static function getDefaultToRecipients($livewire): array
    {
        $record = $livewire->record;
        if (!$record instanceof PurchaseOrder || $record->supplier_id === null) {
            return [];
        }
        $supplier = $record->supplier;
        if ($supplier === null || $supplier->getEmail() === null || $supplier->getEmail() === '') {
            return [];
        }
        return ['supplier_' . $record->supplier_id];
    }

    /**
     * Resolve selected keys (user_*, dealer_*, supplier_*) to array of email addresses.
     *
     * @param  array<int, string>  $selectedKeys
     * @return array<int, string>
     */
    public static function resolveRecipients(array $selectedKeys): array
    {
        $emails = [];

        foreach ($selectedKeys as $key) {
            if (filter_var($key, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $key;
                continue;
            }

            if (str_starts_with($key, 'user_')) {
                $id = (int) str_replace('user_', '', $key);
                $user = User::find($id);
                if ($user !== null && $user->email !== null && $user->email !== '') {
                    $emails[] = $user->email;
                }
            } elseif (str_starts_with($key, 'dealer_')) {
                $id = (int) str_replace('dealer_', '', $key);
                $dealer = Customer::find($id);
                $email = $dealer?->getEmail();
                if ($dealer !== null && $email !== null && $email !== '') {
                    $emails[] = $email;
                }
            } elseif (str_starts_with($key, 'supplier_')) {
                $id = (int) str_replace('supplier_', '', $key);
                $supplier = Supplier::find($id);
                if ($supplier !== null && $supplier->getEmail() !== null && $supplier->getEmail() !== '') {
                    $emails[] = $supplier->getEmail();
                }
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * @return array<string, string>
     */
    public static function getAttachmentOptions(PurchaseOrder $purchaseOrder): array
    {
        $options = [];
        $main = $purchaseOrder->main;

        if ($main === null) {
            return $options;
        }

        foreach ($main->getMedia('product_documents') as $media) {
            $options['media_' . $media->id] = $media->file_name ?: ($media->name . '.' . $media->extension);
        }

        foreach ($main->getMedia('financial_documents') as $media) {
            $options['fin_' . $media->id] = $media->file_name ?: ($media->name . '.' . $media->extension);
        }

        foreach ($main->getMedia('documents') as $media) {
            $options['doc_' . $media->id] = $media->file_name ?: ($media->name . '.' . $media->extension);
        }

        return $options;
    }

    /**
     * @param  array<int, string>  $selectedKeys
     * @return array<int, array{path: string, name: string, mime: string}>
     */
    public static function resolveAttachments(PurchaseOrder $purchaseOrder, array $selectedKeys): array
    {
        $resolved = [];
        $main = $purchaseOrder->main;

        if ($main === null) {
            return $resolved;
        }

        $productDocuments = $main->getMedia('product_documents');
        $financialDocuments = $main->getMedia('financial_documents');
        $documents = $main->getMedia('documents');

        foreach ($selectedKeys as $key) {
            if (str_starts_with($key, 'media_')) {
                $mediaId = (int) str_replace('media_', '', $key);
                $media = $productDocuments->firstWhere('id', $mediaId);
            } elseif (str_starts_with($key, 'fin_')) {
                $mediaId = (int) str_replace('fin_', '', $key);
                $media = $financialDocuments->firstWhere('id', $mediaId);
            } elseif (str_starts_with($key, 'doc_')) {
                $mediaId = (int) str_replace('doc_', '', $key);
                $media = $documents->firstWhere('id', $mediaId);
            } else {
                $media = null;
            }

            if ($media !== null) {
                $resolved[] = [
                    'path' => $media->getPath(),
                    'name' => $media->file_name,
                    'mime' => $media->mime_type,
                ];
            }
        }

        return $resolved;
    }
}
