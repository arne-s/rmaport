<?php

namespace App\Filament\Resources\OrderResource\Pages\Traits;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderProductStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderSubtype;
use App\Enums\OrderType;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Models\OrderProduct;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * Order status UI: dropdown, confirmation modals, tab sync/redirects, remote status polling, and status timeline.
 *
 * Expects {@see $record} to be {@see Main} and (for fitting cancel) {@see FittingTrait::$fittingCancelledReason}.
 */
trait StatusTrait
{
    private const MANUAL_ORDER_STATUS_SESSION_PREFIX = 'view_order_manual_status_sync_';

    /** @var int Seconds: suppress duplicate poll notification after a local status apply (stale snapshot race). */
    private const MANUAL_ORDER_STATUS_POLL_SUPPRESS_SECONDS = 5;

    /**
     * Set to true in {@see applyOrderStatusChange()} only after a successful {@see Main::changeOrderStatus()} in that call.
     * Used so Save can show the "saved" toast when this request did not actually run a status change (avoids duplicate messaging when status was not part of the form state).
     */
    private bool $orderStatusMutatedInApplyCall = false;

    /** Current order status for the header dropdown (saved on Save). */
    public ?string $orderStatus = null;

    /** Order status as loaded from the DB; used for polling to detect external changes. */
    public ?string $orderStatusFromDb = null;

    /** Show confirmation modal: Fitting completed → Quote to be prepared. */
    public bool $showPassingCompleteConfirm = false;

    /** Show confirmation modal: Fitting cancelled → Cancel request (with reason). */
    public bool $showFittingCancelledConfirm = false;

    /** Show confirmation modal: status → OrderApproved (move to Purchasing). */
    public bool $showOrderApprovedConfirm = false;

    /** Signature of main's financial documents for polling; when changed the docs widget is refreshed. */
    public ?string $financialDocsSignature = null;

    /**
     * All purchase lines picked: show a modal before switching to the phase tab (Montage or Levering).
     * Default flow → {@see OrderStatus::ReadyForAssembly} + assembly tab; unit simplified invoice flow → {@see OrderStatus::ReadyForPickup} + delivery tab.
     */
    public bool $showPickCompleteReadyForAssemblyModal = false;

    /**
     * Reserved for workflows that defer syncing {@see Main::$shipping_customer_id} until after delivery fields save.
     */
    public bool $pendingDealerShippingResync = false;

    /**
     * Active view tab; kept in sync with ?tab= via Livewire so AJAX requests (which lack the query string on request()) do not reset the UI to the default "order" tab.
     */
    #[Url(as: 'tab')]
    public string $orderViewTab = 'order';

    /**
     * Status dropdown uses wire:model.live — persist (or open confirmation modal) immediately for every selection.
     */
    public function updatedOrderStatus(?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (OrderStatus::tryFrom($value) === null) {
            return;
        }

        if ($this->applyOrderStatusChange()) {
            if ($this->showPassingCompleteConfirm || $this->showFittingCancelledConfirm || $this->showOrderApprovedConfirm) {
                $this->isOrderViewDirty = true;
            }

            return;
        }
    }

