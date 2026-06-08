<?php

namespace App\Mail\Concerns;

trait BuildsQuotePdfDownloadButton
{
    protected function quotePdfDownloadButton(string $routeName, string $uuid, string $label): string
    {
        if ($uuid === '') {
            return '';
        }

        $url = $this->resolveQuoteHostPublicPdfUrl($routeName, $uuid);
        if ($url === '') {
            return '';
        }

        return '<a href="'.e($url).'" style="display:inline-block;padding:12px 24px;background:#032d5c;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">'.e($label).'</a>';
    }

    /**
     * Mollie checkout URL for invoice online payment (same escaping rules as {@see quotePdfDownloadButton()}).
     */
    protected function invoiceOnlinePaymentButton(?string $url, string $label = 'Online betalen (iDEAL)'): string
    {
        if ($url === null || $url === '') {
            return '';
        }

        return '<a href="'.e($url).'" style="display:inline-block;padding:12px 24px;background:#1a56a8;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">'.e($label).'</a>';
    }

    /**
     * Absolute URL download button (e.g. approve-quote.pdf), same styling as {@see quotePdfDownloadButton()}.
     */
    protected function absoluteDownloadButton(string $url, string $label): string
    {
        if ($url === '') {
            return '';
        }

        return '<a href="'.e($url).'" style="display:inline-block;padding:12px 24px;background:#032d5c;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">'.e($label).'</a>';
    }

    /**
     * Public PDF URLs on the quote host ({@see config('quote.domain')}).
     */
    private function resolveQuoteHostPublicPdfUrl(string $routeName, string $uuid): string
    {
        $host = (string) config('quote.domain');
        $path = match ($routeName) {
            'quote.public.order-pdf' => '/'.$uuid.'/orderbevestiging.pdf',
            'quote.public.invoice-pdf' => '/'.$uuid.'/factuur.pdf',
            default => '',
        };

        if ($path === '') {
            return '';
        }

        return 'https://'.$host.$path;
    }
}
