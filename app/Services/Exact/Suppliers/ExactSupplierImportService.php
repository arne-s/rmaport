<?php

namespace App\Services\Exact\Suppliers;

use App\Models\Country;
use App\Models\ExactGLAccount;
use App\Models\ExactPaymentCondition;
use App\Models\ExactVATCode;
use App\Models\Supplier;
use App\Services\ExactOnlineService;
use Throwable;

class ExactSupplierImportService
{
    public function __construct(
        private ExactSuppliers $exactSuppliers,
        private ExactOnlineService $exactOnline,
    ) {}

    /**
     * @param  callable(int): void|null  $onProgressStart  Invoked once with total account count after fetch.
     * @param  callable(): void|null  $onProgressStep  Invoked after each account is handled (including skipped or failed).
     * @return array{processed: int, created: int, updated: int, failed: int}
     */
    public function import(
        ?callable $onProgressStart = null,
        ?callable $onProgressStep = null,
    ): array {
        $stats = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
        ];

        $accounts = $this->exactSuppliers->fetchAll(
            'IsSupplier eq true',
            ExactSuppliers::MAX_PAGE_SIZE,
            ExactSuppliers::ACCOUNT_SUPPLIER_SELECT
        );

        $onProgressStart?->__invoke(count($accounts));

        foreach ($accounts as $account) {
            if (! is_array($account) || ! isset($account['ID']) || ! is_string($account['ID'])) {
                $onProgressStep?->__invoke();

                continue;
            }

            try {
                $exactId = $account['ID'];
                $existing = Supplier::query()->where('exact_id', $exactId)->first();

                $attrs = $this->mapExactAccountToSupplierAttributes($account, $existing);

                Supplier::query()->updateOrCreate(
                    ['exact_id' => $exactId],
                    $attrs
                );

                $stats['processed']++;
                if ($existing === null) {
                    $stats['created']++;
                } else {
                    $stats['updated']++;
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->exactOnline->log(
                    'ExactSupplierImport: account '.($account['ID'] ?? '?').' '.$e->getMessage(),
                    'error'
                );
            }

            $onProgressStep?->__invoke();
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $account
     */
    public function mapExactAccountToSupplierAttributes(array $account, ?Supplier $existing = null): array
    {
        $code = isset($account['Code']) ? trim((string) $account['Code']) : null;
        $name = trim((string) ($account['Name'] ?? ''));
        if ($name === '') {
            $name = 'Leverancier '.($code ?? $account['ID']);
        }

        $exactId = (string) $account['ID'];
        $name = $this->ensureUniqueSupplierName($name, $exactId, $code);

        $phone = $this->nullableString($account['Phone'] ?? null);
        $ext = $this->nullableString($account['PhoneExtension'] ?? null);
        if ($phone !== null && $ext !== null) {
            $phone = $phone.' '.$ext;
        } elseif ($phone === null && $ext !== null) {
            $phone = $ext;
        }

        $paymentCode = $this->nullableString($account['PaymentConditionPurchase'] ?? null);
        $exactPaymentConditionId = null;
        if ($paymentCode !== null) {
            $exactPaymentConditionId = ExactPaymentCondition::query()->where('code', $paymentCode)->value('id');
            if ($exactPaymentConditionId === null) {
                $this->exactOnline->log(
                    'ExactSupplierImport: unknown PaymentConditionPurchase code '.$paymentCode,
                    'warning'
                );
            }
        }

        $glGuid = $account['GLAccountPurchase'] ?? null;
        $glGuid = is_string($glGuid) && $glGuid !== '' ? $glGuid : null;
        $exactGlAccountId = null;
        if ($glGuid !== null) {
            $exactGlAccountId = ExactGLAccount::query()
                ->whereRaw('LOWER(guid) = ?', [strtolower($glGuid)])
                ->value('id');
            if ($exactGlAccountId === null) {
                $this->exactOnline->log(
                    'ExactSupplierImport: unknown GLAccountPurchase guid '.$glGuid,
                    'warning'
                );
            }
        }

        $purchaseVatCode = $this->nullableString($account['PurchaseVATCode'] ?? null);
        $exactVatCodeId = null;
        if ($purchaseVatCode !== null) {
            $exactVatCodeId = ExactVATCode::query()
                ->where('code', $purchaseVatCode)
                ->whereIn('vat_transaction_type', [
                    ExactVATCode::VAT_TRANSACTION_TYPES['purchase'],
                    ExactVATCode::VAT_TRANSACTION_TYPES['both'],
                ])
                ->value('id');
            if ($exactVatCodeId === null) {
                $exactVatCodeId = ExactVATCode::query()
                    ->where('code', $purchaseVatCode)
                    ->value('id');
            }
            if ($exactVatCodeId === null) {
                $this->exactOnline->log(
                    'ExactSupplierImport: unknown PurchaseVATCode '.$purchaseVatCode,
                    'warning'
                );
            }
        }

        $countryId = $this->resolveCountryId($this->nullableString($account['Country'] ?? null));

        $blocked = $account['Blocked'] ?? false;
        $isActive = ! $this->toBool($blocked);

        $adminExtras = [];
        $searchCode = $this->nullableString($account['SearchCode'] ?? null);
        if ($searchCode !== null) {
            $adminExtras['exact_search_code'] = $searchCode;
        }
        $remarks = $this->nullableString($account['Remarks'] ?? null);
        if ($remarks !== null) {
            $adminExtras['exact_remarks'] = $remarks;
        }

        $mergedAdmin = array_merge(
            is_array($existing?->admin_fields) ? $existing->admin_fields : [],
            $adminExtras
        );

        $email = $this->firstNonEmptyEmailFromAccount($account);

        $reference = $this->normalizeCreditorNumberFromExactCode($account['Code'] ?? null);
        if ($reference === null) {
            $reference = $existing?->reference;
        }

        $attrs = [
            'name' => $name,
            'exact_code' => $code,
            'reference' => $reference,
            'street' => $this->nullableString($account['AddressLine1'] ?? null),
            'house_number' => null,
            'postcode' => $this->nullableString($account['Postcode'] ?? null),
            'city' => $this->nullableString($account['City'] ?? null),
            'country_id' => $countryId,
            'kvk_number' => $this->nullableString($account['ChamberOfCommerce'] ?? null),
            'vat_number' => $this->nullableString($account['VATNumber'] ?? null),
            'email' => $email,
            'email_supplier' => $email,
            'phone_number' => $phone,
            'exact_payment_condition_id' => $exactPaymentConditionId,
            'exact_gl_account_id' => $exactGlAccountId,
            'exact_vat_code_id' => $exactVatCodeId,
            'is_active' => $isActive,
            'sync_with_exact' => true,
            'last_synced_at' => now(),
        ];

        if ($mergedAdmin !== []) {
            $attrs['admin_fields'] = $mergedAdmin;
        }

        return $attrs;
    }

    private function ensureUniqueSupplierName(string $name, string $exactId, ?string $exactCode): string
    {
        $conflict = Supplier::query()
            ->where('name', $name)
            ->where(function ($q) use ($exactId): void {
                $q->whereNull('exact_id')
                    ->orWhere('exact_id', '<>', $exactId);
            })
            ->exists();

        if (! $conflict) {
            return $name;
        }

        $suffix = $exactCode !== null && $exactCode !== '' ? $exactCode : substr($exactId, 0, 8);

        return $name.' ['.$suffix.']';
    }

    private function resolveCountryId(?string $countryCode): ?int
    {
        if ($countryCode === null || $countryCode === '') {
            return null;
        }

        $code = strtoupper(trim($countryCode));

        return Country::query()->where('code', $code)->value('id');
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN)
            || $value === 1
            || $value === '1';
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function firstNonEmptyEmailFromAccount(array $account): ?string
    {
        foreach (['InvoiceEmail', 'Email', 'VisitEmail'] as $key) {
            $v = $this->nullableString($account[$key] ?? null);
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    private function normalizeCreditorNumberFromExactCode(mixed $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', '', (string) $code);

        return $normalized === '' ? null : $normalized;
    }
}
