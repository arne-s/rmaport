<?php

namespace App\Models;

use App\Enums\PaymentTerms;
use App\Enums\RecurringInvoiceFrequency;
use App\Models\Customer;
use App\Models\Order\Invoice;
use App\Support\InvoiceReminderSettings;
use App\Support\RecurringInvoiceSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * @property int $id
 * @property string|null $name
 * @property string|null $email_subject
 * @property string|null $email_text
 * @property array<int, string>|null $email_cc
 * @property array<int, string>|null $email_bcc
 * @property bool $is_active
 * @property RecurringInvoiceFrequency $frequency
 * @property int $start_day
 * @property \Illuminate\Support\Carbon $next_run_date
 * @property \Illuminate\Support\Carbon|null $last_issued_at
 * @property string|null $reference
 * @property string|null $comments
 * @property PaymentTerms $payment_terms
 * @property string $exact_vat_code
 * @property string $exact_payment_condition
 * @property int|null $billing_customer_id
 * @property int|null $author_id
 * @property array<string, mixed>|null $additional
 * @property-read Customer|null $billingCustomer
 * @property-read User|null $author
 */
class RecurringInvoice extends Model
{
    protected static function booted(): void
    {
        static::saving(function (RecurringInvoice $model): void {
            $model->payment_terms = PaymentTerms::Postpay;
        });
    }

