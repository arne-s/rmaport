<?php

namespace App\Filament\Resources\CustomerResource\Widgets;

use App\Enums\CompanyDocumentsTabScope;
use App\Enums\OrderGeneralStatus;
use App\Enums\OrderSubtype;
use App\Enums\OrderType;
use App\Filament\Forms\Components\ToggleFilter;
use App\Filament\Resources\OrderResource\Support\FinancialDocumentMailAttachments;
use App\Filament\Tables\Columns\DocumentStatusColumn;
use App\Filament\Tables\Columns\ReportingOrderNumberColumn;
use App\Models\CompanyDocumentTableRow;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\ExactDocument;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Support\NavigationLink;
use Filament\Forms\Components\CheckboxList;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class CompanyDocumentsWidget extends TableWidget
{
    protected static ?string $heading = '';

    protected static ?string $model = Customer::class;

    public ?Model $record = null;

    /** Injected from {@see CustomerResource} tab via {@see \Filament\Schemas\Components\Livewire::make()} data. */
    public ?string $documentsScope = null;

    public static function canView(): bool
    {
        return true;
    }

    protected function scope(): CompanyDocumentsTabScope
    {
        if ($this->documentsScope !== null && $this->documentsScope !== '') {
            return CompanyDocumentsTabScope::tryFrom($this->documentsScope)
                ?? CompanyDocumentsTabScope::forCustomer($this->record instanceof Customer ? $this->record : null);
        }

        if ($this->record instanceof Customer) {
            return CompanyDocumentsTabScope::forCustomer($this->record);
        }

        return CompanyDocumentsTabScope::InvoiceOnly;
    }

    /**
     * @return list<string>
     */
    private static function invoiceDocumentTypes(): array
    {
        return [
            OrderType::DepositInvoice->value,
            OrderType::Invoice->value,
            OrderType::CreditInvoice->value,
        ];
    }

    /**
     * @return list<string>
     */
    private static function nonFinancialDocumentTypes(): array
    {
        return [
            OrderType::Quote->value,
            OrderType::Order->value,
        ];
    }

    protected function baseOrdersQuery(): Builder
    {
        return BaseOrder::query()
            ->leftJoin('companies', 'orders.company_id', '=', 'companies.id')
            ->leftJoin('orders as main_orders', 'orders.main_id', '=', 'main_orders.id')
            ->whereNotNull('orders.sent_at')
            ->whereNotIn('orders.status', [
                OrderGeneralStatus::Initial->value,
                OrderGeneralStatus::Draft->value,
            ]);
    }

    protected function baseOrdersQueryForCustomer(): Builder
    {
        return BaseOrder::query()
            ->leftJoin('orders as main_orders', 'orders.main_id', '=', 'main_orders.id')
            ->whereNotNull('orders.sent_at')
            ->whereNotIn('orders.status', [
                OrderGeneralStatus::Initial->value,
                OrderGeneralStatus::Draft->value,
            ]);
    }

    /**
     * @return list<int>
     */
    private function dealerCompanyIdsForShippingHub(int $shippingCompanyId): array
    {
        return Company::query()
            ->where('shipping_company_id', $shippingCompanyId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    protected function getTableQuery(): Builder
    {
        if ($this->record instanceof Customer) {
            $customerId = (int) $this->record->id;
            $ordersQuery = $this->baseOrdersQueryForCustomer()
                ->where('orders.customer_id', $customerId)
                ->whereIn('orders.type', [
                    OrderType::Quote->value,
                    OrderType::Order->value,
                    OrderType::DepositInvoice->value,
                    OrderType::Invoice->value,
                    OrderType::CreditInvoice->value,
                ])
                ->selectRaw(
                    "CONCAT('order-', orders.id) as id, 'order' as source_type, orders.id as source_id, orders.sent_at, orders.type, orders.caption, orders.uid, orders.rev, orders.subtype, orders.main_id, main_orders.uid as main_uid, main_orders.reference_internal as main_reference_internal, NULL as company_id, orders.customer_id, NULL as company_name, orders.status, orders.is_cancelled, NULL as file_name, NULL as serial_number_value, orders.created_at"
                );

            $deliveryMediaQuery = $this->getCustomerDeliveryDocumentsForUnion($customerId);
            $deliveryNotePdfQuery = $this->getCustomerDeliveryNotePdfsForUnion($customerId);
            $exactDocumentsQuery = $this->getExactDocumentsForUnion($customerId);

            return CompanyDocumentTableRow::query()->fromSub(
                $ordersQuery->unionAll($deliveryMediaQuery)->unionAll($deliveryNotePdfQuery)->unionAll($exactDocumentsQuery),
                'company_documents'
            );
        }

        if (! $this->record instanceof Company) {
            return CompanyDocumentTableRow::query()->fromSub(
                BaseOrder::query()->whereRaw('0 = 1')->selectRaw(
                    "CONCAT('order-', orders.id) as id, 'order' as source_type, orders.id as source_id, orders.sent_at, orders.type, orders.caption, orders.uid, orders.rev, orders.subtype, orders.main_id, NULL as main_uid, NULL as main_reference_internal, NULL as company_id, orders.customer_id, NULL as company_name, orders.status, orders.is_cancelled, NULL as file_name, NULL as serial_number_value, orders.created_at"
                ),
                'company_documents'
            );
        }

        $companyId = (int) $this->record->id;
        $scope = $this->scope();

        $ordersQuery = match ($scope) {
            CompanyDocumentsTabScope::InvoiceOnly => $this->baseOrdersQuery()
                ->where('orders.company_id', $companyId)
                ->whereIn('orders.type', self::invoiceDocumentTypes()),

            CompanyDocumentsTabScope::ShippingOnly => $this->shippingHubDocumentsQuery($companyId),

            CompanyDocumentsTabScope::AllGlobal => $this->baseOrdersQuery()
                ->where('orders.company_id', $companyId)
                ->where(function (Builder $q): void {
                    $q->whereIn('orders.type', self::invoiceDocumentTypes())
                        ->orWhereIn('orders.type', self::nonFinancialDocumentTypes());
                }),
        };

        $ordersQuery->selectRaw(
            "CONCAT('order-', orders.id) as id, 'order' as source_type, orders.id as source_id, orders.sent_at, orders.type, orders.caption, orders.uid, orders.rev, orders.subtype, orders.main_id, main_orders.uid as main_uid, main_orders.reference_internal as main_reference_internal, orders.company_id, orders.customer_id, companies.name as company_name, orders.status, orders.is_cancelled, NULL as file_name, NULL as serial_number_value, orders.created_at"
        );

        // For Shipping and AllGlobal scopes, add packing slips via UNION
        if (in_array($scope, [CompanyDocumentsTabScope::ShippingOnly, CompanyDocumentsTabScope::AllGlobal], true)) {
            $packingSlipsQuery = $this->getPackingSlipsForUnion($companyId, $scope);
            return CompanyDocumentTableRow::query()->fromSub(
                $ordersQuery->unionAll($packingSlipsQuery),
                'company_documents'
            );
        }

        return CompanyDocumentTableRow::query()->fromSub($ordersQuery, 'company_documents');
    }

    /**
     * Exact-imported documents for a customer, formatted for UNION with the orders query.
     */
    protected function getExactDocumentsForUnion(int $customerId): Builder
    {
        $morphType = (new ExactDocument())->getMorphClass();

        return ExactDocument::query()
            ->where('exact_documents.customer_id', $customerId)
            ->leftJoin('media', function ($join) use ($morphType): void {
                $join->on('media.model_id', '=', 'exact_documents.id')
                    ->where('media.model_type', '=', $morphType)
                    ->where('media.collection_name', '=', 'pdf');
            })
            ->selectRaw(
                "CONCAT('exact-', exact_documents.id) as id,
                'exact_document' as source_type,
                exact_documents.id as source_id,
                exact_documents.document_date as sent_at,
                exact_documents.mapped_type as type,
                NULL as caption,
                NULL as uid,
                NULL as rev,
                NULL as subtype,
                NULL as main_id,
                NULL as main_uid,
                NULL as main_reference_internal,
                NULL as company_id,
                exact_documents.customer_id,
                NULL as company_name,
                NULL as status,
                NULL as is_cancelled,
                COALESCE(media.file_name, exact_documents.exact_subject) as file_name,
                NULL as serial_number_value,
                exact_documents.created_at"
            );
    }

    /**
     * Get packing slips query formatted for UNION with orders query.
     * Returns Media query with same columns as orders query.
     */
    protected function getPackingSlipsForUnion(int $companyId, CompanyDocumentsTabScope $scope): Builder
    {
        $mainIds = match ($scope) {
            CompanyDocumentsTabScope::ShippingOnly => $this->mainIdsForShippingHub($companyId),
            CompanyDocumentsTabScope::AllGlobal => $this->mainIdsForInvoiceDealer($companyId),
            default => [],
        };

        if ($mainIds === []) {
            return Media::query()
                ->whereRaw('0 = 1')
                ->selectRaw(
                    "CONCAT('media-', id) as id, 'media' as source_type, id as source_id, created_at as sent_at, 'packing_slip' as type, NULL as caption, NULL as uid, NULL as rev, NULL as subtype, NULL as main_id, NULL as main_uid, NULL as main_reference_internal, NULL as company_id, NULL as customer_id, NULL as company_name, NULL as status, NULL as is_cancelled, file_name, NULL as serial_number_value, created_at"
                );
        }

        $morphType = (new Main())->getMorphClass();

        // Join with orders to get order data (subtype, company_id, customer_id)
        // Join with main_orders to get main.uid
        // GROUP BY media fields to ensure one row per packing slip even if multiple orders exist for the main
        return Media::query()
            ->where('media.model_type', $morphType)
            ->whereIn('media.model_id', $mainIds)
            ->where('media.collection_name', 'delivery_documents')
            ->where('media.file_name', 'like', 'afleverbon-%')
            ->leftJoin('orders', function ($join) {
                $join->on('media.model_id', '=', 'orders.main_id')
                    ->where('orders.type', '=', OrderType::Order->value);
            })
            ->leftJoin('orders as main_orders', 'media.model_id', '=', 'main_orders.id')
            ->leftJoin('companies', 'orders.company_id', '=', 'companies.id')
            ->groupBy('media.id', 'media.model_id', 'media.file_name', 'media.created_at')
            ->selectRaw(
                "CONCAT('media-', media.id) as id,
                'media' as source_type,
                media.id as source_id,
                media.created_at as sent_at,
                'packing_slip' as type,
                NULL as caption,
                REPLACE(REPLACE(media.file_name, 'afleverbon-', ''), '.pdf', '') as uid,
                NULL as rev,
                MAX(orders.subtype) as subtype,
                media.model_id as main_id,
                MAX(main_orders.uid) as main_uid,
                MAX(main_orders.reference_internal) as main_reference_internal,
                MAX(orders.company_id) as company_id,
                MAX(orders.customer_id) as customer_id,
                MAX(companies.name) as company_name,
                NULL as status,
                NULL as is_cancelled,
                media.file_name,
                NULL as serial_number_value,
                media.created_at"
            );
    }

    /**
     * Delivery PDFs on {@see Main} (afleverbon, PostNL labels) for unions on the customer documents tab.
     *
     * @return list<int>
     */
    private function mainIdsLinkedToCustomer(int $customerId): array
    {
        return Main::query()
            ->withoutGlobalScopes()
            ->where('type', OrderType::Main->value)
            ->where(function (Builder $q) use ($customerId): void {
                $q->where('customer_id', $customerId)
                    ->orWhere('billing_customer_id', $customerId)
                    ->orWhere('shipping_customer_id', $customerId);
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Media rows on mains linked to this customer (end, billing, or shipping), same column shape as {@see self::getPackingSlipsForUnion()}.
     */
    protected function getCustomerDeliveryDocumentsForUnion(int $customerId): Builder
    {
        $mainIds = $this->mainIdsLinkedToCustomer($customerId);

        if ($mainIds === []) {
            return Media::query()
                ->whereRaw('0 = 1')
                ->selectRaw(
                    "CONCAT('media-', id) as id, 'media' as source_type, id as source_id, created_at as sent_at, 'packing_slip' as type, NULL as caption, NULL as uid, NULL as rev, NULL as subtype, NULL as main_id, NULL as main_uid, NULL as main_reference_internal, NULL as company_id, NULL as customer_id, NULL as company_name, NULL as status, NULL as is_cancelled, file_name, NULL as serial_number_value, created_at"
                );
        }

        $morphType = (new Main())->getMorphClass();

        return Media::query()
            ->where('media.model_type', $morphType)
            ->whereIn('media.model_id', $mainIds)
            ->where('media.collection_name', 'delivery_documents')
            ->where(function (Builder $q): void {
                $q->where('media.file_name', 'like', 'afleverbon-%')
                    ->orWhere('media.file_name', 'like', 'postnl-label-%')
                    ->orWhere('media.file_name', 'like', 'postnl-retour-label-%');
            })
            ->leftJoin('orders', function ($join) {
                $join->on('media.model_id', '=', 'orders.main_id')
                    ->where('orders.type', '=', OrderType::Order->value);
            })
            ->leftJoin('orders as main_orders', 'media.model_id', '=', 'main_orders.id')
            ->leftJoin('customers as billing_customer', 'orders.billing_customer_id', '=', 'billing_customer.id')
            ->groupBy('media.id', 'media.model_id', 'media.file_name', 'media.created_at')
            ->selectRaw(
                "CONCAT('media-', media.id) as id,
                'media' as source_type,
                media.id as source_id,
                media.created_at as sent_at,
                CASE
                    WHEN media.file_name LIKE 'afleverbon-%' THEN 'packing_slip'
                    WHEN media.file_name LIKE 'postnl-label-%' THEN 'postnl_label'
                    WHEN media.file_name LIKE 'postnl-retour-label-%' THEN 'postnl_retour_label'
                    ELSE 'packing_slip'
                END as type,
                NULL as caption,
                CASE
                    WHEN media.file_name LIKE 'afleverbon-%' THEN REPLACE(REPLACE(media.file_name, 'afleverbon-', ''), '.pdf', '')
                    WHEN media.file_name LIKE 'postnl-label-%' THEN REPLACE(REPLACE(media.file_name, 'postnl-label-', ''), '.pdf', '')
                    WHEN media.file_name LIKE 'postnl-retour-label-%' THEN REPLACE(REPLACE(media.file_name, 'postnl-retour-label-', ''), '.pdf', '')
                    ELSE NULL
                END as uid,
                NULL as rev,
                MAX(orders.subtype) as subtype,
                media.model_id as main_id,
                MAX(main_orders.uid) as main_uid,
                MAX(main_orders.reference_internal) as main_reference_internal,
                NULL as company_id,
                MAX(orders.customer_id) as customer_id,
                MAX(COALESCE(NULLIF(TRIM(CONCAT_WS(' ', billing_customer.first_name, billing_customer.last_name)), ''), billing_customer.name)) as company_name,
                NULL as status,
                NULL as is_cancelled,
                media.file_name,
                NULL as serial_number_value,
                media.created_at"
            );
    }

    /**
     * Delivery note PDFs on {@see DeliveryNote} (Spatie collection "pdf"); stored filenames use the "delivery-note-" prefix (legacy "pakbon-" is still matched).
     */
    protected function getCustomerDeliveryNotePdfsForUnion(int $customerId): Builder
    {
        $mainIds = $this->mainIdsLinkedToCustomer($customerId);

        if ($mainIds === []) {
            return Media::query()
                ->whereRaw('0 = 1')
                ->selectRaw(
                    "CONCAT('media-', id) as id, 'media' as source_type, id as source_id, created_at as sent_at, 'delivery_note' as type, NULL as caption, NULL as uid, NULL as rev, NULL as subtype, NULL as main_id, NULL as main_uid, NULL as main_reference_internal, NULL as company_id, NULL as customer_id, NULL as company_name, NULL as status, NULL as is_cancelled, file_name, NULL as serial_number_value, created_at"
                );
        }

        $morphType = (new DeliveryNote())->getMorphClass();

        return Media::query()
            ->where('media.model_type', $morphType)
            ->where('media.collection_name', 'pdf')
            ->where(function (Builder $q): void {
                $q->where('media.file_name', 'like', 'delivery-note-%')
                    ->orWhere('media.file_name', 'like', 'pakbon-%');
            })
            ->join('delivery_notes', 'delivery_notes.id', '=', 'media.model_id')
            ->join('orders', function ($join): void {
                $join->on('delivery_notes.order_id', '=', 'orders.id')
                    ->where('orders.type', '=', OrderType::Order->value);
            })
            ->whereIn('orders.main_id', $mainIds)
            ->leftJoin('orders as main_orders', 'orders.main_id', '=', 'main_orders.id')
            ->leftJoin('customers as billing_customer', 'orders.billing_customer_id', '=', 'billing_customer.id')
            ->groupBy('media.id', 'media.model_id', 'media.file_name', 'media.created_at')
            ->selectRaw(
                "CONCAT('media-', media.id) as id,
                'media' as source_type,
                media.id as source_id,
                media.created_at as sent_at,
                'delivery_note' as type,
                NULL as caption,
                CASE
                    WHEN media.file_name LIKE 'delivery-note-%' THEN REPLACE(REPLACE(media.file_name, 'delivery-note-', ''), '.pdf', '')
                    WHEN media.file_name LIKE 'pakbon-%' THEN REPLACE(REPLACE(media.file_name, 'pakbon-', ''), '.pdf', '')
                    ELSE NULL
                END as uid,
                NULL as rev,
                MAX(orders.subtype) as subtype,
                MAX(orders.main_id) as main_id,
                MAX(main_orders.uid) as main_uid,
                MAX(main_orders.reference_internal) as main_reference_internal,
                NULL as company_id,
                MAX(orders.customer_id) as customer_id,
                MAX(COALESCE(NULLIF(TRIM(CONCAT_WS(' ', billing_customer.first_name, billing_customer.last_name)), ''), billing_customer.name)) as company_name,
                NULL as status,
                NULL as is_cancelled,
                media.file_name,
                NULL as serial_number_value,
                media.created_at"
            );
    }

    /**
     * Get packing slips query for inclusion in available types check
     */
    protected function getPackingSlipsQuery(): Builder
    {
        if (! $this->record instanceof Company) {
            return Media::query()->whereRaw('1 = 0');
        }

        $scope = $this->scope();
        if ($scope === CompanyDocumentsTabScope::InvoiceOnly) {
            return Media::query()->whereRaw('1 = 0');
        }

        $companyId = (int) $this->record->id;
        $mainIds = match ($scope) {
            CompanyDocumentsTabScope::ShippingOnly => $this->mainIdsForShippingHub($companyId),
            CompanyDocumentsTabScope::AllGlobal => $this->mainIdsForInvoiceDealer($companyId),
            default => [],
        };

        if ($mainIds === []) {
            return Media::query()->whereRaw('1 = 0');
        }

        $morphType = (new Main())->getMorphClass();

        return Media::query()
            ->where('model_type', $morphType)
            ->whereIn('model_id', $mainIds)
            ->where('collection_name', 'delivery_documents')
            ->where('file_name', 'like', 'afleverbon-%');
    }

    /**
     * @return list<int>
     */
    private function mainIdsForShippingHub(int $shippingCompanyId): array
    {
        $dealerIds = Company::query()
            ->where('shipping_company_id', $shippingCompanyId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $shippingKey = 'customer-' . $shippingCompanyId;

        return BaseOrder::query()
            ->withoutGlobalScopes()
            ->where('type', OrderType::Order->value)
            ->whereNotNull('main_id')
            ->where(function (Builder $q) use ($dealerIds, $shippingKey): void {
                if ($dealerIds !== []) {
                    $q->whereIn('company_id', $dealerIds);
                }
                $q->orWhere('additional->shipping_address_type_key', $shippingKey);
            })
            ->pluck('main_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function mainIdsForInvoiceDealer(int $companyId): array
    {
        return BaseOrder::query()
            ->withoutGlobalScopes()
            ->where('type', OrderType::Order->value)
            ->whereNotNull('main_id')
            ->where('company_id', $companyId)
            ->pluck('main_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function shippingHubDocumentsQuery(int $shippingCompanyId): Builder
    {
        $dealerIds = $this->dealerCompanyIdsForShippingHub($shippingCompanyId);
        $shippingKey = 'customer-' . $shippingCompanyId;

        return $this->baseOrdersQuery()
            ->whereIn('orders.type', self::nonFinancialDocumentTypes())
            ->where(function (Builder $q) use ($dealerIds, $shippingKey): void {
                if ($dealerIds !== []) {
                    $q->whereIn('orders.company_id', $dealerIds);
                }
                $q->orWhere('orders.additional->shipping_address_type_key', $shippingKey);
            });
    }

    /**
     * Get available document types for the current scope.
     *
     * @return array<string, string>
     */
    protected function getAvailableTypes(): array
    {
        // Get types from orders
        $types = $this->getTableQuery()
            ->distinct()
            ->pluck('type')
            ->map(fn ($type) => $type instanceof \BackedEnum ? $type->value : $type)
            ->filter()
            ->toArray();

        // Check if we should include packing slips
        $scope = $this->scope();
        if (in_array($scope, [CompanyDocumentsTabScope::ShippingOnly, CompanyDocumentsTabScope::AllGlobal], true)) {
            $hasPackingSlips = $this->getPackingSlipsQuery()->exists();
            if ($hasPackingSlips) {
                $types[] = 'packing_slip';
            }
        }

        $result = [];
        foreach ($types as $typeValue) {
            $result[$typeValue] = FinancialDocumentMailAttachments::documentTypeLabel(
                is_string($typeValue) ? $typeValue : (string) $typeValue,
                null,
            );
        }

        return $result;
    }

    /**
     * Get available request numbers (aanvraagnummers) for the current scope.
     *
     * @return array<string, string>
     */
    protected function getAvailableMainUids(): array
    {
        return $this->getTableQuery()
            ->distinct()
            ->whereNotNull('main_uid')
            ->orderBy('main_uid')
            ->pluck('main_uid')
            ->mapWithKeys(fn (string $uid): array => [$uid => $uid])
            ->all();
    }

    public function table(Table $table): Table
    {
        $scope = $this->scope();
        $availableTypes = $this->getAvailableTypes();
        $availableMainUids = $this->getAvailableMainUids();

        return $table
            ->query($this->getTableQuery())
            ->columns([
                TextColumn::make('sent_at')
                    ->label('Datum')
                    ->formatStateUsing(fn ($record) => $record->sent_at?->translatedFormat('j M Y'))
                    ->searchable(['sent_at'])
                    ->sortable(['sent_at'])
                    ->disabledClick(),

                TextColumn::make('type')
                    ->label('Documenttype')
                    ->formatStateUsing(function ($state, $record): string {
                        $typeValue = $state instanceof \BackedEnum ? $state->value : (string) $state;

                        return FinancialDocumentMailAttachments::documentTypeLabel(
                            $typeValue,
                            $record->caption ?? null,
                        );
                    })
                    ->disabledClick(),

                ReportingOrderNumberColumn::make('uid')
                    ->label('Documentnummer')
                    ->viewData(['displayDate' => false])
                    ->formatStateUsing(function ($state, $record): string {
                        if (in_array($record->type, ['packing_slip', 'postnl_label', 'postnl_retour_label', 'delivery_note'], true)) {
                            return $record->uid ?? '-';
                        }

                        if ($record->source_type === 'order' && method_exists($record, 'getUidFormatted')) {
                            return $record->getUidFormatted() ?: '-';
                        }

                        return $state ?? '-';
                    })
                    ->searchable(['uid', 'file_name'])
                    ->sortable(['uid'])
                    ->disabledClick(),

                TextColumn::make('subtype')
                    ->label('Type')
                    ->formatStateUsing(function ($state, $record): string {
                        if ($state instanceof OrderSubtype) {
                            return $state->getLabel() ?? '-';
                        }

                        if (is_string($state) && $state !== '') {
                            return OrderSubtype::tryFrom($state)?->getLabel() ?? $state;
                        }

                        return '-';
                    })
                    ->searchable()
                    ->sortable()
                    ->disabledClick(),

                TextColumn::make('main_uid')
                    ->label('Aanvraagnummer')
                    ->formatStateUsing(fn ($record) => NavigationLink::main(
                        $record->main_id,
                        $record->main_uid ?? '-',
                    ))
                    ->openUrlInNewTab()
                    ->searchable(['main_uid'])
                    ->sortable(['main_uid'])
                    ->disabledClick(false),

                TextColumn::make('main_reference_internal')
                    ->label('Referentie (intern)')
                    ->formatStateUsing(fn ($state): string => filled((string) $state) ? (string) $state : '-')
                    ->searchable(['main_reference_internal'])
                    ->sortable(['main_reference_internal'])
                    ->disabledClick(),

                TextColumn::make('company_name')
                    ->label('Dealer')
                    ->formatStateUsing(fn ($state, $record) => $state ?? ($record->company?->name ?? '-'))
                    ->searchable(['company_name'])
                    ->sortable(['company_name'])
                    ->disabledClick(),

                DocumentStatusColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn ($state, $record) => isset($record->type) && in_array($record->type, ['packing_slip', 'postnl_label', 'postnl_retour_label', 'delivery_note'], true) ? '-' : $state)
                    ->disabledClick(),
            ])
            ->defaultSort('sent_at', 'desc')
            ->deferFilters(false)
            ->filters(array_values(array_filter([
                $availableTypes !== [] ? Filter::make('type')
                    ->label('Type')
                    ->schema([
                        ToggleFilter::make()
                            ->label('Type')
                            ->schema([
                                CheckboxList::make('type')
                                    ->searchable(false)
                                    ->label('')
                                    ->options($availableTypes),
                            ])
                    ])
                    ->indicateUsing(function (array $data) use ($availableTypes): ?string {
                        if (!isset($data['type']) || !is_array($data['type']) || $data['type'] === []) {
                            return null;
                        }

                        $selected = array_intersect_key($availableTypes, array_flip($data['type']));
                        if ($selected === []) {
                            return null;
                        }

                        return 'Type: ' . implode(', ', $selected);
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        if (!isset($data['type']) || !is_array($data['type']) || $data['type'] === []) {
                            return $query;
                        }

                        return $query->whereIn('type', $data['type']);
                    }) : null,

                $availableMainUids !== [] ? Filter::make('main_uid')
                    ->label('Aanvraagnummer')
                    ->schema([
                        ToggleFilter::make()
                            ->label('Aanvraagnummer')
                            ->schema([
                                CheckboxList::make('main_uid')
                                    ->searchable(false)
                                    ->label('')
                                    ->options($availableMainUids),
                            ])
                    ])
                    ->indicateUsing(function (array $data) use ($availableMainUids): ?string {
                        if (!isset($data['main_uid']) || !is_array($data['main_uid']) || $data['main_uid'] === []) {
                            return null;
                        }

                        $selected = array_intersect_key($availableMainUids, array_flip($data['main_uid']));
                        if ($selected === []) {
                            return null;
                        }

                        return 'Aanvraagnummer: ' . implode(', ', $selected);
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        if (!isset($data['main_uid']) || !is_array($data['main_uid']) || $data['main_uid'] === []) {
                            return $query;
                        }

                        return $query->whereIn('main_uid', $data['main_uid']);
                    }) : null,
            ])), layout: FiltersLayout::AboveContent)
            ->emptyStateHeading($scope->getEmptyStateHeading());
    }
}
