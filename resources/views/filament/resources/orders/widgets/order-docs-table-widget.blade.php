@php
    use App\Filament\Resources\OrderResource\Widgets\OrderDocsTableWidget;
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;
    use function Filament\Support\generate_icon_html;

    /** @var OrderDocsTableWidget $this */

    $acceptAttribute = config('financial-docs.accept_attribute', '');
@endphp
<x-filament-widgets::widget class="fi-wi-table financial-docs-widget financial-docs-widget-outer">
    <div class="fi-docs-header">
        <div class="fi-docs-header-left">
            <h3 class="card__title">Financiële documenten</h3>
            <div style="margin-bottom: -10px; margin-top: -7px; font-size: 12px">
                Offertes, orders en facturen. Upload de PDF met Inkoopordernummer van Dealer.
            </div>
        </div>
        <div class="fi-docs-header-actions">
            @if ($this->canShowCreateQuoteFromRequestButton())
                <button
                    type="button"
                    wire:click="redirectToCreateQuoteFromMain"
                    wire:loading.attr="disabled"
                    class="fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950 fi-btn fi-size-md fi-ac-btn-action"
                >
                    <span
                        wire:loading.remove
                        wire:target="redirectToCreateQuoteFromMain"
                        class="new-appointment-btn-label fi-ac-btn-action-label"
                    >
                        <span class="new-appointment-btn-label__icon">
                            {{ generate_icon_html(Heroicon::PlusCircle, size: IconSize::Medium) }}
                        </span>
                        <span class="new-appointment-btn-label__text">Offerte</span>
                    </span>
                </button>
            @endif
            @if ($this->canShowCreateOrderFromRequestButton())
                <button
                    type="button"
                    wire:click="redirectToCreateOrderFromMain"
                    wire:loading.attr="disabled"
                    class="fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950 fi-btn fi-size-md fi-ac-btn-action"
                >
                    <span
                        wire:loading.remove
                        wire:target="redirectToCreateOrderFromMain"
                        class="new-appointment-btn-label fi-ac-btn-action-label"
                    >
                        <span class="new-appointment-btn-label__icon">
                            {{ generate_icon_html(Heroicon::PlusCircle, size: IconSize::Medium) }}
                        </span>
                        <span class="new-appointment-btn-label__text">Order</span>
                    </span>
                </button>
            @endif
            @if ($this->canGenerateInvoice())
                <button
                    type="button"
                    x-on:click="$wire.mountAction('generate_invoice')"
                    wire:loading.attr="disabled"
                    class="fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950 fi-btn fi-size-md fi-ac-btn-action"
                >
                    <span
                        wire:loading.remove
                        wire:target="mountAction('generate_invoice')"
                        class="new-appointment-btn-label fi-ac-btn-action-label"
                    >
                        <span class="new-appointment-btn-label__icon">
                            {{ generate_icon_html(Heroicon::PlusCircle, size: IconSize::Medium) }}
                        </span>
                        <span class="new-appointment-btn-label__text">Slotfactuur</span>
                    </span>
                </button>
            @endif
            @if ($this->canShowCreateInvoiceFromMainButton())
                <button
                    type="button"
                    x-on:click="$wire.mountAction('create_invoice')"
                    wire:loading.attr="disabled"
                    class="fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950 fi-btn fi-size-md fi-ac-btn-action"
                >
                    <span
                        wire:loading.remove
                        wire:target="mountAction('create_invoice')"
                        class="new-appointment-btn-label fi-ac-btn-action-label"
                    >
                        <span class="new-appointment-btn-label__icon">
                            {{ generate_icon_html(Heroicon::PlusCircle, size: IconSize::Medium) }}
                        </span>
                        <span class="new-appointment-btn-label__text">Factuur</span>
                    </span>
                </button>
            @endif
        </div>
    </div>

    {{ $this->table }}

    <div
        class="financial-docs-upload-zone"
        wire:loading.class="opacity-60 pointer-events-none"
        wire:target="documentFiles"
        :class="{ 'border-primary-500': isDragging }"
    >
        <label class="flex items-center justify-center gap-1 cursor-pointer w-full h-full px-3 py-3">
            <input
                type="file"
                wire:model="documentFiles"
                multiple
                class="sr-only financial-docs-upload"
                accept="{{ $acceptAttribute }}"
            />
            <span class="text-sm text-gray-600 dark:text-gray-400">
                Drag &amp; Drop je bestanden of
            </span>
            <span class="text-sm underline" style="color: var(--primary-600);">
                Bladeren
            </span>
        </label>
    </div>

    <div wire:loading wire:target="documentFiles" class="mt-2 text-sm text-gray-500">
        Bestanden uploaden...
    </div>

    @push('styles')
        <style>
            .financial-docs-widget .fi-ta-actions {
                justify-content: flex-start !important;
            }
            .financial-docs-widget-outer .fi-docs-header {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 12px;
                margin-bottom: 12px;
            }
            .financial-docs-widget-outer .fi-docs-header-actions {
                display: flex;
                gap: 8px;
                flex-shrink: 0;
                align-items: center;
            }
        </style>
    @endpush
</x-filament-widgets::widget>
