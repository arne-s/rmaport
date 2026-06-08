<?php

namespace App\Filament\Tables\Actions\Portal;

use App\Enums\OrderGeneralStatus;
use App\Models\Order\BaseOrder;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class DownloadInvoicesAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $customerId = Auth::user()?->id;
        $earliestYear = $this->getInvoiceQuery($customerId)
            ->selectRaw('YEAR(MIN(sent_at)) as year')
            ->value('year') ?? date('Y');

        $this
            ->label('Facturen downloaden')
            ->extraAttributes(['class' => 'portalHeaderAction'])
            ->modalWidth(Width::ExtraLarge)
            ->modalHeading(new HtmlString('
                <h6 class="fi-modal-heading" style="color: #3A343E; font-size: 21px; margin-top: -5px;">
                Facturen downloaden
                </h6>
            '))
            ->modalSubmitActionLabel('Facturen downloaden')
            ->modalCancelAction(false)
            ->extraModalWindowAttributes(['class' => 'downloadInvoices'])
            ->schema([
                Html::make('<hr>'),

                Group::make([
                    Select::make('period')
                        ->label('Van welke periode wil je facturen exporteren?')
                        ->inlineLabel()
                        ->options([
                            'month' => 'Maand',
                            'quarter' => 'Kwartaal',
                            'year' => 'Jaar',
                        ])
                        ->placeholder('Selecteer een periode')
                        ->live()
                        ->required()
                        ->markAsRequired(false),
                ])->extraAttributes(['class' => 'fieldsContainer']),

                Html::make('<hr>')
                    ->visible(fn (Get $get) => $get('period')),

                Group::make([
                    Select::make('month')
                        ->label('Kies een maand:')
                        ->inlineLabel()
                        ->options([
                            1 => 'Januari',
                            2 => 'Februari',
                            3 => 'Maart',
                            4 => 'April',
                            5 => 'Mei',
                            6 => 'Juni',
                            7 => 'Juli',
                            8 => 'Augustus',
                            9 => 'September',
                            10 => 'Oktober',
                            11 => 'November',
                            12 => 'December',
                        ])
                        ->placeholder('Selecteer een maand')
                        ->default(date('n'))
                        ->visible(fn (Get $get) => $get('period') === 'month' && $get('year'))
                        ->required()
                        ->markAsRequired(false),

                    Select::make('quarter')
                        ->label('Kies een kwartaal:')
                        ->inlineLabel()
                        ->options([
                            1 => 'Kwartaal 1',
                            2 => 'Kwartaal 2',
                            3 => 'Kwartaal 3',
                            4 => 'Kwartaal 4',
                        ])
                        ->placeholder('Selecteer een kwartaal')
                        ->visible(fn (Get $get) => $get('period') === 'quarter' && $get('year'))
                        ->required()
                        ->markAsRequired(false),

                    Select::make('year')
                        ->label('Kies een jaar:')
                        ->inlineLabel()
                        ->options(
                            // From current year to earliest year with invoices
                            collect(range(date('Y'), $earliestYear))
                                ->mapWithKeys(fn($year) => [$year => (string) $year])
                                ->toArray()
                        )
                        ->placeholder('Selecteer een jaar')
                        ->default(date('Y'))
                        ->live()
                        ->visible(fn (Get $get) => $get('period'))
                        ->required()
                        ->markAsRequired(false),
                ])->extraAttributes(['class' => 'fieldsContainer']),
            ])
            ->action(function (array $data, Action $action) {
                return $this->downloadInvoices($data, $action);
            });
    }

    private function getInvoiceQuery(?int $customerId): Builder
    {
        return BaseOrder::whereIn('type', ['invoice', 'deposit_invoice', 'credit_invoice'])
            ->where('billing_customer_id', $customerId)
            ->whereNotNull('uid')
            ->whereNotNull('sent_at')
            ->whereNotIn('status', [OrderGeneralStatus::Initial->value, OrderGeneralStatus::Draft->value]);
    }

    private function downloadInvoices(array $data, Action $action)
    {
        $customerId = Auth::user()?->id;

        $period = $data['period'] ?? null;
        $year = $data['year'] ?? null;

        /** @var Builder $query */
        $query = $this->getInvoiceQuery($customerId);

        if ($period === 'year' && $year) {
            $query
                ->whereYear('sent_at', $year);
        } elseif ($period === 'month' && $year && !empty($data['month'])) {
            $month = (int) $data['month'];
            $query
                ->whereYear('sent_at', $year)
                ->whereMonth('sent_at', $month);
        } elseif ($period === 'quarter' && $year && !empty($data['quarter'])) {
            $quarter = (int) $data['quarter'];
            $months = [];
            if ($quarter === 1) $months = [1,2,3];
            if ($quarter === 2) $months = [4,5,6];
            if ($quarter === 3) $months = [7,8,9];
            if ($quarter === 4) $months = [10,11,12];

            $query
                ->whereYear('sent_at', $year)
                ->where(function ($q) use ($months) {
                    foreach ($months as $m) {
                        $q->orWhereMonth('sent_at', $m);
                    }
                });
        } else {
            Notification::make()->title('Ongeldige periode')->danger()->send();
            $action->halt();
            return;
        }

        /** @var Collection<int, BaseOrder> $invoices */
        $invoices = $query
            ->orderBy('sent_at')
            ->get();

        if ($invoices->isEmpty()) {
            Notification::make()
                ->title('Geen facturen gevonden')
                ->body('Er zijn geen facturen gevonden voor de geselecteerde periode.')
                ->warning()
                ->send();

            $action->halt();
            return;
        }

        // Collect PDF file paths from storage (use saved doc_path). Fall back to generating temp PDFs if needed.
        $pdfFiles = [];
        foreach ($invoices as $invoice) {
            try {
                // Fallback: generate PDF from doc in memory and write to temp file
                if (empty($invoice->getDoc())) {
                    $invoice->generateDoc();
                    $invoice->save();
                    $invoice->saveDocToStorage();
                }

                if (empty($invoice->getDoc())) {
                    continue;
                }

                $docPath = $invoice->getDocPath();

                if ($docPath && Storage::disk('public')->exists($docPath)) {
                    // Use stored file directly
                    $realPath = Storage::disk('public')->path($docPath);

                    $pdfFiles[] = [
                        'path' => $realPath,
                        'filename' => $invoice->getFilename() . '.pdf',
                    ];
                }
            } catch (\Throwable $e) {
                report($e);
                continue;
            }
        }

        if (empty($pdfFiles)) {
            Notification::make()
                ->title('Geen facturen gevonden')
                ->body('Er zijn geen (geldige) facturen gegenereerd voor de geselecteerde periode.')
                ->warning()
                ->send();
            $action->halt();
            return;
        }

        $tmpFile = sys_get_temp_dir() . '/invoices_' . uniqid() . '.zip';
        $zip = new ZipArchive();
        // Create the zip file. If it fails notify the user
        if ($zip->open($tmpFile, ZipArchive::CREATE) !== true) {
            Notification::make()
                ->title('Er is een fout opgetreden bij het downloaden van de facturen. Probeer het later opnieuw.')
                ->danger()
                ->send();

            $action->halt();
            return;
        }

        foreach ($pdfFiles as $f) {
            if (file_exists($f['path'])) {
                $zip->addFile($f['path'], $f['filename']);
            }
        }

        $zip->close();

        if (!file_exists($tmpFile)) {
            Notification::make()
                ->title('Er is een fout opgetreden bij het downloaden van de facturen. Probeer het later opnieuw.')
                ->danger()
                ->send();

            $action->halt();
            return;
        }

        $downloadName = 'facturen_' . ($year ?? date('Y'));
        if ($period === 'month' && !empty($data['month'])) {
            $downloadName .= '_' . str_pad($data['month'], 2, '0', STR_PAD_LEFT);
        } elseif ($period === 'quarter' && !empty($data['quarter'])) {
            $downloadName .= '_kwartaal_' . $data['quarter'];
        }
        $downloadName .= '.zip';

        try {
            Notification::make()
                ->title('Facturen')
                ->body('De facturen voor de geselecteerde periode worden binnen enkele seconden gedownload.')
                ->success()
                ->send();

            return response()
                ->download($tmpFile, $downloadName)
                ->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title('Er is een fout opgetreden bij het downloaden van de facturen. Probeer het later opnieuw.')
                ->danger()
                ->send();

            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }

            $action->halt();
            return;
        }
    }
}
