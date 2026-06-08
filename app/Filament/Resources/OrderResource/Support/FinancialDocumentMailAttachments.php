<?php

namespace App\Filament\Resources\OrderResource\Support;

use App\Enums\InvoiceCaption;
use App\Enums\OrderGeneralStatus;
use App\Enums\OrderType;
use App\Models\Document;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Financial documents on a main request (same scope as OrderDocsTableWidget) for mail attachments.
 */
final class FinancialDocumentMailAttachments
{
    /**
     * @return array<string, string> Checkbox keys => labels
     */
    public static function attachmentOptions(BaseOrder $record): array
    {
        $main = OrderCustomerMailRecipients::documentOwnerForRecord($record);
        if (! $main instanceof Main) {
            return [];
        }

        $options = [];

        foreach (self::financialOrdersForMain($main) as $order) {
            if (! self::hasBeenSentForMailAttachment($order)) {
                continue;
            }

            $options['bo_'.$order->getId()] = self::orderCheckboxLabel($order);
        }

        foreach ($main->getMedia('financial_documents') as $media) {
            $options['fin_'.$media->id] = self::uploadCheckboxLabel($media);
        }

        return $options;
    }

    public static function orderCheckboxLabel(BaseOrder $order): string
    {
        $uid = $order->getType() === OrderType::Order
            ? $order->getUidFormattedWithRevision()
            : $order->getUidFormatted();

        return self::orderDocumentLabel($order).($uid !== '' ? '-'.$uid : '');
    }

    public static function uploadCheckboxLabel(Media $media): string
    {
        $invoiceNumber = $media->getCustomProperty('invoice_number');
        if (is_string($invoiceNumber) && $invoiceNumber !== '') {
            return $invoiceNumber;
        }

        return $media->file_name ?: ($media->name
            ? $media->name.'.'.$media->extension
            : 'document-'.$media->id.'.'.$media->extension);
    }

    /**
     * Whether a financial document may be offered as a mail attachment (must have been sent to the customer).
     */
    public static function hasBeenSentForMailAttachment(BaseOrder $order): bool
    {
        if ($order->getSentAt() !== null) {
            return true;
        }

        $type = $order->getType();
        if ($type === null) {
            return false;
        }

        $status = $order->getStatus();

        return match ($type) {
            OrderType::Quote,
            OrderType::Invoice,
            OrderType::DepositInvoice,
            OrderType::CreditInvoice => $status === OrderGeneralStatus::Sent,
            OrderType::Order => false,
            default => false,
        };
    }

    /**
     * @param  array<int, string>  $selectedKeys
     * @return array{financial_media_ids: list<int>, financial_order_ids: list<int>}
     */
    public static function parseSelectedKeys(array $selectedKeys): array
    {
        $financialMediaIds = [];
        $financialOrderIds = [];

        foreach ($selectedKeys as $key) {
            $key = (string) $key;

            if (str_starts_with($key, 'bo_')) {
                $financialOrderIds[] = (int) str_replace('bo_', '', $key);

                continue;
            }

            if (str_starts_with($key, 'fin_')) {
                $financialMediaIds[] = (int) str_replace('fin_', '', $key);
            }
        }

        return [
            'financial_media_ids' => $financialMediaIds,
            'financial_order_ids' => $financialOrderIds,
        ];
    }