    /**
     * @return array<int, array{value: string, label: string, selectable: bool}>
     */
    public function getOrderStatusDropdownOptions(): array
    {
        $current = $this->record->getOrderStatus()
            ?? OrderStatus::tryFrom((string) ($this->orderStatusFromDb ?? ''));
        $subtype = $this->record->getSubtype();
        $isPartOrService = in_array($subtype, [OrderSubtype::Part, OrderSubtype::Service], true);
        $skipQuoteStatusUi = $this->partOrServiceSkipsQuoteStatusUi($current);
        $category = $current !== null
            ? OrderStatus::getCategory($current)
            : ($isPartOrService ? 'Order' : 'Passing');
        $currentValue = $current?->value;

        $hiddenForPartOrService = [OrderStatus::OrderAudit, OrderStatus::OrderApproved, OrderStatus::OrderSent];
        $hiddenAssemblyForPart = [
            OrderStatus::ReadyForAssembly,
            OrderStatus::AssemblyPlanned,
            OrderStatus::Assembled,
        ];
        $hiddenForUnit = [OrderStatus::OrderSent];
        $unitSimplifiedSalesFlow = $this->record instanceof Main && $this->record->usesUnitSimplifiedSalesFlow();
        $hiddenForUnitSimplifiedSalesFlow = [
            OrderStatus::FittingDraft,
            OrderStatus::FittingPlanned,
            OrderStatus::FittingReady,
            OrderStatus::FittingCancelled,
            OrderStatus::OrderAudit,
            OrderStatus::OrderApproved,
            OrderStatus::ReadyForAssembly,
            OrderStatus::AssemblyPlanned,
            OrderStatus::Assembled,
        ];

        $all = OrderStatus::allWithCategories();
        $options = [];
        $showCategoryPrefix = $category !== '' && $category !== 'Onbekend';
        foreach ($all as $value => $item) {
            $status = $item['status'] ?? OrderStatus::tryFrom($value);

            if ($skipQuoteStatusUi && ($item['category'] ?? '') === 'Offerte') {
                continue;
            }

            if (($item['category'] ?? '') === $category) {
                if ($subtype === OrderSubtype::Service
                    && $status !== null
                    && OrderStatus::getMainStatusFor($status) === OrderStatus::Delivery
                    && $value !== $currentValue) {
                    continue;
                }
                if ($isPartOrService && $status !== null && in_array($status, $hiddenForPartOrService, true) && $value !== $currentValue) {
                    continue;
                }
                if ($subtype === OrderSubtype::Part
                    && $status !== null
                    && (
                        in_array($status, $hiddenAssemblyForPart, true)
                        || in_array(OrderStatus::getMainStatusFor($status), [OrderStatus::Assembly, OrderStatus::Fitting], true)
                    )
                    && $value !== $currentValue) {
                    continue;
                }
                if (! $isPartOrService && $status !== null && in_array($status, $hiddenForUnit, true) && $value !== $currentValue) {
                    continue;
                }
                if ($unitSimplifiedSalesFlow && $status !== null && in_array($status, $hiddenForUnitSimplifiedSalesFlow, true) && $value !== $currentValue) {
                    continue;
                }

                if ($status !== null && ! $status->isVisibleInSelect() && $value !== $currentValue) {
                    continue;
                }
                $label = $item['label'] ?? OrderStatus::tryFrom($value)?->getLabel() ?? $value;
                if ($label === $category && $value !== $currentValue) {
                    continue;
                }
                $selectable = false;
                if ($status !== null) {
                    if ($value === $currentValue) {
                        $selectable = true;
                    } elseif ($unitSimplifiedSalesFlow && $this->isManualDeliveryCompletionSelectableInUnitSimplifiedFlow($status, $current)) {
                        $selectable = true;
                    } elseif ($subtype === OrderSubtype::Part && $this->isPartDeliveryCompletionSelectable($status, $current)) {
                        $selectable = true;
                    } else {
                        $selectable = $status->isUserSelectable() && $status->canBeSelectedWhenCurrentIs($current, $subtype);
                    }
                }
                if (
                    $selectable
                    && $status === OrderStatus::Delivered
                    && $value !== $currentValue
                    && $subtype === OrderSubtype::Part
                    && $this->record instanceof Main
                    && ! $this->record->latestSalesOrderHasOrderConfirmationSent()
                ) {
                    $selectable = false;
                }
                if (
                    $selectable
                    && $status === OrderStatus::FittingCancelled
                    && $value !== $currentValue
                    && $this->record instanceof Main
                    && ! $this->record->isFittingCancellationSelectable()
                ) {
                    $selectable = false;
                }
                $optionLabel = $label;
                if ($showCategoryPrefix && $category !== $label) {
                    if (str_starts_with($label, $category . ': ')) {
                        $optionLabel = $label;
                    } elseif (str_starts_with($label, $category . ' ')) {
                        $optionLabel = $category . ': ' . substr($label, strlen($category) + 1);
                    } else {
                        $optionLabel = $category . ': ' . $label;
                    }
                }
                $options[] = [
                    'value' => $value,
                    'label' => $optionLabel,
                    'selectable' => $selectable,
                ];
            }
        }

        return $options;
    }

    public function confirmPassingCompleteAndSave(): void
    {
        $this->showPassingCompleteConfirm = false;
        $this->orderStatus = OrderStatus::QuoteDraft->value;
        $this->saveOrderDetails();
    }

    public function cancelPassingCompleteConfirm(): void
    {
        $this->showPassingCompleteConfirm = false;
        $this->orderStatus = $this->orderStatusFromDb;
    }

