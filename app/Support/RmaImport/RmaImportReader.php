<?php

namespace App\Support\RmaImport;

use App\Enums\RmaImportTemplate;
use App\Support\RmaImport\Universal\UniversalImportParser;
use App\Support\RmaImport\ConsumerReturns\ConsumerReturnsImportParser;
use App\Support\RmaImport\ConsumerReturnsShipment\ConsumerReturnsShipmentImportParser;
use App\Support\RmaImport\Contracts\RmaImportParser;
use App\Support\RmaImport\MediaMarkt\MediaMarktImportParser;
use Illuminate\Validation\ValidationException;

final class RmaImportReader
{
    /**
     * @return list<RmaImportParser>
     */
    private function parsersForTemplate(RmaImportTemplate $template): array
    {
        return match ($template) {
            RmaImportTemplate::Auto => [
                app(UniversalImportParser::class),
                app(ConsumerReturnsShipmentImportParser::class),
                app(ConsumerReturnsImportParser::class),
                app(MediaMarktImportParser::class),
            ],
            RmaImportTemplate::Universal, RmaImportTemplate::AutovisionStore => [app(UniversalImportParser::class)],
            RmaImportTemplate::ConsumerReturns => [app(ConsumerReturnsImportParser::class)],
            RmaImportTemplate::ConsumerReturnsShipment => [app(ConsumerReturnsShipmentImportParser::class)],
            RmaImportTemplate::MediaMarkt => [app(MediaMarktImportParser::class)],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function read(string $path, string $extension, RmaImportTemplate $template = RmaImportTemplate::Auto): array
    {
        $extension = strtolower($extension);

        foreach ($this->parsersForTemplate($template) as $parser) {
            if ($parser->supports($path, $extension)) {
                $rows = $parser->parse($path, $extension);

                if ($rows !== []) {
                    return $rows;
                }
            }
        }

        if ($template === RmaImportTemplate::Auto) {
            throw ValidationException::withMessages([
                'file' => 'Het bestandsformaat kon niet worden herkend. Kies een importtemplate of controleer het bestand.',
            ]);
        }

        throw ValidationException::withMessages([
            'file' => 'Het bestand komt niet overeen met het gekozen importtemplate.',
        ]);
    }
}