    /**
     * @return list<BaseOrder>
     */
    public static function financialOrdersForMain(Main $main): array
    {
        return BaseOrder::withoutGlobalScopes()
            ->where('main_id', $main->getId())
            ->whereIn('type', [
                OrderType::Quote->value,
                OrderType::Order->value,
                OrderType::Invoice->value,
                OrderType::DepositInvoice->value,
                OrderType::CreditInvoice->value,
            ])
            ->where('status', '!=', OrderGeneralStatus::Initial)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    public static function orderDocumentLabel(BaseOrder $order): string
    {
        $typeValue = $order->type instanceof \BackedEnum
            ? $order->type->value
            : (string) ($order->type ?? '');

        return self::documentTypeLabel($typeValue, $order->caption);
    }

    /**
     * Human-readable document type for lists (customer documents tab, order docs, mail attachments).
     */
    public static function documentTypeLabel(?string $typeValue, InvoiceCaption|string|null $caption): string
    {
        $typeValue = (string) ($typeValue ?? '');

        $description = match ($typeValue) {
            'quote' => 'Offerte',
            'order' => 'Order',
            'invoice' => 'Slotfactuur',
            'deposit_invoice' => 'Aanbetalingsfactuur',
            'credit_invoice' => 'Creditfactuur',
            'stock_order' => 'Inkooporder',
            'packing_slip' => 'Afleverbon',
            'postnl_label' => 'PostNL-label',
            'postnl_retour_label' => 'PostNL-retourlabel',
            'delivery_note' => 'Pakbon',
            default => OrderType::tryFrom($typeValue)?->getLabel()
                ?? ucfirst((string) __("orders.type.{$typeValue}")),
        };

        $captionEnum = $caption instanceof InvoiceCaption
            ? $caption
            : (is_string($caption) && $caption !== '' ? InvoiceCaption::tryFrom($caption) : null);

        if ($captionEnum !== null && $typeValue !== 'credit_invoice') {
            return $captionEnum->getLabel();
        }

        return $description;
    }

    public static function orderPdfMedia(BaseOrder $order): ?Media
    {
        $collection = Document::mediaCollectionForOrderType($order->getType() ?? OrderType::Quote);

        if ($collection === null) {
            return null;
        }

        return $order->getFirstMedia($collection);
    }

    /**
     * Same strategy as {@see \App\Http\Controllers\OrderController::orderPdfDownload()}.
     *
     * @return array{content: string, filename: string, mime: string}|null
     */
    public static function resolveOrderPdfAttachment(BaseOrder $order): ?array
    {
        $type = $order->getType();
        if ($type === null || Document::mediaCollectionForOrderType($type) === null) {
            return null;
        }

        $collection = Document::mediaCollectionForOrderType($type);
        if ($type === OrderType::Quote) {
            try {
                Document::regenerateQuotePdfFromLiveOrder($order);
                $order->refresh();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $media = $order->getFirstMedia($collection);

        if ($media === null) {
            try {
                Document::createFromOrder($order);
                $order->refresh();
                $media = $order->getFirstMedia($collection);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($media !== null && is_file($media->getPath())) {
            $content = file_get_contents($media->getPath());
            if ($content === false) {
                return null;
            }

            return [
                'content' => $content,
                'filename' => $media->file_name ?: Document::pdfFilenameForOrder($order),
                'mime' => $media->mime_type ?? 'application/pdf',
            ];
        }

        try {
            [$pdf, $filename] = Document::buildPdfWrapperFromOrder($order);

            return [
                'content' => $pdf->output(),
                'filename' => $filename,
                'mime' => 'application/pdf',
            ];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Attach financial PDFs when building the queued mailable (IDs only in the queue payload).
     *
     * @param  list<int>  $financialMediaIds
     * @param  list<int>  $financialOrderIds
     */
    public static function attachToMailable(
        \Illuminate\Mail\Mailable $mail,
        Main $main,
        array $financialMediaIds,
        array $financialOrderIds,
    ): void {
        $financialDocuments = $main->getMedia('financial_documents');

        foreach ($financialMediaIds as $mediaId) {
            $media = $financialDocuments->firstWhere('id', $mediaId);
            if ($media === null) {
                continue;
            }

            $attachment = self::resolveMediaAttachment($media);
            if ($attachment === null) {
                continue;
            }

            self::attachResolvedFile($mail, $attachment);
        }

        foreach ($financialOrderIds as $orderId) {
            try {
                $order = BaseOrder::findOrFailTypedWithoutScopes($orderId);
            } catch (\Throwable) {
                continue;
            }

            if ((int) $order->main_id !== (int) $main->getId()) {
                continue;
            }

            if (! self::hasBeenSentForMailAttachment($order)) {
                continue;
            }

            $attachment = self::resolveOrderPdfAttachment($order);
            if ($attachment === null) {
                continue;
            }

            self::attachResolvedFile($mail, $attachment);
        }
    }

    /**
     * @param  array{content: string, filename: string, mime: string}  $attachment
     */
    private static function attachResolvedFile(\Illuminate\Mail\Mailable $mail, array $attachment): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fin_doc_');
        if ($tmp === false) {
            return;
        }

        if (file_put_contents($tmp, $attachment['content']) === false) {
            @unlink($tmp);

            return;
        }

        $mail->attach($tmp, [
            'as' => $attachment['filename'],
            'mime' => $attachment['mime'],
        ]);
    }

    /**
     * @return array{content: string, filename: string, mime: string}|null
     */
    private static function resolveMediaAttachment(Media $media): ?array
    {
        $path = $media->getPathRelativeToRoot();
        if (! Storage::disk($media->disk)->exists($path)) {
            return null;
        }

        $content = Storage::disk($media->disk)->get($path);
        if ($content === null) {
            return null;
        }

        $filename = $media->file_name ?: ($media->name
            ? $media->name.'.'.$media->extension
            : 'document-'.$media->id.'.'.$media->extension);

        return [
            'content' => $content,
            'filename' => $filename,
            'mime' => $media->mime_type ?? 'application/octet-stream',
        ];
    }
}
