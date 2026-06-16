<?php

namespace App\Http\Controllers;

use App\Filament\Support\SalesAuthorization;
use App\Models\ImportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportBatchExportDownloadController extends Controller
{
    public function __invoke(Request $request, ImportExport $importExport): StreamedResponse
    {
        abort_unless(SalesAuthorization::canManage(), 403);

        $path = "exports/{$importExport->import_id}/{$importExport->uid}.xlsx";

        abort_if(! Storage::disk($importExport->file_disk)->exists($path), 404);

        return Storage::disk($importExport->file_disk)->download(
            $path,
            $importExport->file_name,
        );
    }
}
