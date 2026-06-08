<?php

namespace App\Http\Livewire;

use App\Models\OrderProduct;
use Livewire\Component;

class QuoteEditorModal extends Component
{
    public bool $showQuoteEditorModal = false;
    public ?OrderProduct $product = null;

    protected $listeners = [
        'closeModalFromIframe' => 'close',
        'showQuoteEditorModal' => 'openConfigurator',
    ];

    public function openConfigurator($product)
    {
        /** @var OrderProduct|null $orderProduct */
        $orderProduct = is_array($product) ? OrderProduct::find($product['product'] ?? $product['id'] ?? null) : OrderProduct::find($product);
        if ($orderProduct === null) {
            return;
        }
        $this->product = $orderProduct;
        $this->showQuoteEditorModal = true;
    }

    public function close(?int $orderProductId = null, ?int $productId = null)
    {
        $this->reset('showQuoteEditorModal', 'product');

        $this->dispatch('addOrderProduct', [
            'orderProductId' => $orderProductId,
            'productId' => $productId,
        ]);
    }

    public function render()
    {
        return view('livewire.quote-editor-modal');
    }
}
