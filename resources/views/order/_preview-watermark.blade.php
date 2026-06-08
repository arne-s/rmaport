<div class="document-preview-watermark" aria-hidden="true">
    <span class="document-preview-watermark__label">PREVIEW</span>
</div>

<style>
    body:has(> .document-preview-watermark) {
        position: relative;
        min-height: 100vh;
    }

    .order-wrapper {
        position: relative;
    }

    .document-preview-watermark {
        position: absolute;
        inset: 0;
        z-index: 10000;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        min-height: 100%;
        pointer-events: none;
        overflow: hidden;
    }

    .document-preview-watermark__label {
        flex-shrink: 0;
        font-family: Verdana, sans-serif;
        font-weight: 700;
        font-size: clamp(2.8rem, 16.8vw, 9.8rem);
        line-height: 1;
        letter-spacing: 0.14em;
        color: rgba(33, 33, 33, 0.028);
        white-space: nowrap;
        transform: rotate(-32deg);
        user-select: none;
    }

    @media print {
        .document-preview-watermark {
            position: absolute;
            inset: 0;
            width: 100%;
            min-height: 100%;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
    }
</style>
