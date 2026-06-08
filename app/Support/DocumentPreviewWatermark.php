<?php

namespace App\Support;

final class DocumentPreviewWatermark
{
    public static function appendToHtml(string $html): string
    {
        $markup = view('order._preview-watermark')->render();

        if (preg_match('/<\/body>/i', $html) === 1) {
            return (string) preg_replace('/<\/body>/i', $markup.'</body>', $html, 1);
        }

        return $html.$markup;
    }
}
