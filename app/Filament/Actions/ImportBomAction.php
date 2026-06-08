<?php

namespace App\Filament\Actions;

use App\Filament\Resources\NoteResource;
use App\Models\BillOfMaterial;
use App\Models\Order\BaseOrder;
use App\Models\OrderProduct;
use App\Models\PurchaseOrder;
use Filament\Actions\Action;
use Filament\Actions\Concerns\CanCustomizeProcess;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Livewire\Component;

class ImportBomAction extends Action
{
    use CanCustomizeProcess;

    public static function getDefaultName(): ?string
    {
        return 'import_bom';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Stuklijst inladen')
            ->button()
            ->modalHeading('Stuklijst inladen')
            ->extraModalWindowAttributes(['class' => 'import-bom-modal'])
            ->modalWidth(Width::Small)
            ->modalSubmitActionLabel('Inladen')
            ->schema([
                Select::make('bill_of_material_id')
                    ->label('Stuklijst')
                    ->options(BillOfMaterial::pluck('name', 'id'))
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data, BaseOrder|PurchaseOrder|null $record, Component $livewire) {
                $bom = BillOfMaterial::query()->find($data['bill_of_material_id']);
                if ($record === null) {
                    return;
                }

                $vatPercent = method_exists($livewire, 'getVatPercentageFromCode')
                    ? $livewire->getVatPercentageFromCode($livewire->record->getAdditional()['exact_vat_code'] ?? null)
                    : 21;

                $orderProducts = [];
                foreach ($bom->billOfMaterialProducts()->get() as $bomProduct) {
                    $product = $bomProduct->product;
                    if ($product === null) {
                        continue;
                    }

                    $attributeSummaryBasicFromProduct = $product->getDescription() ?? '';

                    $createData = [
                        'product_id' => $product->getId(),
                        'value' => $product->getName(),
                        'qty' => $bomProduct->getQty() ?? 1,
                        'company_purchase_price_base' => round($product->getCompanyPurchasePrice(), 2),
                        'company_purchase_price_additional' => 0,
                        'company_purchase_price_subtotal' => round($product->getCompanyPurchasePrice(), 2),
                        'company_sales_price_base' => round($product->getCompanySalesPrice(), 2),
                        'company_sales_price_additional' => 0,
                        'company_sales_price_subtotal' => round($product->getCompanySalesPrice(), 2),
                        'attribute_summary_basic' => $attributeSummaryBasicFromProduct,
                        'attribute_summary_company' => '',
                        'vat' => $vatPercent,
                        'supplier_id' => $product->supplier?->id,
                    ];
                    if ($record instanceof PurchaseOrder) {
                        $createData['order_id'] = null;
                    } else {
                        $createData['order_id'] = $record->getId();
                    }

                    /** @var OrderProduct $orderProduct */
                    $orderProduct = OrderProduct::create($createData);
                    if ($record instanceof PurchaseOrder) {
                        $orderProduct->setPurchaseOrderId($record->getId());
                    }
                    $orderProduct
                        ->setFulfillmentTypeBasedOnProduct()
                        ->save();
                    $orderProducts[] = $orderProduct->getId();
                }

                // Dispatch event to the root component/page
                $livewire->dispatch('loadBomProducts', orderProducts: $orderProducts);

                Notification::make()
                    ->title('Stuklijst ingeladen')
                    ->success()
                    ->send();
            });
    }
}