    protected $fillable = [
        'name',
        'email_subject',
        'email_text',
        'email_cc',
        'email_bcc',
        'is_active',
        'frequency',
        'start_day',
        'next_run_date',
        'last_issued_at',
        'reference',
        'comments',
        'payment_terms',
        'exact_vat_code',
        'exact_payment_condition',
        'billing_customer_id',
        'author_id',
        'billing_address_type',
        'additional',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'frequency' => RecurringInvoiceFrequency::class,
            'start_day' => 'integer',
            'next_run_date' => 'date',
            'last_issued_at' => 'datetime',
            'payment_terms' => PaymentTerms::class,
            'additional' => 'array',
            'email_cc' => 'array',
            'email_bcc' => 'array',
        ];
    }

    /**
     * @return HasMany<RecurringInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RecurringInvoiceLine::class)->orderBy('sort');
    }

    /**
     * Invoices issued from this recurring template.
     *
     * @return HasMany<Invoice, $this>
     */
    public function issuedInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'recurring_order_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function billingCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'billing_customer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }

    public function getAuthorId(): ?int
    {
        return $this->author_id !== null ? (int) $this->author_id : null;
    }

    public function setAuthorId(?int $value): void
    {
        $this->author_id = $value;
    }

    public function getName(): ?string
    {
        $n = $this->name;

        return is_string($n) && trim($n) !== '' ? $n : null;
    }

    public function setName(?string $value): void
    {
        $this->name = $value !== null && trim($value) !== '' ? trim($value) : null;
    }

    public function getEmailSubject(): ?string
    {
        $v = $this->email_subject;

        return is_string($v) && trim($v) !== '' ? $v : null;
    }

    public function setEmailSubject(?string $value): void
    {
        $this->email_subject = $value !== null && trim($value) !== '' ? trim($value) : null;
    }

    public function getEmailText(): ?string
    {
        $v = $this->email_text;

        return is_string($v) && trim($v) !== '' ? $v : null;
    }

    public function setEmailText(?string $value): void
    {
        $this->email_text = $value !== null && trim($value) !== '' ? $value : null;
    }

    /**
     * @return array<int, string>
     */
    public function getEmailCcKeys(): array
    {
        $raw = $this->email_cc;
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key) {
            if (is_string($key) && $key !== '') {
                $out[] = $key;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<int, string>  $keys
     */
    public function setEmailCcKeys(array $keys): void
    {
        $this->email_cc = $keys === [] ? null : $keys;
    }

    /**
     * @return array<int, string>
     */
    public function getEmailBccKeys(): array
    {
        $raw = $this->email_bcc;
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key) {
            if (is_string($key) && $key !== '') {
                $out[] = $key;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<int, string>  $keys
     */
    public function setEmailBccKeys(array $keys): void
    {
        $this->email_bcc = $keys === [] ? null : $keys;
    }

    public function getIsActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function setIsActive(bool $value): void
    {
        $this->is_active = $value;
    }

    public function getBillingCustomerId(): ?int
    {
        return $this->billing_customer_id !== null ? (int) $this->billing_customer_id : null;
    }

    public function setBillingCustomerId(?int $value): void
    {
        $this->billing_customer_id = $value;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $value): void
    {
        $this->reference = $value;
    }

    public function getComments(): ?string
    {
        return $this->comments;
    }

    public function setComments(?string $value): void
    {
        $this->comments = $value;
    }

    public function getExactVatCode(): string
    {
        return (string) $this->exact_vat_code;
    }

    public function setExactVatCode(string $value): void
    {
        $this->exact_vat_code = $value;
    }

    public function getExactPaymentCondition(): string
    {
        return (string) $this->exact_payment_condition;
    }

    public function setExactPaymentCondition(string $value): void
    {
        $this->exact_payment_condition = $value;
    }

    /**
     * Resolve Exact payment condition for billing: payment terms override, else customer code, else settings.
     */
    public function resolveExactPaymentConditionCodeForBillingContext(?Customer $invoiceCustomer = null): string
    {
        $terms = $this->payment_terms instanceof PaymentTerms
            ? $this->payment_terms
            : PaymentTerms::tryFrom((string) ($this->payment_terms ?? '')) ?? PaymentTerms::Postpay;

        $forced = PaymentTerms::forcedExactPaymentConditionCodeFor($terms);
        if ($forced !== null) {
            return $forced;
        }

        $invoiceCustomer ??= $this->billingCustomer;
        if ($invoiceCustomer !== null) {
            $customerCode = $invoiceCustomer->getExactPaymentCondition();
            if (is_string($customerCode) && $customerCode !== '') {
                return $customerCode;
            }
        }

        return $this->resolveDefaultExactPaymentConditionCode($invoiceCustomer);
    }

    protected function resolveDefaultExactPaymentConditionCode(?Customer $invoiceCustomer = null): string
    {
        $segment = InvoiceReminderSettings::resolveSegmentKey($invoiceCustomer ?? $this->billingCustomer);

        $code = Setting::get("exact_payment_condition_by_type.{$segment}");

        if (is_string($code) && $code !== '') {
            return $code;
        }

        return ExactPaymentCondition::DEFAULT_PAYMENT_CONDITION_CODE;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAdditional(): array
    {
        return $this->additional ?? [];
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    public function setAdditional(?array $value): void
    {
        $this->additional = $value;
    }

    public function getStartDay(): int
    {
        return (int) $this->start_day;
    }

    public function setStartDay(int $value): void
    {
        $this->start_day = $value;
    }

    public function getFrequency(): RecurringInvoiceFrequency
    {
        $f = $this->frequency;
        if ($f instanceof RecurringInvoiceFrequency) {
            return $f;
        }

        return RecurringInvoiceFrequency::from((string) $f);
    }

    public function setFrequency(RecurringInvoiceFrequency $value): void
    {
        $this->frequency = $value;
    }

    public function getNextRunDate(): Carbon
    {
        $d = $this->next_run_date;

        return $d instanceof Carbon ? $d : Carbon::parse((string) $d);
    }

    public function setNextRunDate(Carbon|string $value): void
    {
        $this->next_run_date = $value instanceof Carbon ? $value->toDateString() : (string) $value;
    }

    public function getLastIssuedAt(): ?Carbon
    {
        $v = $this->last_issued_at;

        return $v instanceof Carbon ? $v : ($v !== null ? Carbon::parse((string) $v) : null);
    }

    public function setLastIssuedAt(?Carbon $value): void
    {
        $this->last_issued_at = $value;
    }

    public static function createDraft(int $billingCustomerId): self
    {
        $customer = Customer::query()->find($billingCustomerId);

        $startDay = 1;
        $nextRun = RecurringInvoiceSchedule::firstNextRunDateOnOrAfter(now(), $startDay);

        $exactVat = (string) ($customer?->getExactVatCode() ?? '');
        $exactPayment = (string) ($customer?->getExactPaymentCondition() ?? '');

        /** @var self $recurring */
        $recurring = self::query()->create([
            'is_active'                => false,
            'frequency'                => RecurringInvoiceFrequency::Month,
            'start_day'                => $startDay,
            'next_run_date'            => $nextRun->toDateString(),
            'reference'                => null,
            'comments'                 => null,
            'payment_terms'            => PaymentTerms::Postpay,
            'exact_vat_code'           => $exactVat,
            'exact_payment_condition'  => $exactPayment,
            'billing_customer_id'      => $billingCustomerId,
            'author_id'                => Auth::id(),
            'billing_address_type'     => 'customer-' . $billingCustomerId,
            'additional' => [
                'exact_vat_code'          => $exactVat,
                'exact_payment_condition' => $exactPayment,
            ],
        ]);

        return $recurring;
    }
}
