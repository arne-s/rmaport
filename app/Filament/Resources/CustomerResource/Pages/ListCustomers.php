<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Filament\Imports\CustomerImporter;
use App\Filament\Resources\CustomerResource;
use App\Filament\Support\ImportExportAuthorization;
use App\Models\Customer;
use App\Support\CustomerCsvSchema;
use Filament\Actions\Action;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected static ?string $breadcrumb = 'Klanten';

    public function getDefaultTableRecordsPerPageSelectOption(): int
    {
        return 100;
    }

    public function table(Table $table): Table
    {
        $table = parent::table($table);
        $existingActions = $table->getHeaderActions();

        return $table
            ->headerActions(array_merge(
                $existingActions,
                [
                    Action::make('export_csv')
                        ->label('Export')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->visible(fn (): bool => ImportExportAuthorization::canManage())
                        ->action(fn (): BinaryFileResponse => $this->exportCustomersCsv()),
                    ImportAction::make('import_csv')
                        ->importer(CustomerImporter::class)
                        ->label('Import')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->visible(fn (): bool => ImportExportAuthorization::canManage())
                        ->csvDelimiter(CustomerCsvSchema::DELIMITER),
                ]
            ))
            ->paginationPageOptions([50, 100, 250])
            ->defaultPaginationPageOption(100);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.app.resources.customers.index') => 'Klanten',
            url()->current() => 'Klanten',
        ];
    }

    public function exportCustomersCsv(): BinaryFileResponse
    {
        abort_unless(ImportExportAuthorization::canManage(), 403);

        Storage::makeDirectory('exports');

        $basename = 'klanten_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.csv';
        $filepath = storage_path('app/exports/' . $basename);

        $handle = fopen($filepath, 'w');

        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, CustomerCsvSchema::headers(), CustomerCsvSchema::DELIMITER);

        $query = $this->getFilteredTableQuery()
            ->whereIn('type', CustomerType::csvImportTypeValues())
            ->with(['address.country', 'billingAddress.country', 'shippingAddress.country']);

        foreach ($query->orderBy('name')->cursor() as $customer) {
            if (! $customer instanceof Customer) {
                continue;
            }

            fputcsv($handle, $this->customerToCsvRow($customer), CustomerCsvSchema::DELIMITER);
        }

        fclose($handle);

        return response()->download($filepath)->deleteFileAfterSend(true);
    }

    /**
     * @return list<string|int|float|null>
     */
    private function customerToCsvRow(Customer $customer): array
    {
        return array_merge(
            [
                $customer->getId(),
                $customer->getType()?->getLabel() ?? '',
                $customer->getStatus()->getLabel(),
                $customer->name ?? '',
                $customer->salutation ?? '',
                $customer->first_name ?? '',
                $customer->middle_name ?? '',
                $customer->last_name ?? '',
                $customer->dob?->format('d-m-Y') ?? '',
                $customer->email ?? '',
                $customer->phone_number ?? '',
                $customer->mobile_phone_number ?? '',
                $customer->vat ?? '',
                $customer->kvk ?? '',
                $customer->iban ?? '',
                $customer->debtor_number ?? '',
                $customer->payment_terms?->getLabel() ?? '',
                $customer->discount_percentage !== null
                    ? number_format((float) $customer->discount_percentage, 2, ',', '')
                    : '',
                CustomerCsvSchema::formatDeliveryAddressTypeForExport($customer->delivery_address_type),
            ],
            CustomerCsvSchema::addressValues($customer->address),
            CustomerCsvSchema::addressValues($customer->billingAddress),
            CustomerCsvSchema::addressValues($customer->shippingAddress, includeLocationName: true),
            [
                $customer->comment ?? '',
                $this->formatNewsletterForCsv($customer),
            ],
        );
    }

    private function formatNewsletterForCsv(Customer $customer): string
    {
        if ($customer->getStatus() !== CustomerStatus::Active) {
            return 'Nee';
        }

        if ($customer->getType()?->usesNewsletterDealerSegments() === true) {
            $billing = (bool) ($customer->billingAddress?->newsletter_subscribed ?? false);
            $shipping = (bool) ($customer->shippingAddress?->newsletter_subscribed ?? false);
            $isCustomDelivery = ($customer->delivery_address_type ?? 'contact') === 'custom';

            $anySubscribed = $isCustomDelivery
                ? ($billing || $shipping)
                : $billing;

            return $anySubscribed ? 'Ja' : 'Nee';
        }

        return $customer->newsletter_subscribed ? 'Ja' : 'Nee';
    }
}
