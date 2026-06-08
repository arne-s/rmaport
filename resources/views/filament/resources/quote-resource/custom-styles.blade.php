<style>
    td.filament-table-repeater-column {
        padding: 0 5px 5px 5px;
    }

    div.it-table-repeater .fi-fo-field-label:not(label[for*=has_credit]) {
        display: none;
    }

    div.it-table-repeater tr td:has([type=hidden]) {
        display: none;
    }

    div.it-table-repeater td {
        vertical-align: top;
    }

    div.it-table-repeater td textarea {
        font-size: 11px;
        min-height: 120px;
    }

    div.it-table-repeater td textarea.autoHeight {
        font-size: 11px;
        min-height: auto;
    }

    div.it-table-repeater td input[type=checkbox] {
        width: 23px !important;
        height: 23px !important;
        border-radius: .5rem;
        border: 1px solid #adadad;
        outline: 2px solid transparent;
        outline-offset: 2px;
    }

    div.it-table-repeater td input[type=checkbox]:checked,
    div.it-table-repeater td input[type=checkbox]:checked:hover {
        border-radius: .5rem !important;
        border: 1px solid #adadad !important;
        outline: 2px solid transparent !important;
        outline-offset: 2px !important;
    }

    .fi-resource-create-record-page .fi-sc-actions {
        display: none;
    }

    .input-value {
        min-width: 300px;
        font-size: 12px;
        max-width: 300px;
    }

    .input-reference {
        max-width: 350px;
    }

    .input-margin {
        width: 160px;
        padding-left: 10px;
    }

    .input-margin input {
        margin-left: -15px;
    }

    .as-text input {
        background-color: transparent;
        border: none;
        width: 140px;
    }

    .input-sell .fi-input-wrp-suffix {
        position: absolute;
        margin-top: 40px;
        max-width: 110px;
        white-space: unset;
        border: 0;
    }

    /* Korting % suffix: same styling as € prefix (fi-input-wrp-prefix) */
    .input-korting-pct .fi-input-wrp-suffix {
        position: static;
        margin-top: 0;
        max-width: none;
        border-left: 1px solid #e4e4e7;
        padding: 0;
        background: #fbfbfb;
    }
    .input-korting-pct .fi-input-wrp-suffix .fi-input-wrp-label,
    .input-korting-pct .fi-input-wrp-suffix span {
        display: inline-block;
        padding: 0 10px;
        font-size: inherit;
        line-height: 1;
        color: inherit;
    }

    .input-total .fi-input-wrp-suffix {
        position: absolute;
        margin-top: 40px;
        max-width: 110px;
        white-space: unset;
        border: 0;
    }

    .input-sell + .fi-sc.fi-inline,
    .input-total + .fi-sc.fi-inline {
        span {
            color: #9ca3af;
            font-size: 11px;
            margin-bottom: 5px;
        }
    }

    .input-total {
        box-shadow: none;
    }

    .input-total .fi-input-wrp-prefix {
        border: 0;
        padding: 0;
    }

    .input-total input {
        padding: 6px 5px;
        -webkit-appearance: none;
        appearance: none;
        background: none !important;
    }

    [x-cloak] {
        display: none !important;
    }

    .configure-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 40px;
        margin: 10px 5px;
        padding: 0 16px;
        background-color: #e5e7eb;
        color: #111827;
        font-size: 14px;
        font-weight: 500;
        border-radius: 6px;
        text-decoration: none;
        transition: background-color 0.2s ease-in-out;
        border: 1px solid #d1d5db;
    }

    .configure-button:hover {
        background-color: #d1d5db;
    }

    .configure-button:active {
        background-color: #9ca3af;
    }

    .configure-button.disabled {
        background-color: #f3f4f6;
        color: #9ca3af;
        cursor: not-allowed;
    }

    .fi-icon-btn.disabled {
        background-color: #f3f4f6;
        color: #9ca3af;
        cursor: not-allowed;
    }

    .filament-main-footer {
        display: none !important;
    }

    .fi-modal-content .order-wrapper {
        padding: 10px;
    }
</style>

<script>
    window.addEventListener('scrollToFirstError', event => {
        setTimeout(() => {
            const firstError = document.querySelector('.fi-forms-fieldset-error-message, .text-danger-600');
            if (firstError) {
                firstError.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });

                const firstInvalidInput = document.querySelector('.invalid input');
                if (firstInvalidInput) {
                    firstInvalidInput.focus();
                }
            }
        }, 100);
    })
</script>

<livewire:quote-editor-modal/>
