<aside id="card-rma-status">
    @php
        /** @var array<int, array{status: \App\Enums\RmaStatus, label: string, date: mixed, changedByUserName: ?string, isCurrent: bool}> $timeline */
        $items = $timeline ?? [];
        $statusToCome = true;
    @endphp
    <div class="status status--grouped">
        @foreach ($items as $item)
            @php
                if ($item['isCurrent']) {
                    $statusToCome = false;
                }
            @endphp
            <div class="status-row">
                <div class="timeline__cell">
                    <span
                        @class([
                            'dot',
                            'dot--outline' => ! $statusToCome && ! $item['isCurrent'],
                            'dot--current' => $item['isCurrent'],
                        ])
                    ></span>
                </div>
                <div class="status-block">
                    <div class="status-block__items">
                        <div
                            @class([
                                'st-item',
                                'st-item--current' => $item['isCurrent'],
                            ])
                        >
                            <span class="st-name">
                                {{ $item['label'] }}
                                @if (! empty($item['date']))
                                    <span class="st-date">{{ $item['date']->translatedFormat('j M Y') }}{{ ! empty($item['changedByUserName']) ? ' (' . e($item['changedByUserName']) . ')' : '' }}</span>
                                @endif
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</aside>
