<?php

namespace App\Enums;

enum ExactDocumentMappedType: string
{
    case Order = 'order';
    case DepositInvoice = 'deposit_invoice';
    case Invoice = 'invoice';
    case PackingSlip = 'packing_slip';
    case DeliveryNote = 'delivery_note';
    case Other = 'other';

    /**
     * Derive a mapped type from Exact Online document metadata.
     *
     * Detection is subject-keyword-first, then falls back to TypeDescription,
     * then to the numeric Type.
     */
    public static function fromExact(int $exactType, string $typeDescription, string $subject): self
    {
        $subjectLower = mb_strtolower($subject);
        $descriptionLower = mb_strtolower($typeDescription);

        // Subject-based matching (documents uploaded from this application follow known naming conventions)
        if (str_contains($subjectLower, 'aanbetalingsfactuur')) {
            return self::DepositInvoice;
        }

        if (str_contains($subjectLower, 'slotfactuur') || str_contains($subjectLower, 'factuur')) {
            return self::Invoice;
        }

        if (str_contains($subjectLower, 'orderbevestiging')) {
            return self::Order;
        }

        if (str_contains($subjectLower, 'afleverbon')) {
            return self::PackingSlip;
        }

        if (str_contains($subjectLower, 'pakbon') || str_contains($subjectLower, 'delivery note')) {
            return self::DeliveryNote;
        }

        // TypeDescription fallback for documents created directly in Exact
        if (str_contains($descriptionLower, 'aanbetalingsfactuur')) {
            return self::DepositInvoice;
        }

        if (str_contains($descriptionLower, 'factuur') || str_contains($descriptionLower, 'invoice')) {
            return self::Invoice;
        }

        if (str_contains($descriptionLower, 'order') || str_contains($descriptionLower, 'offerte') || str_contains($descriptionLower, 'quotation')) {
            return self::Order;
        }

        if (str_contains($descriptionLower, 'afleverbon') || str_contains($descriptionLower, 'packing')) {
            return self::PackingSlip;
        }

        if (str_contains($descriptionLower, 'pakbon') || str_contains($descriptionLower, 'delivery')) {
            return self::DeliveryNote;
        }

        return self::Other;
    }

    public function label(): string
    {
        return match ($this) {
            self::Order => 'Order',
            self::DepositInvoice => 'Aanbetalingsfactuur',
            self::Invoice => 'Slotfactuur',
            self::PackingSlip => 'Afleverbon',
            self::DeliveryNote => 'Pakbon',
            self::Other => 'Overig',
        };
    }
}
