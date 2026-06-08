<?php

namespace App\Actions;

use App\Helpers\EmailHelper;
use App\Mail\CustomInvoiceMail;
use App\Mail\InvoiceMail;
use App\Models\Order\BaseOrder;
use App\Models\Order\Invoice;
use App\Filament\Resources\OrderResource\Support\OrderCustomerMailRecipients;
use App\Services\MicrosoftMailDispatcher;
use Illuminate\Mail\Mailable;
use InvalidArgumentException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendInvoiceMailAction
{
    public function __construct(
        protected Invoice $invoice,
        protected MicrosoftMailDispatcher $dispatcher,
    ) {
    }

    /**
     * Slotfactuur (en vergelijkbare mails): als factuurklant afwijkt van de aanvraagklant en een geldig
     * factuur-e-mailadres heeft, is de primaire ontvanger de factuurklant en de eindklant CC.
     */
    public static function invoiceMailShouldAddressBillingCustomerFirst(BaseOrder $order): bool
    {
        $customerId = $order->getCustomerId();
        $billingId = $order->billing_customer_id;
        if ($customerId === null || $billingId === null) {
            return false;
        }
        if ((int) $customerId === (int) $billingId) {
            return false;
        }
        $order->loadMissing('billingCustomer');

        $billingEmail = $order->billingCustomer?->getEmail();

        return is_string($billingEmail) && $billingEmail !== '' && EmailHelper::isValid($billingEmail);
    }

    /**
     * @return array{name: string, email: string}|null
     */
    public static function billingMailRecipientNameAndEmail(BaseOrder $order): ?array
    {
        $order->loadMissing('billingCustomer');
        $billing = $order->billingCustomer;
        if ($billing === null) {
            return null;
        }
        $email = $billing->getEmail();
        if (! is_string($email) || $email === '' || ! EmailHelper::isValid($email)) {
            return null;
        }

        return ['name' => (string) ($billing->getName() ?? ''), 'email' => $email];
    }

    /**
     * To/CC voor factuur-e-mail wanneer niet via modal/Microsoft expliciet gezet (queue / legacy Mail::send).
     *
     * @return array{to: list<array{name: string, email: string}>, cc: list<array{name: string, email: string}>}
     */
    public static function buildInvoiceMailToCcArrays(BaseOrder $order): array
    {
        $order->loadMissing(['customer', 'billingCustomer']);
        $to = [];
        $cc = [];

        if (self::invoiceMailShouldAddressBillingCustomerFirst($order)) {
            $b = self::billingMailRecipientNameAndEmail($order);
            if ($b !== null) {
                $to[] = $b;
            }
            $c = OrderCustomerMailRecipients::customerMailRecipientNameAndEmail($order);
            if ($c !== null && $b !== null && ! EmailHelper::emailsEqualIgnoringCase($c['email'], $b['email'])) {
                $cc[] = $c;
            }

            return ['to' => $to, 'cc' => $cc];
        }

        $customer = $order->customer;
        $billingCustomer = $order->billingCustomer;

        if ($customer !== null) {
            $pair = OrderCustomerMailRecipients::customerMailRecipientNameAndEmail($order);
            if ($pair !== null) {
                $to[] = $pair;
            }
            if ($billingCustomer !== null) {
                $dealerEmail = $billingCustomer->getEmail();
                if (is_string($dealerEmail) && $dealerEmail !== '') {
                    $custEmail = $pair['email'] ?? null;
                    if ($custEmail === null || ! EmailHelper::emailsEqualIgnoringCase($custEmail, $dealerEmail)) {
                        $cc[] = [
                            'name' => (string) ($billingCustomer->getName() ?? ''),
                            'email' => $dealerEmail,
                        ];
                    }
                }
            }
        } elseif ($billingCustomer !== null) {
            $dealerEmail = $billingCustomer->getEmail();
            if (is_string($dealerEmail) && $dealerEmail !== '') {
                $to[] = [
                    'name' => (string) ($billingCustomer->getName() ?? ''),
                    'email' => $dealerEmail,
                ];
            }
        }

        return ['to' => $to, 'cc' => $cc];
    }

    public static function applyInvoiceMailToCcToMailable(Mailable $mail, BaseOrder $order): void
    {
        $resolved = self::buildInvoiceMailToCcArrays($order);
        foreach ($resolved['to'] as $r) {
            $mail->to($r['email'], $r['name']);
        }
        foreach ($resolved['cc'] as $r) {
            $mail->cc($r['email'], $r['name']);
        }
    }

    /**
     * Send from the Filament modal with chosen recipients and subject/body.
     *
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     *
     * @throws \Throwable
     */
    public function executeWithModalEmail(
        array $to,
        array $cc,
        array $bcc,
        string $subject,
        string $message,
    ): void {
        $toEmails = array_values(array_filter(
            $to,
            fn (mixed $email): bool => is_string($email) && EmailHelper::isValid($email),
        ));
        if ($toEmails === []) {
            throw new InvalidArgumentException(
                'Geen geldige ontvanger (To) voor factuur-mail (invoice id '.$this->invoice->getId().').'
            );
        }

        $ccEmails = self::filterCcEmailsNotInTo(
            array_values(array_filter(
                $cc,
                fn (mixed $email): bool => is_string($email) && EmailHelper::isValid($email),
            )),
            $toEmails,
        );
        $bccEmails = array_values(array_filter(
            $bcc,
            fn (mixed $email): bool => is_string($email) && EmailHelper::isValid($email),
        ));

        $this->invoice->getOrCreatePublicDownloadUuid();

        $mailable = new CustomInvoiceMail(
            $this->invoice,
            subjectOverride: $subject !== '' ? $subject : null,
            messageOverride: $message !== '' ? $message : null,
            recipientsResolvedByMailFacade: true,
        );

        $this->dispatcher->dispatch($mailable, $toEmails, $ccEmails, $bccEmails);
    }

    /**
     * @return array<int, string>
     */
    public static function defaultExecuteStyleCustomerCcWhenBillingIsTo(BaseOrder $order): array
    {
        $email = OrderCustomerMailRecipients::resolveCustomerContactEmailForModal($order, null);
        if (! is_string($email) || ! EmailHelper::isValid($email)) {
            return [];
        }
        $billingEmail = $order->billingCustomer?->getEmail();
        if (EmailHelper::emailsEqualIgnoringCase($email, $billingEmail)) {
            return [];
        }

        return [$email];
    }

    /**
     * Same implicit CC as {@see execute()}: company (dealer) email when the customer is the To party
     * (customer with non-empty email, company with non-empty email).
     *
     * @return array<int, string>
     */
    public static function defaultExecuteStyleCcEmailStrings(BaseOrder $order): array
    {
        $customer = $order->customer;
        if ($customer === null) {
            return [];
        }

        $customerEmail = OrderCustomerMailRecipients::resolveCustomerContactEmailForModal($order, null);
        if (! is_string($customerEmail) || $customerEmail === '' || ! EmailHelper::isValid($customerEmail)) {
            return [];
        }

        $billingCustomer = $order->billingCustomer;
        if ($billingCustomer === null) {
            return [];
        }

        $dealerEmail = $billingCustomer->getEmail();
        if (! is_string($dealerEmail) || $dealerEmail === '') {
            return [];
        }

        return [$dealerEmail];
    }

    /**
     * Merges implicit CC: billing party when To is customer; end customer when To is billing (afwijkende factuurklant).
     *
     * @param  array<int, string>  $toKeys
     * @param  array<int, string>  $ccEmails
     * @return array<int, string>
     */
    public static function mergeExecuteStyleDealerCcWhenCustomerOnTo(Invoice $invoice, array $toKeys, array $ccEmails): array
    {
        if ($toKeys === ['customer']) {
            return self::uniqueEmailStrings(array_merge(
                $ccEmails,
                self::defaultExecuteStyleCcEmailStrings($invoice),
            ));
        }
        if ($toKeys === ['dealer'] && self::invoiceMailShouldAddressBillingCustomerFirst($invoice)) {
            return self::uniqueEmailStrings(array_merge(
                $ccEmails,
                self::defaultExecuteStyleCustomerCcWhenBillingIsTo($invoice),
            ));
        }

        return self::uniqueEmailStrings($ccEmails);
    }

    /**
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $to
     * @return array<int, string>
     */
    public static function filterCcEmailsNotInTo(array $cc, array $to): array
    {
        return array_values(array_filter(
            $cc,
            fn (string $ccEmail): bool => ! array_any(
                $to,
                fn (string $toEmail): bool => EmailHelper::emailsEqualIgnoringCase($ccEmail, $toEmail),
            ),
        ));
    }

    /**
     * @param  array<int, string>  $emails
     * @return array<int, string>
     */
    private static function uniqueEmailStrings(array $emails): array
    {
        $seen = [];
        $out = [];
        foreach ($emails as $email) {
            if (! is_string($email) || $email === '') {
                continue;
            }
            $key = strtolower($email);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $email;
        }

        return $out;
    }

    /**
     * @throws \Throwable
     */
    public function execute(): void
    {
        $resolved = self::buildInvoiceMailToCcArrays($this->invoice);

        if ($resolved['to'] === []) {
            Log::warning('SendInvoiceMailAction: geen ontvangers voor slotfactuur-mail', [
                'invoice_id' => $this->invoice->getId(),
                'customer_id' => $this->invoice->getCustomerId(),
                'billing_customer_id' => $this->invoice->billing_customer_id,
            ]);
            throw new InvalidArgumentException(
                'Geen e-mailontvanger voor slotfactuur (invoice id ' . $this->invoice->getId() . ').'
            );
        }

        $this->invoice->getOrCreatePublicDownloadUuid();

        $mailable = new InvoiceMail($this->invoice);
        $subject = $mailable->getTemplateSubject();

        Mail::send($mailable);

        app(OrderMailEventLogger::class)->logSent(
            $this->invoice,
            InvoiceMail::class,
            $resolved['to'],
            $resolved['cc'],
            subject: $subject,
        );
    }
}
