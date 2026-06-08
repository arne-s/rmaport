<?php

namespace App\Filament\Resources\ReleaseOrders\Actions;

use App\Filament\Resources\ReleaseOrders\Support\ReleaseOrderMailRecipients;
use App\Mail\ReleaseOrderConfirmMail;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\MailSenderProfile;
use App\Models\ReleaseOrder;
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
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Auth;

class ApproveReleaseOrderEmailAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'send_release_order_email';
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
            ->modalHeading('Afroepverzoek verzenden')
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
                            ->default(fn (): string => MailSenderProfile::modalFromDisplayLabel('purchases')),

                        EmailRecipientSelect::make('to')
                            ->label('To')
                            ->options(fn ($livewire) => self::getRecipientOptions(
                                $livewire->record instanceof ReleaseOrder ? $livewire->record : null,
                                $livewire,
                            ))
                            ->default(fn ($livewire) => self::getDefaultToRecipients($livewire))
                            ->lockedValues(fn ($livewire) => self::lockedToRecipientKeys($livewire))
                            ->columnSpanFull(),

                        EmailRecipientSelect::make('cc')
                            ->label('CC')
                            ->options(fn ($livewire) => self::getRecipientOptions(
                                $livewire->record instanceof ReleaseOrder ? $livewire->record : null,
                                $livewire,
                            ))
                            ->default(fn ($livewire) => self::getDefaultCcRecipients($livewire))
                            ->lockedValues(fn ($livewire) => self::lockedCcRecipientKeys($livewire))
                            ->columnSpanFull(),

                        EmailRecipientSelect::make('bcc')
                            ->label('BCC')
                            ->options(fn ($livewire) => self::getRecipientOptions(
                                $livewire->record instanceof ReleaseOrder ? $livewire->record : null,
                                $livewire,
                            ))
                            ->columnSpanFull(),

                        TextInput::make('subject')
                            ->label('Onderwerp')
                            ->required()
                            ->default(fn ($livewire) => $livewire->record instanceof ReleaseOrder
                                ? (new ReleaseOrderConfirmMail($livewire->record))->getTemplateSubject()
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
                            ->default(fn ($livewire) => $livewire->record instanceof ReleaseOrder
                                ? (new ReleaseOrderConfirmMail($livewire->record))->getTemplateContent()
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

                ViewField::make('release_order_documents_upload')
                    ->view('filament.resources.purchase-orders.partials.mail-modal-document-upload')
                    ->viewData(fn ($livewire): array => [
                        'hasAttachableDocuments' => $livewire->record instanceof ReleaseOrder
                            && self::getAttachmentOptions($livewire->record) !== [],
                    ])
                    ->label('')
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, $livewire): void {
                $releaseOrder = $livewire->record;
                if (! $releaseOrder instanceof ReleaseOrder) {
                    return;
                }

                $releaseOrder->setAuthorId(Auth::id());
                $releaseOrder->saveQuietly();

                $toEmails = self::resolveRecipients($data['to'] ?? [], $releaseOrder, $livewire);
                if (empty($toEmails)) {
                    Notification::make()
                        ->title('Minimaal één ontvanger (To) is verplicht')
                        ->danger()
                        ->send();
                    return;
                }

                $data['to'] = self::ensureMandatoryToRecipients($toEmails, $releaseOrder, $livewire);
                $data['cc'] = self::ensureMandatoryCcRecipients(
                    self::resolveRecipients($data['cc'] ?? [], $releaseOrder, $livewire),
                );
                $data['bcc'] = self::resolveRecipients($data['bcc'] ?? [], $releaseOrder, $livewire);

                $livewire->placeReleaseOrderWithEmail($data);
            })
            ->modalSubmitActionLabel('Verzenden')
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    /**
     * Get dealer advisor name/email from the main fitting note.
     *
     * @return array{email: string, display_name: string}|null
     */
    private static function getAdvisorDealerRecipient(?ReleaseOrder $releaseOrder): ?array
    {
        $note = $releaseOrder?->main?->getFittingNote();

        if (! is_array($note)) {
            return null;
        }

        $email = trim(data_get($note, 'advisor_dealer_email'));

        if (! filled($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $contact = trim(data_get($releaseOrder->getAdditional(), 'contactperson'));
        $fittingName = trim(data_get($note, 'advisor_dealer_name'));

        return [
            'email' => $email,
            'display_name' => $contact ?: $fittingName ?: 'Adviseur dealer',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getRecipientOptions(?ReleaseOrder $releaseOrder = null, mixed $livewire = null): array
    {
        $options = [];

        $deliveryLabel = ReleaseOrderMailRecipients::recipientOptionLabel($releaseOrder, $livewire);
        if ($deliveryLabel !== null) {
            $options[ReleaseOrderMailRecipients::RECIPIENT_KEY] = $deliveryLabel;
        }

        $options[ReleaseOrderMailRecipients::INKOOP_CC_EMAIL] = 'Inkoop: inkoop@rdmobility.com <'
            .ReleaseOrderMailRecipients::INKOOP_CC_EMAIL.'>';

        $advisor = self::getAdvisorDealerRecipient($releaseOrder);
        if ($advisor !== null) {
            $options['advisor_dealer'] = 'Adviseur dealer: ' . $advisor['display_name'] . ' <' . $advisor['email'] . '>';
        }

        foreach (User::query()->whereNotNull('email')->orderBy('first_name')->orderBy('last_name')->get() as $user) {
            $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email;
            $options['user_' . $user->id] = 'Gebruiker: ' . $name . ' <' . $user->email . '>';
        }

        foreach (Customer::query()->where('type', CustomerType::Dealer->value)->whereNotNull('email')->orderBy('name')->get() as $dealer) {
            $options['dealer_' . $dealer->id] = 'Dealer: ' . $dealer->getName() . ' <' . $dealer->getEmail() . '>';
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private static function getDefaultToRecipients($livewire): array
    {
        $record = $livewire->record;
        if (! $record instanceof ReleaseOrder) {
            return [];
        }

        if (ReleaseOrderMailRecipients::defaultMailRecipient($record, $livewire) !== null) {
            return [ReleaseOrderMailRecipients::RECIPIENT_KEY];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public static function getDefaultCcRecipients($livewire): array
    {
        return self::lockedCcRecipientKeys($livewire);
    }

    /**
     * @return list<string>
     */
    public static function lockedToRecipientKeys(mixed $livewire): array
    {
        $record = $livewire->record ?? null;
        if (! $record instanceof ReleaseOrder) {
            return [];
        }

        if (ReleaseOrderMailRecipients::defaultMailRecipient($record, $livewire) === null) {
            return [];
        }

        return [ReleaseOrderMailRecipients::RECIPIENT_KEY];
    }

    /**
     * @return list<string>
     */
    public static function lockedCcRecipientKeys(mixed $livewire): array
    {
        return [ReleaseOrderMailRecipients::INKOOP_CC_EMAIL];
    }

    /**
     * @param  array<int, Address|string>  $recipients
     * @return array<int, Address|string>
     */
    public static function ensureMandatoryToRecipients(
        array $recipients,
        ?ReleaseOrder $releaseOrder = null,
        mixed $livewire = null,
    ): array {
        $mandatory = self::resolveRecipients(
            [ReleaseOrderMailRecipients::RECIPIENT_KEY],
            $releaseOrder,
            $livewire,
        );

        if ($mandatory === []) {
            return $recipients;
        }

        return self::mergeUniqueRecipients($mandatory, $recipients);
    }

    /**
     * @param  array<int, Address|string>  $recipients
     * @return array<int, Address|string>
     */
    public static function ensureMandatoryCcRecipients(array $recipients): array
    {
        $mandatory = self::resolveRecipients([ReleaseOrderMailRecipients::INKOOP_CC_EMAIL]);

        return self::mergeUniqueRecipients($mandatory, $recipients);
    }

    /**
     * @param  array<int, Address|string>  $first
     * @param  array<int, Address|string>  $second
     * @return array<int, Address|string>
     */
    private static function mergeUniqueRecipients(array $first, array $second): array
    {
        $merged = array_merge($first, $second);
        $seenEmails = [];
        $result = [];

        foreach ($merged as $recipient) {
            $email = $recipient instanceof Address ? $recipient->address : (string) $recipient;
            if (isset($seenEmails[$email])) {
                continue;
            }
            $seenEmails[$email] = true;
            $result[] = $recipient;
        }

        return array_values($result);
    }

    /**
     * Resolve selected keys (user_*, dealer_*, advisor_dealer) to mail targets.
     * Dealer advisor is added to recipients if it exists.
     *
     * @param  array<int, string>  $selectedKeys
     * @return array<int, Address|string>
     */
    public static function resolveRecipients(array $selectedKeys, ?ReleaseOrder $releaseOrder = null, mixed $livewire = null): array
    {
        $recipients = [];
        $seenEmails = [];

        foreach ($selectedKeys as $key) {
            if (filter_var($key, FILTER_VALIDATE_EMAIL)) {
                if (! isset($seenEmails[$key])) {
                    $seenEmails[$key] = true;
                    $recipients[] = $key;
                }
                continue;
            }

            if ($key === ReleaseOrderMailRecipients::RECIPIENT_KEY) {
                $delivery = ReleaseOrderMailRecipients::defaultMailRecipient($releaseOrder, $livewire);
                if ($delivery !== null) {
                    $email = $delivery['email'];
                    if (! isset($seenEmails[$email])) {
                        $seenEmails[$email] = true;
                        $recipients[] = new Address($email, $delivery['display_name']);
                    }
                }

                continue;
            }

            if ($key === 'advisor_dealer') {
                $advisor = self::getAdvisorDealerRecipient($releaseOrder);
                if ($advisor !== null) {
                    $email = $advisor['email'];
                    if (! isset($seenEmails[$email])) {
                        $seenEmails[$email] = true;
                        $recipients[] = new Address($email, $advisor['display_name']);
                    }
                }
                continue;
            }
            if (str_starts_with($key, 'user_')) {
                $id = (int) str_replace('user_', '', $key);
                $user = User::find($id);
                if ($user !== null && $user->email !== null && $user->email !== '') {
                    $email = $user->email;
                    if (! isset($seenEmails[$email])) {
                        $seenEmails[$email] = true;
                        $recipients[] = $email;
                    }
                }
            } elseif (str_starts_with($key, 'dealer_')) {
                $id = (int) str_replace('dealer_', '', $key);
                $dealer = Customer::find($id);
                $dealerEmail = $dealer?->getEmail();
                if ($dealer !== null && $dealerEmail !== null && $dealerEmail !== '') {
                    if (! isset($seenEmails[$dealerEmail])) {
                        $seenEmails[$dealerEmail] = true;
                        $recipients[] = $dealerEmail;
                    }
                }
            }
        }

        return array_values($recipients);
    }

    /**
     * @return array<string, string>
     */
    public static function getAttachmentOptions(ReleaseOrder $releaseOrder): array
    {
        $options = [];
        $main = $releaseOrder->main;

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
    public static function resolveAttachments(ReleaseOrder $releaseOrder, array $selectedKeys): array
    {
        $resolved = [];
        $main = $releaseOrder->main;

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
