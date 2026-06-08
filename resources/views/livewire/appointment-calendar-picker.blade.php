@php
    $categoryVisibilityEvent = $categoryVisibilityEvent ?? 'appointment-picker-category-visibility-changed';
    $showOutlookNotice = $showOutlookNotice ?? false;
    $outlookSettingsUrl = $outlookSettingsUrl ?? '';
@endphp
<div
    class="appointment-calendar-picker"
    wire:key="acp-picker-{{ $weekStart }}-{{ $showWeekend ? '1' : '0' }}-{{ md5(implode(',', $categoryFilterKeys)) }}"
    x-data="{
        filterOpen: false,
        settingsOpen: false,
        goToWeek(callback) {
            this.$el.removeAttribute('wire:ignore');
            this.$el.querySelectorAll('[wire\\:ignore]').forEach((el) => el.removeAttribute('wire:ignore'));
            callback();
        },
    }"
>

    {{-- Week navigation --}}
    <div class="acp-week-nav mb-2">
        <div class="acp-week-nav__toolbar flex items-center justify-between gap-2">
            <button type="button"
                wire:loading.attr="disabled"
                @click="goToWeek(() => $wire.previousWeek())"
                class="acp-week-nav__nav-btn fi-btn fi-btn-size-sm fi-btn-color-gray fi-color-gray inline-grid shrink-0">
                <span class="fi-btn-label" wire:loading.remove wire:target="previousWeek,nextWeek">‹ Vorige week</span>
                <span class="fi-btn-label" wire:loading wire:target="previousWeek,nextWeek">Laden…</span>
            </button>

            <div class="acp-week-nav__week flex min-w-0 flex-1 items-center justify-center gap-2">
                <span class="text-sm font-semibold text-gray-700">{{ $weekLabel }}</span>

                <div class="acp-week-nav__actions flex shrink-0 items-center gap-0.5">
                    @if ($categoryFilterCount > 0)
                        <div class="acp-week-nav__filter relative">
                            <button
                                type="button"
                                @click="settingsOpen = false; filterOpen = !filterOpen"
                                class="acp-week-nav__filter-trigger relative flex items-center rounded-md p-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
                                title="Filter categorieën"
                                :aria-expanded="filterOpen"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                                </svg>
                                <span
                                    x-show="($wire.visibleCategoryKeys ?? []).length < {{ $categoryFilterCount }}"
                                    x-text="($wire.visibleCategoryKeys ?? []).length"
                                    class="acp-week-nav__filter-badge absolute -right-1 -top-1 flex h-3.5 w-3.5 items-center justify-center rounded-full text-[8px] font-bold leading-none text-white"
                                ></span>
                            </button>

                            <div
                                x-show="filterOpen"
                                x-cloak
                                @click.outside="filterOpen = false"
                                x-transition:enter="transition ease-out duration-100"
                                x-transition:enter-start="opacity-0 scale-95"
                                x-transition:enter-end="opacity-100 scale-100"
                                x-transition:leave="transition ease-in duration-75"
                                x-transition:leave-start="opacity-100 scale-100"
                                x-transition:leave-end="opacity-0 scale-95"
                                class="acp-category-filter-panel acp-category-filter-panel--dropdown"
                            >
                                @foreach ($categoryGroups as $group)
                                    <div class="acp-category-filter-group" wire:key="acp-filter-group-{{ $group['token_id'] }}">
                                        <div class="acp-category-filter-group__heading border-b border-gray-100 bg-gray-50 px-3 py-1.5 text-xs font-semibold text-gray-600">
                                            {{ $group['label'] }}
                                        </div>
                                        @foreach ($group['categories'] as $category)
                                            <label
                                                wire:key="acp-filter-cat-{{ $group['token_id'] }}-{{ $category['key'] }}"
                                                class="acp-category-filter-option flex cursor-pointer select-none items-center gap-2 py-1.5 pr-3 text-sm"
                                                style="padding-left:10px;background-color:{{ $category['color'] }};color:#000;text-shadow:0 1px 2px rgba(255,255,255,0.6);"
                                            >
                                                <input
                                                    type="checkbox"
                                                    :checked="($wire.visibleCategoryKeys ?? []).includes(@js($category['key']))"
                                                    @change="
                                                        let keys = [...($wire.visibleCategoryKeys ?? [])];
                                                        const key = @js($category['key']);
                                                        const idx = keys.indexOf(key);
                                                        if (idx >= 0) {
                                                            keys.splice(idx, 1);
                                                        } else {
                                                            keys.push(key);
                                                        }
                                                        Livewire.dispatch(@js($categoryVisibilityEvent), { visibleCategoryKeys: keys });
                                                    "
                                                    class="h-3.5 w-3.5 shrink-0 cursor-pointer rounded border-gray-300"
                                                >
                                                <span class="overflow-hidden text-ellipsis whitespace-nowrap">{{ $category['name'] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="acp-week-nav__settings relative">
                        <button
                            type="button"
                            @click="filterOpen = false; settingsOpen = !settingsOpen"
                            class="acp-week-nav__settings-trigger flex items-center rounded-md p-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
                            title="Agenda-instellingen"
                            :aria-expanded="settingsOpen"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </button>

                        <div
                            x-show="settingsOpen"
                            x-cloak
                            @click.outside="settingsOpen = false"
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="opacity-100 scale-100"
                            x-transition:leave-end="opacity-0 scale-95"
                            class="acp-settings-panel acp-category-filter-panel--dropdown"
                        >
                            <label class="acp-settings-option flex cursor-pointer select-none items-center gap-2 px-3 py-2 text-sm text-gray-700">
                                <input
                                    type="checkbox"
                                    wire:model.live="showWeekend"
                                    class="h-3.5 w-3.5 shrink-0 cursor-pointer rounded border-gray-300"
                                >
                                <span>Toon weekend</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <button type="button"
                wire:loading.attr="disabled"
                @click="goToWeek(() => $wire.nextWeek())"
                class="acp-week-nav__nav-btn fi-btn fi-btn-size-sm fi-btn-color-gray fi-color-gray inline-grid shrink-0">
                <span class="fi-btn-label" wire:loading.remove wire:target="previousWeek,nextWeek">Volgende week ›</span>
                <span class="fi-btn-label" wire:loading wire:target="previousWeek,nextWeek">Laden…</span>
            </button>
        </div>
    </div>

    @if ($showOutlookNotice)
        <div class="mb-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
            <p>Er is geen Outlook-agenda gekoppeld voor deze weergave. Onderstaand overzicht komt uit het systeem.</p>
            <p class="mt-1">
                <a href="{{ $outlookSettingsUrl }}" class="text-primary-600 underline">Outlook instellen</a>
            </p>
        </div>
    @endif

    @if (! $calendarCanLoad)
        <div class="flex items-center justify-center rounded-lg border border-gray-200 bg-gray-50 py-10">
            <p class="text-sm text-gray-400">
                Selecteer eerst een adviseur om de kalender te laden.
            </p>
        </div>
    @else
        @php
            $totalPx  = $gridTotalPx;
            $halfPx   = $pxPerHour / 2;
            $startH   = $gridHours[0];
            $endH     = end($gridHours);
            $numSegs  = count($gridHours) - 1;
            $acpGridConfig = [
                'readOnly' => $readOnly,
                'pxPerHour' => $pxPerHour,
                'totalPx' => $totalPx,
                'gridStartMinutes' => $gridStartMinutes,
                'scrollInitialPx' => $scrollInitialPx,
                'todayDate' => $todayDate,
                'nowPx' => $nowPx,
                'dayCount' => count($days),
                'selection' => $committedSelection,
                'weekStart' => $weekStart ?? '',
            ];
        @endphp

        <div
            class="acp-grid-container overflow-hidden rounded-lg border border-gray-200"
            wire:key="acp-grid-{{ $weekStart }}-{{ $showWeekend ? '1' : '0' }}"
        >

            {{-- Sticky day headers --}}
            <div class="flex border-b border-gray-200" style="min-width:768px">
                <div class="w-10 shrink-0 border-r border-gray-200 bg-gray-50" style="height:22px"></div>
                @foreach ($days as $dayIndex => $day)
                    <div
                        class="flex-1 flex items-center justify-center border-r border-gray-200 last:border-r-0 text-xs font-semibold {{ $day['isToday'] ? 'bg-primary-50 text-primary-700' : 'bg-gray-50 text-gray-600' }}"
                        style="height:22px">
                        {{ $day['label'] }}
                    </div>
                @endforeach
            </div>

            @if ($allDayHeightPx > 0)
                {{-- All-day events (fixed, like Outlook) --}}
                <div class="acp-allday-section flex border-b border-gray-200 bg-white" style="min-width:768px">
                    <div class="w-10 shrink-0 border-r border-gray-200 bg-gray-50" style="height:{{ $allDayHeightPx }}px"></div>
                    <div class="relative flex flex-1 min-w-0" style="height:{{ $allDayHeightPx }}px">
                        @foreach ($days as $day)
                            <div
                                class="relative flex-1 min-w-0 border-r border-gray-200 last:border-r-0 {{ $day['isToday'] ? 'bg-primary-50/40' : 'bg-gray-50/80' }}"
                            >
                                @if ($day['isPast'])
                                    <div class="absolute inset-0 pointer-events-none" style="background:rgba(0,0,0,0.06)"></div>
                                @endif
                            </div>
                        @endforeach

                        <div class="pointer-events-none absolute inset-0 z-10">
                            @foreach ($allDayPlaced as $allDay)
                                <div
                                    class="acp-allday-event absolute overflow-hidden rounded px-1 text-[9px] font-semibold leading-tight text-gray-800"
                                    style="top:{{ $allDay['rowIndex'] * 18 + 1 }}px;left:calc((100%) * {{ $allDay['startCol'] }} / {{ $dayCount }});width:calc((100%) * {{ $allDay['colSpan'] }} / {{ $dayCount }} - 2px);height:{{ 16 }}px;margin-left:1px;background-color:{{ $allDay['color'] }};border:1px solid rgba(0,0,0,0.12)"
                                    title="{{ $allDay['title'] }}"
                                >
                                    <span class="block truncate">{!! \App\Support\MainRequestNumberLinkifier::linkify((string) ($allDay['title'] ?? '')) !!}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            {{-- Scrollable grid body --}}
            <div
                class="acp-grid-body"
                style="overflow-y:auto"
                data-scroll-initial-px="{{ $scrollInitialPx }}"
                x-data="appointmentCalendarGrid(@js($acpGridConfig))"
                x-init="
                    const px = parseInt($el.dataset.scrollInitialPx || '0', 10);
                    if (Number.isFinite(px) && px > 0) {
                        const apply = () => { $el.scrollTop = px };
                        apply();
                        requestAnimationFrame(() => requestAnimationFrame(apply));
                        setTimeout(apply, 120);
                        setTimeout(apply, 420);
                    }
                "
                x-on:pointerdown="onGridPointerDown($event)"
                x-on:destroy="destroy()"
            >
                <div class="relative" style="min-width:768px">
                    <div class="flex acp-day-columns" style="min-width:768px" x-ref="dayColumns">

                    {{-- Time label column --}}
                    <div class="relative w-10 shrink-0 border-r border-gray-200 bg-gray-50" style="height:{{ $totalPx }}px">
                        @foreach ($gridHours as $h)
                            @php
                                $hourLabelTopPx = $h === $startH
                                    ? ($h - $startH) * $pxPerHour + 6
                                    : ($h - $startH) * $pxPerHour - 4;
                            @endphp
                            <div
                                class="absolute right-1 text-[9px] leading-none text-gray-400"
                                style="top:{{ $hourLabelTopPx }}px"
                            >{{ sprintf('%02d', $h) }}</div>
                        @endforeach
                    </div>

                    {{-- Day columns --}}
                    @foreach ($days as $dayIndex => $day)
                        @php
                            $isPastDay   = $day['isPast'];
                            $isToday     = $day['isToday'];
                            $colCursor   = ($readOnly || $isPastDay) ? 'default' : 'crosshair';
                        @endphp
                        <div
                            class="relative flex-1 min-w-0 border-r border-gray-200 last:border-r-0 select-none touch-none"
                            style="height:{{ $totalPx }}px;cursor:{{ $colCursor }}"
                            data-date="{{ $day['date'] }}"
                            data-col="{{ $dayIndex }}"
                            data-is-past="{{ $isPastDay ? '1' : '0' }}"
                        >
                            {{-- Background: off-hours #fafafa; weekdays 08:00–17:00 #ffffff --}}
                            <div class="absolute inset-0 z-0 pointer-events-none" style="background:#fafafa"></div>
                            @if (! $day['isWeekend'])
                                <div
                                    class="absolute left-0 right-0 z-0 pointer-events-none"
                                    style="top:{{ $workStartPx }}px;height:{{ $workEndPx - $workStartPx }}px;background:#ffffff"
                                ></div>
                            @endif

                            {{-- Working hours boundaries at 08:00 and 17:00 --}}
                            <div class="absolute left-0 right-0 z-[5] pointer-events-none" style="top:{{ $workStartPx }}px;height:1px;background:#949494"></div>
                            <div class="absolute left-0 right-0 z-[5] pointer-events-none" style="top:{{ $workEndPx }}px;height:1px;background:#949494"></div>

                            {{-- Grid lines: full hour and half-hour --}}
                            @foreach ($gridHours as $i => $h)
                                <div class="absolute w-full pointer-events-none border-t border-gray-200"
                                    style="top:{{ $i * $pxPerHour }}px"></div>
                                @if (! $loop->last)
                                    <div class="absolute w-full pointer-events-none border-t border-dashed border-gray-100"
                                        style="top:{{ $i * $pxPerHour + $halfPx }}px"></div>
                                @endif
                            @endforeach

                            {{-- Past overlay: full column for past days, partial for today --}}
                            @if ($isPastDay)
                                <div class="absolute inset-0 z-10 pointer-events-none" style="background:rgba(0,0,0,0.06)"></div>
                            @elseif ($isToday)
                                <div class="absolute left-0 right-0 top-0 z-10 pointer-events-none" style="height:{{ $nowPx }}px;background:rgba(0,0,0,0.06)"></div>
                                {{-- Current-time indicator --}}
                                <div class="absolute left-0 right-0 z-10 pointer-events-none" style="top:{{ $nowPx }}px;height:2px;background:#ef4444;opacity:0.7"></div>
                            @endif

                            {{-- Existing appointments --}}
                            @foreach ($appointmentsByDay[$day['date']] ?? [] as $appt)
                                @php
                                    $leftPct = $appt['leftPct'] ?? 0;
                                    $widthPct = $appt['widthPct'] ?? 100;
                                @endphp
                                <div
                                    class="acp-calendar-appointment absolute z-20 overflow-hidden rounded px-1 py-0.5 text-[9px] leading-tight text-gray-800 pointer-events-none"
                                    style="top:{{ $appt['topPx'] }}px;height:{{ $appt['heightPx'] }}px;left:calc(0.125rem + (100% - 0.25rem) * {{ $leftPct }} / 100);width:calc((100% - 0.25rem) * {{ $widthPct }} / 100);background-color:{{ $appt['color'] }};border:1px solid rgba(0,0,0,0.12)"
                                >
                                    <div class="acp-calendar-appointment__title font-bold">
                                        <span>{{ $appt['time'] ?? '' }}</span>@if(filled($appt['title'] ?? null))<span> {!! \App\Support\MainRequestNumberLinkifier::linkify((string) $appt['title']) !!}</span>@endif
                                    </div>
                                    @if(filled($appt['description'] ?? null))
                                        <div class="acp-calendar-appointment__description font-normal">{!! \App\Support\MainRequestNumberLinkifier::linkify((string) $appt['description']) !!}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach

                    </div>

                    <div
                        x-show="committedSelectionVisible()"
                        x-cloak
                        wire:ignore
                        class="pointer-events-none absolute inset-x-0 top-0 z-30"
                        style="height:{{ $totalPx }}px"
                    >
                        <div
                            class="acp-selection-handle absolute touch-none rounded border-2 border-primary-500 bg-primary-200/60 pointer-events-auto"
                            :style="committedSelectionStyle()"
                            :data-sel-top="committedSelection?.startPx ?? 0"
                            :data-sel-height="committedSelection?.heightPx ?? 0"
                            title="Sleep om de periode te verplaatsen (ook naar andere dagen)"
                        ></div>
                    </div>

                    <div
                        x-show="showDragOverlay()"
                        x-cloak
                        class="pointer-events-none absolute inset-x-0 top-0 z-40"
                        style="height:{{ $totalPx }}px"
                    >
                        <div
                            class="rounded border-2 border-primary-400 bg-primary-300/40 touch-none pointer-events-none"
                            :class="{ 'pointer-events-auto': moveMode }"
                            :style="overlayStyle"
                        ></div>
                    </div>

                </div>
            </div>
        </div>

    @endif

</div>
