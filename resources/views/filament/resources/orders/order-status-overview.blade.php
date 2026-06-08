<aside id="card-status">
    @php
        use App\Models\Order\Main;

        /** @var Main|null $order */
        /** @var array $timeline */

        $blocks = $timeline ?? [];
        $statusToCome = true;
    @endphp
    <div class="status status--grouped">
        @foreach ($blocks as $block)
            @php
                $blockIsCurrent = collect($block['items'])->contains('isCurrent', true);
                if ($blockIsCurrent) {
                    $statusToCome = false;
                }
                $mainNumber = $block['mainNumber'] ?? 0;
            @endphp
            <div class="status-row">
                <div class="timeline__cell">
                    <span
                        @class([
                            'dot',
                            'dot--outline' => !$statusToCome && !$blockIsCurrent,
                            'dot--current' => $blockIsCurrent,
                        ])
                    ></span>
                </div>
                <div class="status-block">
                    <div class="status-block__title">{{ $mainNumber }}. {{ $block['mainLabel'] }}</div>
                    <div class="status-block__items">
                        @foreach ($block['items'] as $item)
                            <div
                                @class([
                                    'st-item',
                                    'st-item--current' => $item['isCurrent'],
                                ])
                            >
                                <span class="st-name">
                                    {{ $item['label'] }}
                                    @if (!empty($item['date']))
                                        <span class="st-date">{{ $item['date']->translatedFormat('j M Y') }}{{ !empty($item['changedByUserName']) ? ' (' . e($item['changedByUserName']) . ')' : '' }}</span>
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</aside>
