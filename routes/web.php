<?php

use App\Http\Controllers\ImportBatchDownloadController;
use App\Http\Controllers\ImportBatchExportDownloadController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\Quote\QuotePublicDocumentController;
use App\Http\Controllers\QuoteApprovalController;
use App\Models\OutlookExternalConnectInvite;
use App\Services\ExactOnlineService;
use App\Services\MicrosoftMailService;
use App\Services\OutlookExternalConnectInviteService;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::domain(config('app.documents_url'))->group(function () {
    Route::get('/document/{publicAccessToken}/{filename?}',
        [DocumentController::class, 'customerExport'])
        ->name('customer-export');
});


Route::get('/invoice-header', function () {
    return view('order.invoice-header');
})->name('invoice-header');

$quoteDomain = config('quote.domain');

if (is_string($quoteDomain) && $quoteDomain !== '') {
    Route::domain($quoteDomain)->group(function (): void {
        Route::get('/{uuid}/orderbevestiging.pdf', [QuotePublicDocumentController::class, 'orderConfirmation'])
            ->whereUuid('uuid')
            ->middleware('throttle:60,1')
            ->name('quote.public.order-pdf');
        Route::get('/{uuid}/factuur.pdf', [QuotePublicDocumentController::class, 'invoice'])
            ->whereUuid('uuid')
            ->middleware('throttle:60,1')
            ->name('quote.public.invoice-pdf');

        Route::get('/{uuid}/offerte.pdf', [QuoteApprovalController::class, 'pdf'])
            ->whereUuid('uuid')
            ->name('approve-quote.pdf');
        Route::get('/{uuid}', [QuoteApprovalController::class, 'show'])
            ->whereUuid('uuid')
            ->name('approve-quote');
        Route::post('/{uuid}', [QuoteApprovalController::class, 'submit'])
            ->whereUuid('uuid')
            ->middleware('throttle:20,1')
            ->name('approve-quote.submit');
    });
}

Route::group(['middleware' => ['auth']], function () {
    Route::get('/account/document/{baseOrder}', [OrderController::class, 'companyDocument'])
        ->name('order.company-document');

    Route::get('/account/document/{baseOrder}/download', [OrderController::class, 'companyExport'])
        ->name('order.company-export');

    Route::get('/account/order-margins/{orderId}', [DocumentController::class, 'companyOrderMargins'])
        ->name('order.company-order-margins');

});

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});


