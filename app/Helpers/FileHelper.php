<?php

namespace App\Helpers;

class FileHelper
{
    public static function formatFileSize(?int $size): string
    {
        if ($size === null) {
            return 'Onbekend';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return number_format($size, 2, ',', '.') . ' ' . $units[$unitIndex];
    }
}
