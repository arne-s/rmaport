@php
    /** @var \App\Models\Order\BaseOrder $order */

    try {
        $id = request()->all()['updates'][0]['payload']['params'][1];
        $order = \App\Models\Order\BaseOrder::find($id);
    } catch (Exception $e) {
        $order = null;
    }
@endphp

<div class="modal-order">
    {!! $order->getDoc()  !!}
</div>

<style>
    div.modal-order {
        max-height: 80vh;
        overflow-y: auto !important;
        margin: -16px -24px;
    }

    div.modal-order div.order-wrapper {
        box-shadow: #00000020 inset -1px 1px 20px;
        padding: 30px;
    }
</style>