    /**
     * @throws \Throwable
     */
    public function confirmFittingCancelledAndSave(): void
    {
        $reason = trim($this->fittingCancelledReason);
        if ($reason === '') {
            return;
        }

        $this->record->setCancelComment($reason);
        $this->record->saveQuietly();

        $this->record->changeOrderStatus(OrderStatus::FittingCancelled);
        $this->record->changeOrderStatus(OrderStatus::Cancelled);

        $this->closeFittingCancelledConfirm();
        $this->record->refresh();
        $this->orderStatusFromDb = $this->record->getOrderStatus()?->value;
        $this->orderStatus = $this->orderStatusFromDb;
        $this->record?->refresh();

        Notification::make()
            ->title('Aanvraag is geannuleerd.')
            ->success()
            ->send();
    }

    public function cancelFittingCancelledConfirm(): void
    {
        $this->closeFittingCancelledConfirm();
    }

    protected function closeFittingCancelledConfirm(): void
    {
        $this->showFittingCancelledConfirm = false;
        $this->fittingCancelledReason = '';
        $this->orderStatus = $this->orderStatusFromDb;
    }

    public function confirmOrderApprovedAndSave(): void
    {
        $this->showOrderApprovedConfirm = false;

        try {
            $this->record->changeOrderStatus(OrderStatus::OrderApproved);
            $this->orderStatusMutatedInApplyCall = true;
        } catch (ValidationException $e) {
            $this->orderStatus = $this->orderStatusFromDb;
            $message = collect($e->errors())->flatten()->first() ?? $e->getMessage();
            Notification::make()
                ->title('Status niet gewijzigd')
                ->body($message)
                ->danger()
                ->send();

            return;
        }

        $this->record->refresh();
        $this->orderStatusFromDb = $this->record->getOrderStatus()?->value;
        $this->orderStatus = $this->orderStatusFromDb;
        $this->redirectToStatusTabIfNeeded(force: true);

        $finalStatus = OrderStatus::tryFrom((string) $this->orderStatusFromDb);
        if ($finalStatus !== null) {
            $this->notifyUserInitiatedOrderStatusChange($finalStatus);
        }
    }

    public function cancelOrderApprovedConfirm(): void
    {
        $this->showOrderApprovedConfirm = false;
        $this->orderStatus = $this->orderStatusFromDb;
    }

    /**
     * Assembly completed: save fields, then transition to Assembled and immediately to Ready for pickup (previously after modal confirm).
     *
     * @return bool Always true: workflow handled; no second {@see Main::changeOrderStatus()} for the same selection.
     */
    protected function applyAssembledCompleteStatusChange(): bool
    {
        if (! $this->saveDeliveryFields()) {
            $this->pendingDealerShippingResync = false;
            $this->orderStatus = $this->orderStatusFromDb;

            return true;
        }

        $this->applyPendingDealerShippingResyncAfterDeliverySave();

        try {
            $this->record->refresh();
            $this->record->changeOrderStatus(OrderStatus::Assembled);
            $this->record->refresh();
            if ($this->record->getSubtype() !== OrderSubtype::Service) {
                $this->record->changeOrderStatus(OrderStatus::ReadyForPickup);
            }
            $this->orderStatusMutatedInApplyCall = true;
        } catch (ValidationException $e) {
            $this->record->refresh();
            $this->orderStatusFromDb = $this->record->getOrderStatus()?->value;
            $this->orderStatus = $this->orderStatusFromDb;
            $message = collect($e->errors())->flatten()->first() ?? $e->getMessage();
            Notification::make()
                ->title('Status niet gewijzigd')
                ->body($message)
                ->danger()
                ->send();

            return true;
        }

        $this->record->fill($this->customerFormDataForRecordSave());
        $this->persistAssemblyTabAdditional();

        if (! $this->saveFittingFields()) {
            $this->orderStatus = $this->orderStatusFromDb;

            return true;
        }

        $this->record->save();

        $this->record->refresh();
        $this->orderChairColor = $this->resolveEffectiveChairColorForMount($this->record);
        $this->orderStatusFromDb = $this->record->getOrderStatus()?->value;
        $this->orderStatus = $this->orderStatusFromDb;
        $this->loadFittingFields();
        $this->loadDeliveryFields();

        $this->dispatch('fitting-measurements-reload');

        $finalStatus = OrderStatus::tryFrom((string) $this->orderStatusFromDb);
        if ($finalStatus !== null) {
            $this->redirectToStatusTabIfNeeded(force: true);
            $this->notifyUserInitiatedOrderStatusChange($finalStatus);
        }

        return true;
    }

