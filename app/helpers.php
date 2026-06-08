<?php

function formatMarginPercentage($sellingPrice, $costPrice): string
{
    $margin = $sellingPrice - $costPrice;
    if ($costPrice <> 0) {
        $percentage = ($margin / $costPrice) * 100;
        return ' (' . round($percentage, 1) . '%)';
    }
    return '';
}

function arrayToTextareaString(string|array $data): string
{
    if (is_string($data)) {
        return $data;
    }
    $lines = [];

    foreach ($data as $key => $value) {
        $lines[] = "{$key}: {$value}";
    }

    return implode("\n", $lines);
}

function sess_id(): string
{
    return request()->session()->getId();
}

function textareaStringToArray(?string $text): array
{
    if (!$text) return [];

    $lines = explode("\n", $text);
    $array = [];

    foreach ($lines as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            $array[$key] = $value;
        }
    }

    return $array;
}

function format_percentage(?float $value, int $decimals = 1): string
{
    if ($value === null || is_nan($value) || is_infinite($value)) {
        return '–';
    }

    return number_format($value, $decimals, ',', '.') . '%';
}


function format_money_amount(float|int|string|null $value): string
{
    if ($value === null || $value === '') {
        return '–';
    }

    if (!is_numeric($value)) {
        return '–';
    }

    $number = round((float)$value, 2);

    return number_format($number, 2, ',', '.');
}
