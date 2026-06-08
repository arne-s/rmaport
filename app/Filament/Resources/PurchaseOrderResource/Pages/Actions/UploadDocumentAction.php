<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages\Actions;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderConfirmation;
use App\Models\PurchaseOrderInvoice;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Storage;
use Throwable;

enum DocumentType: string
{
    case Confirmation = 'confirmation';
    case Invoice = 'invoice';
}

class UploadDocumentAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->name('uploadDocument')
            ->icon('heroicon-o-arrow-up-tray')
            ->label('Uploaden')
            ->extraAttributes(['class' => 'uploadDocumentAction'])
            ->authorize(fn (): bool => auth()->user()?->can('access filament panel') ?? false)
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(false)
            ->modalIcon('heroicon-o-arrow-up-tray')
            ->modalHeading('Document uploaden')
            ->modalDescription('Upload hier een document dat gekoppeld moet worden aan deze inkooporder.')
            ->modalWidth(Width::Medium)
            ->centerModal()
            ->modalFooterActionsAlignment(Alignment::Between)
            ->extraModalWindowAttributes(['class' => 'upload-document-modal modalForm'])
            ->modalSubmitActionLabel('Uploaden')
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']))
            ->schema([
                Select::make('type')
                    ->label('Type')
                    ->options([
                        DocumentType::Confirmation->value => 'Inkoopbevestiging',
                        DocumentType::Invoice->value => 'Inkoopfactuur',
                    ])
                    ->required()
                    ->live(),

                FileUpload::make('file')
                    ->label('Bestand')
                    ->disk('public')
                    ->directory(fn (Get $get) => match ($get('type')) {
                        DocumentType::Confirmation->value => PurchaseOrderConfirmation::STORAGE_DIRECTORY,
                        DocumentType::Invoice->value => 'purchase_order_uploads',
                        default => 'purchase_order_uploads',
                    })
                    ->acceptedFileTypes(['application/pdf'])
                    ->required(),

                ...$this->getConfirmationFormSchema(),
                ...$this->getInvoiceFormSchema(),
            ])
            ->action(function (PurchaseOrder $record, array $data): void {
                $this->handleSubmit($record, $data);
            });
    }

    private function getConfirmationFormSchema(): array
    {
        return [
            Grid::make(6)
                ->visible(fn (Get $get) => $get('type') === DocumentType::Confirmation->value)
                ->schema([
                    DatePicker::make('expected_delivery_date')
                        ->label('Verwachte leverdatum')
                        ->tooltip('De verwachte leverdatum wordt als leverweek getoond aan de dealer.')
                        ->required()
                        ->columnSpan(6),

                    Checkbox::make('set_confirmed')
                        ->label('Inkooporder op status Bevestigd zetten')
                        ->default(false)
                        ->columnSpan(6),
                ]),
        ];
    }

    private function getInvoiceFormSchema(): array
    {
        return [
            Grid::make(6)
                ->visible(fn (Get $get) => $get('type') === DocumentType::Invoice->value)
                ->schema([
                    TextInput::make('invoice_number')
                        ->label('Factuurnummer')
                        ->required()
                        ->columnSpan(6)
                        ->extraInputAttributes(['autocomplete' => 'off']),

                    DatePicker::make('entry_date')
                        ->label('Factuurdatum')
                        ->default(now())
                        ->required()
                        ->columnSpan(3)
                        ->extraInputAttributes(['autocomplete' => 'off']),

                    DatePicker::make('due_date')
                        ->label('Vervaldatum')
                        ->required()
                        ->columnSpan(3),

                    TextInput::make('amount')
                        ->label('Totaal excl. BTW')
                        ->prefix('€')
                        ->numeric()
                        ->inputMode('decimal')
                        ->required()
                        ->minValue(0)
                        ->columnSpan(2),

                    TextInput::make('vat_amount')
                        ->label('BTW-bedrag')
                        ->prefix('€')
                        ->numeric()
                        ->inputMode('decimal')
                        ->required()
                        ->minValue(0)
                        ->columnSpan(2),

                    TextInput::make('total_amount_inc_vat')
                        ->label('Totaal incl. BTW')
                        ->prefix('€')
                        ->numeric()
                        ->inputMode('decimal')
                        ->required()
                        ->minValue(0)
                        ->columnSpan(2),
                ]),
        ];
    }

    private function handleSubmit(PurchaseOrder $record, array $data): void
    {
        try {
            if ($data['type'] === DocumentType::Confirmation->value) {
                $this->handleConfirmationUpload($record, $data);
            } elseif ($data['type'] === DocumentType::Invoice->value) {
                $this->handleInvoiceUpload($record, $data);
            }

            $this->redirect(route('filament.app.resources.purchase-orders.view', ['record' => $record->id]));
        } catch (Throwable $e) {
            report($e);
            $this->failureNotificationTitle('Er is een fout opgetreden. Probeer het later opnieuw.');
            $this->failure();
        }
    }

    private function handleConfirmationUpload(PurchaseOrder $record, array $data): void
    {
        $expectedDeliveryDate = Carbon::parse($data['expected_delivery_date']);

        $confirmation = new PurchaseOrderConfirmation();
        $confirmation->purchase_order_id = $record->id;
        $confirmation->pdf_path = $data['file'];
        $confirmation->expected_delivery_date = $expectedDeliveryDate;
        $confirmation->email_received_at = now();
        $confirmation->save();

        $record->syncDeliveryWeekFromConfirmation($expectedDeliveryDate);

        $statusChanged = false;
        if ($data['set_confirmed'] ?? false) {
            $current = $record->getStatus();
            $target = PurchaseOrderStatus::Confirmed;

            if ($current !== $target && $this->canSetPurchaseOrderToConfirmed($current)) {
                $record->setStatus($target);
                $statusChanged = true;
            }
        }

        $record->save();

        Notification::make()
            ->title($statusChanged
                ? 'Inkoopbevestiging geüpload. Inkooporder staat op Bevestigd.'
                : 'Inkoopbevestiging succesvol geüpload.')
            ->success()
            ->send();
    }

    private function canSetPurchaseOrderToConfirmed(?PurchaseOrderStatus $from): bool
    {
        if ($from === PurchaseOrderStatus::Confirmed) {
            return false;
        }

        if ($from === null) {
            return true;
        }

        $rank = [
            PurchaseOrderStatus::Initial->value => 0,
            PurchaseOrderStatus::Draft->value => 5,
            PurchaseOrderStatus::Purchased->value => 10,
            PurchaseOrderStatus::PartiallyConfirmed->value => 20,
            PurchaseOrderStatus::Confirmed->value => 30,
            PurchaseOrderStatus::PartiallyDelivered->value => 40,
            PurchaseOrderStatus::Delivered->value => 50,
            PurchaseOrderStatus::Cancelled->value => 60,
        ];

        return ($rank[PurchaseOrderStatus::Confirmed->value] ?? 0) >= ($rank[$from->value] ?? 0);
    }

    private function handleInvoiceUpload(PurchaseOrder $record, array $data): void
    {
        $duplicate = PurchaseOrderInvoice::query()
            ->where('orderable_type', PurchaseOrder::class)
            ->where('orderable_id', $record->id)
            ->where('invoice_number', $data['invoice_number'])
            ->exists();

        if ($duplicate) {
            Notification::make()
                ->title('Deze factuurnummer is al gekoppeld aan deze inkooporder.')
                ->danger()
                ->send();

            return;
        }

        $relativePath = $data['file'];
        $absolutePath = Storage::disk('public')->path($relativePath);

        if (! is_file($absolutePath)) {
            throw new \RuntimeException('Geüpload bestand niet gevonden.');
        }

        $entryDate = Carbon::parse($data['entry_date'] ?? now());

        $invoice = PurchaseOrderInvoice::query()->create([
            'orderable_type' => PurchaseOrder::class,
            'orderable_id' => $record->id,
            'main_id' => $record->main_id,
            'exact_id' => null,
            'description' => 'Handmatig geüpload',
            'amount' => (float) $data['amount'],
            'vat_amount' => (float) $data['vat_amount'],
            'total_amount_inc_vat' => (float) $data['total_amount_inc_vat'],
            'currency' => 'EUR',
            'entry_date' => $entryDate,
            'due_date' => Carbon::parse($data['due_date']),
            'invoice_number' => $data['invoice_number'],
            'supplier_name' => $record->supplier?->name,
        ]);

        ['filename' => $filename, 'display_name' => $displayName] = $this->resolveInvoiceFilename($record, $invoice);

        $record->addMedia($absolutePath)
            ->usingFileName($filename)
            ->usingName($displayName)
            ->withCustomProperties([
                'source' => 'manual_upload',
                'purchase_order_invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'entry_date' => $invoice->entry_date?->format('Y-m-d'),
            ])
            ->toMediaCollection('documents');

        Notification::make()
            ->title('Inkoopfactuur geüpload. De factuur wordt binnen tien minuten gesynchroniseerd met Exact.')
            ->success()
            ->send();
    }

    /**
     * @return array{filename: string, display_name: string}
     */
    private function resolveInvoiceFilename(PurchaseOrder $purchaseOrder, PurchaseOrderInvoice $invoice): array
    {
        $uid = $purchaseOrder->reference_number;
        $prefix = $invoice->amount < 0 ? 'Creditfactuur' : 'Factuur';

        $existingCount = $purchaseOrder->purchaseOrderInvoices()
            ->where('id', '!=', $invoice->id)
            ->where('amount', $invoice->amount < 0 ? '<' : '>=', 0)
            ->count();

        $suffix = $existingCount > 0 ? ' (' . ($existingCount + 1) . ')' : '';

        return [
            'filename' => "{$prefix} Inkoop {$uid}{$suffix}.pdf",
            'display_name' => "{$prefix} | Inkoop | {$uid}{$suffix}",
        ];
    }
}