    /**
     * After delivery fields are persisted when completing assembly: apply any deferred main/shipping sync.
     * Default no-op; override on the page when a concrete resync is required.
     */
    protected function applyPendingDealerShippingResyncAfterDeliverySave(): void
    {
    }

    /**
     * Apply order status change from dropdown: show modals for FittingReady/FittingCancelled or change status.
     * Returns true if caller should return (modal shown, validation error, or assembled workflow handled), false otherwise.
     */
    protected function applyOrderStatusChange(): bool
    {
        $this->orderStatusMutatedInApplyCall = false;

        $oldStatus = $this->record->getOrderStatus();
        $newStatus = OrderStatus::tryFrom($this->orderStatus);

        if ($newStatus === null || $newStatus === $oldStatus) {
            return false;
        }

        if ($newStatus === OrderStatus::FittingReady) {
            $this->showPassingCompleteConfirm = true;

            return true;
        }
        if ($newStatus === OrderStatus::Assembled) {
            return $this->applyAssembledCompleteStatusChange();
        }
        if ($newStatus === OrderStatus::FittingCancelled) {
            if (! $this->record instanceof Main || ! $this->record->isFittingCancellationSelectable()) {
                $this->orderStatus = $oldStatus?->value ?? '';
                Notification::make()
                    ->title('Status niet gewijzigd')
                    ->body(sprintf(
                        '%s is pas mogelijk nadat een passing-afspraak is aangemaakt.',
                        OrderStatus::formatForDisplay(OrderStatus::FittingCancelled),
                    ))
                    ->warning()
                    ->send();

                return true;
            }

            $this->fittingCancelledReason = '';
            $this->showFittingCancelledConfirm = true;

            return true;
        }
        if ($newStatus === OrderStatus::OrderApproved) {
            $this->showOrderApprovedConfirm = true;

            return true;
        }

        try {
            $this->record->changeOrderStatus($newStatus);
            $this->orderStatusMutatedInApplyCall = true;
        } catch (ValidationException $e) {
            $this->orderStatus = $oldStatus?->value ?? '';
            $message = collect($e->errors())->flatten()->first() ?? $e->getMessage();
            Notification::make()
                ->title('Status niet gewijzigd')
                ->body($message)
                ->danger()
                ->send();

            return true;
        }

        $this->record->refresh();
        $this->orderStatusFromDb = $this->record->getOrderStatus()?->value;
        $this->orderStatus = $this->orderStatusFromDb;
        $this->redirectToStatusTabIfNeeded(force: true);

        $finalStatus = OrderStatus::tryFrom((string) $this->orderStatusFromDb);
        if ($finalStatus !== null) {
            $this->notifyUserInitiatedOrderStatusChange($finalStatus);
        }

        return false;
    }

    /**
     * Toast (info) when the order status changes from the UI. Session flag debounces wire:poll duplicates.
     */
    protected function notifyUserInitiatedOrderStatusChange(OrderStatus $status): void
    {
        $label = OrderStatus::formatForDisplay($status);
        Notification::make()
            ->title('Status veranderd')
            ->body('Status veranderd naar ' . $label)
            ->info()
            ->send();

        session()->put(self::MANUAL_ORDER_STATUS_SESSION_PREFIX . $this->record->getId(), [
            'at' => microtime(true),
            'value' => $status->value,
        ]);
    }

    /**
     * After a pick or order-line status change, the main row in the DB is already updated; without this sync the UI would stay stale until the next wire:poll.
     */
    public function onRefreshProductsTab(): void
    {
        if ($this->isNonFilamentModalActive()) {
            $this->dispatch('$refresh');

            return;
        }

        $main = Main::withoutGlobalScopes()->find($this->record->getId());
        $current = $main?->getOrderStatus();
        if ($main !== null && $current !== null && $current !== OrderStatus::Cancelled
            && OrderStatus::getMainStatusFor($current) === OrderStatus::Purchase) {
            $main->applyDerivedOrderStatusFromOrderProducts(
                fn (OrderProduct $orderProduct): ?OrderProductStatus => $orderProduct->getStatus(),
            );
        }

        $this->syncLivewireOrderStatusWithDatabaseAfterOrderProductMutation();
        $this->dispatch('$refresh');
    }

