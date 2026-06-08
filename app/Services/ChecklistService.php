<?php

namespace App\Services;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\ReleaseOrderStatus;
use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Resources\ReleaseOrders\ReleaseOrderResource;
use App\Models\Order\DepositInvoice;
use App\Models\Order\Invoice;
use App\Models\Order\Main;
use App\Models\Order\Order;
use App\Models\Order\Quote;
use App\Models\PurchaseOrder;
use App\Models\ReleaseOrder;
use App\Models\StatusChange;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class ChecklistService
{
    /**
     * Build data for the main-summary block on the checklist tab.
     *
     * @return array{
     *     customerName: string,
     *     unitName: string,
     *     advisorName: string,
     *     summaryRows: list<array{
     *         activity: string,
     *         initials: string,
     *         date: string,
     *         dateTooltip: string,
     *         number: string,
     *         numberUrl: ?string,
     *         modalPayload?: array<string, mixed>,
     *         showActivity?: bool,
     *         activityRowspan?: int,
     *         opensPdfPlaceholderModal?: bool
     *     }>
     * }
     */
    public function getMainSummaryData(Main $main): array
    {
        $frameProduct = $main->getOrderForPurchase()?->frameProduct;

        /** @var Quote|null $approvedQuote */
        $approvedQuote = $main->getNewestApprovedQuote();
        /** @var Order|null $latestOrderConfirmation */
        $latestOrderConfirmation = $main->orders()
            ->where('status', '!=', OrderGeneralStatus::Initial->value)
            ->latest('created_at')
            ->first();

        $purchaseOrders = $main->purchaseOrders()
            ->where('status', '!=', PurchaseOrderStatus::Initial->value)
            ->latest('created_at')
            ->get();

        $releaseOrders = $main->releaseOrders()
            ->where('status', '!=', ReleaseOrderStatus::Initial->value)
            ->latest('created_at')
            ->get();

        /** @var StatusChange|null $readyForDeliveryStatusChange */
        $readyForDeliveryStatusChange = $this->latestStatusChangeTo($main, OrderStatus::ReadyForPickup);
        /** @var StatusChange|null $orderApprovedStatusChange */
        $orderApprovedStatusChange = $this->latestStatusChangeTo($main, OrderStatus::OrderApproved);
        /** @var StatusChange|null $readyForAssemblyStatusChange */
        $readyForAssemblyStatusChange = $this->latestStatusChangeTo($main, OrderStatus::ReadyForAssembly);
        $checklist = is_array($main->checklist) ? $main->checklist : [];
        [$finalInspectionDateRaw, $finalInspectionSignedByName] = $this->parseFinalInspectionRow($checklist);

        /** @var PurchaseOrder|null $latestPurchaseOrder */
        $latestPurchaseOrder = $purchaseOrders->first();
        /** @var PurchaseOrder|null $frameProductPurchaseOrder */
        $frameProductPurchaseOrder = $this->resolveFrameProductPurchaseOrder($main);
        /** @var ReleaseOrder|null $latestReleaseOrder */
        $latestReleaseOrder = $releaseOrders->first();
        /** @var DepositInvoice|null $depositInvoice */
        $depositInvoice = $latestOrderConfirmation?->depositInvoice;
        /** @var Invoice|null $finalInvoice */
        $finalInvoice = $latestOrderConfirmation?->invoice;

        return [
            'customerName' => $main->getCustomerAddressDisplayName() ?: '-',
            'unitName' => $frameProduct?->getName() ?? '-',
            'advisorName' => $main->advisor?->name ?? '-',
            'summaryRows' => $this->buildSummaryRows(
                $approvedQuote,
                $latestOrderConfirmation,
                $latestPurchaseOrder,
                $purchaseOrders,
                $frameProductPurchaseOrder,
                $latestReleaseOrder,
                $releaseOrders,
                $orderApprovedStatusChange,
                $readyForAssemblyStatusChange,
                $readyForDeliveryStatusChange,
                $finalInspectionDateRaw,
                $finalInspectionSignedByName,
                $finalInvoice,
                $depositInvoice,
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $checklist
     * @return array{0: string|null, 1: string}
     */
    protected function parseFinalInspectionRow(array $checklist): array
    {
        $finalInspectionDateRaw = null;
        $finalInspectionSignedByName = '';
        foreach ($checklist as $row) {
            $description = mb_strtolower(trim((string) ($row['description'] ?? '')));
            if ($description !== 'eindcontrole') {
                continue;
            }
            $rawDate = trim((string) ($row['checked_at'] ?? ($row['date'] ?? '')));
            $rawCheckedByName = trim((string) ($row['checked_by_name'] ?? ''));
            if ($rawDate !== '') {
                $finalInspectionDateRaw = $rawDate;
            }
            if ($rawCheckedByName !== '') {
                $finalInspectionSignedByName = $rawCheckedByName;
            }
            break;
        }

        return [$finalInspectionDateRaw, $finalInspectionSignedByName];
    }

    protected function latestStatusChangeTo(Main $main, OrderStatus $toStatus): ?StatusChange
    {
        return $main->statusChanges()
            ->where('to_status', $toStatus->value)
            ->latest('created_at')
            ->first();
    }

    protected function resolveFrameProductPurchaseOrder(Main $main): ?PurchaseOrder
    {
        return $main->getOrderForPurchase()?->orderProducts()
            ->whereHas('product', fn ($query) => $query->where('type', ProductType::Frame->value))
            ->whereNotNull('purchase_order_id')
            ->with('purchaseOrder')
            ->latest('updated_at')
            ->first()
            ?->purchaseOrder;
    }

    /**
     * Row `activity` values are Dutch copy for the Filament UI; service API stays English.
     *
     * @return list<array{
     *     activity: string,
     *     initials: string,
     *     date: string,
     *     dateTooltip: string,
     *     number: string,
     *     numberUrl: ?string,
     *     showActivity?: bool,
     *     activityRowspan?: int,
     *     opensPdfPlaceholderModal?: bool
     * }>
     */
    protected function buildSummaryRows(
        ?Quote $approvedQuote,
        ?Order $latestOrderConfirmation,
        ?PurchaseOrder $latestPurchaseOrder,
        EloquentCollection $purchaseOrders,
        ?PurchaseOrder $frameProductPurchaseOrder,
        ?ReleaseOrder $latestReleaseOrder,
        EloquentCollection $releaseOrders,
        ?StatusChange $orderApprovedStatusChange,
        ?StatusChange $readyForAssemblyStatusChange,
        ?StatusChange $readyForDeliveryStatusChange,
        ?string $finalInspectionDateRaw,
        string $finalInspectionSignedByName,
        ?Invoice $finalInvoice,
        ?DepositInvoice $depositInvoice,
    ): array {
        $displayPurchaseOrders = $purchaseOrders->reverse()->values();
        $displayPurchaseOrdersForParts = $frameProductPurchaseOrder === null
            ? $displayPurchaseOrders
            : $displayPurchaseOrders->reject(
                fn (PurchaseOrder $purchaseOrder): bool => $purchaseOrder->is($frameProductPurchaseOrder)
            )->values();
        $displayReleaseOrders = $releaseOrders->reverse()->values();

        return array_merge([
            [
                'activity' => 'Offerte',
                'initials' => $this->userDisplayName($approvedQuote?->author),
                'date' => $approvedQuote?->getSentAt()?->format('d-m-Y') ?? '-',
                'dateTooltip' => $approvedQuote?->getSentAt()?->format('d-m-Y H:i') ?? '',
                'number' => $approvedQuote ? $approvedQuote->getUid() : '-',
                'numberUrl' => null,
                'modalPayload' => $this->documentModalPayload('quote', $approvedQuote?->id),
            ],
            [
                'activity' => 'Orderbevestiging',
                'initials' => $this->userDisplayName($orderApprovedStatusChange?->changedBy),
                'date' => $orderApprovedStatusChange?->getCreatedAt()?->format('d-m-Y') ?? '-',
                'dateTooltip' => $orderApprovedStatusChange?->getCreatedAt()?->format('d-m-Y H:i') ?? '',
                'number' => $latestOrderConfirmation?->getUid() ?? '-',
                'numberUrl' => null,
                'modalPayload' => $this->documentModalPayload('order', $latestOrderConfirmation?->id),
            ],
            [
                'activity' => 'Bestelling stoel (frame)',
                'initials' => $this->userDisplayName($frameProductPurchaseOrder?->author),
                'date' => $frameProductPurchaseOrder?->getSentAt()?->format('d-m-Y') ?? '-',
                'dateTooltip' => $frameProductPurchaseOrder?->getSentAt()?->format('d-m-Y H:i') ?? '',
                'number' => $frameProductPurchaseOrder?->getReferenceNumber() ?? '-',
                'numberUrl' => $this->purchaseOrderViewUrl($frameProductPurchaseOrder),
            ],
        ], $this->buildPurchaseOrderRows($displayPurchaseOrdersForParts), $this->buildReleaseOrderRows($displayReleaseOrders), [
            [
                'activity' => 'Afleverklaar WP',
                'initials' => $this->userDisplayName($readyForDeliveryStatusChange?->changedBy),
                'date' => $readyForDeliveryStatusChange?->getCreatedAt()?->format('d-m-Y') ?? '-',
                'dateTooltip' => $readyForDeliveryStatusChange?->getCreatedAt()?->format('d-m-Y H:i') ?? '',
                'number' => '-',
                'numberUrl' => null,
            ],
            [
                'activity' => 'Afleverbon',
                'initials' => $this->userDisplayName($readyForAssemblyStatusChange?->changedBy),
                'date' => $readyForAssemblyStatusChange?->getCreatedAt()?->format('d-m-Y') ?? '-',
                'dateTooltip' => $readyForAssemblyStatusChange?->getCreatedAt()?->format('d-m-Y H:i') ?? '',
                'number' => '-',
                'numberUrl' => null,
            ],
            [
                'activity' => 'Eindcontrole ADV',
                'initials' => $finalInspectionSignedByName,
                'date' => $finalInspectionDateRaw !== null && $finalInspectionDateRaw !== '' ? Carbon::parse($finalInspectionDateRaw)->format('d-m-Y') : '-',
                'dateTooltip' => $finalInspectionDateRaw !== null && $finalInspectionDateRaw !== '' ? Carbon::parse($finalInspectionDateRaw)->format('d-m-Y H:i') : '',
                'number' => '-',
                'numberUrl' => null,
            ],
            [
                'activity' => 'Aanbetaling',
                'initials' => $this->userDisplayName($depositInvoice?->author),
                'date' => $depositInvoice?->getSentAt()?->format('d-m-Y') ?? '-',
                'dateTooltip' => $depositInvoice?->getSentAt()?->format('d-m-Y H:i') ?? '',
                'number' => $depositInvoice?->getUid() ?? '-',
                'numberUrl' => null,
                'modalPayload' => $this->documentModalPayload('invoice', $depositInvoice?->id),
            ],
            [
                'activity' => 'Factuur',
                'initials' => $this->userDisplayName($finalInvoice?->author),
                'date' => $finalInvoice?->getSentAt()?->format('d-m-Y') ?? '-',
                'dateTooltip' => $finalInvoice?->getSentAt()?->format('d-m-Y H:i') ?? '',
                'number' => $finalInvoice?->getUid() ?? '-',
                'numberUrl' => null,
                'modalPayload' => $this->documentModalPayload('invoice', $finalInvoice?->id),
            ],
        ]);
    }

    /**
     * @return array{id: string, orderId: string, quotePreview?: bool, invoicePreview?: bool}|null
     */
    protected function documentModalPayload(string $documentType, ?int $modelId): ?array
    {
        if ($modelId === null) {
            return null;
        }

        return match ($documentType) {
            'quote' => ['id' => 'open-document', 'orderId' => (string) $modelId, 'quotePreview' => true],
            'order', 'invoice' => ['id' => 'open-document', 'orderId' => (string) $modelId, 'invoicePreview' => true],
            default => null,
        };
    }

    /**
     * @return list<array{activity: string, initials: string, date: string, dateTooltip: string, number: string, numberUrl: ?string, showActivity?: bool, activityRowspan?: int}>
     */
    protected function buildPurchaseOrderRows(EloquentCollection $purchaseOrders): array
    {
        $rows = $purchaseOrders
            ->map(function (PurchaseOrder $purchaseOrder): array {
                return [
                    'activity' => 'Bestelling onderdelen',
                    'initials' => $this->userDisplayName($purchaseOrder->author),
                    'date' => $purchaseOrder->getSentAt()?->format('d-m-Y') ?? '-',
                    'dateTooltip' => $purchaseOrder->getSentAt()?->format('d-m-Y H:i') ?? '',
                    'number' => $purchaseOrder->getReferenceNumber() ?? '-',
                    'numberUrl' => $this->purchaseOrderViewUrl($purchaseOrder),
                    'showActivity' => false,
                    'activityRowspan' => 1,
                ];
            })
            ->values()
            ->all();

        if ($rows === []) {
            return [[
                'activity' => 'Bestelling onderdelen',
                'initials' => '',
                'date' => '-',
                'dateTooltip' => '',
                'number' => '-',
                'numberUrl' => null,
                'showActivity' => true,
                'activityRowspan' => 1,
            ]];
        }

        $rows[0]['showActivity'] = true;
        $rows[0]['activityRowspan'] = count($rows);

        return $rows;
    }

    /**
     * @return list<array{activity: string, initials: string, date: string, dateTooltip: string, number: string, numberUrl: ?string, showActivity?: bool, activityRowspan?: int}>
     */
    protected function buildReleaseOrderRows(EloquentCollection $releaseOrders): array
    {
        $rows = $releaseOrders
            ->map(function (ReleaseOrder $releaseOrder): array {
                return [
                    'activity' => 'Afroep onderdelen',
                    'initials' => $this->userDisplayName($releaseOrder->author),
                    'date' => $releaseOrder->getSentAt()?->format('d-m-Y') ?? '-',
                    'dateTooltip' => $releaseOrder->getSentAt()?->format('d-m-Y H:i') ?? '',
                    'number' => $releaseOrder->getReferenceNumber() ?? '-',
                    'numberUrl' => $this->releaseOrderViewUrl($releaseOrder),
                    'showActivity' => false,
                    'activityRowspan' => 1,
                ];
            })
            ->values()
            ->all();

        if ($rows === []) {
            return [[
                'activity' => 'Afroep onderdelen',
                'initials' => '',
                'date' => '-',
                'dateTooltip' => '',
                'number' => '-',
                'numberUrl' => null,
                'showActivity' => true,
                'activityRowspan' => 1,
            ]];
        }

        $rows[0]['showActivity'] = true;
        $rows[0]['activityRowspan'] = count($rows);

        return $rows;
    }

    protected function userDisplayName(?object $user): string
    {
        if ($user === null) {
            return '';
        }

        return trim((string) $user->getName());
    }

    protected function purchaseOrderViewUrl(?PurchaseOrder $purchaseOrder): ?string
    {
        if ($purchaseOrder === null) {
            return null;
        }

        return PurchaseOrderResource::getUrl('view', ['record' => $purchaseOrder]);
    }

    protected function releaseOrderViewUrl(?ReleaseOrder $releaseOrder): ?string
    {
        if ($releaseOrder === null) {
            return null;
        }

        return ReleaseOrderResource::getUrl('view', ['record' => $releaseOrder]);
    }
}
