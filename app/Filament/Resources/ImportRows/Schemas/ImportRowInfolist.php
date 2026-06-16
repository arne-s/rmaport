<?php

namespace App\Filament\Resources\ImportRows\Schemas;

use App\Models\ImportRow;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

class ImportRowInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Importregel')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('source.name')
                            ->label('Bron'),
                        TextEntry::make('reference')
                            ->label('Referentie'),
                        TextEntry::make('customer_order_id')
                            ->label('Customer order ID'),
                        TextEntry::make('assignment_nr')
                            ->label('Opdrachtnummer'),
                        TextEntry::make('ean_nr')
                            ->label('EAN'),
                        TextEntry::make('product_name')
                            ->label('Productnaam import')
                            ->columnSpanFull(),
                        TextEntry::make('customer_nr')
                            ->label('Klantnummer'),
                        TextEntry::make('source_description')
                            ->label('Bronomschrijving')
                            ->columnSpanFull(),
                        TextEntry::make('purchase_date')
                            ->label('Aankoopdatum')
                            ->date('d-m-Y'),
                        TextEntry::make('return_date')
                            ->label('Retourdatum')
                            ->date('d-m-Y'),
                        TextEntry::make('is_doa')
                            ->label('DOA')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Ja' : 'Nee'),
                        TextEntry::make('return_reason')
                            ->label('Retourreden')
                            ->columnSpanFull(),
                        TextEntry::make('accessories')
                            ->label('Accessoires')
                            ->columnSpanFull(),
                    ]),
                Section::make('Importbatch')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('importBatch.file_name')
                            ->label('Bestand'),
                        TextEntry::make('importBatch.user.name')
                            ->label('Geüpload door'),
                        TextEntry::make('importBatch.reference')
                            ->label('Batch referentie'),
                        TextEntry::make('importBatch.track_trace_nr')
                            ->label('Track & Trace'),
                        TextEntry::make('importBatch.import_date')
                            ->label('Aanvraagdatum')
                            ->date('d-m-Y'),
                        TextEntry::make('importBatch.shipment_date')
                            ->label('Verzenddatum')
                            ->date('d-m-Y'),
                        TextEntry::make('importBatch.created_at')
                            ->label('Geïmporteerd op')
                            ->dateTime('d-m-Y H:i'),
                        TextEntry::make('importBatch.file_path')
                            ->label('Download')
                            ->formatStateUsing(function (?string $state, ImportRow $record): ?string {
                                $path = $record->importBatch?->file_path;

                                if ($path === null || ! Storage::disk('local')->exists($path)) {
                                    return 'Bestand niet beschikbaar';
                                }

                                return $record->importBatch?->file_name;
                            })
                            ->url(function (ImportRow $record): ?string {
                                $path = $record->importBatch?->file_path;

                                if ($path === null || ! Storage::disk('local')->exists($path)) {
                                    return null;
                                }

                                return route('import-batches.download', $record->importBatch);
                            })
                            ->openUrlInNewTab(),
                    ]),
            ]);
    }
}
