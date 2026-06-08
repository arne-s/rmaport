@php
    $checklistFilledCount = collect($rows)
        ->filter(static fn (array $r): bool => trim((string) ($r['checked_at'] ?? ($r['date'] ?? ''))) !== '')
        ->count();
    $checklistTotalCount = count($rows);
@endphp

<div id="checklist-table" wire:key="checklist-table-{{ $ownerId }}" class="checklist-table">
    <div class="overflow-x-auto">
        <table class="checklist-table__grid min-w-full border-collapse">
            <colgroup>
                <col style="width: 30px">
                <col>
                <col>
            </colgroup>
            <tbody>
                @foreach ($rows as $index => $row)
                    @php
                        $isFinalCheck = mb_strtolower(trim((string) ($row['description'] ?? ''))) === 'eindcontrole';
                        $checkedAtRaw = trim((string) ($row['checked_at'] ?? ($row['date'] ?? '')));
                        $checkedByName = trim((string) ($row['checked_by_name'] ?? ''));
                        $checklistRowHasDate = $checkedAtRaw !== '';
                        $checkedAtLabel = $checklistRowHasDate
                            ? \Carbon\Carbon::parse($checkedAtRaw)->format('d-m-Y')
                            : '';
                    @endphp
                    <tr
                        wire:key="checklist-row-{{ $ownerId }}-{{ $index }}"
                        @class([
                            'checklist-table__row--last' => $index === count($rows) - 1,
                            'checklist-table__row--filled' => $checklistRowHasDate,
                        ])
                    >
                        <td class="checklist-table__td checklist-table__td--checkbox p-0 @if($index === count($rows) - 1) checklist-table__td--last @endif">
                            <div
                                class="checklist-table__input-wrp checklist-table__input-wrp--checkbox"
                                @if (! $isFinalCheck && ! $checklistRowHasDate)
                                    wire:click="toggleRow({{ $index }}, true)"
                                    role="button"
                                    tabindex="0"
                                @endif
                            >
                                @if ($checklistRowHasDate)
                                    <svg class="checklist-table__checkmark" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M6 12.5L10 16.5L18 8.5" stroke="#3366cc" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                @endif
                            </div>
                        </td>
                        <td class="checklist-table__td p-0 @if($index === count($rows) - 1) checklist-table__td--last @endif">
                            <div class="checklist-table__input-wrp">
                                <span class="checklist-table__input min-w-0">{{ $row['description'] }}</span>
                            </div>
                        </td>
                        <td class="checklist-table__td p-0 @if($index === count($rows) - 1) checklist-table__td--last @endif">
                            <div class="checklist-table__input-wrp">
                                <span class="checklist-table__input checklist-table__input--date min-w-0">
                                    @if ($checklistRowHasDate)
                                        {{ $checkedAtLabel }}{{ $checkedByName !== '' ? ' (' . $checkedByName . ')' : '' }}
                                    @else
                                        -
                                    @endif
                                </span>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
