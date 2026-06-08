<?php

namespace App\Filament\Resources\OrderResource\Actions;

use App\Actions\SendInvoiceMailAction;
use App\Enums\OrderSubtype;
use App\Enums\OrderType;
use App\Filament\Resources\OrderResource\Support\FinancialDocumentMailAttachments;
use App\Filament\Resources\OrderResource\Support\OrderConfirmMailRecipients;
use App\Filament\Resources\OrderResource\Support\OrderCustomerMailRecipients;
use App\Models\Order\BaseOrder;
use App\Filament\Support\EmailRecipientResolver;
use App\Models\EmailTemplate;
use App\Models\Order\Order;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\RichEditor;
use App\Filament\Forms\Components\EmailRecipientSelect;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Models\MailSenderProfile;
use Closure;

class ApproveOrderEmailAction extends Action
{
    private const string ORDER_NUMBER_UNKNOWN_PLACEHOLDER = '(nog niet bekend)';

    public static function getDefaultName(): ?string
    {
        return 'send_order_email';
    }

    public function getLabel(): string
    {
        return 'Verzenden';
    }

    /**
     * Shared modal fields for order confirmation (EditOrder “Verzenden”).
     *
     * @param  Closure(object): ?Order  $resolveOrder
     * @return array<int, mixed>
     */
    public static function makeOrderConfirmationEmailModalSchema(Closure $resolveOrder): array
    {
        return [
            Html::make('<span tabindex="0" aria-hidden="true" style="position:absolute;opacity:0;width:0;height:0;overflow:hidden;"></span>'),

            Group::make()
                ->extraAttributes(['class' => 'custom-form-design', 'style' => 'margin-top: -25px'])
                ->schema([
                    TextInput::make('from')
                        ->label('Vanaf')
                        ->required()
                        ->disabled()
                        ->default(fn ($livewire): string => self::modalFromDisplayLabelForOrder($resolveOrder($livewire))),

                    EmailRecipientSelect::make('to')
                        ->label('To')
                        ->options(fn ($livewire) => self::getRecipientOptionsForOrder($resolveOrder($livewire), $livewire))
                        ->default(fn ($livewire) => self::getDefaultToRecipientsForOrder($resolveOrder($livewire), $livewire))
                        ->columnSpanFull(),

                    EmailRecipientSelect::make('cc')
                        ->label('CC')
                        ->options(fn ($livewire) => self::getRecipientOptionsForOrder($resolveOrder($livewire), $livewire))
                        ->default(fn ($livewire) => self::getDefaultCcRecipientsForOrder($resolveOrder($livewire), $livewire))
                        ->columnSpanFull(),

                    EmailRecipientSelect::make('bcc')
                        ->label('BCC')
                        ->options(fn ($livewire) => self::getRecipientOptionsForOrder($resolveOrder($livewire), $livewire))
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
                        ->required()
                        ->afterStateHydrated(function ($component, $set, $livewire) use ($resolveOrder): void {
                            $order = $resolveOrder($livewire);
                            if (! $order instanceof Order) {
                                return;
                            }
                            (new self('hydrate_order_confirm_template'))->loadEmailTemplate($set, $order);
                        })
                        ->disableToolbarButtons(['attachFiles'])
                        ->columnSpanFull(),
                ]),

            CheckboxList::make('attachments')
                ->label('Documenten meesturen')
                ->options(fn ($livewire) => self::getAttachmentOptionsForResolvedOrder($resolveOrder, $livewire))
                ->default(fn ($livewire) => self::getDefaultAttachmentsForResolvedOrder($resolveOrder, $livewire))
                ->extraFieldWrapperAttributes(['class' => 'checkbox-compact'])
                ->columns(2)
                ->columnSpanFull(),

            ViewField::make('order_documents_upload')
                ->view('filament.resources.orders.partials.mail-modal-document-upload')
                ->viewData(fn ($livewire) => [
                    'hasAttachableDocuments' => ($order = $resolveOrder($livewire)) instanceof Order
                        && self::getAttachmentOptions($order) !== [],
                ])
                ->label('')
                ->columnSpanFull(),
        ];
    }

