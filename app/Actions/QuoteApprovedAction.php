<?php

namespace App\Actions;

use App\Enums\OrderGeneralStatus;
use App\Mail\QuoteApprovedMail;
use App\Models\Order\Quote;
use App\Models\QuoteApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class QuoteApprovedAction
{
    public function __construct(
        private readonly string $signaturePayload,
        private readonly string $customerName,
    ) {
    }

    /**
     * @return array{ok: true, alreadyApproved?: true}
     */
    public function execute(QuoteApproval $approval): array
    {
        $approval->loadMissing('quote');

        $quote = $approval->quote;
        if ($approval->approved_at !== null && $quote !== null && $quote->getStatus() === OrderGeneralStatus::Completed) {
            return ['ok' => true, 'alreadyApproved' => true];
        }

        try {
            $outcome = DB::transaction(function () use ($approval): string {
                /** @var QuoteApproval|null $locked */
                $locked = QuoteApproval::query()->whereKey($approval->id)->lockForUpdate()->first();
                if ($locked === null) {
                    abort(404);
                }

                /** @var Quote|null $quote */
                $quote = Quote::withoutGlobalScopes()->whereKey($locked->quote_id)->lockForUpdate()->first();
                if ($quote === null) {
                    abort(404);
                }

                if ($locked->approved_at !== null && $quote->getStatus() === OrderGeneralStatus::Completed) {
                    return 'already_approved';
                }

                if (! in_array($quote->getStatus(), [OrderGeneralStatus::Pending, OrderGeneralStatus::Sent], true)) {
                    abort(403, 'Deze offerte kan niet meer online worden goedgekeurd.');
                }

                if ($quote->isValidityExpired()) {
                    abort(403, 'De geldigheidsduur van deze offerte is verstreken. Neem contact op voor een nieuwe offerte.');
                }

                if ($locked->approved_at !== null) {
                    throw new \RuntimeException('Goedkeuring is in een ongeldige tussenstaat.');
                }

                $locked->signature = $this->signaturePayload;
                $locked->customer_name = $this->customerName !== '' ? $this->customerName : '-';
                $locked->approved_at = now();
                $locked->browser = $this->filteredBrowserMeta();
                $locked->save();

                $quote->acceptQuote(saveQuote: true, reserveInventory: false);

                return 'ok';
            });
        } catch (Throwable $e) {
            throw $e;
        }

        if ($outcome === 'already_approved') {
            return ['ok' => true, 'alreadyApproved' => true];
        }

        $approval->refresh();
        $quote = $approval->quote;

        if ($quote !== null && $quote->main !== null) {
            $customerName = $approval->customer_name;
            $quote->main->orderEvents()->create([
                'type' => 'Offerte goedgekeurd door klant ' . $customerName,
                'data' => [
                    'quote_id' => $quote->getId(),
                    'quote_approval_id' => $approval->id,
                ],
                'user_id' => null,
            ]);
        }

        $mailable = new QuoteApprovedMail($quote, $approval);
        Mail::send($mailable);

        app(OrderMailEventLogger::class)->logSent(
            $quote,
            QuoteApprovedMail::class,
            $mailable->to,
            $mailable->cc,
            $mailable->bcc,
            is_string($mailable->subject) ? $mailable->subject : null,
        );

        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function filteredBrowserMeta(): array
    {
        $request = request();

        return [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'accept_language' => $request->header('Accept-Language'),
            'time' => microtime(true),
        ];
    }
}
