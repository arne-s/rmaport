@props(['showCompanySalesPrice' => true])
@php
    $livewire = $getLivewire();
    $record = $getRecord() ?? $this->record;
    $columns = 4;
    if (!$showCompanySalesPrice) $columns -= 2;
@endphp
<div
    style="min-width: {{ $showCompanySalesPrice ? '550px' : '400px' }};"
    wire:key="totals-{{ $record->id }}"
    x-data="{
        INSUFFICIENT_MARGIN_THRESHOLD: 20,
        showCompanySalesPrice: @json($showCompanySalesPrice),
        numberFormatter: new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }),

        companyPurchasePriceDiscountPercentage: 0,
        companySalesPriceDiscountPercentage: 0,

        totals: {
            company_purchase_price_subtotal: 0,
            company_purchase_price_total: 0,
            company_purchase_price_total_inc_vat: 0,
            company_purchase_price_vat: 0,

            company_sales_price_subtotal: 0,
            company_sales_price_discount: 0,
            company_sales_price_total: 0,
            company_sales_price_total_inc_vat: 0,
            company_sales_price_vat: 0,

            company_margin_subtotal: 0,
            company_margin_total: 0,
            company_margin_total_inc_vat: 0,
            company_margin_subtotal_percentage: null,
            company_margin_total_percentage: null,
            company_margin_total_inc_vat_percentage: null,
        },

        init() {
            this.computeTotals();

            $wire.$on('update-totals', (event) => {
                this.computeTotals();
            });

            // Ensure discount amounts default to 0,00
            try {
                if (typeof $wire.get === 'function') {
                    if (!$wire.get('companyPurchasePriceDiscount')) $wire.set('companyPurchasePriceDiscount', '0,00');
                    if (this.showCompanySalesPrice && !$wire.get('companySalesPriceDiscount')) $wire.set('companySalesPriceDiscount', '0,00');
                } else {
                    if (!$wire.companyPurchasePriceDiscount) $wire.companyPurchasePriceDiscount = '0,00';
                    if (this.showCompanySalesPrice && !$wire.companySalesPriceDiscount) $wire.companySalesPriceDiscount = '0,00';
                }
            } catch (e) {
                // ignore
            }
        },

        // Allow typing decimals and commas in percentage inputs without blocking
        onPercentageInput(event, modelName) {
            const raw = String(event.target.value || '');
            // allow digits, dots and commas only
            const sanitized = raw.replace(/[^0-9\.,]/g, '');
            // preserve comma/dot as typed so user can enter decimals
            event.target.value = sanitized;
            this[modelName] = sanitized;
        },

        // Format currency inputs on blur and set Livewire property
        formatAmountInput(event, fieldName) {
            const raw = String(event.target.value || '').trim();
            let n = 0;
            if (raw === '' || raw === null) {
                n = 0;
            } else {
                const normalized = raw.replace(/\./g, '').replace(',', '.');
                const parsed = parseFloat(normalized);
                n = Number.isFinite(parsed) ? parsed : 0;
            }
            const formatted = n.toFixed(2).replace('.', ',');
            event.target.value = formatted;
            if (typeof $wire.set === 'function') {
                $wire.set(fieldName, formatted);
            } else {
                try { $wire[fieldName] = formatted; } catch (e) {}
            }
            this.computeTotals();
        },

        setCompanyPurchaseDiscountFromPercentage() {
            const subtotal = this.totals.company_purchase_price_subtotal || 0;
            const pct = Number(this.companyPurchasePriceDiscountPercentage.replace(',', '.')) || 0;
            const amount = Math.round((subtotal * (pct / 100)) * 100) / 100;
            if (typeof $wire.set === 'function') {
                $wire.set('companyPurchasePriceDiscount', amount.toFixed(2).replace('.', ','));
            }
            this.computeTotals();
        },

        setCompanySalesDiscountFromPercentage() {
            const subtotal = this.totals.company_sales_price_subtotal || 0;
            const pct = Number(this.companySalesPriceDiscountPercentage.replace(',', '.')) || 0;
            const amount = Math.round((subtotal * (pct / 100)) * 100) / 100;
            if (typeof $wire.set === 'function') {
                $wire.set('companySalesPriceDiscount', amount.toFixed(2).replace('.', ','));
            }
            this.computeTotals();
        },

        parseMoneyAmount(value) {
            if (typeof value === 'string') {
                const normalized = value.replace(/\./g, '').replace(',', '.').trim();
                const n = parseFloat(normalized);
                return Number.isFinite(n) ? Math.round(n * 100) / 100 : 0;
            }
            if (typeof value === 'number') return Math.round(value * 100) / 100;
            return 0;
        },

        getMarginPercentage(total, base) {
            if (total === 0 && base === 0) return 0;
            if (base === 0) return 100;
            return ((total / base) - 1) * 100;
        },

        getVatPercentage() {
            try {
                const v = (typeof $wire.get === 'function' ? $wire.get('vat_percentage') : $wire.vat_percentage) ?? $wire.data?.vat_percentage ?? '21';
                const pct = parseFloat(String(v).replace(',', '.'));
                return Number.isFinite(pct) ? Math.max(0, pct) : 21;
            } catch (e) {
                return 21;
            }
        },

        computeTotals() {
            const orderProducts = Object.values($wire.data.order_products ?? {});

            let cPurchaseSubtotal = 0;
            let cSalesSubtotal = 0;

            orderProducts.forEach(op => {
                if (!op || !op.id) return;
                const qty = this.parseMoneyAmount(op.qty ?? 0);
                const purchaseBase = this.parseMoneyAmount(op.company_purchase_price_base ?? 0);
                cPurchaseSubtotal += purchaseBase * qty;
                cSalesSubtotal += this.parseMoneyAmount(op.company_sales_price_total ?? 0);
            });

            // Discounts are stored as fields on the component, not in the form data
            const cPurchaseDiscount = -this.parseMoneyAmount($wire.companyPurchasePriceDiscount ?? 0);
            const cSalesDiscount = this.showCompanySalesPrice
                ? -this.parseMoneyAmount($wire.companySalesPriceDiscount ?? 0)
                : 0;

            // Calculate the discount percentages (don't overwrite inputs while user is typing)
            try {
                const focusedId = document.activeElement ? document.activeElement.id : null;

                const purchasePct = (cPurchaseSubtotal === 0) ? 0 : Math.round(this.parseMoneyAmount($wire.companyPurchasePriceDiscount ?? 0) / cPurchaseSubtotal * 10_000) / 100;
                if (focusedId !== 'cPurchasePriceDiscountPercentage') {
                    this.companyPurchasePriceDiscountPercentage = String(purchasePct).replace('.', ',');
                }

                if (this.showCompanySalesPrice) {
                    const salesPct = (cSalesSubtotal === 0) ? 0 : Math.round(this.parseMoneyAmount($wire.companySalesPriceDiscount ?? 0) / cSalesSubtotal * 10_000) / 100;
                    if (focusedId !== 'cSalesPriceDiscountPercentage') {
                        this.companySalesPriceDiscountPercentage = String(salesPct).replace('.', ',');
                    }
                }
            } catch (e) {
                // ignore
            }

            const vatPct = this.getVatPercentage();
            const vatFactor = 1 + (vatPct / 100);

            const cPurchaseTotal = cPurchaseSubtotal + cPurchaseDiscount;
            const cPurchaseTotalIncVat = cPurchaseTotal * vatFactor;

            this.totals.company_purchase_price_subtotal = Math.round(cPurchaseSubtotal * 100) / 100;
            this.totals.company_purchase_price_total = Math.round(cPurchaseTotal * 100) / 100;
            this.totals.company_purchase_price_total_inc_vat = Math.round(cPurchaseTotalIncVat * 100) / 100;

            this.totals.company_purchase_price_vat = Math.round((cPurchaseTotalIncVat - cPurchaseTotal) * 100) / 100;

            if (this.showCompanySalesPrice) {
                const cSalesTotal = cSalesSubtotal + cSalesDiscount;
                const cSalesTotalIncVat = cSalesTotal * vatFactor;

                const cMarginSubtotal = cSalesSubtotal - cPurchaseSubtotal;
                const cMarginTotal = cSalesTotal - cPurchaseTotal;
                const cMarginTotalIncVat = cSalesTotalIncVat - cPurchaseTotalIncVat;

                this.totals.company_sales_price_subtotal = Math.round(cSalesSubtotal * 100) / 100;
                this.totals.company_sales_price_discount = Math.round(cSalesDiscount * 100) / 100;
                this.totals.company_sales_price_total = Math.round(cSalesTotal * 100) / 100;
                this.totals.company_sales_price_total_inc_vat = Math.round(cSalesTotalIncVat * 100) / 100;
                this.totals.company_sales_price_vat = Math.round((cSalesTotalIncVat - cSalesTotal) * 100) / 100;

                this.totals.company_vat_net = Math.round((this.totals.company_sales_price_vat - this.totals.company_purchase_price_vat) * 100) / 100;

                this.totals.company_margin_subtotal = Math.round(cMarginSubtotal * 100) / 100;
                this.totals.company_margin_total = Math.round(cMarginTotal * 100) / 100;
                this.totals.company_margin_total_inc_vat = Math.round(cMarginTotalIncVat * 100) / 100;
                this.totals.company_margin_subtotal_percentage = this.getMarginPercentage(this.totals.company_sales_price_subtotal, this.totals.company_purchase_price_subtotal);
                this.totals.company_margin_total_percentage = this.getMarginPercentage(this.totals.company_sales_price_total, this.totals.company_purchase_price_total);
                this.totals.company_margin_total_inc_vat_percentage = this.getMarginPercentage(this.totals.company_sales_price_total_inc_vat, this.totals.company_purchase_price_total_inc_vat);
            }
        },

        formatMoney(amount) {
            return this.numberFormatter.format(amount).replace('\u00A0', '');
        },

        formatPercentage(value) {
            if (value === null || value === undefined) return '';
            return (Math.round(value * 100) / 100) + '%';
        },
    }"
