<div>
    @php
        $type = isset($contents['type']) ? $contents['type'] : null;
    @endphp
    @if ($type === 'pdf')
        <div>
            {!! $contents['doc'] !!}
        </div>
    @else
        <div style="border-radius:5px; max-height: 75vh; overflow:scroll">
        @php
            $debug = $contents ?? '';
            @dump($debug);
        @endphp
        </div>
    @endif
    <style>
        .sf-dump span[style*="#A0A0A0"] {
            display: none !important;
        }
    </style>
</div>
