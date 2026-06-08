<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Enums\FulfillmentType;
use App\Enums\OrderGeneralStatus;
use App\Enums\OrderProductStatus;
use App\Enums\OrderSubtype;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Models\Order\Order;
use App\Enums\OrderStatus;
use App\Filament\Resources\Mains\MainResource as MainsResource;
use App\Filament\Resources\OrderResource\Actions\RegisterPostNLShipmentAction;
use App\Filament\Resources\OrderResource\Actions\SendPackingSlipAction;
use App\Filament\Resources\OrderResource\Actions\SendOrderEmailAction;
use App\Filament\Resources\OrderResource\Support\OrderCustomerMailRecipients;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\OrderProduct;
use App\Services\InventoryService;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\Action;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\WithFileUploads;

/**
 * @property Main $record
 */
class ViewOrder extends ViewRecord implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use WithFileUploads;
    use Concerns\TracksViewOrderUnsavedChanges;
    use Traits\DeliveryLocationTrait;
    use Traits\FittingTrait;
    use Traits\ServiceTrait;
    use Traits\StatusTrait;

    protected static string $resource = MainsResource::class;

    protected static ?string $title = 'Aanvraag';

    protected string $view = 'filament.resources.orders.pages.view-order';


    public function getTitle(): string
    {
        $main = $this->record?->getMain() ?? $this->record;

        return $main?->getUid() ?? '';
    }

    protected $listeners = [
        'saveCompanyForm',
        'saveCustomerForm',
        'order-docs-updated' => 'onOrderDocsUpdated',
        'refreshProductsTab' => 'onRefreshProductsTab',
    ];

    public int $orderDocsVersion = 0;

    public ?float $totalAmountPurchase = null;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> Used by mail modal document upload. */
    public array $documentFiles = [];

    public ?OrderProduct $confirmOrderProductRecord = null;

    public ?string $orderSubtype = null;

    public string $orderReference = '';

    public string $referenceInternal = '';

    public string $assemblyNotes = '';
    public string $checklistExtraNote = '';
    public string $checklistComments = '';
    public string $checklistAxleSize = '';
    public string $checklistWeight = '';
    public string $deliveryNoteAttendees = '';
    public string $deliveryNoteGeneralNotes = '';
    public string $shippingNotes = '';

    /** Chair color (request / assembly): stored in `additional.chair_color` when set or different from frame product default. */
    public string $orderChairColor = '';

    protected function isNonFilamentModalActive(): bool
    {
        return $this->showPickCompleteReadyForAssemblyModal
            || $this->showPassingCompleteConfirm
            || $this->showFittingCancelledConfirm
            || $this->showOrderApprovedConfirm;
    }

    public function shouldPollOrderStatus(): bool
    {
        if ($this->isNonFilamentModalActive()) {
            return false;
        }

        if (! empty($this->mountedActions)) {
            return false;
        }

        if (property_exists($this, 'mountedFormComponentActions') && ! empty($this->mountedFormComponentActions)) {
            return false;
        }

        return true;
    }

    public function mount(string|int $record): void
    {
        static::authorizeResourceAccess();

        $requestedKey = trim((string) $record);
        /** @var Main $resolvedMain */
        $resolvedMain = $this->resolveRecord($requestedKey);

        if ((string) $resolvedMain->getKey() !== $requestedKey) {
            $suffix = filled(request()->getQueryString()) ? '?'.request()->getQueryString() : '';

            $this->redirect(
                route('filament.app.resources.mains.view', ['record' => $resolvedMain->getKey()]).$suffix,
                navigate: false,
            );
        }

        $this->record = $resolvedMain;
        abort_unless(static::getResource()::canView($this->getRecord()), 403);
        $this->orderSubtype = $resolvedMain->subtype?->value ?? '';
        $this->orderReference = $resolvedMain->reference ?? '';
        $this->referenceInternal = $resolvedMain->reference_internal ?? '';
        $this->assemblyNotes = (string) data_get($resolvedMain->getAdditional() ?? [], 'assembly_notes', '');
        $this->shippingNotes = (string) data_get($resolvedMain->getAdditional() ?? [], 'shipping_notes', '');
        $this->checklistExtraNote = (string) data_get($resolvedMain->getAdditional() ?? [], 'checklist_extra_note', '');
        $this->checklistComments = (string) data_get($resolvedMain->getAdditional() ?? [], 'checklist_comments', '');
        $this->checklistAxleSize = (string) data_get($resolvedMain->getAdditional() ?? [], 'axle_size', '');
        $this->checklistWeight = (string) data_get($resolvedMain->getAdditional() ?? [], 'weight', '');
        $this->orderChairColor = $this->resolveEffectiveChairColorForMount($resolvedMain);

        $this->orderStatus = $this->record->getOrderStatus()?->value;
        $this->orderStatusFromDb = $this->orderStatus;
        $this->financialDocsSignature = $this->getFinancialDocsSignature($resolvedMain->getId());

        $this->loadFittingFields();
        $this->loadDeliveryFields();
        $this->loadServiceFields();

        $this->redirectToStatusTabIfNeeded();
        $this->normalizeOrderViewTabForCurrentRecord();

        $this->clearOrderViewDirty();
    }

    /**
     * Align header status dropdown and tab rules with the database after {@see Main::changeOrderStatus()} (or similar) outside the normal save flow.
     */
    public function syncOrderStatusUiFromDatabase(): void
    {
        $this->record->refresh();
        $this->orderStatusFromDb = $this->record->getOrderStatus()?->value;
        $this->orderStatus = $this->orderStatusFromDb;
        $this->normalizeOrderViewTabForCurrentRecord();
    }

    protected function resolveRecord(int|string $key): Main
    {
        $main = Main::query()->find($key);

        if ($main !== null) {
            return $main;
        }

        $candidate = BaseOrder::withoutGlobalScopes()->find($key);

        if ($candidate === null) {
            abort(404);
        }

        $mainId = $candidate->main_id;

        if ($mainId !== null && $mainId !== '' && (int) $mainId !== 0) {
            return Main::query()->findOrFail((int) $mainId);
        }

        abort(404);
    }

    /**
     * Events for the history tab: from the main order when viewing a child, otherwise from the record.
     */
    public function getOrderEventsForHistory(): \Illuminate\Support\Collection
    {
        $order = $this->record->getMain() ?? $this->record;

        return $order->orderEvents()->with('user')->orderByDesc('id')->get();
    }


    /**
     * Order or quote used for finance totals (Purchase/Sales/Margin). Prefer main's quote, else main's order.
     */
    protected function getFinancialRecord(): ?BaseOrder
    {
        return $this->record->quote;
    }



    protected function customerForm(Schema $schema): Schema
    {
        return $schema
            ->components([])
            ->statePath('data')
            ->model($this->getRecord());
    }

    protected function fillCustomerForm()
    {
        $data = $this->getRecord()->attributesToArray();
        $this->customerForm->fill($data);
    }

    /**
     * The customer form is filled from the record at mount. Tab-specific JSON (maten, checklist) and
     * fitting/service notes are saved via dedicated properties or child components and must not be
     * overwritten by stale form state when persisting the record.
     *
     * @return array<string, mixed>
     */
    protected function customerFormDataForRecordSave(): array
    {
        $data = $this->customerForm->getState();

        unset(
            $data['fitting_measurements'],
            $data['checklist'],
            $data['fitting_note'],
            $data['service_note'],
        );

        return $data;
    }

    public function saveCustomerForm(): void
    {
        // Validate the form and get the form data
        $data = $this->customerFormDataForRecordSave();

        $this->record->fill($data);
        $this->record->save();

        Notification::make()
            ->title('Opgeslagen.')
            ->success()
            ->send();

        $this->clearOrderViewDirty();
    }


    /**
     * @param bool $showSavedToast When true (footer Save), show the saved toast unless this invocation actually ran {@see Main::changeOrderStatus()} (see {@see $orderStatusMutatedInApplyCall}).
     */
    public function saveOrderDetails(bool $showSavedToast = false): void
    {
        if ($this->applyOrderStatusChange()) {
            return; // on error, stop saving
        }

        $this->record->refresh();

        $this->record->fill($this->customerFormDataForRecordSave());
        $this->persistAssemblyTabAdditional();

        if (!$this->saveFittingFields()) {
            return;
        }

        if (!$this->saveServiceFields()) {
            return;
        }

        if (!$this->saveDeliveryFields()) {
            return;
        }

        $this->record->save();

        $this->record->refresh();
        ++$this->orderDocsVersion;
        $this->orderChairColor = $this->resolveEffectiveChairColorForMount($this->record);
        $this->orderStatusFromDb = $this->record->getOrderStatus()?->value;
        $this->orderStatus = $this->orderStatusFromDb;
        $this->loadFittingFields();
        $this->loadDeliveryFields();
        $this->loadServiceFields();

        $this->dispatch('fitting-measurements-reload');

        $this->clearOrderViewDirty();

        if ($showSavedToast && !$this->orderStatusMutatedInApplyCall) {
            Notification::make()
                ->title('Opgeslagen.')
                ->success()
                ->send();
        }
    }

    /**
     * @param array<int, array{description: string, date: string, checked_at?: string, checked_by_name?: string}> $checklistRows
     */
    protected function persistAssemblyTabAdditional(): void
    {
        $additional = $this->record->getAdditional() ?? [];

        $assemblyNotes = trim($this->assemblyNotes);
        if ($assemblyNotes === '') {
            unset($additional['assembly_notes']);
        } else {
            $additional['assembly_notes'] = $assemblyNotes;
        }

        $checklistExtraNote = trim($this->checklistExtraNote);
        if ($checklistExtraNote === '') {
            unset($additional['checklist_extra_note']);
        } else {
            $additional['checklist_extra_note'] = $checklistExtraNote;
        }

        $checklistComments = trim($this->checklistComments);
        if ($checklistComments === '') {
            unset($additional['checklist_comments']);
        } else {
            $additional['checklist_comments'] = $checklistComments;
        }

        $axleSize = trim($this->checklistAxleSize);
        if ($axleSize === '') {
            unset($additional['axle_size']);
        } else {
            $additional['axle_size'] = $axleSize;
        }

        $weight = trim($this->checklistWeight);
        if ($weight === '') {
            unset($additional['weight']);
        } else {
            $additional['weight'] = $weight;
        }

        if ($this->record->getSubtype() === OrderSubtype::Unit) {
            $productChairColor = data_get($this->record->getOrderForPurchase()?->frameProduct?->additional, 'chair_color');
            $chairColorVal = trim($this->orderChairColor);
            if ($chairColorVal === '' || (is_string($productChairColor) && $chairColorVal === $productChairColor)) {
                unset($additional['chair_color']);
            } else {
                $additional['chair_color'] = $chairColorVal;
            }
        }

        $shippingNotes = trim($this->shippingNotes);
        if ($shippingNotes === '') {
            unset($additional['shipping_notes']);
        } else {
            $additional['shipping_notes'] = $shippingNotes;
        }

        $this->record->setAdditional($additional !== [] ? $additional : null);
    }

    protected function resolveEffectiveChairColorForMount(Main $record): string
    {
        $saved = data_get($record->getAdditional() ?? [], 'chair_color');
        if (is_string($saved) && $saved !== '') {
            return $saved;
        }

        $frame = $record->getOrderForPurchase()?->frameProduct;
        $fromProduct = data_get($frame?->additional, 'chair_color');

        return is_string($fromProduct) ? $fromProduct : '';
    }

    public function saveDeliveryFields(): bool
    {
        if ($this->record === null) {
            return true;
        }

        if (!$this->persistDeliveryLocationFromFittingTab()) {
            return false;
        }

        $deliveryNote = array_filter([
            'attendees' => trim($this->deliveryNoteAttendees) !== '' ? trim($this->deliveryNoteAttendees) : null,
            'general_notes' => trim($this->deliveryNoteGeneralNotes) !== '' ? trim($this->deliveryNoteGeneralNotes) : null,
        ], fn($v) => $v !== null && $v !== '');

        $this->record->setDeliveryNote($deliveryNote !== [] ? $deliveryNote : null);

        return true;
    }

    /**
     * When the user uploads files via the mail modal "(add)", add them to the document owner's media and merge into the form checklist.
     */
    public function updatedDocumentFiles(): void
    {
        if (empty($this->documentFiles)) {
            return;
        }

        $allowedMimes = config('documents.allowed_mime_types', []);
        $mimetypesRule = $allowedMimes !== [] ? 'mimetypes:' . implode(',', $allowedMimes) : 'file';
        $maxKb = 10240;

        try {
            $this->validate([
                'documentFiles' => 'required|array',
                'documentFiles.*' => 'file|' . $mimetypesRule . '|max:' . $maxKb,
            ]);
        } catch (ValidationException $e) {
            $this->documentFiles = [];
            $message = $e->validator->errors()->first();
            Notification::make()
                ->title('Ongeldige bestanden.')
                ->body($message ?: 'Controleer het bestandstype en de bestandsgrootte.')
                ->danger()
                ->send();

            return;
        }

        $owner = OrderCustomerMailRecipients::documentOwnerForRecord($this->record);
        if ($owner === null) {
            $this->documentFiles = [];
            return;
        }

        $newMediaIds = [];
        $count = 0;
        $rejected = [];

        foreach ($this->documentFiles as $file) {
            if (!$file) {
                continue;
            }
            $mime = $file->getMimeType();
            if ($allowedMimes !== [] && !in_array($mime, $allowedMimes, true)) {
                $rejected[] = $file->getClientOriginalName();
                continue;
            }
            $media = $owner->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection('documents');
            $newMediaIds[] = (string)$media->id;
            $count++;
        }

        $this->documentFiles = [];
        $owner->unsetRelation('media');

        $this->mergeNewUploadedAttachmentsIntoMountedAction($newMediaIds);

        if ($count > 0) {
            Notification::make()
                ->title($count === 1 ? 'Document geüpload.' : "{$count} documenten geüpload.")
                ->success()
                ->send();
        }
        if ($rejected !== []) {
            $names = implode(', ', array_slice($rejected, 0, 5));
            if (count($rejected) > 5) {
                $names .= ' … (+' . (count($rejected) - 5) . ' meer)';
            }
            Notification::make()
                ->title('Bestandstype niet toegestaan.')
                ->body('Overgeslagen: ' . $names)
                ->danger()
                ->send();
        }
    }

    #[On('documents-uploaded')]
    public function onDocumentsUploaded(mixed ...$args): void
    {
        $newMediaIds = $this->normalizeDocumentsUploadedPayload($args);
        $this->mergeNewUploadedAttachmentsIntoMountedAction($newMediaIds);
    }

    /**
     * Normalize event payload: Livewire may pass named params as first argument (array of IDs) or as single array with key newMediaIds.
     *
     * @param array<mixed> $args
     * @return array<int|string>
     */
    protected function normalizeDocumentsUploadedPayload(array $args): array
    {
        $first = $args[0] ?? null;
        if (is_array($first) && array_key_exists('newMediaIds', $first)) {
            $first = $first['newMediaIds'];
        }
        if (!is_array($first)) {
            return [];
        }
        return array_values(array_map(fn($id) => is_int($id) ? $id : (string)$id, $first));
    }

    /**
     * When the "Mail customer" action modal is open, add newly uploaded document media IDs to the form's uploaded_attachments so they appear checked.
     */
    protected function mergeNewUploadedAttachmentsIntoMountedAction(array $newMediaIds): void
    {
        if ($newMediaIds === [] || empty($this->mountedActions)) {
            return;
        }

        $index = null;
        foreach ($this->mountedActions as $key => $mounted) {
            if (!is_array($mounted)) {
                continue;
            }
            if (($mounted['name'] ?? null) === 'send_customer_email') {
                $index = $key;
                break;
            }
            if (isset($mounted['data']['uploaded_attachments'])) {
                $index = $key;
                break;
            }
        }
        if ($index === null) {
            return;
        }

        if (!array_key_exists('data', $this->mountedActions[$index])) {
            $this->mountedActions[$index]['data'] = [];
        }

        $current = $this->mountedActions[$index]['data']['uploaded_attachments'] ?? [];
        $current = is_array($current) ? $current : [];
        $merged = array_values(array_unique(array_merge($current, $newMediaIds)));
        $this->mountedActions[$index]['data']['uploaded_attachments'] = $merged;
    }

    public function getCompanyMarginSummary(): string
    {
        $financial = $this->getFinancialRecord();
        $spMargin = $financial?->getSpMarginSummaryAttribute() ?? '';
        if (empty($spMargin)) {
            return $spMargin;
        }
        $spMargin = str_replace('(', '<span class="percentage">(', $spMargin);
        $spMargin .= str_ends_with($spMargin, '%)') ? '</span>' : '';

        return $spMargin;
    }

    public function getOrderPurchasePriceMarginSummary(): ?string
    {
        $financial = $this->getFinancialRecord();
        if ($financial === null || $this->totalAmountPurchase === null) {
            return null;
        }

        $paymentAmount = floatval($this->totalAmountPurchase);
        $companyPrice = $financial->getCompanySalesPriceTotal() ?? 0;

        $margin = $companyPrice - $paymentAmount;
        $add = '';

        if ($paymentAmount > 0) {
            $percentage = ($margin / $paymentAmount) * 100;
            $add = ' <span class="percentage">(' . round($percentage, 1) . '%)</span>';
        }

        return '€' . number_format((float)$margin, 2, ',', '.') . $add;
    }

    public function getDeltaPurchaseMargin(): ?string
    {
        $financial = $this->getFinancialRecord();
        if ($financial === null || $this->totalAmountPurchase === null) {
            return null;
        }

        $companyPrice = $financial->getCompanySalesPriceTotal() ?? 0;
        $costPrice = $financial->getCompanyPurchasePriceTotal() ?? 0;
        $paymentAmount = floatval($this->totalAmountPurchase);
        $marginAdmin = $companyPrice - $costPrice;
        $marginPurchase = $companyPrice - $paymentAmount;

        $delta = round($marginPurchase - $marginAdmin, 2);
        $sign = $delta > 0 ? '+' : ($delta < 0 ? '-' : '');

        return $sign . '€' . number_format(abs($delta), 2, ',', '.');
    }

    #[On('confirmOrderPicked')]
    public function confirmOrderPicked(bool $confirm): void
    {
        if ($confirm) {
            // Reduce physical and reserved stock when picking a Make-to-Stock product
            $orderProduct = $this->confirmOrderProductRecord;
            if ($orderProduct->getFulfillmentType() === FulfillmentType::MakeToStock) {
                $inventoryService = app(InventoryService::class);
                $inventoryService->pickOrderProduct($orderProduct);
            }

            // Update order product status
            $orderProduct->setStatus(OrderProductStatus::PickedReceived);
            $orderProduct->save();

            // Set order status to Ready for Pickup/Invoiced.
            $this->record->changeOrderStatus(OrderStatus::ReadyForPickup);

            Notification::make()
                ->title("De orderstatus is bijgewerkt.")
                ->success()
                ->send();
        }
        // $this->confirmOrderProductRecord = null;
        $this->dispatch('close-modal', id: 'order_picked_confirm');
    }

    protected function getHeaderActions(): array
    {
        return array_values(array_filter(array_merge(
            parent::getHeaderActions(),
            [
                $this->sendOrderEmailAction()->label('Mailen'),
                $this->createRegisterPostNLShipmentAction(),
                $this->createPackingSlipHeaderAction(),
            ],
        )));
    }

    private function createPackingSlipHeaderAction(): ?Action
    {
        if (! $this->record instanceof Main) {
            return null;
        }

        if ($this->record->getSubtype() !== OrderSubtype::Unit) {
            return null;
        }

        if ($this->record->usesUnitSimplifiedSalesFlow()) {
            return null;
        }

        $currentStatus = $this->record->getOrderStatus();
        if (! in_array($currentStatus, [OrderStatus::DeliveryPlanned, OrderStatus::PartiallyDelivered], true)) {
            return null;
        }

        $order = $this->record->getOrderForPurchase();

        if (! $order instanceof Order
            || in_array($order->getStatus(), [OrderGeneralStatus::Initial, OrderGeneralStatus::Draft], true)) {
            return null;
        }

        if (! $order->packingSlipEligibleOrderProducts()->whereNull('order_products.packing_slip_id')->exists()) {
            return null;
        }

        return SendPackingSlipAction::make('send_packing_slip');
    }

    private function createRegisterPostNLShipmentAction(): ?Action
    {
        $status = $this->record?->getOrderStatus();

        if ($status === null) {
            return null;
        }

        $mainPhase = OrderStatus::getMainStatusFor($status);
        $inDeliveryPhase = OrderStatus::getMainStatusFor($status) === OrderStatus::Delivery;
        $serviceAssemblyShippable = $this->record instanceof Main
            && $this->record->getSubtype() === OrderSubtype::Service
            && in_array($status, [OrderStatus::AssemblyPlanned, OrderStatus::Assembled], true);

        if (! $inDeliveryPhase && ! $serviceAssemblyShippable) {
            return null;
        }

        return RegisterPostNLShipmentAction::make();
    }

    /**
     * Options for the dealer dropdown: "not applicable" entry plus all dealer-type customers (id => name).
     *
     * @return array<string, string>
     */
    public function getCompanyOptionsForSelect(): array
    {
        $options = ['' => 'N.v.t.'];
        foreach (Customer::where('type', CustomerType::Dealer)->orderBy('name')->get() as $dealer) {
            $options[(string)$dealer->id] = $dealer->getName() ?? (string)$dealer->id;
        }

        return $options;
    }

    /**
     * Header actions for use in the custom view (e.g. view-order-header).
     */
    public function getHeaderActionsForView(): array
    {
        return $this->getHeaderActions();
    }

    public function onOrderDocsUpdated(): void
    {
        $this->orderDocsVersion++;
        $this->financialDocsSignature = $this->getFinancialDocsSignature($this->record->getId());
    }

    public function sendOrderEmailAction(): SendOrderEmailAction
    {
        return SendOrderEmailAction::make();
    }

    /**
     * Normalize mountedActions when the client sends a partial update (e.g. RichEditor only updates data.message)
     * that omits the action name, which would cause ActionNotResolvableException.
     */
    public function updatedMountedActions(mixed $value): void
    {
        $this->normalizeMountedActionsState($value);
        if (
            property_exists($this, 'mountedActions')
            && is_array($value)
            && $this->looksLikeMountedActionsState($value)
        ) {
            $this->mountedActions = $value;
        }
    }

    public function getMountedActions(): array
    {
        if (property_exists($this, 'mountedActions')) {
            $this->normalizeMountedActionsState($this->mountedActions);
        }

        return parent::getMountedActions();
    }

    public function callMountedAction(array $arguments = []): mixed
    {
        if (property_exists($this, 'mountedActions')) {
            $this->normalizeMountedActionsState($this->mountedActions);
        }

        return parent::callMountedAction($arguments);
    }

    private function normalizeMountedActionsState(mixed &$value): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => &$item) {
            if ($this->isLivewireArrayMetadata($item)) {
                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            if ($this->looksLikeMountedAction($item)) {
                $this->normalizeMountedActionPayload($item);
                $this->ensureSendCustomerEmailActionName($value, $key, $item);

                continue;
            }

            $this->normalizeMountedActionsState($item);
        }

        unset($item);
    }

    private function isLivewireArrayMetadata(mixed $item): bool
    {
        return is_array($item)
            && ($item['s'] ?? null) === 'arr'
            && count($item) === 1;
    }

    private function looksLikeMountedAction(array $item): bool
    {
        if (isset($item['name']) || isset($item['arguments']) || isset($item['context'])) {
            return true;
        }

        return $this->resolveMountedActionFormData($item) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveMountedActionFormData(array $action): ?array
    {
        $data = $action['data'] ?? null;

        if (! is_array($data)) {
            return null;
        }

        if ($this->isLivewireArrayMetadata($data)) {
            return null;
        }

        if (array_is_list($data)) {
            foreach ($data as $entry) {
                if (! is_array($entry) || $this->isLivewireArrayMetadata($entry)) {
                    continue;
                }

                return $entry;
            }

            return null;
        }

        return $data;
    }

    private function normalizeMountedActionPayload(array &$action): void
    {
        if (! isset($action['data']) || ! is_array($action['data'])) {
            return;
        }

        $unwrappedData = $this->unwrapLivewireTuple($action['data']);
        if (is_array($unwrappedData)) {
            $action['data'] = $unwrappedData;
        }

        foreach (['message', 'to', 'cc', 'bcc', 'attachments', 'uploaded_attachments'] as $field) {
            if (! array_key_exists($field, $action['data']) || ! is_array($action['data'][$field])) {
                continue;
            }

            $unwrappedField = $this->unwrapLivewireTuple($action['data'][$field]);
            if ($unwrappedField !== null) {
                $action['data'][$field] = $unwrappedField;
            }
        }
    }

    private function unwrapLivewireTuple(mixed $value): mixed
    {
        if (! is_array($value) || $this->isLivewireArrayMetadata($value)) {
            return null;
        }

        if (! array_is_list($value)) {
            return null;
        }

        foreach ($value as $entry) {
            if (is_array($entry) && ! $this->isLivewireArrayMetadata($entry)) {
                return $entry;
            }
        }

        return null;
    }

    private function ensureSendCustomerEmailActionName(array &$parent, int|string $key, array $action): void
    {
        if (isset($action['name']) && $action['name'] !== '') {
            return;
        }

        $data = $this->resolveMountedActionFormData($action);
        if ($data === null) {
            return;
        }

        $isSendCustomerEmailForm = array_key_exists('message', $data)
            || array_key_exists('to', $data)
            || array_key_exists('cc', $data)
            || array_key_exists('bcc', $data)
            || array_key_exists('attachments', $data)
            || array_key_exists('uploaded_attachments', $data)
            || array_key_exists('subject', $data)
            || array_key_exists('from', $data);

        if ($isSendCustomerEmailForm) {
            $parent[$key]['name'] = 'send_customer_email';
        }
    }

    private function looksLikeMountedActionsState(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        $first = reset($value);
        if (! is_array($first)) {
            return false;
        }

        if (isset($first['name']) || isset($first['data']) || isset($first['arguments']) || isset($first['context'])) {
            return true;
        }

        $nestedFirst = reset($first);
        if (! is_array($nestedFirst)) {
            return false;
        }

        return isset($nestedFirst['name'])
            || isset($nestedFirst['data'])
            || isset($nestedFirst['arguments'])
            || isset($nestedFirst['context']);
    }

    public function getBreadcrumbs(): array
    {
        return [
            '' => 'Verkoop',
            route('filament.app.resources.production.index') => 'Verkoopproces',
        ];
    }
}
