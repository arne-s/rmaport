<?php

namespace App\Support\RmaImport;

use App\Support\RmaImport\ConsumerReturns\ConsumerReturnsImportParser;
use App\Support\RmaImport\ConsumerReturnsShipment\ConsumerReturnsShipmentImportParser;
use App\Support\RmaImport\Contracts\RmaImportParser;
use App\Support\RmaImport\MediaMarkt\MediaMarktImportParser;
use App\Support\RmaImport\Universal\UniversalImportParser;
use Throwable;

final class RmaImportFixtureProductExtractor
{
    /**
     * @var list<class-string<RmaImportParser>>
     */
    private const PARSERS = [
        MediaMarktImportParser::class,
        UniversalImportParser::class,
        ConsumerReturnsImportParser::class,
        ConsumerReturnsShipmentImportParser::class,
    ];

    /**
     * @return list<array{
     *     ean: string,
     *     name: string|null,
     *     brand: string|null,
     *     article_number: string|null,
     * }>
     */
    public function extract(?string $fixtureDirectory = null): array
    {
        $directory = $fixtureDirectory ?? base_path('tests/fixtures/rma');

        if (! is_dir($directory)) {
            return [];
        }

        /** @var array<string, array{ean: string, name: string|null, brand: string|null, article_number: string|null}> $productsByEan */
        $productsByEan = [];

        foreach (glob($directory . '/*.{xlsx,xls,csv}', GLOB_BRACE) ?: [] as $fixturePath) {
            foreach (self::PARSERS as $parserClass) {
                /** @var RmaImportParser $parser */
                $parser = app($parserClass);
                $extension = strtolower(pathinfo($fixturePath, PATHINFO_EXTENSION));

                try {
                    $rows = $parser->parse($fixturePath, $extension);
                } catch (Throwable) {
                    continue;
                }

                if ($rows === []) {
                    continue;
                }

                foreach ($rows as $row) {
                    $ean = $this->normalizeEan($row['ean'] ?? null);

                    if ($ean === null) {
                        continue;
                    }

                    $name = $this->nullableString($row['product_name'] ?? null);
                    $brand = $this->nullableString($row['brand'] ?? null);
                    $articleNumber = $this->nullableString($row['article_number'] ?? null);

                    if (! array_key_exists($ean, $productsByEan)) {
                        $productsByEan[$ean] = [
                            'ean' => $ean,
                            'name' => $name,
                            'brand' => $brand,
                            'article_number' => $articleNumber,
                        ];

                        continue;
                    }

                    $existing = $productsByEan[$ean];

                    if ($this->shouldPreferName($name, $existing['name'])) {
                        $productsByEan[$ean]['name'] = $name;
                    }

                    $productsByEan[$ean]['brand'] ??= $brand;
                    $productsByEan[$ean]['article_number'] ??= $articleNumber;
                }
            }
        }

        $products = array_values($productsByEan);

        usort(
            $products,
            fn (array $left, array $right): int => strcmp($left['ean'], $right['ean']),
        );

        return $products;
    }

    public function normalizeEan(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($value ?? ''));

        if ($digits === null || $digits === '') {
            return null;
        }

        if (strlen($digits) > 13) {
            return null;
        }

        return str_pad($digits, 13, '0', STR_PAD_LEFT);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function shouldPreferName(?string $candidate, ?string $current): bool
    {
        if ($candidate === null) {
            return false;
        }

        if ($current === null) {
            return true;
        }

        return mb_strlen($candidate) > mb_strlen($current);
    }
}