    /**
     * Resolve recipients, substitute template variables in subject/message.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function finalizeModalDataForOrder(Order $order, $livewire, array $data): array
    {
        $toKeys = $data['to'] ?? [];
        $toEmails = self::resolveRecipientsForOrder($order, $livewire, $toKeys);
        if ($toEmails === []) {
            throw ValidationException::withMessages([
                'to' => ['Minimaal één ontvanger (To) is verplicht'],
            ]);
        }

        $data['to'] = $toEmails;
        $data['cc'] = SendInvoiceMailAction::filterCcEmailsNotInTo(
            self::resolveRecipientsForOrder($order, $livewire, $data['cc'] ?? []),
            $toEmails,
        );
        $data['bcc'] = self::resolveRecipientsForOrder($order, $livewire, $data['bcc'] ?? []);

        $helper = new self('finalize_order_confirm_modal');
        $variables = $helper->getTemplateVariables($order, self::detectPrimaryRecipientType($toKeys));
        $data['subject'] = $helper->replaceTemplateVariables($data['subject'] ?? '', $variables);
        $data['message'] = $helper->replaceTemplateVariables($data['message'] ?? '', $variables);

        return $data;
    }

    /**
     * After the order row is persisted (UID assigned), refresh subject/body placeholders that were
     * substituted too early while the number was still unknown.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function refreshOrderConfirmationModalDataAfterOrderPersist(Order $order, array $data): array
    {
        $formatted = $order->getUidFormatted();
        if ($formatted !== '' && $formatted !== null) {
            if (isset($data['subject']) && is_string($data['subject'])) {
                $data['subject'] = str_replace(self::ORDER_NUMBER_UNKNOWN_PLACEHOLDER, $formatted, $data['subject']);
            }
            if (isset($data['message']) && is_string($data['message'])) {
                $data['message'] = str_replace(self::ORDER_NUMBER_UNKNOWN_PLACEHOLDER, $formatted, $data['message']);
            }
        }

        $helper = new self('refresh_order_confirm_after_persist');
        $variables = $helper->getTemplateVariables($order, null);
        if (isset($data['subject']) && is_string($data['subject'])) {
            $data['subject'] = $helper->replaceTemplateVariables($data['subject'], $variables);
        }
        if (isset($data['message']) && is_string($data['message'])) {
            $data['message'] = $helper->replaceTemplateVariables($data['message'], $variables);
        }

        return $data;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('heroicon-o-envelope')
            ->modalHeading('Verzenden')
            ->closeModalByEscaping(false)
            ->schema(self::makeOrderConfirmationEmailModalSchema(
                fn ($livewire): ?Order => $livewire->record instanceof Order ? $livewire->record : null,
            ))
            ->action(function (array $data, $livewire): void {
                $order = $livewire->record;
                if (! $order instanceof Order) {
                    return;
                }

                $order->setAuthorId(Auth::id());
                $order->saveQuietly();

                try {
                    $data = self::finalizeModalDataForOrder($order, $livewire, $data);
                } catch (ValidationException $e) {
                    $message = collect($e->errors())->flatten()->first() ?? $e->getMessage();
                    Notification::make()
                        ->title('Minimaal één ontvanger (To) is verplicht')
                        ->body($message)
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $livewire->placeOrder(emailData: $data);
                } catch (ValidationException $e) {
                    $message = collect($e->errors())->flatten()->first() ?? $e->getMessage();
                    Notification::make()
                        ->title('Order kon niet worden geplaatst')
                        ->body($message)
                        ->danger()
                        ->send();
                }
            })
            ->modalSubmitActionLabel('Verzenden')
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    /**
     * @return array<int, string>
     */
    private static function getDefaultToRecipientsForOrder(?Order $order, $livewire): array
    {
        if (! $order instanceof Order) {
            return [];
        }

        return OrderConfirmMailRecipients::defaultToRecipientKeys($order, $livewire);
    }

    /**
     * @return array<int, string>
     */
    private static function getDefaultCcRecipientsForOrder(?Order $order, $livewire): array
    {
        if (! $order instanceof Order) {
            return [];
        }

        return OrderConfirmMailRecipients::defaultCcRecipientKeys($order, $livewire);
    }

    /**
     * @return array<string, string>
     */
    private static function getRecipientOptionsForOrder(?Order $order, $livewire): array
    {
        if (! $order instanceof Order) {
            return EmailRecipientResolver::getRecipientOptions();
        }

        return OrderConfirmMailRecipients::recipientOptions($order, $livewire);
    }

