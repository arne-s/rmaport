<?php

namespace App\Models;

use App\Enums\OrderType;
use App\Jobs\SyncOrderDocumentToExactJob;
use App\Models\Order\BaseOrder;
use Barryvdh\Snappy\Facades\SnappyPdf as PDF;
use Barryvdh\Snappy\PdfWrapper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;
use RuntimeException;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $documentable_type
 * @property int $documentable_id
 * @property string|null $exact_id
 * @property Carbon|null $exact_synced_at
 * @property Carbon|null $exact_error_at
 * @property string $content
 * @property array<string, mixed>|null $additional
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model $documentable
 */
class Document extends Model
{
    protected $table = 'documents';

    protected $fillable = [
        'documentable_type',
        'documentable_id',
        'exact_id',
        'exact_synced_at',
        'exact_error_at',
        'content',
        'additional',
    ];

    protected function casts(): array
    {
        return [
            'additional' => 'array',
            'exact_synced_at' => 'datetime',
            'exact_error_at' => 'datetime',
        ];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Create an HTML snapshot row from a sales {@see BaseOrder}, then render and attach the PDF on the order (Spatie).
     */
    public static function createFromOrder(BaseOrder $order): self
    {
        $order = BaseOrder::findOrFailTypedWithoutScopes($order->getId());

        $type = $order->getType();
        if ($type === null || static::mediaCollectionForOrderType($type) === null) {
            throw new InvalidArgumentException(
                'Document::createFromOrder only supports quote, order, and invoice order types.'
            );
        }

        $html = static::buildHtmlSnapshotForOrder($order);

        $document = $order->documents()->create([
            'content' => $html,
        ]);

        $document->renderPdf();

        if (config('exact.enabled')) {
            SyncOrderDocumentToExactJob::dispatch($document->getKey());
        }

        return $document;
    }

    /**
     * Re-render a quote PDF from live order/customer data (admin download/preview).
     * Updates the latest {@see Document} row and replaces Spatie `quote` media.
     */
    public static function regenerateQuotePdfFromLiveOrder(BaseOrder $order): self
    {
        $order = BaseOrder::findOrFailTypedWithoutScopes($order->getId());

        if ($order->getType() !== OrderType::Quote) {
            throw new InvalidArgumentException(
                'Document::regenerateQuotePdfFromLiveOrder only supports quote order type.'
            );
        }

        $html = static::buildHtmlSnapshotForOrder($order);

        $document = Document::query()
            ->where('documentable_id', $order->getKey())
            ->where('documentable_type', $order->getMorphClass())
            ->latest('id')
            ->first();

        if ($document === null) {
            $document = $order->documents()->create([
                'content' => $html,
            ]);
        } else {
            $document->setContent($html);
            $document->save();
        }

        $document->renderPdf();

        return $document;
    }

    /**
     * HTML snapshot used for {@see self::createFromOrder} and live PDF downloads.
     */
    public static function buildHtmlSnapshotForOrder(BaseOrder $order, bool $isPreview = false): string
    {
        $order = BaseOrder::findOrFailTypedWithoutScopes($order->getId());

        $type = $order->getType();
        if ($type === null) {
            throw new InvalidArgumentException('Order has no type for document HTML.');
        }

        if (($type === OrderType::Invoice || $type === OrderType::DepositInvoice) && $order->getPaidAt() === null) {
            PaymentLink::createForInvoice($order);
            $order->refresh();
        }

        $order->load([
            'orderProducts.product',
            'customer.address.country',
            'billingCustomer.address.country',
            'billingCustomer.billingAddress.country',
            'billingCustomer.billingAddress.country',
            'shippingCustomer.shippingAddress.country',
            'shippingCustomer.billingAddress.country',
            'order.author',
            'order.order.author',
            'main.author',
            'main.advisor',
            'main.quotes.author',
            'main.orderEvents.user',
            'author',
            'invoice',
            'paymentLink',
        ]);
        $viewName = match ($type) {
            OrderType::Quote => 'order.quote',
            OrderType::Order => 'order.order',
            default => 'order.invoice',
        };

        return view($viewName, [
            'order' => $order,
            'products' => $order->orderProducts,
            'isPreview' => $isPreview,
        ])->render();
    }

    /**
     * @return array{0: PdfWrapper, 1: string}
     */
    public static function buildPdfWrapperFromOrder(BaseOrder $order): array
    {
        $html = static::buildHtmlSnapshotForOrder($order);

        $pdf = PDF::loadHTML($html)
            ->setOption('margin-top', 10)
            ->setOption('margin-bottom', 10)
            ->setOption('margin-left', 0)
            ->setOption('margin-right', 0);

        return [$pdf, static::pdfFilenameForOrder($order)];
    }

    public static function pdfFilenameForOrder(BaseOrder $order): string
    {
        $type = $order->getType();
        if ($type === null) {
            throw new InvalidArgumentException('Order has no type for PDF filename.');
        }

        $prefix = match ($type) {
            OrderType::Quote => 'offerte',
            OrderType::Order => 'orderbevestiging',
            OrderType::DepositInvoice => 'aanbetalingsfactuur',
            OrderType::CreditInvoice => 'creditfactuur',
            default => 'factuur',
        };
        $safeUid = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $order->getUidFormatted());

        return $prefix.'-'.$safeUid.'.pdf';
    }

    public static function mediaCollectionForOrderType(OrderType $type): ?string
    {
        return match ($type) {
            OrderType::Quote => 'quote',
            OrderType::Order => 'order',
            OrderType::DepositInvoice => 'deposit_invoice',
            OrderType::CreditInvoice => 'credit_invoice',
            OrderType::Invoice => 'invoice',
            default => null,
        };
    }

    /**
     * Render PDF from stored {@see $this->content} and attach to the related {@see BaseOrder}'s Spatie collection.
     * Idempotent: clears that collection first so repeat calls replace the file.
     */
    public function renderPdf(): void
    {
        $this->loadMissing('documentable');

        $order = $this->documentable;
        if (! $order instanceof BaseOrder) {
            throw new InvalidArgumentException('renderPdf only supports documents whose documentable is a BaseOrder.');
        }

        $type = $order->getType();
        if ($type === null) {
            throw new InvalidArgumentException('Order has no type for PDF rendering.');
        }

        $collection = static::mediaCollectionForOrderType($type);
        if ($collection === null) {
            throw new InvalidArgumentException('Order type does not support PDF media collection.');
        }

        $html = trim($this->content);
        if ($html === '') {
            throw new RuntimeException('Document has no HTML content to render as PDF.');
        }

        $pdf = PDF::loadHTML($this->content)
            ->setOption('margin-top', 10)
            ->setOption('margin-bottom', 10)
            ->setOption('margin-left', 0)
            ->setOption('margin-right', 0);

        $binary = $pdf->output();
        if ($binary === null || $binary === '') {
            throw new RuntimeException('PDF output was empty.');
        }

        $order->clearMediaCollection($collection);

        $order->addMediaFromString($binary)
            ->usingFileName(static::pdfFilenameForOrder($order))
            ->toMediaCollection($collection);
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAdditional(): ?array
    {
        return $this->additional;
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    public function setAdditional(?array $value): static
    {
        $this->additional = $value;

        return $this;
    }
}
