<?php

use App\Models\MailLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mail_logs') || ! Schema::hasTable('email_templates')) {
            return;
        }

        $this->backfillEmailTemplateIdsInMailLogHeaders();
    }

    public function down(): void
    {
        // Data backfill — not reversed.
    }

    private function backfillEmailTemplateIdsInMailLogHeaders(): void
    {
        $patterns = DB::table('email_templates')
            ->select(['id', 'subject'])
            ->orderBy('id')
            ->get()
            ->map(fn (object $template): array => [
                'id' => (int) $template->id,
                'regex' => $this->templateSubjectToRegex((string) $template->subject),
            ])
            ->all();

        DB::table('mail_logs')
            ->select(['id', 'subject', 'headers'])
            ->where(function ($query): void {
                $query->whereNull('headers')
                    ->orWhere('headers', 'not like', '%' . MailLog::EMAIL_HEADER_TEMPLATE_ID . ':%');
            })
            ->orderBy('id')
            ->chunkById(100, function (Collection $logs) use ($patterns): void {
                foreach ($logs as $log) {
                    $templateId = $this->resolveTemplateIdFromSubject(
                        $this->normalizeSubject((string) $log->subject),
                        $patterns,
                    );

                    if ($templateId === null) {
                        continue;
                    }

                    DB::table('mail_logs')
                        ->where('id', $log->id)
                        ->update([
                            'headers' => $this->appendTemplateIdHeader(
                                $log->headers,
                                $templateId,
                            ),
                        ]);
                }
            });
    }

    /**
     * @param  list<array{id: int, regex: string}>  $patterns
     */
    private function resolveTemplateIdFromSubject(string $subject, array $patterns): ?int
    {
        $matchingIds = [];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern['regex'], $subject) === 1) {
                $matchingIds[] = $pattern['id'];
            }
        }

        if ($matchingIds === []) {
            return null;
        }

        return min($matchingIds);
    }

    private function normalizeSubject(string $subject): string
    {
        return trim(preg_replace('/\s+/u', ' ', $subject) ?? $subject);
    }

    private function templateSubjectToRegex(string $templateSubject): string
    {
        $pattern = preg_quote($templateSubject, '/');
        $pattern = preg_replace('/\\\\#\\\\\[.*?\\\\\]/', '.+?', $pattern);
        $pattern = preg_replace('/\\\\\[.*?\\\\\]/', '.+?', $pattern);

        return '/^' . $pattern . '$/iu';
    }

    private function appendTemplateIdHeader(?string $headers, int $templateId): string
    {
        $headers = is_string($headers) ? rtrim($headers) : '';
        $headerLine = MailLog::EMAIL_HEADER_TEMPLATE_ID . ': ' . $templateId;

        if ($headers === '') {
            return $headerLine . "\r\n";
        }

        return $headers . "\r\n" . $headerLine . "\r\n";
    }
};
