<?php

namespace App\Filament\Resources\InvoiceResource\Actions;

use App\Actions\SendInvoiceMailAction;
use App\Filament\Resources\OrderResource\Support\OrderCustomerMailRecipients;
use App\Filament\Support\EmailRecipientResolver;
use App\Helpers\EmailHelper;
use App\Mail\CustomInvoiceMail;
use App\Models\Order\BaseOrder;
use App\Models\Order\Invoice;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use App\Filament\Forms\Components\EmailRecipientSelect;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use App\Models\MailSenderProfile;

class SubmitInvoiceEmailAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'submit_invoice_email';
    }

    public function getLabel(): string
    {
        return 'Opslaan en verzenden';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('heroicon-o-envelope')
            ->color('primary')
            ->extraAttributes(['id' => 'save-send-invoice'])
            ->modalHeading('Factuur verzenden')
            ->closeModalByEscaping(false)
            ->schema([
                Html::make('<span tabindex="0" aria-hidden="true" style="position:absolute;opacity:0;width:0;height:0;overflow:hidden;"></span>'),

                Group::make()
                    ->extraAttributes(['class' => 'confirm-order-modal custom-form-design', 'style' => 'margin-top: -25px'])
                    ->schema([
                        TextInput::make('from')
                            ->label('Vanaf')
                            ->required()
                            ->disabled()
                            ->default(fn (): string => MailSenderProfile::modalFromDisplayLabel('invoices')),

                        EmailRecipientSelect::make('to')
                            ->label('To')
                            ->options(fn ($livewire) => self::getRecipientOptions($livewire))
                            ->default(fn ($livewire) => self::getDefaultToRecipients($livewire))
                            ->columnSpanFull(),

                        EmailRecipientSelect::make('cc')
                            ->label('CC')
                            ->options(fn ($livewire) => self::getRecipientOptions($livewire))
                            ->default(fn ($livewire) => self::getDefaultCcRecipients($livewire))
                            ->columnSpanFull(),

                        EmailRecipientSelect::make('bcc')
                            ->label('BCC')
                            ->options(fn ($livewire) => self::getRecipientOptions($livewire))
                            ->columnSpanFull(),

                        TextInput::make('subject')
                            ->label('Onderwerp')
                            ->required()
                            ->default(fn ($livewire) => self::getDefaultSubject($livewire)),
                    ]),

                Section::make('Bericht')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        RichEditor::make('message')
                            ->hiddenLabel()
                            ->label('Bericht')
                            ->required()
                            ->default(fn ($livewire) => self::getDefaultMessage($livewire))
                            ->disableToolbarButtons(['attachFiles'])
                            ->columnSpanFull(),
                    ]),
            ])
            ->action(function (array $data, $livewire): void {
                $record = $livewire->record;
                if (! $record instanceof Invoice) {
                    return;
                }

                $toEmails = self::resolveRecipientEmailsForInvoice($record, $data['to'] ?? [], $livewire);
                if ($toEmails === []) {
                    Notification::make()
                        ->title('Minimaal één ontvanger (To) is verplicht')
                        ->danger()
                        ->send();

                    return;
                }

                $data['to'] = $toEmails;
                $data['cc'] = SendInvoiceMailAction::filterCcEmailsNotInTo(
                    self::resolveRecipientEmailsForInvoice($record, $data['cc'] ?? [], $livewire),
                    $toEmails,
                );
                $data['bcc'] = self::resolveRecipientEmailsForInvoice($record, $data['bcc'] ?? [], $livewire);

                $livewire->submitWithEmail($data);
            })
            ->modalSubmitActionLabel('Verzenden')
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    /** @return array<int, string> */
    private static function getDefaultToRecipients($livewire): array
    {
        $record = $livewire->record;
        if (! $record instanceof Invoice) {
            return [];
        }

        return self::defaultRecipientKeysForInvoice($record, $livewire);
    }

    /** @return array<int, string> */
    private static function getDefaultCcRecipients($livewire): array
    {
        $record = $livewire->record;
        if (! $record instanceof Invoice) {
            return [];
        }

        return self::defaultCcRecipientKeysForInvoice($record, $livewire);
    }

    /**
     * Keys for the default CC line (mirror {@see SendInvoiceMailAction::buildInvoiceMailToCcArrays}).
     *
     * @return array<int, string>
     */
    public static function defaultCcRecipientKeysForInvoice(BaseOrder $order, mixed $livewire = null): array
    {
        $toKeys = self::defaultRecipientKeysForInvoice($order, $livewire);
        $ccKeys = self::defaultCcRecipientKeysForInvoiceGivenToKeys($order, $toKeys, $livewire);

        return self::ccRecipientKeysExcludingDuplicateOfTo($order, $toKeys, $ccKeys, $livewire);
    }

    /**
     * CC keys before deduplication against resolved To e-mails (mirror {@see SendInvoiceMailAction::buildInvoiceMailToCcArrays}).
     *
     * @param  array<int, string>  $toKeys
     * @return array<int, string>
     */
    public static function defaultCcRecipientKeysForInvoiceGivenToKeys(BaseOrder $order, array $toKeys, mixed $livewire = null): array
    {
        if (SendInvoiceMailAction::invoiceMailShouldAddressBillingCustomerFirst($order)) {
            return $toKeys === ['dealer'] ? ['customer'] : [];
        }

        if ($toKeys === ['customer']) {
            return ['dealer'];
        }

        return [];
    }

    /**
     * @param  array<int, string>  $toKeys
     * @param  array<int, string>  $ccKeys
     * @return array<int, string>
     */
    private static function ccRecipientKeysExcludingDuplicateOfTo(
        BaseOrder $order,
        array $toKeys,
        array $ccKeys,
        mixed $livewire = null,
    ): array {
        if ($ccKeys === []) {
            return [];
        }

        if ($order instanceof Invoice) {
            $toEmails = self::resolveRecipientEmailsForInvoice($order, $toKeys, $livewire);
            $ccEmails = self::resolveRecipientEmailsForInvoice($order, $ccKeys, $livewire);

            return SendInvoiceMailAction::filterCcEmailsNotInTo($ccEmails, $toEmails) !== []
                ? $ccKeys
                : [];
        }

        return SendInvoiceMailAction::buildInvoiceMailToCcArrays($order)['cc'] !== []
            ? $ccKeys
            : [];
    }

    /**
     * Keys for the default To line: factuurklant first when die afwijkt van de aanvraagklant; anders eindklant vóór dealer.
     *
     * @return array<int, string>
     */
    public static function defaultRecipientKeysForInvoice(BaseOrder $order, mixed $livewire = null): array
    {
        if (SendInvoiceMailAction::invoiceMailShouldAddressBillingCustomerFirst($order)) {
            return ['dealer'];
        }

        $email = OrderCustomerMailRecipients::resolveCustomerContactEmailForModal($order, $livewire);
        if (EmailHelper::isValid($email)) {
            return ['customer'];
        }

        $billingCustomer = $order->billingCustomer;
        if ($billingCustomer !== null) {
            $email = $billingCustomer->getEmail();
            if (is_string($email) && $email !== '') {
                return ['dealer'];
            }
        }

        return [];
    }

    /** @return array<string, string> */
    private static function getRecipientOptions($livewire): array
    {
        $record = $livewire->record;
        $options = EmailRecipientResolver::getRecipientOptions();

        if ($record instanceof Invoice) {
            $customerLabel = OrderCustomerMailRecipients::customerRecipientOptionLabel($record, $livewire);
            if ($customerLabel !== null) {
                $options['customer'] = $customerLabel;
            }

            $billingCustomer = $record->billingCustomer;
            if ($billingCustomer !== null) {
                $options['dealer'] = 'Dealer: '.$billingCustomer->getName().' <'.($billingCustomer->getEmail() ?: '—').'>';
            }
        }

        return $options;
    }

    /**
     * @param  array<int, string>  $selectedKeys
     * @return array<int, string>
     */
    public static function resolveRecipientEmailsForInvoice(Invoice $invoice, array $selectedKeys, mixed $livewire = null): array
    {
        $emails = [];

        foreach ($selectedKeys as $key) {
            if ($key === 'customer') {
                $email = OrderCustomerMailRecipients::resolveCustomerContactEmailForModal($invoice, $livewire);
                if (EmailHelper::isValid($email)) {
                    $emails[] = $email;
                }

                continue;
            }

            if ($key === 'dealer') {
                $email = $invoice->billingCustomer?->getEmail();
                if (EmailHelper::isValid($email)) {
                    $emails[] = $email;
                }

                continue;
            }

            $emails = array_merge($emails, EmailRecipientResolver::resolveRecipients([$key]));
        }

        return array_values(array_unique($emails));
    }

    private static function getDefaultSubject($livewire): string
    {
        $record = $livewire->record;
        if (! $record instanceof Invoice) {
            return '';
        }

        return self::defaultModalSubjectFromTemplate();
    }

    public static function defaultModalSubjectFromTemplate(): string
    {
        $rawSubject = CustomInvoiceMail::getRawTemplateSubjectFromDatabase();

        return $rawSubject !== '' ? $rawSubject : 'Factuur [invoice_number]';
    }

    private static function getDefaultMessage($livewire): string
    {
        $record = $livewire->record;
        if (! $record instanceof Invoice) {
            return '';
        }

        return self::defaultModalMessageBodyFromTemplateForInvoice($record);
    }

    public static function defaultModalMessageBodyFromTemplateForInvoice(Invoice $invoice): string
    {
        $rawContent = CustomInvoiceMail::getRawTemplateContentFromDatabase();

        return $rawContent !== '' ? self::revertFirstNamesToPlaceholder($rawContent, $invoice) : '';
    }

    /**
     * Replaces [placeholder] values in subject/message after the invoice is saved (e.g. uid assigned).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyTemplateVariablesAfterPersist(Invoice $record, array $data): array
    {
        $variables = self::getTemplateVariables($record);
        $data['subject'] = self::replaceVariables((string) ($data['subject'] ?? ''), $variables);
        $data['message'] = self::replaceVariables((string) ($data['message'] ?? ''), $variables);

        return $data;
    }

    /**
     * Same keys as {@see CustomInvoiceMail::getTemplateVars()} plus template recipient vars; values substituted as [key].
     *
     * @return array<string, string>
     */
    private static function getTemplateVariables(Invoice $invoice): array
    {
        $mail = new CustomInvoiceMail($invoice);

        return array_merge(
            $mail->getTemplateRecipientVars(),
            $mail->getTemplateVars(),
        );
    }

    /**
     * @param  array<string, string>  $variables  flat keys (invoice_number, …), same rules as {@see \App\Mail\Traits\HasTemplate::parseTemplateString()}.
     */
    private static function replaceVariables(string $content, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $content = str_replace('['.$key.']', (string) $value, $content);
        }

        return $content;
    }

    public static function revertFirstNamesToPlaceholder(string $content, Invoice $invoice): string
    {
        $customerFirst = $invoice->customer?->getFirstName() ?? '';
        $companyFirst = $invoice->billingCustomer?->getFirstName() ?? '';

        if ($customerFirst !== '') {
            $content = str_replace($customerFirst, '[first_name]', $content);
        }
        if ($companyFirst !== '' && $companyFirst !== $customerFirst) {
            $content = str_replace($companyFirst, '[first_name]', $content);
        }

        return $content;
    }
}
