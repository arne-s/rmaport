<?php

namespace App\Filament\Resources\OrderResource\Actions;

use App\Actions\CreatePackingSlipPdfAction;
use App\Actions\PackingSlipRecipientResolver;
use App\Actions\SendOrderCustomerMessageMailAction;
use App\Filament\Forms\Components\EmailRecipientSelect;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\Resources\OrderResource\Support\OrderCustomerMailRecipients;
use App\Filament\Resources\OrderResource\Support\PackingSlipDeliveryProofFormSchema;
use App\Filament\Resources\OrderResource\Support\PackingSlipMailRecipients;
use App\Filament\Resources\PurchaseOrderResource\Actions\ApprovePurchaseOrderEmailAction;
use App\Filament\Support\RecordLockNavigation;
use App\Mail\PackingSlipMail;
use App\Models\MailSenderProfile;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\RecordLockService;
use App\Support\PackingSlipChecklist;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SendPackingSlipAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'send_packing_slip';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('heroicon-o-clipboard-document-list')
            ->label('Afleverbon')
            ->modalHeading('Afleverbon mailen')
            ->closeModalByEscaping(true)
            ->closeModalByClickingAway(false)
            ->modalWidth('4xl')
            ->before(function (Action $action, $livewire): void {
                $order = self::resolveOrderForRecordLock($livewire);
                if ($order === null) {
                    return;
                }

                $user = Auth::user();
                if (! $user instanceof User) {
                    return;
                }

                $backUrl = $livewire instanceof ViewOrder && $livewire->record instanceof Main
                    ? route('filament.app.resources.mains.view', ['record' => $livewire->record->getId()])
                    : url()->current();

                $details = app(RecordLockService::class)->getBlockedDetailsFor($order, $user, $backUrl);

                if ($details !== null) {
                    RecordLockNavigation::notifyDocumentInUse($details);
                    $action->halt();
                }
            })
            ->mountUsing(function ($livewire): void {
                $order = self::resolveOrderForRecordLock($livewire);
                if ($order === null) {
                    return;
                }

                $user = Auth::user();
                if (! $user instanceof User) {
                    return;
                }

                app(RecordLockService::class)->acquire($order, $user);
            })
            ->fillForm(fn ($livewire): array => self::defaultModalFormState($livewire))
            ->schema(function ($livewire) {
                $main = $livewire->record instanceof Main ? $livewire->record : null;
                $order = $main instanceof Main ? self::resolveOrderForPackingSlip($main) : null;
                $serialNumber = $main instanceof Main
                    ? self::resolveSerialNumberDisplay($main, $livewire)
                    : '-';
                $orderUid = self::resolveOrderNumberDisplay($main, $order);
                $mainReference = $main instanceof Main ? (string) ($main->getReference() ?? '') : '';

                return [
                    Html::make('<span tabindex="0" aria-hidden="true" style="position:absolute;opacity:0;width:0;height:0;overflow:hidden;"></span>'),

                    Group::make()
                        ->extraAttributes(['class' => 'custom-form-design', 'style' => 'margin-top: -25px'])
                        ->schema([
                            TextInput::make('from')
                                ->label('Vanaf')
                                ->required()
                                ->disabled()
                                ->default(fn (): string => PackingSlipMail::modalFromDisplayLabel()),

                            EmailRecipientSelect::make('to')
                                ->label('To')
                                ->options(fn ($livewire) => self::getRecipientOptions($livewire))
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
                                ->required()
                                ->default(fn () => PackingSlipMail::getRawTemplateSubjectFromDatabase()),
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
                                ->default(fn () => self::htmlToRichEditorDocument(PackingSlipMail::getRawTemplateContentFromDatabase()))
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

                    Section::make('Producten op deze afleverbon')
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            CheckboxList::make('packing_slip_order_product_ids')
                                ->hiddenLabel()
                                ->extraFieldWrapperAttributes(['class' => 'checkbox-compact mt-4'])
                                ->options(fn () => $order instanceof Order ? self::getPackingSlipLineOptions($order) : [])
                                ->default(fn () => $order instanceof Order ? array_keys(self::getPackingSlipLineOptions($order)) : [])
                                ->columns(1)
                                ->columnSpanFull()
                                ->required(),
                        ]),

                    Section::make('Bewijs van aflevering')
                        ->schema([
                            Group::make()
                                ->extraAttributes(['class' => 'custom-form-design', 'style' => 'margin-top: 10px; margin-bottom: 15px'])
                                ->schema([
                                    Select::make('checklist_type')
                                        ->label('Type')
                                        ->inlineLabel()
                                        ->options(PackingSlipChecklist::typeOptions())
                                        ->default(fn (): string => PackingSlipChecklist::defaultType())
                                        ->selectablePlaceholder(false)
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(function (?string $state, Set $set): void {
                                            $type = PackingSlipChecklist::resolveType($state);
                                            $set('checklist_items', []);
                                            PackingSlipDeliveryProofFormSchema::resetDeliveryProofForType($set, $type);
                                        })
                                        ->columnSpanFull(),
                                ]),
                            ...PackingSlipDeliveryProofFormSchema::components(),

                            Html::make('<h3 class="fi-section-header-heading" style="margin-top: 1rem; margin-bottom: 0.5rem;">Checklist</h3>'),

                            CheckboxList::make('checklist_items')
                                ->hiddenLabel()
                                ->extraFieldWrapperAttributes(['class' => 'checkbox-compact mt-2'])
                                ->options(fn (Get $get): array => PackingSlipChecklist::itemsForType(
                                    PackingSlipChecklist::resolveType($get('checklist_type'))
                                ))
                                ->columns(1)
                                ->columnSpanFull()
                                ->rules([
                                    fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                        $type = PackingSlipChecklist::resolveType($get('checklist_type'));
                                        $allKeys = PackingSlipChecklist::itemKeysForType($type);
                                        $checked = is_array($value) ? $value : [];
                                        if (array_diff($allKeys, $checked) !== []) {
                                            $fail('De checklist is niet compleet.');
                                        }
                                    },
                                ]),
                        ]),

                    ...self::getSignatureFieldSchema(),

                    Group::make()
                        ->extraAttributes(['class' => 'custom-form-design'])
                        ->schema([
                            TextInput::make('reference')
                                ->label('Referentie')
                                ->required()
                                ->inlineLabel()
                                ->default($mainReference)
                                ->maxLength(255),
                            TextInput::make('serial_number_display')
                                ->label('Serienummer')
                                ->inlineLabel()
                                ->default($serialNumber)
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('ordernummer_display')
                                ->label('Ordernummer')
                                ->inlineLabel()
                                ->default($orderUid)
                                ->disabled()
                                ->dehydrated(false),
                        ]),
                    Textarea::make('comment')
                        ->label('Opmerking afleverbon')
                        ->rows(3)
                        ->maxLength(65535)
                        ->extraAttributes(['style' => 'max-height: 60px']),
                ];
            })
            ->action(function (array $data, $livewire): void {
                $main = $livewire->record;
                if (! $main instanceof Main) {
                    return;
                }

                $order = self::resolveOrderForPackingSlip($main);
                if (! $order instanceof Order) {
                    Notification::make()
                        ->title('Geen bevestigde order gevonden')
                        ->danger()
                        ->send();

                    return;
                }

                $toEmails = PackingSlipMailRecipients::resolveEmails($main, $order, $data['to'] ?? []);
                if ($toEmails === []) {
                    Notification::make()
                        ->title('Minimaal één ontvanger (To) is verplicht')
                        ->danger()
                        ->send();

                    return;
                }

                $checklistType = PackingSlipChecklist::resolveType($data['checklist_type'] ?? null);
                $checkedItems = is_array($data['checklist_items'] ?? null) ? $data['checklist_items'] : [];
                $requiredChecklistKeys = PackingSlipChecklist::itemKeysForType($checklistType);
                if (array_diff($requiredChecklistKeys, $checkedItems) !== []) {
                    Notification::make()
                        ->title('Checklist incompleet')
                        ->body('Vink alle punten van de checklist aan voor het gekozen type.')
                        ->danger()
                        ->send();

                    return;
                }

                $rawLineIds = $data['packing_slip_order_product_ids'] ?? [];
                if (! is_array($rawLineIds) || $rawLineIds === []) {
                    Notification::make()
                        ->title('Selecteer minimaal één product')
                        ->body('Kies welke regels op deze afleverbon horen.')
                        ->danger()
                        ->send();

                    return;
                }

                $orderProductIds = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $rawLineIds)));
                $allowedIds = array_map(static fn (string|int $id): int => (int) $id, array_keys(self::getPackingSlipLineOptions($order)));
                if (array_diff($orderProductIds, $allowedIds) !== []) {
                    Notification::make()
                        ->title('Ongeldige productkeuze')
                        ->body('Vernieuw de pagina en probeer opnieuw.')
                        ->danger()
                        ->send();

                    return;
                }

                $deliveryProofChecked = is_array($data['delivery_proof_items'] ?? null)
                    ? $data['delivery_proof_items']
                    : [];
                $deliveryProofTexts = is_array($data['delivery_proof_text'] ?? null)
                    ? $data['delivery_proof_text']
                    : [];

                try {
                    $packingSlip = app(CreatePackingSlipPdfAction::class)->execute(
                        main: $main,
                        order: $order,
                        orderProductIds: $orderProductIds,
                        signature: (string) ($data['signature'] ?? ''),
                        comment: (string) ($data['comment'] ?? ''),
                        reference: (string) ($data['reference'] ?? ''),
                        checklistType: $checklistType,
                        checklist: $checkedItems,
                        deliveryProofChecked: $deliveryProofChecked,
                        deliveryProofTexts: $deliveryProofTexts,
                    );
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()
                        ->title('Afleverbon aanmaken mislukt')
                        ->body($e->getMessage() ?: 'PDF kon niet worden gegenereerd.')
                        ->danger()
                        ->send();

                    return;
                }

                $attachmentName = 'afleverbon-' . $packingSlip->uid . '.pdf';
                $main->unsetRelation('media');
                $media = $main->media()
                    ->where('collection_name', 'delivery_documents')
                    ->where('file_name', $attachmentName)
                    ->first();

                if ($media === null) {
                    Notification::make()
                        ->title('Afleverbon niet gevonden')
                        ->body('Het media-record voor de PDF ontbreekt; mail niet verzonden.')
                        ->danger()
                        ->send();

                    return;
                }

                $relativePath = $media->getPathRelativeToRoot();
                if (! Storage::disk($media->disk)->exists($relativePath)) {
                    Notification::make()
                        ->title('Afleverbon niet gevonden')
                        ->body('De PDF staat niet op schijf; mail niet verzonden.')
                        ->danger()
                        ->send();

                    return;
                }

                $recipientFirstName = PackingSlipRecipientResolver::recipientFirstNameForToKeys(
                    $order,
                    $data['to'] ?? [],
                );

                $mailableContext = new PackingSlipMail(
                    main: $main,
                    order: $order,
                    packingSlip: $packingSlip,
                    toAddress: '',
                    toName: '',
                    recipientFirstName: $recipientFirstName,
                );

                $subject = trim((string) ($data['subject'] ?? ''));
                if ($subject === '') {
                    $subject = PackingSlipMail::getRawTemplateSubjectFromDatabase();
                }
                $subject = $mailableContext->interpolatePlaceholders($subject);

                $bodyRaw = self::richEditorMessageToHtml($data['message'] ?? null);
                $bodyPlain = trim(str_replace("\xc2\xa0", ' ', strip_tags($bodyRaw)));
                if ($bodyPlain === '') {
                    $bodyRaw = PackingSlipMail::getRawTemplateContentFromDatabase();
                }
                $bodyHtml = $mailableContext->interpolatePlaceholders($bodyRaw);

                $ccEmails = PackingSlipMailRecipients::resolveEmails($main, $order, $data['cc'] ?? []);
                $bccEmails = PackingSlipMailRecipients::resolveEmails($main, $order, $data['bcc'] ?? []);

                try {
                    app(SendOrderCustomerMessageMailAction::class)->execute(
                        order: $main,
                        toAddress: $toEmails,
                        subject: $subject,
                        body: $bodyHtml,
                        attachmentData: [],
                        attachmentMediaIds: [],
                        cc: array_map(fn (string $email): array => ['name' => null, 'email' => $email], $ccEmails),
                        bcc: array_map(fn (string $email): array => ['name' => null, 'email' => $email], $bccEmails),
                        logMailableClass: PackingSlipMail::class,
                        attachmentDeliveryDocumentMediaIds: [(int) $media->getKey()],
                        microsoftMailTokenId: PackingSlipMail::microsoftMailTokenId(),
                    );
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()
                        ->title('E-mail verzenden mislukt')
                        ->body($e->getMessage() ?: 'Controleer de mailconfiguratie.')
                        ->danger()
                        ->send();

                    return;
                }

                $main->unsetRelation('media');
                $livewire->dispatch('uploaded-docs-changed');

                if (property_exists($livewire, 'orderStatusFromDb')) {
                    $livewire->orderStatusFromDb = $main->fresh()?->getOrderStatus()?->value;
                    $livewire->orderStatus = $livewire->orderStatusFromDb;
                }

                $livewire->record->refresh();

                Notification::make()
                    ->title('Afleverbon verzonden')
                    ->body('E-mail is verzonden naar: ' . implode(', ', $toEmails))
                    ->success()
                    ->send();

                if ($livewire instanceof ViewOrder) {
                    $livewire->redirectToTabForCurrentStatus();
                }
            })
            ->modalSubmitActionLabel('Versturen')
            ->modalCancelActionLabel('Annuleren')
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    /**
     * @return array<int, string>
     */
    private static function getPackingSlipLineOptions(Order $order): array
    {
        $lines = $order->packingSlipEligibleOrderProducts()
            ->whereNull('order_products.packing_slip_id')
            ->with('product')
            ->orderBy('order_products.sort')
            ->get();

        $options = [];
        foreach ($lines as $line) {
            $name = $line->product?->getName() ?? ('Regel #' . $line->getId());
            $options[$line->getId()] = $name . ' × ' . $line->qty;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function getRecipientOptions($livewire): array
    {
        $main = $livewire->record instanceof Main ? $livewire->record : null;
        $order = $main !== null ? self::resolveOrderForPackingSlip($main) : null;

        $options = ApprovePurchaseOrderEmailAction::getRecipientOptions();

        $deliveryLabel = PackingSlipMailRecipients::recipientOptionLabel($main, $order);
        if ($deliveryLabel !== null) {
            $options[PackingSlipMailRecipients::RECIPIENT_KEY] = $deliveryLabel;
        }

        $options[PackingSlipMailRecipients::INFO_CC_EMAIL] = 'Info: info@rdmobility.com <'
            .PackingSlipMailRecipients::INFO_CC_EMAIL.'>';

        $template = PackingSlipMail::emailTemplate();
        if ($template !== null && filled($template->cc_sender_profile_uid)) {
            $profile = MailSenderProfile::query()
                ->where('uid', $template->cc_sender_profile_uid)
                ->with('microsoftMailToken')
                ->first();
            $profileEmail = $profile?->microsoftMailToken?->microsoft_email;
            if (is_string($profileEmail) && $profileEmail !== '' && ! isset($options[$profileEmail])) {
                $profileName = $profile?->name ?? $template->cc_sender_profile_uid;
                $options[$profileEmail] = 'CC profiel: '.$profileName.' <'.$profileEmail.'>';
            }
        }

        if ($main?->customer !== null) {
            $label = OrderCustomerMailRecipients::customerRecipientOptionLabel($main, $livewire);
            if ($label !== null) {
                $options['customer'] = $label;
            }
        }
        $billingCustomer = $main?->billingCustomer;
        if ($billingCustomer !== null && $billingCustomer->getType()?->isBusiness()) {
            $options['dealer'] = 'Dealer: ' . $billingCustomer->getName() . ' <' . ($billingCustomer->getEmail() ?: '—') . '>';
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private static function getDefaultCcRecipients($livewire): array
    {
        $keys = array_merge(
            [PackingSlipMailRecipients::INFO_CC_EMAIL],
            PackingSlipMail::defaultCcRecipientKeysFromEmailTemplate(),
        );

        if ($livewire->record instanceof BaseOrder) {
            $main = OrderCustomerMailRecipients::documentOwnerForRecord($livewire->record);
            $advisor = $main->advisor;
            if ($advisor !== null && $advisor->getEmail() !== '') {
                $keys[] = 'user_'.$advisor->getKey();
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<int, string>
     */
    private static function getDefaultToRecipients($livewire): array
    {
        $main = $livewire->record instanceof Main ? $livewire->record : null;
        $order = $main !== null ? self::resolveOrderForPackingSlip($main) : null;

        if (PackingSlipMailRecipients::defaultMailRecipient($main, $order) !== null) {
            return [PackingSlipMailRecipients::RECIPIENT_KEY];
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private static function getDefaultBccRecipients($livewire): array
    {
        $main = $livewire->record instanceof Main ? $livewire->record : null;
        $order = $main !== null ? self::resolveOrderForPackingSlip($main) : null;

        return PackingSlipMailRecipients::defaultBccRecipientKeys(
            $main,
            $order,
            $livewire,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultModalFormState(mixed $livewire): array
    {
        $main = $livewire->record ?? null;
        if (! $main instanceof Main) {
            return [];
        }

        $order = self::resolveOrderForPackingSlip($main);
        $defaultChecklistType = PackingSlipChecklist::defaultType();

        return [
            'from' => PackingSlipMail::modalFromDisplayLabel(),
            'to' => self::getDefaultToRecipients($livewire),
            'cc' => self::getDefaultCcRecipients($livewire),
            'bcc' => self::getDefaultBccRecipients($livewire),
            'subject' => PackingSlipMail::getRawTemplateSubjectFromDatabase(),
            'message' => self::htmlToRichEditorDocument(PackingSlipMail::getRawTemplateContentFromDatabase()),
            'packing_slip_order_product_ids' => $order !== null
                ? array_keys(self::getPackingSlipLineOptions($order))
                : [],
            'checklist_type' => $defaultChecklistType,
            'checklist_items' => [],
            ...PackingSlipDeliveryProofFormSchema::emptyStateForType($defaultChecklistType),
            'reference' => (string) ($main->getReference() ?? ''),
            'serial_number_display' => self::resolveSerialNumberDisplay($main, $livewire),
            'ordernummer_display' => self::resolveOrderNumberDisplay($main, $order),
            'comment' => '',
        ];
    }

    public static function resolveOrderForPackingSlip(Main $main): ?Order
    {
        $candidate = $main->getOrderForPurchase();
        if ($candidate instanceof Order) {
            $uid = $candidate->getUid();
            if ($uid !== null && $uid !== '') {
                return $candidate;
            }
        }

        return $main->resolveOrderForDeliveryNote();
    }

    public static function resolveSerialNumberDisplay(Main $main, mixed $livewire = null): string
    {
        if ($livewire instanceof ViewOrder) {
            $fromPage = trim($livewire->orderSerialNumber);
            if ($fromPage !== '') {
                return $fromPage;
            }
        }

        $fromRecord = trim((string) ($main->getSerialNumberRecord()?->getSerialNumber() ?? ''));
        if ($fromRecord !== '') {
            return $fromRecord;
        }

        $fromMain = trim((string) ($main->getSerialNumber() ?? ''));
        if ($fromMain !== '') {
            return $fromMain;
        }

        return '-';
    }

    public static function resolveOrderNumberDisplay(?Main $main, ?Order $order = null): string
    {
        $order ??= $main instanceof Main ? self::resolveOrderForPackingSlip($main) : null;

        if ($order instanceof Order) {
            $formatted = $order->getUidFormatted();

            return $formatted !== '' ? $formatted : '-';
        }

        return '-';
    }

    /**
     * @return array<string, mixed>
     */
    private static function htmlToRichEditorDocument(string $html): array
    {
        $emptyDoc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [],
                ],
            ],
        ];

        if (trim($html) === '') {
            return $emptyDoc;
        }

        try {
            $document = RichContentRenderer::make($html)->getEditor()->getDocument();

            return is_array($document) ? $document : $emptyDoc;
        } catch (Throwable $e) {
            report($e);

            return $emptyDoc;
        }
    }

    private static function richEditorMessageToHtml(mixed $message): string
    {
        if (is_string($message)) {
            return $message;
        }

        if (is_array($message)) {
            try {
                return RichContentRenderer::make($message)->toUnsafeHtml();
            } catch (Throwable $e) {
                report($e);

                return '';
            }
        }

        return '';
    }

    /**
     * @return array<int, mixed>
     */
    private static function getSignatureFieldSchema(): array
    {
        if (class_exists(\Saade\FilamentAutograph\Forms\Components\SignaturePad::class)) {
            return [
                \Saade\FilamentAutograph\Forms\Components\SignaturePad::make('signature')
                    ->label('Handtekening')
                    ->loadStrategy('idle')
                    ->required()
                    ->columnSpanFull(),
            ];
        }

        return [
            Textarea::make('signature')
                ->label('Handtekening')
                ->helperText('Signature plugin niet beschikbaar. Gebruik tijdelijke data-URL/base64 invoer.')
                ->required()
                ->extraAttributes(['style' => 'max-height: 60px'])
                ->extraFieldWrapperAttributes(['style' => 'max-height: 60px'])
                ->rows(1)
                ->columnSpanFull(),
        ];
    }

    private static function resolveOrderForRecordLock(mixed $livewire): ?Order
    {
        if (! $livewire instanceof ViewOrder) {
            return null;
        }

        $main = $livewire->record;
        if (! $main instanceof Main) {
            return null;
        }

        return self::resolveOrderForPackingSlip($main);
    }
}