>
    <table class="table-auto w-full text-sm totalsAll">
        <thead>
            <tr>
                <th></th>

                <th class="pb-2 text-left font-semibold">
                    Inkoop
                </th>

                @if ($showCompanySalesPrice)
                    <th class="pb-2 text-left font-semibold">
                        Verkoop
                    </th>

                    <th class="pb-2 text-left font-semibold" style="min-width: 120px;">
                        Opslag
                    </th>
                @endif
            </tr>
            <tr>
                <th colspan="{{ $columns }}" class="pb-2">
                    <hr style="border-width: 1px; border-color: #000;">
                </th>
            </tr>
        </thead>

        <tbody>
            <tr>
                <td class="py-1 min-w-[240px] pRight">
                    Subtotaal (excl. BTW):
                </td>

                <td class="py-1 min-w-[220px] text-left">
                   <span x-text="formatMoney(totals.company_purchase_price_subtotal)"></span>
                </td>

                @if ($showCompanySalesPrice)
                    <td class="py-1 min-w-[220px] text-left">
                        <span x-text="formatMoney(totals.company_sales_price_subtotal)"></span>
                    </td>

                    <td class="py-1 min-w-[100px]">
                        <span
                            :style="totals.company_margin_subtotal_percentage !== null && totals.company_margin_subtotal_percentage < INSUFFICIENT_MARGIN_THRESHOLD ? 'color: red !important; font-weight: bold' : ''"
                            x-text="formatMoney(totals.company_margin_subtotal) + ' (' + formatPercentage(totals.company_margin_subtotal_percentage) + ')'">
                        </span>
                    </td>
                @endif
            </tr>

            <tr>
                <td colspan="{{ $columns }}" class="py-2">
                    <hr>
                </td>
            </tr>

            <tr>
                <td class="py-1 align-middle pRight">
                    <label for="korting-bedrag" class="block">
                        Inkoopkorting (excl. BTW):
                    </label>
                </td>

                <td class="py-1">
                    <div class="flex gap-2">
                        <div class="fi-fo-field input-discount">
                            <div class="fi-fo-field-content-col">
                                <div class="fi-input-wrp fi-fo-text-input">
                                    <div class="fi-input-wrp-prefix fi-input-wrp-prefix-has-content">
                                        <span class="fi-input-wrp-label">€-</span>
                                    </div>

                                    <div class="fi-input-wrp-content-ctn">
                                        <input
                                            class="fi-input"
                                            id="cPurchasePriceDiscount"
                                            type="text"
                                            placeholder="0,00"
                                            autocomplete="false"
                                            x-mask:dynamic="$money($input, ',', '')"
                                            wire:model.live="companyPurchasePriceDiscount"
                                            @blur="formatAmountInput($event, 'companyPurchasePriceDiscount')"
                                            @input="computeTotals()"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="fi-fo-field input-discount-percentage">
                            <div class="fi-fo-field-content-col">
                                <div class="fi-input-wrp fi-fo-text-input">
                                    <div class="fi-input-wrp-content-ctn">
                                        <input
                                            class="fi-input"
                                            id="cPurchasePriceDiscountPercentage"
                                            type="text"
                                            autocomplete="false"
                                            x-model="companyPurchasePriceDiscountPercentage"
                                            @input="onPercentageInput($event, 'companyPurchasePriceDiscountPercentage'); setCompanyPurchaseDiscountFromPercentage()"
                                        />
                                    </div>

                                    <div class="fi-input-wrp-suffix fi-input-wrp-suffix-has-content">
                                        <span class="fi-input-wrp-label">%</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </td>

                @if ($showCompanySalesPrice)
                    <td class="py-1">
                        <div class="flex gap-2">
                            <div class="fi-fo-field input-discount">
                                <div class="fi-fo-field-content-col">
                                    <div class="fi-input-wrp fi-fo-text-input">
                                        <div class="fi-input-wrp-prefix fi-input-wrp-prefix-has-content fi-input-wrp-prefix-has-label">
                                            <span class="fi-input-wrp-label">€-</span>
                                        </div>

                                        <div class="fi-input-wrp-content-ctn">
                                            <input
                                                class="fi-input"
                                                id="cSalesPriceDiscount"
                                                type="text"
                                                placeholder="0,00"
                                                autocomplete="off"
                                                x-mask:dynamic="$money($input, ',', '')"
                                                wire:model.live="companySalesPriceDiscount"
                                                @blur="formatAmountInput($event, 'companySalesPriceDiscount')"
                                                @input="computeTotals()"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="fi-fo-field input-discount-percentage">
                                <div class="fi-fo-field-content-col">
                                    <div class="fi-input-wrp fi-fo-text-input">
                                        <div class="fi-input-wrp-content-ctn">
                                            <input
                                                class="fi-input"
                                                id="cSalesPriceDiscountPercentage"
                                                type="text"
                                                autocomplete="false"
                                                x-model="companySalesPriceDiscountPercentage"
                                                @input="onPercentageInput($event, 'companySalesPriceDiscountPercentage'); setCompanySalesDiscountFromPercentage()"
                                            />
                                        </div>

                                        <div class="fi-input-wrp-suffix fi-input-wrp-suffix-has-content">
                                            <span class="fi-input-wrp-label">%</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </td>

                    <td></td>
                @endif
            </tr>

            <tr>
                <td colspan="{{ $columns }}" class="py-2">
                    <hr>
                </td>
            </tr>

            <tr>
                <td class="py-1 font-semibold pRight">
                    Totaal (excl. BTW):
                </td>

                <td class="py-1 text-left font-semibold">
                    <span x-text="formatMoney(totals.company_purchase_price_total)"></span>
                </td>

                @if ($showCompanySalesPrice)
                    <td class="py-1 text-left font-semibold">
                        <span x-text="formatMoney(totals.company_sales_price_total)"></span>
                    </td>

                    <td class="py-1">
                        <span
                            :style="totals.company_margin_total_percentage !== null && totals.company_margin_total_percentage < INSUFFICIENT_MARGIN_THRESHOLD ? 'color: red !important; font-weight: bold' : ''"
                            x-text="formatMoney(totals.company_margin_total) + ' (' + formatPercentage(totals.company_margin_total_percentage) + ')'">
                        </span>
                    </td>
                @endif
            </tr>

            <tr>
                <td class="py-1 pRight">
                    <span x-text="getVatPercentage() + '% BTW'"></span>:
                </td>

                <td class="py-1 text-left">
                    <span x-text="formatMoney(totals.company_purchase_price_vat)"></span>
                </td>

                @if ($showCompanySalesPrice)
                    <td class="py-1 text-left">
                        <span x-text="formatMoney(totals.company_sales_price_vat)"></span>
                    </td>

                    <td class="py-1"></td>
                @endif
            </tr>

            <tr>
                <td colspan="{{ $columns }}" class="py-2">
                    <hr>
                </td>
            </tr>

            <tr>
                <td class="py-1 font-semibold pRight">
                    Totaal (incl. BTW):
                </td>

                <td class="py-1 text-left font-semibold">
                    <span x-text="formatMoney(totals.company_purchase_price_total_inc_vat)"></span>
                </td>

                @if ($showCompanySalesPrice)
                    <td class="py-1 text-left font-semibold">
                        <span x-text="formatMoney(totals.company_sales_price_total_inc_vat)"></span>
                    </td>

                    <td class="py-1"></td>
                @endif
            </tr>
        </tbody>
    </table>
</div>
