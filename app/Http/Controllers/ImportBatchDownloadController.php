<?php

namespace App\Http\Controllers;

use App\Filament\Support\SalesAuthorization;
use App\Models\ImportBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportBatchDownloadController extends Controller
{
    public function __invoke(Request $request, ImportBatch $importBatch): StreamedResponse
    {
        abort_unless(SalesAuthorization::canManage(), 403);

        $path = $importBatch->file_path;

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $importBatch->file_name);
    }
}