    /**
     * Returns true when a custom (non-Filament-action) modal is open, preventing status-driven redirects from closing it.
     * Override in the concrete page class to check page-specific modal state.
     */
    protected function isNonFilamentModalActive(): bool
    {
        return $this->showPickCompleteReadyForAssemblyModal
            || $this->showOrderApprovedConfirm;
    }

    /**
     * Read the main's order_status from the DB and align Livewire state plus modals/redirects (same behaviour as the status poll).
     *
     * @param  string|null  $previousMainOrderStatus  Status snapshot before the mutation; used for the pick-complete → Assembly modal flow.
     */
    protected function syncLivewireOrderStatusWithDatabaseAfterOrderProductMutation(?string $previousMainOrderStatus = null): void
    {
        $previousStatusInSnapshot = $previousMainOrderStatus ?? $this->orderStatusFromDb;
        $main = Main::withoutGlobalScopes()->find($this->record->getId());
        if ($main === null) {
            return;
        }

        $currentStatusInDb = $main->getOrderStatus()?->value;
        if ($currentStatusInDb === $this->orderStatusFromDb) {
            return;
        }

        $this->applyRemoteMainOrderStatusChange($currentStatusInDb, $previousStatusInSnapshot);
    }

    /**
     * @param  string|null  $previousStatusInSnapshot  Livewire's order_status before this DB state was applied.
     */
    protected function applyRemoteMainOrderStatusChange(?string $currentStatusInDb, ?string $previousStatusInSnapshot): void
    {
        $mainId = $this->record->getId();
        $manualKey = self::MANUAL_ORDER_STATUS_SESSION_PREFIX . $mainId;
        $manual = session()->get($manualKey);
        $suppressPollNotification = is_array($manual)
            && isset($manual['at'], $manual['value'])
            && is_numeric($manual['at'])
            && is_string($manual['value'])
            && (microtime(true) - $manual['at']) < self::MANUAL_ORDER_STATUS_POLL_SUPPRESS_SECONDS
            && $manual['value'] === $currentStatusInDb;

        if ($suppressPollNotification) {
            session()->forget($manualKey);
        } else {
            $newStatus = OrderStatus::tryFrom((string) $currentStatusInDb);
            $previousStatusEnum = OrderStatus::tryFrom((string) $previousStatusInSnapshot);
            $showPickCompleteModal = ($newStatus === OrderStatus::ReadyForAssembly
                    && $previousStatusEnum !== OrderStatus::ReadyForAssembly)
                || (
                    $newStatus === OrderStatus::ReadyForPickup
                    && $previousStatusEnum !== OrderStatus::ReadyForPickup
                    && $this->record instanceof Main
                    && $this->record->getSubtype() === OrderSubtype::Part
                )
                || (
                    $newStatus === OrderStatus::ReadyForPickup
                    && $previousStatusEnum !== OrderStatus::ReadyForPickup
                    && $this->record instanceof Main
                    && $this->record->usesUnitSimplifiedSalesFlow()
                );

            if (! $showPickCompleteModal) {
                $statusLabel = $newStatus !== null
                    ? OrderStatus::formatForDisplay($newStatus)
                    : $currentStatusInDb;

                Notification::make()
                    ->title('Status veranderd')
                    ->body('Status veranderd naar ' . $statusLabel)
                    ->info()
                    ->send();
            }
        }

        $this->orderStatusFromDb = $currentStatusInDb;
        $this->orderStatus = $currentStatusInDb;
        $this->record->refresh();
        $this->normalizeOrderViewTabForCurrentRecord();
        $this->redirectToStatusTabIfNeeded(force: true, orderStatusBeforeUpdate: $previousStatusInSnapshot);
    }

    /**
     * Polling: re-render only when the DB-backed status or document set actually changed (avoids layout flicker every poll interval).
     * Status uses a fresh Main query; documents use a stable signature string of related financial document ids (same idea as OrderDocsTableWidget).
     */
    public function checkOrderStatusChanged(): void
    {
        if (! empty($this->mountedActions) || $this->isNonFilamentModalActive()) {
            $this->skipRender();

            return;
        }

        $mainId = $this->record->getId();
        $main = Main::withoutGlobalScopes()->find($mainId);
        if ($main === null) {
            $this->skipRender();

            return;
        }

        $currentStatusInDb = $main->getOrderStatus()?->value;
        if ($currentStatusInDb !== $this->orderStatusFromDb) {
            $this->applyRemoteMainOrderStatusChange($currentStatusInDb, $this->orderStatusFromDb);

            return;
        }

        $currentDocsSignature = $this->getFinancialDocsSignature($mainId);
        if ($currentDocsSignature !== $this->financialDocsSignature) {
            $this->financialDocsSignature = $currentDocsSignature;
            $this->orderDocsVersion++;

            return;
        }

        $this->skipRender();
    }

