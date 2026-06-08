@props(['serialNumber', 'ledgerEntries'])
@php
    use App\Enums\OrderSubtype;
    use App\Models\Product;
    use App\Models\SerialNumber;
    /** @var \Illuminate\Support\Collection<int, SerialNumber>|array<int, SerialNumber> $ledgerEntries */
    $entries = $ledgerEntries instanceof \Illuminate\Support\Collection
        ? $ledgerEntries
        : collect($ledgerEntries ?? [$serialNumber]);
@endphp
<div class="space-y-2">
    <table class="min-w-full unit-table">
        <thead>
            <th class="py-1 text-left" style="min-width: 100px">Type</th>
            <th class="py-1 text-left" style="width: 100%;">Frame</th>
            <th class="py-1 text-left" style="min-width: 150px">Aanvraagnummer</th>
            <th class="py-1 text-left" style="min-width: 170px">Datum (aangemaakt)</th>
            <th class="py-1 text-left" style="min-width: 200px">Totaalfactuurbedrag <span style="font-size: 9px">(incl. BTW)</span></th>
            <th></th>
        </thead>

        @foreach ($entries as $entry)
            @php
                $order = $entry->order;
                $mainOrder = $order?->main ?? $entry->main;
                $frameName = $order?->frameProduct?->getName() ?? $entry->getName() ?? '-';
                $orderDate = $order?->getOrderDate() ?? $entry->getOrderDate();
                $totalAmount = $entry->getTotalPriceInc() ?? 0;
                $subtype = $entry->getOrderSubType();
                $unitType = $subtype === OrderSubtype::Unit && filled($entry->type)
                    ? Product::getFrameChairTypeLabel($entry->type)
                    : ($subtype->getLabel() ?? '-');
                $requestReference = $mainOrder?->getUid();
                $fallbackOrderNumber = filled($entry->order_number) ? trim((string) $entry->order_number) : '';
                $canExpand = in_array($subtype, [OrderSubtype::Unit, OrderSubtype::Part, OrderSubtype::Service], true)
                    && $order
                    && $order->orderProducts->isNotEmpty();
            @endphp
            <tbody x-data="{ open: false }">
                <tr data-id="{{ $entry->getId() }}">
                    <td class="py-1">
                        {{ $unitType }}
                    </td>
                    <td class="py-1">
                        {{ $frameName }}
                    </td>
                    <td class="py-1">
                        @if (filled($requestReference) && $mainOrder)
                            <a href="{{ route('filament.app.resources.mains.view', ['record' => $mainOrder->getId()]) }}" class="main-request-number-link hover:underline" target="_blank">
                                {{ $requestReference }}
                            </a>
                        @elseif (filled($fallbackOrderNumber))
                            {{ $fallbackOrderNumber }}
                        @else
                            -
                        @endif
                    </td>
                    <td class="py-1">
                        {{ $orderDate?->format('d-m-Y') ?? '' }}
                    </td>
                    <td class="py-1">
                        @money($totalAmount)
                    </td>
                    <td class="py-1 justify-items-end">
                        @if ($canExpand)
                            <x-filament::icon-button
                                color="gray"
                                :icon="\Filament\Support\Icons\Heroicon::ChevronUp"
                                :icon-alias="\Filament\Support\View\SupportIconAlias::SECTION_COLLAPSE_BUTTON"
                                class="fi-section-collapse-btn"
                                x-on:click.stop="open = !open"
                                x-bind:style="!open && { rotate: '180deg' }"
                            />
                        @endif
                    </td>
                </tr>

                @if ($canExpand)
                    <tr
                        class="bg-white min-w-full"
                        x-show="open"
                        x-cloak
                    >
                        <td colspan="100%" class="subtable-wrapper">
                            <table class="w-full subtable">
                                <thead>
                                    <th class="py-1 text-left" style="width: 400px">Artikelnaam</th>
                                    <th class="py-1 text-left" style="width: 200px">Artikelnummer</th>
                                    <th class="py-1 text-left" style="width: 50px">#</th>
                                    <th class="py-1 px-6 text-left"></th>
                                    <th></th>
                                </thead>

                                <tbody>
                                    @foreach ($order->orderProducts as $orderProduct)
                                        <tr data-id="{{ $orderProduct->getId() }}">
                                            <td class="py-1">
                                                {{ $orderProduct->getValue() ?? '' }}
                                            </td>
                                            <td class="py-1">
                                                @if ($orderProduct->product)
                                                    @can('manage products')
                                                        <a href="{{ route('filament.app.resources.products.edit', ['record' => $orderProduct->product->getId()]) }}" target="_blank">
                                                            {{ $orderProduct->product->getUid() ?? '' }}
                                                        </a>
                                                    @else
                                                        {{ $orderProduct->product->getUid() ?? '' }}
                                                    @endcan
                                                @else
                                                    -
                                                @endif
                                            </td>
                                            <td class="py-1">
                                                {{ $orderProduct->getQty() ?? '' }}
                                            </td>
                                            @if (in_array($subtype, [OrderSubtype::Unit, OrderSubtype::Part], true) && ($mainOrder?->getSubtype()) !== OrderSubtype::Service && $orderProduct->product)
                                                @php
                                                    $initData = base64_encode(json_encode([
                                                        'subtype' => OrderSubtype::Part->value,
                                                        'customer_id' => $serialNumber->getOwnerId(),
                                                        'product_id' => $orderProduct->product->getId(),
                                                    ]));
                                                @endphp
                                                <td class="py-1 px-6">
                                                    <button
                                                        type="button"
                                                        class="inline-flex items-center px-3 py-1 font-medium rounded-md bg-primary-600 text-white hover:bg-primary-700"
                                                        x-on:click="$dispatch('open-modal', {
                                                            id: 'unit_create_quote_or_order_modal',
                                                            initData: '{{ $initData }}',
                                                        })"
                                                    >
                                                        Bestellen
                                                    </button>
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </td>
                    </tr>
                @endif
            </tbody>
        @endforeach
    </table>