    /**
     * @param  Closure(object): ?Order  $resolveOrder
     * @return array<string, string>
     */
    private static function getAttachmentOptionsForResolvedOrder(Closure $resolveOrder, $livewire): array
    {
        $order = $resolveOrder($livewire);
        if (! $order instanceof Order) {
            return [];
        }

        return self::getAttachmentOptions($order);
    }

    /**
     * @param  Closure(object): ?Order  $resolveOrder
     * @return array<int, string>
     */
    private static function getDefaultAttachmentsForResolvedOrder(Closure $resolveOrder, $livewire): array
    {
        $order = $resolveOrder($livewire);
        if (! $order instanceof Order) {
            return [];
        }

        return self::getDefaultAttachments($order);
    }

    /**
     * @param array<int, string> $selectedKeys
     * @return array<int, string>
     */
    public static function resolveRecipientsForOrder(Order $order, $livewire, array $selectedKeys): array
    {
        return OrderConfirmMailRecipients::resolveRecipientEmails($order, $livewire, $selectedKeys);
    }

    /**
     * @param array<int, string> $selected
     */
    private static function detectPrimaryRecipientType(array $selected): ?string
    {
        foreach ($selected as $key) {
            if ($key === 'customer') {
                return 'customer';
            }
            if ($key === 'dealer' || $key === 'billing_company' || $key === OrderConfirmMailRecipients::DEALER_LOCATION_KEY) {
                return 'dealer';
            }
        }

        return null;
    }

    public static function resolveOrderConfirmMailClass(Order $order): string
    {
        $subtype = $order->main?->getSubtype() ?? $order->getSubtype() ?? OrderSubtype::Unit;

        return match ($subtype) {
            OrderSubtype::Service => \App\Mail\Service\OrderConfirmMail::class,
            OrderSubtype::Part    => \App\Mail\Part\OrderConfirmMail::class,
            default               => \App\Mail\Unit\OrderConfirmMail::class,
        };
    }

    public static function orderConfirmEmailTemplate(?Order $order): ?EmailTemplate
    {
        if (! $order instanceof Order) {
            return null;
        }

        return EmailTemplate::query()
            ->where('class', self::resolveOrderConfirmMailClass($order))
            ->with('senderProfile')
            ->first();
    }

    public static function modalFromDisplayLabelForOrder(?Order $order): string
    {
        $uid = self::orderConfirmEmailTemplate($order)?->senderProfile?->uid ?? 'orders';

        return MailSenderProfile::modalFromDisplayLabel($uid);
    }

    private function loadEmailTemplate($set, Order $order): void
    {
        $template = self::orderConfirmEmailTemplate($order);
        if ($template === null) {
            return;
        }

        $variables = $this->getTemplateVariables($order, null);
        $subject = $this->replaceTemplateVariables($template->getSubject(), $variables);
        $content = $this->replaceTemplateVariables($template->getContent() ?? '', $variables);
        $content = $this->revertFirstNamesToPlaceholder($content, $order);

        $set('subject', $subject);
        $set('message', $content);
    }

    private function revertFirstNamesToPlaceholder(string $content, Order $order): string
    {
        $customerFirst = $order->customer?->getFirstName() ?? '';
        $dealerFirst = $order->billingCustomer?->getFirstName() ?? '';
        if ($customerFirst !== '') {
            $content = str_replace($customerFirst, '[first_name]', $content);
        }
        if ($dealerFirst !== '' && $dealerFirst !== $customerFirst) {
            $content = str_replace($dealerFirst, '[first_name]', $content);
        }
        return $content;
    }

    private function getTemplateVariables(Order $order, ?string $toRecipient = null): array
    {
        $billingCustomer = $order->billingCustomer;
        $virtualCustomer = $order->main?->getVirtualCustomer($toRecipient)
            ?? match ($toRecipient) {
                'dealer' => $billingCustomer,
                default => $order->customer ?? $billingCustomer,
            };

        $firstName = $virtualCustomer?->getFirstName() ?? '';
        if ($firstName === '' && $toRecipient === null) {
            $firstName = '[first_name]';
        }

        $customerName = $virtualCustomer?->getName()
            ?? $order->customer?->getName()
            ?? $order->billingCustomer?->getName()
            ?? '';

        return [
            '[order_number]' => $order->getUid() ?: self::ORDER_NUMBER_UNKNOWN_PLACEHOLDER,
            '[main_number]' => $order->main?->getUidFormatted() ?? '',
            '[customer_name]' => $billingCustomer?->getName() ?? '',
            '[customer_number]' => (string) ($billingCustomer?->debtor_number ?? ''),
            '[first_name]' => $firstName,
            '[customer_first_name]' => $customerName,
            '[user_first_name]' => $customerName,
            '[user_name]' => $customerName,
            '[reference]' => $order->uid ?? '',
            '[customer_email]' => $virtualCustomer?->getEmail() ?? '',
            '[customer_last_name]' => $virtualCustomer?->getLastName() ?? '',
        ];
    }