    /**
     * Resolve the tab key from the current order status, constrained to currently available tabs.
     */
    protected function resolveStatusDrivenTab(?OrderStatus $status): string
    {
        if ($status === null) {
            return 'order';
        }

        $availableTabs = $this->getAvailableOrderViewTabs($status);
        $targetTab = OrderProductStatus::OrderStatusToTab($status);

        if (
            $this->record->getSubtype() === OrderSubtype::Part
            && OrderStatus::getMainStatusFor($status) === OrderStatus::Delivery
        ) {
            $targetTab = 'shipping';
        }

        if (in_array($targetTab, $availableTabs, true)) {
            return $targetTab;
        }

        if ($this->record->getSubtype() === OrderSubtype::Service && in_array('service', $availableTabs, true)) {
            return 'service';
        }

        return 'order';
    }

    /**
     * Redirect/switch tab according to current status.
     * When not forced, explicit `?tab=` in URL is respected.
     *
     * @param  string|null  $orderStatusBeforeUpdate  Main order_status before this change; when moving to ReadyForAssembly / (simplified) ReadyForPickup after pick-complete, show the confirmation modal instead of jumping straight to the phase tab.
     */
    protected function redirectToStatusTabIfNeeded(bool $force = false, ?string $orderStatusBeforeUpdate = null): void
    {
        if (! $force && request()->has('tab')) {
            return;
        }

        $status = OrderStatus::tryFrom((string) $this->orderStatusFromDb)
            ?? $this->record->getOrderStatus();

        $targetTab = $this->resolveStatusDrivenTab($status);

        if ($this->shouldDeferAssemblyTabRedirectForPickComplete($status, $targetTab, $orderStatusBeforeUpdate)) {
            $this->showPickCompleteReadyForAssemblyModal = true;

            return;
        }

        $this->redirectToOrderTab($targetTab);
    }

    /**
     * First transition to ReadyForAssembly, or (unit simplified) to ReadyForPickup, after all lines picked: stay on current tab until user confirms in modal.
     */
    protected function shouldDeferAssemblyTabRedirectForPickComplete(
        ?OrderStatus $newStatus,
        string $targetTab,
        ?string $orderStatusBeforeUpdate,
    ): bool {
        if ($orderStatusBeforeUpdate === null) {
            return false;
        }

        $previous = OrderStatus::tryFrom($orderStatusBeforeUpdate);
        if ($previous === null) {
            return false;
        }

        if ($targetTab === 'assembly'
            && $newStatus === OrderStatus::ReadyForAssembly
            && $previous !== OrderStatus::ReadyForAssembly) {
            return true;
        }

        if ($targetTab === 'shipping'
            && $newStatus === OrderStatus::ReadyForPickup
            && $previous !== OrderStatus::ReadyForPickup
            && $this->record instanceof Main
            && $this->record->getSubtype() === OrderSubtype::Part) {
            return true;
        }

        return $targetTab === 'delivery'
            && $newStatus === OrderStatus::ReadyForPickup
            && $previous !== OrderStatus::ReadyForPickup
            && $this->record instanceof Main
            && $this->record->usesUnitSimplifiedSalesFlow();
    }

    public function confirmPickCompleteReadyForAssemblyNavigation(): void
    {
        $this->showPickCompleteReadyForAssemblyModal = false;
        if ($this->record instanceof Main && $this->record->usesUnitSimplifiedSalesFlow()) {
            $tab = 'delivery';
        } elseif ($this->record instanceof Main && $this->record->getSubtype() === OrderSubtype::Part) {
            $tab = 'shipping';
        } else {
            $tab = $this->record->getSubtype() === OrderSubtype::Service ? 'service' : 'assembly';
        }
        $this->redirectToOrderTab($tab);
    }


    /**
     * Full redirect to the tab that matches the current order status (e.g. after packing slip → Delivered).
     * Avoids Livewire + Alpine showing duplicate tab panels without a full page refresh.
     */
    public function redirectToTabForCurrentStatus(): void
    {
        $this->redirectToStatusTabIfNeeded(force: true);
    }

