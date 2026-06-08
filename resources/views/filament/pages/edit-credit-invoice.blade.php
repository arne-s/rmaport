<style>
    header.fi-header div.fi-ac button {
        margin-top: -2rem;
        margin-bottom: 0;
    }

    .order-createSection > .fi-section > .fi-section-header {
        margin-bottom: 20px;
    }

    .creditInvoiceRepeater li.fi-fo-repeater-item {
        box-shadow: none;
    }

    .creditInvoiceRepeater table th:nth-child(1) { width: 40px !important; min-width: 40px !important; max-width: 40px !important; text-align: center; }
    .creditInvoiceRepeater table td:nth-child(1) { text-align: center; }
    .creditInvoiceRepeater table td:nth-child(1) .fi-fo-field { display: flex; justify-content: center; }
    .creditInvoiceRepeater input[type=checkbox] { position: relative !important; left: 12px !important; }
    .creditInvoiceRepeater table th:nth-child(2) { width: 50px !important; min-width: 50px !important; max-width: 70px !important; }
    .creditInvoiceRepeater table th:nth-child(4) { width: 160px !important; min-width: 160px !important; max-width: 160px !important; }
    .creditInvoiceRepeater table th:nth-child(5) { width: 160px !important; min-width: 160px !important; max-width: 160px !important; }

    .creditInvoiceRepeater span.taxOverview {
        display: block;
        line-height: 0;
    }

    .creditInvoiceRepeater tr td {
        opacity: .4;
    }

    .creditInvoiceRepeater tr:has(input[type=checkbox]:checked) td {
        opacity: 1;
    }

    .creditInvoiceRepeater tr td:has(input[type=checkbox]) {
        opacity: 1 !important;
    }

    .disabled\:opacity-70:disabled {
        opacity: unset;
    }
</style>
