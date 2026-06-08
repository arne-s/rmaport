@php
    $currentStatus = $record->getStatus();
    $timeline = $timeline ?? [];
@endphp
<aside id="card-status" class="card">
    <h3 class="card__title">Afroepstatus</h3>

    <div class="status">
        <div class="timeline">
            @php
                $statusToCome = true;
            @endphp
            @foreach ($timeline as $status)
                <span
                    @class([
                        'dot',
                        'dot--outline' => !$statusToCome,
                        'dot--current' => ($status['status'] ?? null) === $currentStatus,
                    ])
                ></span>
                @php
                    if (($status['status'] ?? null) === $currentStatus) {
                        $statusToCome = false;
                    }
                @endphp
            @endforeach
        </div>

        <div class="status__items">
            @foreach ($timeline as $status)
                <div
                    @class([
                        'st-item',
                        'st-item--current' => ($status['status'] ?? null) === $currentStatus,
                    ])
                >
                    <div class="st-col">
                        <div class="st-cap">{{ $status['category'] ?? '' }}</div>
                        <div class="st-name">{{ $status['label'] ?? '' }}</div>
                    </div>
                    <div class="st-date">
                        @if (!empty($status['date']))
                            {{ \Carbon\Carbon::parse($status['date'])->translatedFormat('j M Y') }}
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</aside>
