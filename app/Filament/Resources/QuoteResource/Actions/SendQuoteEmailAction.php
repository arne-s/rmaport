<?php

namespace App\Filament\Resources\QuoteResource\Actions;

use App\Enums\CustomerType;
use App\Enums\OrderGeneralStatus;
use App\Filament\Resources\OrderResource\Support\OrderCustomerMailRecipients;
use App\Filament\Support\EmailRecipientResolver;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Order\Quote;
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
use Illuminate\Validation\ValidationException;
use Throwable;
use App\Models\MailSenderProfile;

class SendQuoteEmailAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'send_quote_email';
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
            ->modalHeading('Offerte verzenden')
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

                            ->default(fn (): string => MailSenderProfile::modalFromDisplayLabel('orders')),

                        EmailRecipientSelect::make('to')
                            ->label('To')
                            ->options(fn ($livewire) => self::getRecipientOptions($livewire))
                            ->default(fn ($livewire) => self::getDefaultToRecipients($livewire))
                            ->required()
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
                            ->required()
                            ->afterStateHydrated(function ($component, $set, $livewire) {
                                $record = $livewire->record;
                                if ($record instanceof Quote) {
                                    $this->loadEmailTemplate($set, $record, $livewire);
                                }
                            })
                            ->disableToolbarButtons(['attachFiles'])
                            ->columnSpanFull(),
                    ]),

                CheckboxList::make('attachments')
                    ->label('Documenten meesturen')
                    ->options(fn ($livewire) => self::getAttachmentOptions($livewire->record))
                    ->default([])
                    ->extraFieldWrapperAttributes(['class' => 'checkbox-compact'])
                    ->columns(2)
                    ->columnSpanFull(),

                ViewField::make('quote_documents_upload')
                    ->view('filament.resources.orders.partials.mail-modal-document-upload')
                    ->viewData(fn ($livewire): array => [
                        'hasAttachableDocuments' => $livewire->record instanceof Quote
                            && self::getAttachmentOptions($livewire->record) !== [],
                    ])
                    ->label('')
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, $livewire): void {
                $quote = $livewire->record;
                if (!$quote instanceof Quote) {
                    return;
                }
                $toKeys = is_array($data['to'] ?? null) ? $data['to'] : [];
                $toEmails = self::resolveRecipientsForQuote($quote, $livewire, $toKeys);
                if ($toEmails === []) {
                    Notification::make()
                        ->title('Minimaal één ontvanger (To) is verplicht')
                        ->danger()
                        ->send();
                    return;
                }
                $data['primary_recipient_key'] = $quote->normalizeQuoteMailPrimaryRecipientKey(
                    self::detectPrimaryRecipientType($quote, $toKeys),
                );
                $data['to'] = $toEmails;
                $data['cc'] = self::resolveRecipientsForQuote($quote, $livewire, $data['cc'] ?? []);
                $data['bcc'] = self::resolveRecipientsForQuote($quote, $livewire, $data['bcc'] ?? []);
                $data['attachments'] = self::resolveAttachments($quote, $data['attachments'] ?? []);

                try {
                    $livewire->persistFormAndSaveForSending();
                } catch (ValidationException $e) {
                    $message = collect($e->errors())->flatten()->first() ?? $e->getMessage();
                    Notification::make()
                        ->title('Offerte kon niet worden opgeslagen')
                        ->body($message)
                        ->danger()
                        ->send();
                    return;
                }

                $quote->refresh();
                $quote->regeneratePendingQuoteApproval();
                $this->sendEmail($data, $quote);

                $quote->setStatus(OrderGeneralStatus::Sent);
                $quote->save();

                $redirectUrl = \App\Filament\Resources\Resource::getRedirectToMainUrlForRecord($quote);
                if ($redirectUrl !== null) {
                    $livewire->redirect($redirectUrl, navigate: true);
                } else {
                    $livewire->redirect(route('filament.app.resources.quotes.index'), navigate: true);
                }
            })
            ->modalSubmitActionLabel('Versturen')
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    /**
     * True when the invoice party is not the order customer (e.g. dealer, RD as billing customer): default "To" is the billing customer.
     */
    private static function shouldDefaultQuoteEmailToBillingParty(Quote $quote, $livewire): bool
    {
        $billingId = $quote->billing_customer_id;
        $customerId = $quote->customer_id;
        try {
            $form = $livewire->form->getState();
            if (isset($form['billing_customer_id']) && $form['billing_customer_id'] !== null && $form['billing_customer_id'] !== '') {
                $billingId = (int) $form['billing_customer_id'];
            }
            if (isset($form['customer_id']) && $form['customer_id'] !== null && $form['customer_id'] !== '') {
                $customerId = (int) $form['customer_id'];
            }
        } catch (\Throwable) {
        }

        if ($billingId === null) {
            return false;
        }

        $billingId = (int) $billingId;

        if ($customerId === null) {
            return true;
        }

        return $billingId !== (int) $customerId;
    }

    /**
     * @return array<int, string>
     */
    private static function getDefaultToRecipients($livewire): array
    {
        $quote = $livewire->record;
        if (! $quote instanceof Quote) {
            return [];
        }

        $quote->loadMissing('billingCustomer');
        if ($quote->billingCustomer?->getType() === CustomerType::B2B) {
            $email = self::resolveInvoiceDealerEmail($quote, $livewire);
            if ($email !== null && $email !== '') {
                return ['dealer'];
            }
        }

        $invoiceToDealer = self::shouldDefaultQuoteEmailToBillingParty($quote, $livewire);

        if ($invoiceToDealer) {
            $email = self::resolveInvoiceDealerEmail($quote, $livewire);

            return ($email !== null && $email !== '') ? ['dealer'] : [];
        }

        $customerEmail = self::getCustomerEmail($quote, $livewire);
        if ($customerEmail !== null && $customerEmail !== '') {
            return ['customer'];
        }

        if ($quote->billingCustomer !== null && $quote->billingCustomer->getEmail() !== null && $quote->billingCustomer->getEmail() !== '') {
            return ['dealer'];
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private static function getRecipientOptions($livewire): array
    {
        $quote = $livewire->record;
        if (!$quote instanceof Quote) {
            return EmailRecipientResolver::getRecipientOptions();
        }

        $options = EmailRecipientResolver::getRecipientOptions();
        if ($quote->customer !== null) {
            $customerEmail = self::getCustomerEmail($quote, $livewire);
            $options['customer'] = 'Klant: ' . $quote->getCustomerAddressDisplayName() . ' <' . ($customerEmail ?: '—') . '>';
        }

        $invoiceCompany = self::resolveInvoiceCompany($quote, $livewire);
        if ($invoiceCompany !== null) {
            $dealerEmail = $invoiceCompany->getEmail();
            $options['dealer'] = 'Dealer: ' . $invoiceCompany->getName() . ' <' . ($dealerEmail ?: '—') . '>';
        }

        return $options;
    }

    /**
     * @see OrderCustomerMailRecipients::resolveCustomerContactEmailForModal()
     */
    private static function getCustomerEmail(Quote $quote, $livewire): ?string
    {
        return OrderCustomerMailRecipients::resolveCustomerContactEmailForModal($quote, $livewire);
    }

    /**
     * Customer that receives the invoice for the current billing selection.
     */
    private static function resolveInvoiceCompany(Quote $quote, $livewire): Customer|null
    {
        return $quote->billingCustomer;
    }

    private static function resolveInvoiceDealerEmail(Quote $quote, $livewire): ?string
    {
        return self::resolveInvoiceCompany($quote, $livewire)?->getEmail();
    }

    /**
     * @param array<int, string> $selectedKeys
     * @return array<int, string>
     */
    private static function resolveRecipientsForQuote(Quote $quote, $livewire, array $selectedKeys): array
    {
        $emails = [];

        foreach ($selectedKeys as $key) {
            if ($key === 'customer') {
                $email = self::getCustomerEmail($quote, $livewire);
                if ($email !== null && $email !== '') {
                    $emails[] = $email;
                }
                continue;
            }

            if ($key === 'dealer') {
                $email = self::resolveInvoiceDealerEmail($quote, $livewire);
                if ($email !== null && $email !== '') {
                    $emails[] = $email;
                }
                continue;
            }

            $emails = array_merge($emails, EmailRecipientResolver::resolveRecipients([$key]));
        }

        return array_values(array_unique($emails));
    }

    /**
     * @param  array<int, string>  $selected  Recipient option keys from the form (e.g. customer, dealer), not email addresses.
     */
    private static function detectPrimaryRecipientType(Quote $quote, array $selected): ?string
    {
        if (in_array('dealer', $selected, true)) {
            return 'dealer';
        }

        if (in_array('customer', $selected, true)) {
            return $quote->shouldUseDealerQuoteMail('customer') ? 'dealer' : 'customer';
        }

        return null;
    }

    /**
     * Loads the CMS template into the modal as stored — placeholders stay literal until the mailable sends.
     * Picks the dealer or customer variant based on the default To recipient.
     */
    private function loadEmailTemplate($set, Quote $quote, $livewire): void
    {
        $defaultTo = self::getDefaultToRecipients($livewire);
        $primaryKey = self::detectPrimaryRecipientType($quote, $defaultTo);
        $quoteMailClass = $quote->resolveQuoteMailClass($primaryKey);

        $template = EmailTemplate::where('class', $quoteMailClass)->first();
        if ($template === null) {
            return;
        }

        $set('subject', (string) $template->getSubject());
        $set('message', (string) ($template->getContent() ?? ''));
    }

    /**
     * Send email.
     *
     * @throws Throwable
     */
    protected function sendEmail(array $data, Quote $quote): void
    {
        $quote->sendQuote($data, false);
/*
        $main = $quote->main;
        if ($main !== null) {

        }*/

        $toDisplay = is_array($data['to']) ? implode(', ', $data['to']) : $data['to'];
        Notification::make()
            ->title('E-mail verzonden')
            ->body("E-mail is verzonden naar: {$toDisplay}")
            ->success()
            ->send();
    }

    /**
     * @return array<string, string>
     */
    private static function getAttachmentOptions(Quote $quote): array
    {
        $options = [];
        $main = $quote->main;

        foreach ($quote->getMedia('documents') as $media) {
            $options["media_{$media->id}"] = $media->file_name ?: ($media->name . '.' . $media->extension);
        }
        foreach ($quote->getMedia('images') as $media) {
            $options["media_{$media->id}"] = $media->file_name ?: ($media->name . '.' . $media->extension);
        }

        if ($main !== null) {
            foreach ($main->getMedia('fitting_documents') as $media) {
                $options["media_{$media->id}"] = $media->file_name ?: ($media->name . '.' . $media->extension);
            }
            foreach ($main->getMedia('product_documents') as $media) {
                $options["media_{$media->id}"] = $media->file_name ?: ($media->name . '.' . $media->extension);
            }
            foreach ($main->getMedia('documents') as $media) {
                $options["media_{$media->id}"] = $media->file_name ?: ($media->name . '.' . $media->extension);
            }
        }

        return $options;
    }

    /**
     * @param array<int, string> $selectedKeys
     * @return array<int, array{path: string, name: string, mime: string}>
     */
    private static function resolveAttachments(Quote $quote, array $selectedKeys): array
    {
        $resolved = [];
        $main = $quote->main;

        $allMedia = collect();
        $allMedia = $allMedia->merge($quote->getMedia('documents'));
        $allMedia = $allMedia->merge($quote->getMedia('images'));

        if ($main !== null) {
            $allMedia = $allMedia->merge($main->getMedia('fitting_documents'));
            $allMedia = $allMedia->merge($main->getMedia('product_documents'));
            $allMedia = $allMedia->merge($main->getMedia('documents'));
        }

        foreach ($selectedKeys as $key) {
            if (! str_starts_with($key, 'media_')) {
                continue;
            }

            $mediaId = (int) str_replace('media_', '', $key);
            $media = $allMedia->firstWhere('id', $mediaId);
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