    private function replaceTemplateVariables(string $content, array $variables): string
    {
        return str_replace(array_keys($variables), array_values($variables), $content);
    }

    /**
     * @return array<string, string>
     */
    private static function getAttachmentOptions(Order $order): array
    {
        $options = [];
        $main = $order->main;

        $collections = [
            [$order, 'documents'],
            [$order, 'images'],
        ];

        if ($main !== null) {
            $collections[] = [$main, 'fitting_documents'];
            $collections[] = [$main, 'product_documents'];
            $collections[] = [$main, 'documents'];
        }

        foreach ($collections as [$model, $collection]) {
            foreach ($model->getMedia($collection) as $media) {
                $options["media_{$media->id}"] = $media->file_name ?: ($media->name . '.' . $media->extension);
            }
        }

        if ($main !== null) {
            foreach (FinancialDocumentMailAttachments::financialOrdersForMain($main) as $financialOrder) {
                if ($financialOrder->getType() !== OrderType::Quote) {
                    continue;
                }

                $options['bo_'.$financialOrder->getId()] = FinancialDocumentMailAttachments::orderCheckboxLabel($financialOrder);
            }
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private static function getDefaultAttachments(Order $order): array
    {
        $defaults = [];

        if ($order->doc_path) {
            foreach ($order->getMedia('documents') as $media) {
                $defaults[] = "media_{$media->id}";
            }
        }

        return $defaults;
    }

    /**
     * @return array<int, array{path: string, name: string, mime: string}>
     */
    public static function resolveAttachments(Order $order, array $selectedKeys): array
    {
        $resolved = [];
        $main = $order->main;

        $allMedia = collect();
        $allMedia = $allMedia->merge($order->getMedia('documents'));
        $allMedia = $allMedia->merge($order->getMedia('images'));

        if ($main !== null) {
            $allMedia = $allMedia->merge($main->getMedia('fitting_documents'));
            $allMedia = $allMedia->merge($main->getMedia('product_documents'));
            $allMedia = $allMedia->merge($main->getMedia('documents'));
        }

        foreach ($selectedKeys as $key) {
            if (str_starts_with($key, 'media_')) {
                $mediaId = (int) str_replace('media_', '', $key);
                $media = $allMedia->firstWhere('id', $mediaId);
                if ($media !== null) {
                    $resolved[] = [
                        'path' => $media->getPath(),
                        'name' => $media->file_name,
                        'mime' => $media->mime_type,
                    ];
                }

                continue;
            }

            if (! str_starts_with($key, 'bo_')) {
                continue;
            }

            $relatedOrderId = (int) str_replace('bo_', '', $key);

            try {
                $relatedOrder = BaseOrder::findOrFailTypedWithoutScopes($relatedOrderId);
            } catch (\Throwable) {
                continue;
            }

            if ($relatedOrder->getType() !== OrderType::Quote) {
                continue;
            }

            if ($main === null || (int) $relatedOrder->main_id !== (int) $main->getId()) {
                continue;
            }

            $pdfMedia = FinancialDocumentMailAttachments::orderPdfMedia($relatedOrder);
            if ($pdfMedia !== null && is_file($pdfMedia->getPath())) {
                $resolved[] = [
                    'path' => $pdfMedia->getPath(),
                    'name' => $pdfMedia->file_name,
                    'mime' => $pdfMedia->mime_type ?? 'application/pdf',
                ];

                continue;
            }

            $attachment = FinancialDocumentMailAttachments::resolveOrderPdfAttachment($relatedOrder);
            if ($attachment === null) {
                continue;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'quote_pdf_');
            if ($tmp === false) {
                continue;
            }

            if (file_put_contents($tmp, $attachment['content']) === false) {
                @unlink($tmp);

                continue;
            }

            $resolved[] = [
                'path' => $tmp,
                'name' => $attachment['filename'],
                'mime' => $attachment['mime'],
            ];
        }

        return $resolved;
    }
}
