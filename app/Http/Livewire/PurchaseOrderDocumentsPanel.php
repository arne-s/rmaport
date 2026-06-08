<?php

namespace App\Http\Livewire;

use App\Models\PurchaseOrder;
use App\Support\PurchaseOrderDocumentList;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class PurchaseOrderDocumentsPanel extends Component
{
    public int $purchaseOrderId;

    #[On('purchase-order-documents-updated')]
    public function refreshDocuments(): void
    {
        // Re-render after parent upload action completes.
    }

    public function render(): View
    {
        $purchaseOrder = PurchaseOrder::query()->findOrFail($this->purchaseOrderId);

        return view('livewire.purchase-order-documents-panel', [
            'documents' => PurchaseOrderDocumentList::for($purchaseOrder),
        ]);
    }
}
