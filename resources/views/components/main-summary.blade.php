@props([
    'customerName' => '-',
    'unitName' => '-',
    'advisorName' => '-',
    'rows' => [],
])

<div class="main-summary w-full max-w-full">
    <ul class="kv main-summary__meta">
        <li>
            <span class="k">Klantnaam:</span>
            <span class="v">{{ $customerName ?: '-' }}</span>
        </li>
        <li>
            <span class="k">Unit:</span>
            <span class="v">{{ $unitName ?: '-' }}</span>
        </li>
        <li>
            <span class="k">Adviseur:</span>
            <span class="v">{{ $advisorName ?: '-' }}</span>
        </li>
    </ul>

    <div class="main-summary__divider" aria-hidden="true"></div>

    <div @class(['main-summary__content' => isset($aside)])>
        <div class="main-summary__table-col overflow-x-auto">
        <table class="main-summary__table min-w-full border-collapse">
            <thead>
                <tr>
                    <th>Activiteit</th>
                    <th>Paraaf</th>
                    <th>Datum</th>
                    <th>Nummer</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @if (($row['showActivity'] ?? true) === true)
                            <td
                                class="main-summary__activity-cell"
                                style="vertical-align: top;"
                                @if (($row['activityRowspan'] ?? 1) > 1) rowspan="{{ $row['activityRowspan'] }}" @endif
                            >
                                {{ $row['activity'] ?? '-' }}
                            </td>
                        @endif
                        <td class="main-summary__initials-cell" style="font-weight: 600; color: #333;">{{ $row['initials'] ?? '' }}</td>
                        <td class="main-summary__date-cell" style="font-weight: 400; color: #8d8d8d;" title="{{ $row['dateTooltip'] ?? '' }}">{{ $row['date'] ?? '-' }}</td>
                        <td class="main-summary__number-cell" style="font-weight: 400; color: #8d8d8d;">
                            @if (filled($row['modalPayload'] ?? null) && ($row['number'] ?? '-') !== '-')
                                <button
                                    type="button"
                                    class="main-summary__number-link"
                                    x-data
                                    x-on:click="$dispatch('open-modal', {{ json_encode($row['modalPayload']) }})"
                                >
                                    {{ $row['number'] ?? '-' }}
                                </button>
                            @elseif (filled($row['numberUrl'] ?? null))
                                <a href="{{ $row['numberUrl'] }}" class="main-summary__number-link main-request-number-link hover:underline">{{ $row['number'] ?? '-' }}</a>
                            @else
                                {!! nl2br(e($row['number'] ?? '-')) !!}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>

        @isset($aside)
            <div class="main-summary__aside">
                {{ $aside }}
            </div>
        @endisset
    </div>

</div>