    public function redirectToOrderTab(string $tab): void
    {
        $normalizedTab = $tab === 'products' ? 'purchase' : $tab;
        $status = OrderStatus::tryFrom((string) $this->orderStatusFromDb)
            ?? $this->record->getOrderStatus();
        $availableTabs = $this->getAvailableOrderViewTabs($status);
        if (! in_array($normalizedTab, $availableTabs, true)) {
            $normalizedTab = $this->record->getSubtype() === OrderSubtype::Service && in_array('service', $availableTabs, true)
                ? 'service'
                : 'order';
        }

        $this->orderViewTab = $normalizedTab;

        $url = route('filament.app.resources.mains.view', ['record' => $this->record->getId()]) . '?tab=' . $normalizedTab;
        $this->redirect($url, navigate: false);
    }

    /**
     * Clamp {@see $orderViewTab} to tabs that exist for the current main (same rules as the Blade layout).
     */
    protected function normalizeOrderViewTabForCurrentRecord(): void
    {
        $allowedTabs = ['order', 'fitting', 'service', 'notes', 'purchase', 'assembly', 'delivery', 'checklist', 'shipping'];
        $tab = $this->orderViewTab;
        if ($tab === 'products') {
            $tab = 'purchase';
        }
        if (! in_array($tab, $allowedTabs, true)) {
            $tab = 'order';
        }

        $status = $this->record->getOrderStatus();
        if ($tab === 'purchase' && ! OrderStatus::shouldShowOrderViewProductsTab($status)) {
            $tab = 'order';
        }
        if ($tab === 'assembly' && ! OrderStatus::shouldShowOrderViewAssemblyTab($status)) {
            $tab = 'order';
        }
        if ($tab === 'delivery' && ! OrderStatus::shouldShowOrderViewDeliveryTab($status)) {
            $tab = 'order';
        }
        if ($tab === 'service' && $this->record->getSubtype() !== OrderSubtype::Service) {
            $tab = 'order';
        }
        if ($tab === 'shipping' && ! OrderStatus::shouldShowOrderViewShippingTab($status)) {
            $tab = 'order';
        }
        if (in_array($tab, ['fitting', 'assembly', 'delivery', 'checklist'], true) && $this->record->getSubtype() === OrderSubtype::Part) {
            $tab = 'order';
        }
        if (in_array($tab, ['fitting', 'assembly', 'delivery', 'checklist'], true) && $this->record->getSubtype() === OrderSubtype::Service) {
            $tab = 'service';
        }

        if (
            in_array($tab, ['fitting', 'assembly', 'checklist'], true)
            && $this->record instanceof Main
            && $this->record->usesUnitSimplifiedSalesFlow()
        ) {
            $tab = 'order';
        }

        $this->orderViewTab = $tab;
    }

    /**
     * @return list<string>
     */
    protected function getAvailableOrderViewTabs(?OrderStatus $status): array
    {
        $subtype = $this->record->getSubtype();
        $isPart = $subtype === OrderSubtype::Part;
        $isService = $subtype === OrderSubtype::Service;

        $tabs = ['order', 'fitting', 'service', 'notes', 'checklist'];
        if (! $isService) {
            $tabs = array_values(array_filter($tabs, fn (string $tab): bool => $tab !== 'service'));
        }
        if ($isPart || $isService) {
            $tabs = array_values(array_filter($tabs, fn (string $tab): bool => ! in_array($tab, ['fitting', 'checklist'], true)));
        }

        if (OrderStatus::shouldShowOrderViewProductsTab($status)) {
            $tabs[] = 'purchase';
        }
        if (! $isService && ! $isPart && OrderStatus::shouldShowOrderViewAssemblyTab($status)) {
            $tabs[] = 'assembly';
        }
        if (! $isService && ! $isPart && OrderStatus::shouldShowOrderViewDeliveryTab($status)) {
            $tabs[] = 'delivery';
        }
        if (OrderStatus::shouldShowOrderViewShippingTab($status)) {
            $tabs[] = 'shipping';
        }

        if (
            $subtype === OrderSubtype::Unit
            && $this->record instanceof Main
            && $this->record->usesUnitSimplifiedSalesFlow()
        ) {
            $tabs = array_values(array_filter(
                $tabs,
                fn (string $tab): bool => ! in_array($tab, ['fitting', 'checklist', 'assembly'], true)
            ));
        }

        return $tabs;
    }

