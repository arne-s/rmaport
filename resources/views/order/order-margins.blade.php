@props(['order', 'companyView' => false])
@php
    $avCustomer = App\Models\Customer::getAvCustomer();
@endphp

@extends('order.order_layout')
@section('content')
<div>
        <div style="overflow: hidden; margin-bottom: 15px;">
            <div style="position: absolute; right: 0">
            @include('order._rd_logo', ['align' => 'right'])
            </div>

                <h2 style="padding-bottom: 15px">Marge overzicht</h2>

                @include('order._meta_info', [
                    'list' => [
                        [
                            'Aanvraagnummer' => $order->main?->getUid(),
                            'Ordernummer' => $order->getUid(),
                            'Orderdatum' => $order->getOrderDate()->format('d-m-Y'),
                            __('orders.documents.end_customer') => $order->customer?->getName() ?? $order->billingCustomer?->getName() ?? '',
                            __('orders.documents.billing_address') => $order->billingAddress?->getAddressTemplateIncName() ?? '',
                        ],
                    ],
                ])
        </div>
        @include('order._margins_table', ['order' => $order, 'companyView' => $companyView])
<style>
    div.meta table {
        width: 100%;
    }

    div.order-wrapper h2 {
        font-size: 26px;
        margin-bottom: 5px;
    }
</style>
</div>
@endsection
