<?php

namespace App\Filament\Resources\UnitInvoicingResource\Pages;

use App\Enums\OrderGeneralStatus;
use App\Enums\PaymentTerms;
use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\Resource;
use App\Filament\Resources\UnitInvoicingResource;
use App\Filament\Support\ImportExportAuthorization;
use App\Filament\Tables\Columns\InvoiceNumberColumn;
use App\Filament\Tables\Columns\OrderNumberPageColumn;
use App\Filament\Tables\Columns\PaidColumn;
use App\Models\Order\DepositInvoice;
use App\Models\Order\Main;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListUnitInvoicing extends ListRecords
{
    protected static string $resource = UnitInvoicingResource::class;

    protected static ?string $title = 'Unit factuuroverzicht';

    protected static ?string $breadcrumb = 'Unit factuuroverzicht';

    public function getBreadcrumbs(): array
    {
        return [
            '' => 'Reporting',
            route('filament.app.resources.unit-invoicing.index') => 'Unit factuuroverzicht',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back mt-1',
            ]))
            ->columns([
                OrderNumberPageColumn::make('uid')
                    ->label('Aanvraagnummer')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('subtype')
                    ->label('Type')
                    ->state(fn (Main $record): string => $record->getSubtype()?->getLabel() ?? '-')
                    ->sortable(['subtype']),

                TextColumn::make('billing_customer_id')
                    ->label('Factuurklant')
                    ->state(fn (Main $record): string => $this->invoiceCustomerLabel($record))
                    ->sortable(['billing_customer_id'])
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'billingCustomer',
                        fn (Builder $q): Builder => $q->where('name', 'like', "%{$search}%"),
                    )),

                TextColumn::make('payment_terms')
                    ->label('Betalingsvoorwaarden')
                    ->state(fn (Main $record): string => $this->paymentTermsLabel($record)),

                InvoiceNumberColumn::make('deposit_invoice.uid')
                    ->label('Aanbetalingsfactuur'),

                PaidColumn::make('deposit_invoice.payment')
                    ->label('Betaald'),

                InvoiceNumberColumn::make('invoice.uid')
                    ->label('Slotfactuur'),

                PaidColumn::make('invoice.payment')
                    ->label('Betaald'),
            ])
            ->deferFilters(false)
            ->filters([
                Resource::getDateFilter(),
            ], layout: FiltersLayout::AboveContent)
            ->defaultSort('created_at', 'desc')
            ->paginated([50, 100, 250, 'all'])
            ->defaultPaginationPageOption(50)
            ->recordActions([])
            ->headerActions([
                Action::make('export_excel')
                    ->label('Excel export')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => ImportExportAuthorization::canManage())
                    ->action(fn (): ?BinaryFileResponse => $this->exportUnitInvoicingSpreadsheet()),
            ]);
    }

    public function exportUnitInvoicingSpreadsheet(): ?BinaryFileResponse
    {
        abort_unless(ImportExportAuthorization::canManage(), 403);

        Storage::makeDirectory('exports');

        $basename = 'unit_factuuroverzicht_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.xlsx';
        $filepath = storage_path('app/exports/' . $basename);

        $xlsxOptions = new XlsxOptions();
        $xlsxOptions->DEFAULT_COLUMN_WIDTH = 22;

        $writer = new XlsxWriter($xlsxOptions);
        $writer->openToFile($filepath);
        $writer->addRow(Row::fromValues([
            'Aanvraagnummer',
            'Type',
            'Factuurklant',
            'Betalingsvoorwaarden',
            'Aanbetalingsfactuur',
            'Betaald (aanbetaling)',
            'Slotfactuur',
            'Betaald (slot)',
        ]));

        $query = $this->getFilteredTableQuery()
            ->reorder()
            ->orderByDesc('created_at');

        foreach ($query->cursor() as $record) {
            if (! $record instanceof Main) {
                continue;
            }

            $writer->addRow(Row::fromValues([
                (string) ($record->getUid() ?? $record->uid ?? ''),
                $record->getSubtype()?->getLabel() ?? '-',
                $this->invoiceCustomerLabel($record),
                $this->paymentTermsLabel($record),
                $this->exportInvoiceNumberLabel($record, 'deposit'),
                $this->exportPaidLabel($record, 'deposit'),
                $this->exportInvoiceNumberLabel($record, 'invoice'),
                $this->exportPaidLabel($record, 'invoice'),
            ]));
        }

        $writer->close();

        return response()->download($filepath)->deleteFileAfterSend(true);
    }

    private function exportInvoiceNumberLabel(Main $main, string $kind): string
    {
        $doc = $kind === 'deposit'
            ? $this->resolveDepositDocument($main)
            : $main->getInvoice();

        if ($doc === null) {
            return '';
        }

        if ((int) ($doc->getIsCancelled() ?? 0) === 1) {
            return 'Geannuleerd';
        }

        $uid = (string) ($doc->getUid() ?? $doc->uid ?? '');
        if ($uid === '') {
            return '';
        }

        $sentAt = $doc->getSentAt();
        $status = $doc->getStatus();
        $isSent = $sentAt !== null
            && $status !== OrderGeneralStatus::Draft
            && $status !== OrderGeneralStatus::Initial;

        return $isSent ? $uid : 'In behandeling';
    }

    private function exportPaidLabel(Main $main, string $kind): string
    {
        if ($kind === 'deposit' && ! PaymentTerms::requiresDepositInvoice(
            $main->payment_terms instanceof PaymentTerms
                ? $main->payment_terms
                : PaymentTerms::tryFrom($main->getPaymentTermsValueForBillingContext()),
        )) {
            return 'N.v.t.';
        }

        $doc = $kind === 'deposit'
            ? $this->resolveDepositDocument($main)
            : $main->getInvoice();

        if ($doc === null) {
            return '';
        }

        $paidAt = $doc->paid_at;
        if ($paidAt !== null) {
            return $paidAt->format('d/m/Y');
        }

        return 'Nee';
    }

    private function resolveDepositDocument(Main $main): ?DepositInvoice
    {
        $deposit = $main->depositInvoice;

        return $deposit instanceof DepositInvoice ? $deposit : null;
    }

    private function invoiceCustomerLabel(Main $main): string
    {
        $name = trim((string) ($main->billingCustomer?->getName() ?? ''));

        return $name !== '' ? $name : 'Particulier';
    }

    private function paymentTermsLabel(Main $main): string
    {
        $terms = $main->payment_terms instanceof PaymentTerms
            ? $main->payment_terms
            : PaymentTerms::tryFrom($main->getPaymentTermsValueForBillingContext());

        return $terms?->getLabel() ?? '-';
    }
}
