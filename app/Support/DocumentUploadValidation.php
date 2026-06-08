<?php

namespace App\Support;

use Filament\Notifications\Notification;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

final class DocumentUploadValidation
{
    /**
     * @param  array<int, string>  $allowedMimeTypes
     */
    public static function allowedTypesDescription(array $allowedMimeTypes): string
    {
        $labels = [];

        if (self::containsAny($allowedMimeTypes, ['application/pdf'])) {
            $labels[] = 'PDF';
        }

        if (self::containsAny($allowedMimeTypes, [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])) {
            $labels[] = 'Word (.doc, .docx)';
        }

        if (self::containsAny($allowedMimeTypes, [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])) {
            $labels[] = 'Excel (.xls, .xlsx)';
        }

        if (self::containsAny($allowedMimeTypes, [
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ])) {
            $labels[] = 'PowerPoint (.ppt, .pptx)';
        }

        if (self::containsAny($allowedMimeTypes, [
            'message/rfc822',
            'application/vnd.ms-outlook',
        ])) {
            $labels[] = 'e-mail (.eml, .msg)';
        }

        if (self::containsAny($allowedMimeTypes, ['image/jpeg'])) {
            $labels[] = 'JPEG';
        }

        if (self::containsAny($allowedMimeTypes, ['image/png'])) {
            $labels[] = 'PNG';
        }

        if (self::containsAny($allowedMimeTypes, ['image/gif'])) {
            $labels[] = 'GIF';
        }

        if (self::containsAny($allowedMimeTypes, ['image/webp'])) {
            $labels[] = 'WebP';
        }

        if (self::containsAny($allowedMimeTypes, ['image/svg+xml'])) {
            $labels[] = 'SVG';
        }

        if ($labels === []) {
            return 'Je kunt in dit veld alleen toegestane bestandstypes uploaden.';
        }

        $last = array_pop($labels);

        if ($labels === []) {
            return "Je kunt in dit veld alleen bestanden uploaden van het type {$last}.";
        }

        return 'Je kunt in dit veld alleen bestanden uploaden van het type '
            . implode(', ', $labels)
            . ' en '
            . $last
            . '.';
    }

    public static function maxSizeDescription(int $maxFileSizeKb): string
    {
        if ($maxFileSizeKb >= 1024 && $maxFileSizeKb % 1024 === 0) {
            $megabytes = (int) ($maxFileSizeKb / 1024);

            return "Het bestand mag maximaal {$megabytes} MB zijn.";
        }

        return "Het bestand mag maximaal {$maxFileSizeKb} KB zijn.";
    }

    /**
     * @param  array<int, string>  $allowedMimeTypes
     * @return array<string, string>
     */
    public static function validationMessages(array $allowedMimeTypes, int $maxFileSizeKb): array
    {
        $typesDescription = self::allowedTypesDescription($allowedMimeTypes);

        return [
            'documentFiles.*.mimetypes' => $typesDescription,
            'documentFiles.*.max' => self::maxSizeDescription($maxFileSizeKb),
            'documentFiles.*.file' => 'Het geüploade bestand is ongeldig.',
            'documentFiles.*.uploaded' => 'Het uploaden van het bestand is mislukt. Probeer het opnieuw.',
        ];
    }

    /**
     * @param  array<int, string>  $allowedMimeTypes
     */
    public static function sendInvalidUploadNotification(
        ValidationException $exception,
        array $allowedMimeTypes,
        int $maxFileSizeKb,
    ): void {
        Notification::make()
            ->title('Ongeldig bestand')
            ->body(self::resolveInvalidUploadBody($exception->validator, $allowedMimeTypes, $maxFileSizeKb))
            ->danger()
            ->send();
    }

    /**
     * @param  array<int, string>  $allowedMimeTypes
     */
    public static function resolveInvalidUploadBody(
        Validator $validator,
        array $allowedMimeTypes,
        int $maxFileSizeKb,
    ): string {
        foreach ($validator->failed() as $rules) {
            if (isset($rules['Max'])) {
                return self::maxSizeDescription($maxFileSizeKb);
            }
        }

        return self::allowedTypesDescription($allowedMimeTypes);
    }

    /**
     * @param  array<int, string>  $allowedMimeTypes
     * @param  array<int, string>  $needles
     */
    private static function containsAny(array $allowedMimeTypes, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (in_array($needle, $allowedMimeTypes, true)) {
                return true;
            }
        }

        return false;
    }
}
