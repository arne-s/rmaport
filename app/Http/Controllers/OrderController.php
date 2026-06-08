<?php

namespace App\Http\Controllers;

use App\Enums\OrderType;
use App\Enums\PaymentMethodType;
use App\Models\Document;
use App\Models\Order\BaseOrder;
use App\Models\PaymentLink;
use Barryvdh\Snappy\Facades\SnappyPdf as PDF;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class OrderController extends Controller
{
    /**
     * Download PDF export
     */
    public function managerExport(BaseOrder $order): Response
    {
        abort_unless(Gate::allows('export-order'),
            403, 'Unauthorized action.'
        );

        return $this->download($order);
    }

    /**
     * Download order/quote/factuur-PDF: eerst Spatie-media, anders genereren en koppelen, anders direct streamen.
     */
    public function orderPdfDownload(int|string $id): SymfonyResponse
    {
        abort_unless(Gate::allows('export-order'),
            403, 'Unauthorized action.'
        );

        $order = BaseOrder::findOrFailTypedWithoutScopes($id);

        $type = $order->getType();
        match ($type) {
            OrderType::Quote,
            OrderType::Order,
            OrderType::DepositInvoice,
            OrderType::CreditInvoice,
            OrderType::Invoice => true,
            default => abort(404, 'Ongeldig documenttype.'),
        };

        if ($type === OrderType::Quote) {
            try {
                Document::regenerateQuotePdfFromLiveOrder($order);
                $order->refresh();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $collection = match ($type) {
            OrderType::Quote => 'quote',
            OrderType::Order => 'order',
            OrderType::DepositInvoice => 'deposit_invoice',
            OrderType::CreditInvoice => 'credit_invoice',
            OrderType::Invoice => 'invoice',
        };

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
            return response()->download($media->getPath(), $media->file_name);
        }

        [$pdf, $filename] = Document::buildPdfWrapperFromOrder($order);

        return $pdf->download($filename);
    }

    public function companyDocument(BaseOrder $baseOrder)
    {
        $baseOrder->generateDoc();
        $baseOrder->save();

        throw_if(empty($baseOrder->getDoc()),
            new Exception('Er ging iets mis bij het genereren van het document'));

        return $baseOrder->getDoc();
    }

    public function companyExport(BaseOrder $baseOrder): Response
    {
        return $this->download($baseOrder);
    }

    public function customerExport(string $publicAccessToken): Response
    {
        $order = BaseOrder::where('public_access_token', $publicAccessToken)
            ->firstOrFail();

        return $this->download($order, 'inline');
    }

    /**
     * @throws Throwable
     */
    protected function download(BaseOrder $order, string $method = 'download')
    {
        $statusValue = $order->getStatus()?->value;
        if (in_array($statusValue, ['initial', 'draft'], true)) {
            $order->generateDoc(force: true);
            $uid = $order->getUidFormatted() ?: 'concept-' . $order->getId();
        } else {
            // if (empty($order->getDoc())) {
            $order->generateDoc();
            $order->save();
            $uid = $order->getUidFormatted();
            // }
        }

        throw_if(empty($order->getDoc()),
            new Exception('Er ging iets mis bij het genereren van het document'));

        $pdf = PDF::loadHTML($order->getDoc())
            ->setOption('margin-left', 0)
            ->setOption('margin-right', 0)
            ->setOption('margin-top', $order->getPdfSettings('margin-top'))
            ->setOption('header-html', $order->getPdfSettings('header-html'))
            ->setOption('header-spacing', $order->getPdfSettings('header-spacing'));

        return $pdf->$method($uid . '.pdf');
    }

    public function mollieWebhook(Request $request): JsonResponse
    {
        $id = $request->input('id') ?? $request->input('entityId');
        if (! is_string($id) || $id === '') {
            Log::info('mollie.webhook: missing id', ['payload' => $request->all()]);

            return response()->json(['status' => 'ignored']);
        }

        if (! str_starts_with($id, 'pl_')) {
            return response()->json(['status' => 'ignored', 'reason' => 'not_payment_link']);
        }

        $paymentLinkRow = PaymentLink::query()->where('payment_id', $id)->first();
        if ($paymentLinkRow === null) {
            Log::info('mollie.webhook: unknown payment link id', ['id' => $id]);

            return response()->json(['status' => 'ok']);
        }

        try {
            $mollie = app('mollie');
            $remote = $mollie->paymentLinks->get($id);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error'], 500);
        }

        if (! $remote->isPaid()) {
            return response()->json(['status' => 'pending']);
        }

        DB::transaction(function () use ($paymentLinkRow): void {
            $paymentLinkRow->refresh();
            $paymentLinkRow->setAsPaid();

            $order = BaseOrder::withoutGlobalScopes()
                ->where('payment_link_id', $paymentLinkRow->id)
                ->first();

            if ($order === null) {
                Log::warning('mollie.webhook: no order for payment link', [
                    'payment_link_id' => $paymentLinkRow->id,
                ]);

                return;
            }

            if ($order->getPaidAt() !== null) {
                return;
            }

            $order->setPaidAt(now());
            $order->setPaymentMethod(PaymentMethodType::MollieIdeal);
            $order->save();

            if ($order->getType() === OrderType::DepositInvoice) {
                $parentOrder = $order->order;
                if ($parentOrder !== null) {
                    $parentOrder->setIsVerified(1);
                    $parentOrder->save();
                }
            }
        });

        return response()->json(['status' => 'success']);
    }
}
