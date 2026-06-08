<?php

namespace App\Services\MailPreview;

use Illuminate\Support\Facades\Cache;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class MsgPreviewCacheService
{
    public function __construct(
        protected MsgPreviewExtractor $msgPreviewExtractor,
    ) {}

    public function isOutlookMsgMedia(Media $media): bool
    {
        $mimeType = mb_strtolower((string) ($media->mime_type ?? ''));
        $extension = mb_strtolower((string) ($media->extension ?? ''));

        return $mimeType === 'application/vnd.ms-outlook' || $extension === 'msg';
    }

    public function getCacheKey(Media $media, ?string $path = null): string
    {
        $path ??= $media->getPath();
        $mtime = is_string($path) && $path !== '' ? (@filemtime($path) ?: 0) : 0;

        return sprintf('documents:msg-preview:%d:%d', (int) $media->id, (int) $mtime);
    }

    /**
     * Start full .msg parsing after the HTTP response so uploads stay fast.
     */
    public function queueWarmCache(Media $media): void
    {
        if (! $this->isOutlookMsgMedia($media)) {
            return;
        }

        if ((int) config('documents.msg_preview_cache_seconds', 3600) <= 0) {
            return;
        }

        $mediaId = (int) $media->id;

        dispatch(function () use ($mediaId): void {
            $media = Media::query()->find($mediaId);
            if ($media === null) {
                return;
            }

            app(self::class)->warmCacheWithLock($media);
        })->afterResponse();
    }

    /**
     * Parse and cache the full .msg preview (headers, text, HTML, inline images).
     */
    public function warmCache(Media $media): void
    {
        if (! $this->isOutlookMsgMedia($media)) {
            return;
        }

        $path = $media->getPath();
        if (! is_string($path) || ! file_exists($path)) {
            return;
        }

        $ttlSeconds = (int) config('documents.msg_preview_cache_seconds', 3600);
        if ($ttlSeconds <= 0) {
            return;
        }

        $cacheKey = $this->getCacheKey($media, $path);
        if (Cache::has($cacheKey)) {
            return;
        }

        $fullPreview = $this->msgPreviewExtractor->extractFromPath($path, true);
        Cache::put($cacheKey, $fullPreview, now()->addSeconds($ttlSeconds));
    }

    public function warmCacheWithLock(Media $media): void
    {
        if (! $this->isOutlookMsgMedia($media)) {
            return;
        }

        $path = $media->getPath();
        if (! is_string($path) || ! file_exists($path)) {
            return;
        }

        $cacheKey = $this->getCacheKey($media, $path);
        if (Cache::has($cacheKey)) {
            return;
        }

        $lock = Cache::lock('documents:msg-preview-lock:' . $media->id, 300);

        if (! $lock->get()) {
            return;
        }

        try {
            if (Cache::has($cacheKey)) {
                return;
            }

            $this->warmCache($media);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Lock may have expired if parsing took very long.
            }
        }
    }

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
    public function getPreview(Media $media): array
    {
        $path = $media->getPath();
        if (! is_string($path) || ! file_exists($path)) {
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
                'parse_error' => 'Bestand niet gevonden.',
            ];
        }

        $ttlSeconds = (int) config('documents.msg_preview_cache_seconds', 3600);
        if ($ttlSeconds <= 0) {
            return $this->msgPreviewExtractor->extractFromPath($path, true);
        }

        $cacheKey = $this->getCacheKey($media, $path);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $this->queueWarmCache($media);

        return $this->msgPreviewExtractor->extractFromPath($path, false);
    }
}