Route::get('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->name('logout');


Route::group(['middleware' => ['auth', 'can:export-order']], function () {
    Route::get('/order/export/{order}', [OrderController::class, 'managerExport'])
        ->name('order.manager-export');

    Route::get('/order-pdf/{id}/download', [OrderController::class, 'orderPdfDownload'])
        ->name('documents.order-pdf-download');

    Route::get('/email-preview', function () {
        return request()->get('mailable')::preview();
    })
        ->name('email.preview');
});

Route::group(['middleware' => ['auth']], function () {
    Route::get('/mail', function () {
        return \App\Mail\DepositInvoiceReminderMail::preview();
    });
    Route::get('/mail2', function () {
        return new \App\Mail\CompanyActivateConfirmUserMail(\App\Models\User::first());
    });
});

// Exact Online
Route::middleware('auth')->group(function () {
    Route::get('/exact-connect', function () {
        return redirect((new ExactOnlineService())->getAuthorizationUrl());
    })->name('exact.connect');

    Route::get('/exact-callback', function () {
        $status = (new ExactOnlineService())->saveAccessToken(request()->get('code'));
        if ($status) {
            return 'Gelukt. Dit venster kan nu gesloten worden.';
        } else {
            return 'Mislukt. <a href="' . route('exact.connect') . '">Probeer opnieuw</a>.';
        }
    })->name('exact.save');
});

$authorizeManageSettings = static function (): void {
    abort_unless(auth()->user()?->can('manage settings') ?? false, 403);
};

// Microsoft OAuth callbacks (public when ?state= external invite flow; otherwise requires auth)
Route::get('/microsoft/callback', function () use ($authorizeManageSettings) {
    $inviteService = app(OutlookExternalConnectInviteService::class);
    $parsedState = $inviteService->parseAndValidateState(request()->get('state'));

    if ($parsedState !== null && $parsedState['step'] === 'connect') {
        $invite = OutlookExternalConnectInvite::query()->find($parsedState['inviteId']);
        if ($invite === null) {
            return $inviteService->redirectToResult(false, 'Koppeling mislukt: ongeldige koppellink.');
        }

        if ($error = request()->get('error')) {
            $description = request()->get('error_description', $error);

            return $inviteService->redirectToResult(false, 'Microsoft toestemming mislukt: ' . $description);
        }

        $code = request()->get('code');
        if (! is_string($code) || $code === '') {
            return $inviteService->redirectToResult(false, 'Microsoft koppeling mislukt: geen autorisatiecode ontvangen.');
        }

        return $inviteService->handleExternalConnectCallback($invite, $code);
    }

    $authorizeManageSettings();

    return redirect(route('filament.app.resources.customers.settings').'?area=outlook-mail')
        ->with('error', 'Deze callback is niet meer beschikbaar.');
})->name('microsoft.callback');

Route::get('/microsoft-mail/callback', function () use ($authorizeManageSettings) {
    $inviteService = app(OutlookExternalConnectInviteService::class);
    $parsedState = $inviteService->parseAndValidateState(request()->get('state'));

    if ($parsedState !== null && $parsedState['step'] === 'mail') {
        $invite = OutlookExternalConnectInvite::query()->find($parsedState['inviteId']);
        if ($invite === null) {
            return $inviteService->redirectToResult(false, 'Koppeling mislukt: ongeldige koppellink.');
        }

        if ($error = request()->get('error')) {
            $description = request()->get('error_description', $error);

            return $inviteService->redirectToResult(false, 'Microsoft toestemming mislukt (e-mail): ' . $description);
        }

        $code = request()->get('code');
        if (! is_string($code) || $code === '') {
            return $inviteService->redirectToResult(false, 'Microsoft koppeling mislukt: geen autorisatiecode ontvangen.');
        }

        return $inviteService->handleExternalMailCallback($invite, $code);
    }

    $authorizeManageSettings();

    $emailUrl = route('filament.app.resources.customers.settings').'?area=outlook-mail';

    if ($error = request()->get('error')) {
        $description = request()->get('error_description', $error);

        return redirect($emailUrl)->with('error', 'Microsoft koppeling mislukt: ' . $description);
    }

    $code = request()->get('code');
    if (! $code) {
        return redirect($emailUrl)->with('error', 'Microsoft koppeling mislukt: geen autorisatiecode ontvangen.');
    }

    $error = (new MicrosoftMailService())->saveAccessToken($code);

    return redirect($emailUrl)
        ->with($error === null ? 'success' : 'error', $error === null
            ? 'Outlook e-mailaccount succesvol gekoppeld.'
            : $error
        );
})->name('microsoft-mail.callback');

Route::middleware('auth')->group(function () use ($authorizeManageSettings) {
    Route::get('/microsoft-mail/connect', function () use ($authorizeManageSettings) {
        $authorizeManageSettings();

        return redirect((new MicrosoftMailService())->getAuthorizationUrl());
    })->name('microsoft-mail.connect');

    Route::post('/microsoft-mail/disconnect/{token}', function (\App\Models\MicrosoftMailToken $token) use ($authorizeManageSettings) {
        $authorizeManageSettings();

        (new MicrosoftMailService())->disconnect($token->id);
        $url = route('filament.app.resources.customers.settings').'?area=outlook-mail';

        return redirect($url)
            ->with('success', 'Outlook e-mailaccount ontkoppeld.');
    })->name('microsoft-mail.disconnect');
});

Route::get('/external-outlook/result', function () {
    if (request()->boolean('completed')) {
        return response()->view('outlook.external-connect-result', [
            'success' => true,
            'message' => 'Koppeling geslaagd. Het account is nu beschikbaar binnen de applicatie.',
        ]);
    }

    if (request()->boolean('failed')) {
        $message = session('external_outlook_connect_message');

        return response()->view('outlook.external-connect-result', [
            'success' => false,
            'message' => is_string($message) && $message !== ''
                ? $message
                : 'Koppeling mislukt. Gebruik de link opnieuw om het nog een keer te proberen.',
        ]);
    }

    return response()->view('outlook.external-connect-result', [
        'success' => false,
        'message' => 'Geen resultaat beschikbaar. Open de link die u van ons hebt ontvangen.',
    ]);
})->name('microsoft.external.connect.result');

Route::get('/external-outlook/connect/{token}', function (string $token) {
    $inviteService = app(OutlookExternalConnectInviteService::class);
    $invite = $inviteService->resolveInviteFromPlainToken($token);

    if ($invite === null) {
        return response()->view('outlook.external-connect-result', [
            'success' => false,
            'message' => 'Deze koppellink is ongeldig.',
        ]);
    }

    return $inviteService->beginExternalConnect($invite);
})->middleware('throttle:30,1')->name('microsoft.external.connect');

// Mollie
Route::post('/mollie-webhook', [OrderController::class, 'mollieWebhook'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('mollie.webhook');

// OAuth callback
Route::get('/oauth/callback', function () {
    Log::info('OAuth callback request:', [
        'query' => request()->query(),
        'body' => request()->all(),
    ]);
})->name('oauth.callback');

Route::get('/orders/{id}/saveAsPdf', [OrderController::class, 'saveAsPdf'])->name('orders.saveAsPdf');

Route::get('/documents/{orderId}', [\App\Http\Controllers\DocumentController::class, 'showSalesDocumentHtml'])
    ->name('documents.show')
    ->middleware('can:export-order');

Route::get('/documents/quotes/{quoteId}/admin-preview', [\App\Http\Controllers\DocumentController::class, 'quoteAdminPreview'])
    ->name('documents.quote-admin-preview')
    ->whereNumber('quoteId')
    ->middleware('can:export-order');

Route::get('/order-margins/{orderId}', [DocumentController::class, 'orderMargins'])->name('documents.orderMargins')->middleware('can:export-order');
Route::get('/order-margins/{orderId}/download', [DocumentController::class, 'orderMarginsDownload'])->name('documents.orderMarginsDownload')->middleware('can:export-order');

Route::get('/delivery-notes/{orderId}', [DocumentController::class, 'deliveryNote'])->name('documents.deliveryNote')->middleware('can:export-order');
Route::get('/delivery-notes/{orderId}/download', [DocumentController::class, 'deliveryNoteDownload'])->name('documents.deliveryNoteDownload')->middleware('can:export-order');

Route::get('/media-preview/{id}', [DocumentController::class, 'mediaPreview'])->name('documents.media-preview')->middleware(['auth', 'can:export-order']);

Route::get('/invoice-download/{id}', [DocumentController::class, 'invoiceDownload'])->name('documents.invoice-download')->middleware(['auth', 'can:export-order']);

Route::get('/exact-documents/{id}/download', [DocumentController::class, 'exactDocumentDownload'])->name('documents.exact-document-download')->middleware(['auth', 'can:export-order']);
Route::get('/exact-documents/{id}/preview', [DocumentController::class, 'exactDocumentPreview'])->name('documents.exact-document-preview')->middleware(['auth', 'can:export-order']);
Route::get('/media/{id}/download', [DocumentController::class, 'mediaDownload'])->name('documents.media-download')->middleware(['auth', 'can:export-order']);

Route::get('/import-batches/{importBatch}/download', ImportBatchDownloadController::class)
    ->name('import-batches.download')
    ->middleware(['auth']);

Route::get('/import-exports/{importExport}/download', ImportBatchExportDownloadController::class)
    ->name('import-exports.download')
    ->middleware(['auth']);

if (app()->environment('local')) {
    include_once 'dev.php';
}
