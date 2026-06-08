<?php

namespace App\Filament\Resources\PurchaseOrderResource\Actions;

use App\Models\PurchaseOrder;
use Filament\Actions\Action;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class PreviewPurchaseOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'preview_purchase_order';
    }

    public function getLabel(): string
    {
        return 'Preview';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('heroicon-o-eye')
            ->extraAttributes([
                'class' => 'secondary',
            ])
            ->mountUsing(function (): void {
                $livewire = $this->getLivewire();
                try {
                    $livewire->applyFormAndSaveForPreview();
                } catch (ValidationException $e) {
                    $livewire->dispatch('scrollToFirstError');
                    throw $e;
                }
            })
            ->modalHeading('Preview')
            ->modal()
            ->modalContent(function (): HtmlString {
                $record = $this->getRecord();
                if (! $record instanceof PurchaseOrder) {
                    return new HtmlString('<p>Geen inkooporder.</p>');
                }
                $record->loadMissing(['orderProducts', 'supplier']);
                $products = $record->orderProducts;

                $html = view('order.purchase_order', [
                    'order' => $record,
                    'products' => $products,
                    'isPreview' => true,
                ])->render();

                $modalPaddingStyle = '<style>div.order-wrapper { padding: 10px !important; }</style>';
                $html = str_replace('<head>', '<head>' . $modalPaddingStyle, $html);

                $iframeId = 'purchase-order-preview-iframe';

                return new HtmlString(
                    '<div style="border-radius:5px; max-height:75vh; overflow:hidden;">'
                    . '<iframe id="' . $iframeId . '" '
                    . 'style="border:0; width:100%; height:75vh; border-radius:5px; display:block;" '
                    . 'srcdoc="' . htmlspecialchars($html, ENT_QUOTES) . '" '
                    . 'sandbox="allow-same-origin allow-scripts allow-forms allow-modals" '
                    . '></iframe>'
                    . '</div>'
                );
            })
            ->modalFooterActions([]);
    }
}
