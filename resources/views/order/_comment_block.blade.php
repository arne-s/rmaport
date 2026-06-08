@php
    /** @var string $content */
@endphp

<div class="comments">
    <div class="inner">
        {!! $content !!}
    </div>
</div>

<style>
    div.comments {
        margin-top: 20px;
        padding-top: 15px;
        page-break-before: avoid;
        page-break-inside: avoid;
        page-break-after: avoid;
        padding-bottom: 0;
    }

    div.comments div.inner {
        page-break-inside: avoid;
        page-break-after: avoid;
        background-color: #F7F7F7;
        border: 2px solid #D0D0D0;
        line-height: 22px;
        border-radius: 2px;
        padding: 15px 30px;
        font-size: 14px;
    }

    div.comments strong {
        display: inline-block;
        margin-bottom: 10px;
    }

    div.comments span {
        font-weight: bold;
    }

    div.comments a {
        color: #0298c7;
    }
</style>
