<?php

namespace App\Filament\Resources\CreditInvoiceResource\Actions;

use App\Filament\Resources\OrderResource\Support\OrderCustomerMailRecipients;
use App\Filament\Resources\PurchaseOrderResource\Actions\ApprovePurchaseOrderEmailAction;
use App\Helpers\EmailHelper;
use App\Mail\CreditInvoiceMail;
use App\Models\Order\CreditInvoice;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use App\Filament\Forms\Components\EmailRecipientSelect;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use App\Models\MailSenderProfile;

class SubmitCreditInvoiceEmailAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'submit_credit_invoice_email';
    }

    public function getLabel(): string
    {
        return 'Opslaan en versturen';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('heroicon-o-envelope')
            ->modalHeading('Creditfactuur verzenden')
            ->closeModalByEscaping(false)
            ->fillForm(fn ($livewire): array => self::defaultModalFormState($livewire))
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
                            ->afterStateHydrated(function ($state, $set, $livewire): void {
                                if (filled($state)) {
                                    return;
                                }

                                $record = $livewire->record;
                                if (! $record instanceof CreditInvoice) {
                                    return;
                                }

                                $content = self::defaultModalMessageBodyFromTemplateForCreditInvoice($record);
                                if ($content !== '') {
                                    $set('message', $content);
                                }
                            })
                            ->disableToolbarButtons(['attachFiles'])
                            ->columnSpanFull(),
                    ]),
            ])
            ->action(function (array $data, $livewire): void {
                $record = $livewire->record;
                if (! $record instanceof CreditInvoice) {
                    return;
                }

                $toEmails = self::resolveRecipientsForCreditInvoice($record, $livewire, $data['to'] ?? []);
                if (empty($toEmails)) {
                    Notification::make()
                        ->title('Minimaal één ontvanger (To) is verplicht')
                        ->danger()
                        ->send();

                    return;
                }

                $data['to'] = $toEmails;
                $data['cc'] = self::resolveRecipientsForCreditInvoice($record, $livewire, $data['cc'] ?? []);
                $data['bcc'] = self::resolveRecipientsForCreditInvoice($record, $livewire, $data['bcc'] ?? []);

                $livewire->submitWithEmail($data);
            })
            ->modalSubmitActionLabel('Verzenden')
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    /** @return array<int, string> */
    private static function getDefaultToRecipients($livewire): array
    {
        $record = $livewire->record;
        if (! $record instanceof CreditInvoice) {
            return [];
        }

        $customer = $record->customer;
        if ($customer !== null) {
            $email = OrderCustomerMailRecipients::resolveCustomerContactEmailForModal($record, $livewire);
            if (EmailHelper::isValid($email)) {
                return ['customer'];
            }
        }

        $billingCustomer = $record->billingCustomer;
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
        $options = ApprovePurchaseOrderEmailAction::getRecipientOptions();

        if ($record instanceof CreditInvoice) {
            $customerLabel = OrderCustomerMailRecipients::customerRecipientOptionLabel($record, $livewire);
            if ($customerLabel !== null) {
                $options['customer'] = $customerLabel;
            }

            $billingCustomer = $record->billingCustomer;
            if ($billingCustomer !== null) {
                $options['dealer'] = 'Dealer: ' . $billingCustomer->getName() . ' <' . ($billingCustomer->getEmail() ?: '—') . '>';
            }
        }

        return $options;
    }

    /**
     * @param array<int, string> $selectedKeys
     * @return array<int, string>
     */
    private static function resolveRecipientsForCreditInvoice(CreditInvoice $invoice, $livewire, array $selectedKeys): array
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

            $emails = array_merge($emails, ApprovePurchaseOrderEmailAction::resolveRecipients([$key]));
        }

        return array_values(array_unique($emails));
    }

    private static function getDefaultSubject($livewire): string
    {
        $record = $livewire->record;
        if (! $record instanceof CreditInvoice) {
            return '';
        }

        $rawSubject = CreditInvoiceMail::getRawTemplateSubjectFromDatabase();

        return $rawSubject !== '' ? $rawSubject : 'Creditfactuur [invoice_number]';
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultModalFormState($livewire): array
    {
        $record = $livewire->record;
        if (! $record instanceof CreditInvoice) {
            return [];
        }

        return [
            'from' => MailSenderProfile::modalFromDisplayLabel(),
            'to' => self::getDefaultToRecipients($livewire),
            'cc' => [],
            'bcc' => [],
            'subject' => self::getDefaultSubject($livewire),
            'message' => self::defaultModalMessageBodyFromTemplateForCreditInvoice($record),
        ];
    }

    public static function defaultModalMessageBodyFromTemplateForCreditInvoice(CreditInvoice $invoice): string
    {
        $rawContent = CreditInvoiceMail::getRawTemplateContentFromDatabase();

        return $rawContent !== '' ? self::revertFirstNamesToPlaceholder($rawContent, $invoice) : '';
    }

    /**
     * Replaces [placeholder] values in subject/message after the credit invoice is saved (e.g. uid assigned).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyTemplateVariablesAfterPersist(CreditInvoice $record, array $data): array
    {
        $variables = self::getTemplateVariables($record);
        $data['subject'] = self::replaceVariables((string) ($data['subject'] ?? ''), $variables);
        $data['message'] = self::replaceVariables((string) ($data['message'] ?? ''), $variables);

        return $data;
    }

    /**
     * Same keys as {@see CreditInvoiceMail::getTemplateVars()} plus template recipient vars.
     *
     * @return array<string, string>
     */
    private static function getTemplateVariables(CreditInvoice $invoice): array
    {
        $invoice->getOrCreatePublicDownloadUuid();
        $mail = new CreditInvoiceMail($invoice);

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

    private static function revertFirstNamesToPlaceholder(string $content, CreditInvoice $invoice): string
    {
        $customerFirst = $invoice->customer?->getFirstName() ?? '';
        $companyFirst = $invoice->billingCustomer?->getFirstName() ?? '';

        if ($customerFirst !== '') {
            $content = str_replace($customerFirst, '[customer_first_name]', $content);
            $content = str_replace($customerFirst, '[first_name]', $content);
        }
        if ($companyFirst !== '' && $companyFirst !== $customerFirst) {
            $content = str_replace($companyFirst, '[customer_first_name]', $content);
            $content = str_replace($companyFirst, '[first_name]', $content);
        }

        return $content;
    }
}
