<?php

namespace App\Http\Controllers;

use App\Actions\QuoteApprovedAction;
use App\Enums\OrderGeneralStatus;
use App\Models\QuoteApproval;
use App\Models\Document;
use App\Models\Order\Quote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\View\View;

class QuoteApprovalController extends Controller
{
    public function show(string $uuid): View
    {
        $approval = QuoteApproval::query()->where('uuid', $uuid)->firstOrFail();

        /** @var Quote $quote */
        $quote = Quote::withoutGlobalScopes()->findOrFail($approval->quote_id);

        if ($approval->approved_at !== null && $quote->getStatus() === OrderGeneralStatus::Completed) {
            return view('quote-approval.thank-you', [
                'approval' => $approval,
                'quote' => $quote,
            ]);
        }

        if ($quote->isValidityExpired()) {
            return view('quote-approval.expired', [
                'quote' => $quote,
                'expiresAt' => $quote->getExpiresAt(),
            ]);
        }

        if (! in_array($quote->getStatus(), [OrderGeneralStatus::Pending, OrderGeneralStatus::Sent], true)) {
            return view('quote-approval.unavailable', [
                'quote' => $quote,
            ]);
        }

        return view('quote-approval.show', [
            'approval' => $approval,
            'quote' => $quote,
        ]);
    }

    /**
     * Inline PDF for the quote approval iframe (Spatie {@see Quote} media collection `quote`).
     * Accessible via the UUID link at any point — the PDF should remain downloadable after approval/completion.
     */
    public function pdf(string $uuid): BinaryFileResponse
    {
        $approval = QuoteApproval::query()->where('uuid', $uuid)->firstOrFail();

        /** @var Quote $quote */
        $quote = Quote::withoutGlobalScopes()->findOrFail($approval->quote_id);

        $media = $quote->getFirstMedia('quote');
        if ($media === null) {
            try {
                Document::createFromOrder($quote);
                $quote->refresh();
                $media = $quote->getFirstMedia('quote');
            } catch (\Throwable) {
            }
        }

        abort_unless($media !== null, 404, 'Offerte-PDF is nog niet beschikbaar.');

        $fileName = $media->file_name ?: ('offerte-' . $quote->getUidFormatted() . '.pdf');
        $safeFileName = preg_replace('/[^a-zA-Z0-9._\-]+/', '_', $fileName) ?: 'offerte.pdf';

        return response()->file($media->getPath(), [
            'Content-Type' => $media->mime_type ?: 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $safeFileName . '"',
        ]);
    }

    public function submit(Request $request, string $uuid): RedirectResponse
    {
        $approval = QuoteApproval::query()->where('uuid', $uuid)->firstOrFail();

        /** @var Quote $quote */
        $quote = Quote::withoutGlobalScopes()->findOrFail($approval->quote_id);

        if ($quote->isValidityExpired()) {
            return redirect()->route('approve-quote', ['uuid' => $uuid]);
        }

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'signature' => ['required', 'string', 'max:500000'],
        ]);

        (new QuoteApprovedAction(
            (string) $validated['signature'],
            trim((string) $validated['customer_name']),
        ))->execute($approval);

        return redirect()->route('approve-quote', ['uuid' => $uuid]);
    }
}
