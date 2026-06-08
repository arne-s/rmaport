<?php

namespace App\Services\MailPreview;

use Carbon\CarbonImmutable;
use Opt\OLE\MsgParser;
use Opt\OLE\RTF\EmbeddedHTML;

class MsgPreviewExtractor
{
    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     cc: string,
     *     subject: string,
     *     sent_at: string,
     *     body_text: string,
     *     body_html: string,
     *     inline_images: array<int, array{filename: string, mime_type: string, data_uri: string}>,
     *     is_partial: bool,
     *     parse_error: ?string
     * }
     */
    public function extractFromPath(string $path, bool $includeRichContent = true): array
    {
        try {
            $parsed = $this->makeParser($path)->parse();
            $headers = is_array($parsed->headers ?? null) ? $parsed->headers : [];
            $transportHeaders = $this->parseTransportHeaders((string) ($headers['TRANSPORT_MESSAGE_HEADERS'] ?? ''));

            $from = $this->firstNonEmpty([
                $transportHeaders['From'] ?? '',
                (string) ($headers['SENDER_EMAIL_ADDRESS'] ?? ''),
                (string) ($headers['SENDER_NAME'] ?? ''),
            ]);

            $to = $this->firstNonEmpty([
                $transportHeaders['To'] ?? '',
                (string) ($headers['DISPLAY_TO'] ?? ''),
            ]);

            $cc = $this->firstNonEmpty([
                $transportHeaders['Cc'] ?? '',
                (string) ($headers['DISPLAY_CC'] ?? ''),
            ]);

            $subject = $this->firstNonEmpty([
                $transportHeaders['Subject'] ?? '',
                (string) ($headers['SUBJECT'] ?? ''),
            ]);

            $dateRaw = $this->firstNonEmpty([
                $transportHeaders['Date'] ?? '',
                (string) ($headers['Date'] ?? ''),
            ]);

            $bodyText = $this->normalizeBodyText((string) ($parsed->body ?? ''));
            $bodyHtml = '';
            $inlineImages = [];
            if ($includeRichContent) {
                $attachments = is_array($parsed->attachments ?? null) ? $parsed->attachments : [];
                $bodyHtml = $this->extractBodyHtml($attachments);
                $inlineImages = $this->extractInlineImages($attachments);
            }

            return [
                'from' => $from,
                'to' => $to,
                'cc' => $cc,
                'subject' => $subject,
                'sent_at' => $this->normalizeDate($dateRaw),
                'body_text' => $bodyText,
                'body_html' => $bodyHtml,
                'inline_images' => $inlineImages,
                'is_partial' => ! $includeRichContent,
                'parse_error' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'from' => '',
                'to' => '',
                'cc' => '',
                'subject' => '',
                'sent_at' => '',
                'body_text' => '',
                'body_html' => '',
                'inline_images' => [],
                'is_partial' => false,
                'parse_error' => 'Deze .msg kon niet worden gelezen.',
            ];
        }
    }

    protected function makeParser(string $path): object
    {
        return new MsgParser($path);
    }

    /**
     * @return array<string, string>
     */
    private function parseTransportHeaders(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $headers = [];
        $current = null;

        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/^\s+/', $line) === 1 && $current !== null) {
                $headers[$current] .= ' ' . trim($line);
                continue;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $current = trim($parts[0]);
            $headers[$current] = trim($parts[1]);
        }

        return $headers;
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse($value)->translatedFormat('d-m-Y H:i');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function normalizeBodyText(string $body): string
    {
        $normalized = preg_replace("/\r\n|\r/", "\n", $body) ?? $body;
        $normalized = preg_replace("/\n{3,}/", "\n\n", $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @param  array<int, mixed>  $attachments
     */
    private function extractBodyHtml(array $attachments): string
    {
        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $filename = mb_strtolower((string) ($attachment['filename'] ?? ''));
            if ($filename !== 'body.rtf') {
                continue;
            }

            $rtf = (string) ($attachment['data'] ?? '');
            if ($rtf === '') {
                continue;
            }

            $html = EmbeddedHTML::extract($rtf);
            if (trim($html) !== '') {
                return $this->sanitizeHtml($html);
            }
        }

        return '';
    }

    /**
     * @param  array<int, mixed>  $attachments
     * @return array<int, array{filename: string, mime_type: string, data_uri: string}>
     */
    private function extractInlineImages(array $attachments): array
    {
        $images = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $mimeType = mb_strtolower((string) ($attachment['mimeType'] ?? ''));
            if (! str_starts_with($mimeType, 'image/')) {
                continue;
            }

            $binary = $attachment['data'] ?? null;
            if (! is_string($binary) || $binary === '') {
                continue;
            }

            $filename = (string) ($attachment['filename'] ?? 'afbeelding');
            $images[] = [
                'filename' => $filename !== '' ? $filename : 'afbeelding',
                'mime_type' => $mimeType,
                'data_uri' => 'data:' . $mimeType . ';base64,' . base64_encode($binary),
            ];
        }

        return $images;
    }

    private function sanitizeHtml(string $html): string
    {
        $cleaned = preg_replace('/<\s*(script|style)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? $html;
        $cleaned = preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace("/\son\w+\s*=\s*'[^']*'/i", '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\son\w+\s*=\s*[^\s>]+/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s(href|src)\s*=\s*([\'"])\s*javascript:[^\'"]*\2/i', '', $cleaned) ?? $cleaned;

        return trim((string) strip_tags($cleaned, '<a><p><br><div><span><strong><em><b><i><u><ul><ol><li><table><thead><tbody><tr><th><td><img><h1><h2><h3><h4><h5><h6><blockquote><pre><code><hr>'));
    }

    /**
     * @param  array<int, string>  $candidates
     */
    private function firstNonEmpty(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $trimmed = trim($candidate);

            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }
}