</div>

<style>
.fi-sc-tabs .fi-sc-tabs-tab[id='form.units'] {
    padding-top: 25px;
}

button.textAction {
    color: var(--gray-950);
    font-weight: bold;
    pointer-events: none;
    margin-right: 10px;
}

.fi-sc-section.unit-section {
    .fi-section {
        padding: 12px 15px;

        .fi-section-header {
            max-width: 900px;

            .fi-section-header-text-ctn {
                display: flex;
                white-space: nowrap;
                flex-direction: row;
                align-items: center;

                h2 {
                    font-size: 18px;
                    margin-top: 0;
                }

                .fi-section-header-description {
                    padding-left: 15px !important;
                }
            }

            .fi-section-header-after-ctn {
                width: 100%;

                .fi-sc.fi-inline {
                    display: flex;
                    justify-content: unset;

                    .fi-sc-action {
                        display: inline-flex;

                        &:has(.historyAction) {
                            position: relative;
                            bottom: 10px;
                            margin-left: -12px;
                            margin-right: auto;
                        }
                    }
                }

            }
        }

        .fi-section-content-ctn {
            max-width: 900px;
            padding: 10px 0 10px 10px;

            table.unit-table {
                font-size: 13px;

                & > thead {
                    tr {
                        padding-bottom: 3px;

                        th {
                            border-bottom: 1px solid grey;
                        }
                    }
                }

                & > tbody {
                    & > tr:first-child {
                        td {
                            padding-top: 15px;
                        }
                    }
                }

                button {
                    font-size: 12px;
                }

                td.subtable-wrapper {
                    padding-bottom: 15px;
                    padding-left: 20px;
                    padding-top: 10px;

                    table.subtable {
                        tr {
                            th {
                                font-weight: normal;
                                border-bottom: 1px solid #c0c0c0;
                            }
                        }
                    }
                }
            }

            a {
                border-bottom: 2px solid rgb(225, 225, 225);
                transition: all .2s;

                &:hover {
                    border-color: #333;
                    transition: all .2s;
                }
            }
        }
    }
}
</style>