    /**
     * Fingerprint of financial documents tied to a main (ids concatenated); same query scope as OrderDocsTableWidget.
     */
    protected function getFinancialDocsSignature(int $mainId): string
    {
        return BaseOrder::withoutGlobalScopes()
            ->where('main_id', $mainId)
            ->whereIn('type', [
                OrderType::Quote->value,
                OrderType::Order->value,
                OrderType::Invoice->value,
                OrderType::DepositInvoice->value,
                OrderType::CreditInvoice->value,
            ])
            ->where('status', '!=', OrderGeneralStatus::Initial)
            ->orderByDesc('created_at')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->implode(',');
    }

    /**
     * Order status timeline grouped by main status: always shows all 7 main phases and all canonical sub-statuses.
     *
     * @return array<int, array{mainNumber: int, mainLabel: string, items: array<int, array{status: OrderStatus, label: string, date: \Carbon\Carbon|null, changedByUserName: string|null, isCurrent: bool}>}>
     */
    public function getOrderStatusTimeline(): array
    {
        $currentStatusValue = $this->record->order_status?->value ?? null;
        $statusChanges = $this->record->statusChanges()->with('changedBy')->get();

        $currentStatus = OrderStatus::tryFrom($currentStatusValue ?? '');

        $blocks = [];
        foreach (OrderStatus::getMainStatuses() as $mainStatus) {
            $subStatuses = OrderStatus::getSubStatuses($mainStatus);
            if ($subStatuses === []) {
                continue;
            }

            $items = [];
            foreach ($subStatuses as $subStatus) {
                $change = $statusChanges
                    ->filter(function ($statusChange) use ($subStatus): bool {
                        $normalized = OrderStatus::normalizeLegacyStatus((string) $statusChange->to_status);

                        return $normalized === $subStatus;
                    })
                    ->sortByDesc('created_at')
                    ->first();

                $items[] = [
                    'status' => $subStatus,
                    'label' => OrderStatus::formatSubStatusLabel($subStatus),
                    'date' => $change?->created_at,
                    'changedByUserName' => $change?->changedBy?->name,
                    'isCurrent' => $currentStatus === $subStatus,
                ];
            }

            $blocks[] = [
                'mainNumber' => OrderStatus::getMainStatusNumber($mainStatus),
                'mainLabel' => $mainStatus->getLabel() ?? $mainStatus->value,
                'items' => $items,
            ];
        }

        return $blocks;
    }

    private function isPartDeliveryCompletionSelectable(OrderStatus $target, ?OrderStatus $current): bool
    {
        if ($current === null) {
            return false;
        }

        return $target === OrderStatus::Delivered
            && in_array($current, [OrderStatus::ReadyForPickup, OrderStatus::DeliveryPlanned], true);
    }

    /**
     * Unit B2B (simplified invoice-customer) flow: no packing slip to advance delivery; allow choosing
     * gedeeltelijk geleverd / geleverd from active levering substeps.
     */
    private function isManualDeliveryCompletionSelectableInUnitSimplifiedFlow(OrderStatus $target, ?OrderStatus $current): bool
    {
        if ($current === null) {
            return false;
        }

        if ($target !== OrderStatus::PartiallyDelivered && $target !== OrderStatus::Delivered) {
            return false;
        }

        return in_array($current, [
            OrderStatus::Delivery,
            OrderStatus::ReadyForPickup,
            OrderStatus::DeliveryPlanned,
            OrderStatus::PartiallyDelivered,
        ], true);
    }

    /**
     * Part/Service: verberg Offerte-statussen in dropdown/tijdlijn zodra de aanvraag niet meer in de offerte-fase zit.
     * Wijzigt niet de actieve categorie (blijft Montage/Inkoop/Verzending enz. volgens huidige status).
     */
    private function partOrServiceSkipsQuoteStatusUi(?OrderStatus $current): bool
    {
        if ($current === null) {
            return false;
        }

        $subtype = $this->record->getSubtype();
        if (! in_array($subtype, [OrderSubtype::Part, OrderSubtype::Service], true)) {
            return false;
        }

        $mainPhase = OrderStatus::getMainStatusFor($current);

        return ! in_array($mainPhase, [OrderStatus::Quote, OrderStatus::Fitting], true);
    }
}
