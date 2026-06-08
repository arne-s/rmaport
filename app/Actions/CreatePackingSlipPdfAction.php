<?php

namespace App\Actions;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderProductStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderSubtype;
use App\Models\Order\Main;
use App\Models\Order\Order;
use App\Models\PackingSlip;
use App\Support\PackingSlipChecklist;
use Barryvdh\Snappy\Facades\SnappyPdf as PDF;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CreatePackingSlipPdfAction
{
    /**
     * @param  array<int, int|string>  $orderProductIds
     * @param  array<int, string>  $checklist
     * @param  array<int, string>  $deliveryProofChecked
     * @param  array<string, string|null>  $deliveryProofTexts
     */
    public function execute(
        Main $main,
        Order $order,
        array $orderProductIds,
        string $signature,
        ?string $comment = null,
        ?string $reference = null,
        string $checklistType = '',
        array $checklist = [],
        array $deliveryProofChecked = [],
        array $deliveryProofTexts = [],
    ): PackingSlip {
        $resolvedChecklistType = PackingSlipChecklist::resolveType($checklistType !== '' ? $checklistType : null);
        $ids = array_values(array_unique(array_map(static fn (int|string $id): int => (int) $id, $orderProductIds)));
        if ($ids === []) {
            throw new RuntimeException('Selecteer minimaal één product voor de afleverbon.');
        }

        return DB::transaction(function () use ($main, $order, $ids, $signature, $comment, $reference, $resolvedChecklistType, $checklist, $deliveryProofChecked, $deliveryProofTexts): PackingSlip {
            $packingSlip = PackingSlip::query()->create([
                'uid' => PackingSlip::getNextUid(),
                'order_id' => $order->getId(),
                'author_id' => Auth::id(),
                'signature' => $signature,
                'comment' => $comment !== '' ? $comment : null,
                'reference' => $reference !== '' ? $reference : null,
                'checklist_type' => $resolvedChecklistType,
                'checklist' => $checklist !== [] ? $checklist : null,
            ]);

            $lines = $order->packingSlipEligibleOrderProducts()
                ->whereNull('order_products.packing_slip_id')
                ->whereIn('order_products.id', $ids)
                ->lockForUpdate()
                ->with('product')
                ->orderBy('order_products.sort')
                ->get();

            if ($lines->count() !== count($ids)) {
                throw new RuntimeException(
                    'Een of meer geselecteerde regels zijn ongeldig of staan al op een afleverbon. Vernieuw de pagina en probeer opnieuw.'
                );
            }

            foreach ($lines as $line) {
                $line->forceFill([
                    'packing_slip_id' => $packingSlip->getKey(),
                    'status' => OrderProductStatus::Delivered,
                ])->save();
            }

            $products = $lines;

            $checklistKeys = is_array($checklist) ? $checklist : [];

            $html = view('order.packing-slip', [
                'main' => $main,
                'order' => $order,
                'packingSlip' => $packingSlip,
                'products' => $products,
                'todayDate' => now()->format('d-m-Y'),
                'deliveryProofTypeLabel' => PackingSlipChecklist::labelForType($resolvedChecklistType),
                'deliveryProofLines' => PackingSlipChecklist::formatDeliveryProofForPdf(
                    $resolvedChecklistType,
                    $deliveryProofChecked,
                    $deliveryProofTexts,
                ),
                'checklistIntro' => PackingSlipChecklist::introForType($resolvedChecklistType),
                'checklistItems' => PackingSlipChecklist::resolveLabels($resolvedChecklistType, $checklistKeys),
                'checklistOutro' => PackingSlipChecklist::outroForType($resolvedChecklistType),
            ])->render();

            $footerHtml = '<!DOCTYPE html>
<html>
<head>
    <script>
        function subst() {
            var vars = {};
            var query_strings_from_url = document.location.search.substring(1).split("&");
            for (var query_string in query_strings_from_url) {
                if (query_strings_from_url.hasOwnProperty(query_string)) {
                    var temp_var = query_strings_from_url[query_string].split("=", 2);
                    vars[temp_var[0]] = decodeURI(temp_var[1]);
                }
            }
            var css_selector_classes = ["page", "frompage", "topage", "webpage", "section", "subsection", "date", "isodate", "time", "title", "doctitle", "sitepage", "sitepages"];
            for (var css_class in css_selector_classes) {
                if (css_selector_classes.hasOwnProperty(css_class)) {
                    var element = document.getElementsByClassName(css_selector_classes[css_class]);
                    for (var j = 0; j < element.length; ++j) {
                        element[j].textContent = vars[css_selector_classes[css_class]];
                    }
                }
            }
        }
    </script>
</head>
<body onload="subst()" style="text-align: center; font-size: 10px; padding: 5px 0; margin: 0;">
    Pagina <span class="page"></span> van <span class="topage"></span>
</body>
</html>';

            $pdf = PDF::loadHTML($html)
                ->setOption('margin-top', $order->getPdfSettings('margin-top', 'packing_slip'))
                ->setOption('margin-bottom', 15)
                ->setOption('margin-left', 0)
                ->setOption('margin-right', 0)
                ->setOption('header-html', $order->getPdfSettings('header-html', 'packing_slip'))
                ->setOption('header-spacing', $order->getPdfSettings('header-spacing', 'packing_slip'))
                ->setOption('footer-html', $footerHtml)
                ->setOption('footer-spacing', 5);

            $tempPath = tempnam(sys_get_temp_dir(), 'packing-slip-');

            if (is_string($tempPath) && file_exists($tempPath)) {
                @unlink($tempPath);
            }

            $pdf->save($tempPath);

            $main->addMedia($tempPath)
                ->usingFileName('afleverbon-' . $packingSlip->uid . '.pdf')
                ->withCustomProperties([
                    'readonly' => true,
                ])
                ->toMediaCollection('delivery_documents');

            @unlink($tempPath);

            if ($main->getSubtype() === OrderSubtype::Part) {
                $order->refresh();
                if ($order->sent_at === null) {
                    $order->updateQuietly(['sent_at' => now()]);
                }
                if ($main->getInvoiceId() === null) {
                    try {
                        $main->createInvoiceIfRequired();
                    } catch (Throwable $e) {
                        Log::error('createInvoiceIfRequired na afleverbon (onderdeel) mislukt', [
                            'main_id' => $main->getKey(),
                            'exception' => $e,
                        ]);
                    }
                }

                $order->refresh();
                if ($order->getStatus() === OrderGeneralStatus::Completed) {
                    $order->setStatus(OrderGeneralStatus::Sent);
                    $order->saveQuietly();
                }
            }

            $eligibleTotal = $order->packingSlipEligibleOrderProducts()->count();
            $linkedCount = $order->packingSlipEligibleOrderProducts()
                ->whereNotNull('order_products.packing_slip_id')
                ->count();

            if ($main->getSubtype() === OrderSubtype::Service) {
                $main->changeOrderStatus(OrderStatus::Assembled);
            } elseif ($eligibleTotal > 0 && $linkedCount >= $eligibleTotal) {
                $main->changeOrderStatus(OrderStatus::Delivered);
            } else {
                $main->changeOrderStatus(OrderStatus::PartiallyDelivered);
            }

            return $packingSlip;
        });
    }
}
