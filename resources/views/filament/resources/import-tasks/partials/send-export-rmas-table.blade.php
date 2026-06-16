@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\ImportRow> $rows */
@endphp

<div class="send-export-rmas-table max-h-[280px] overflow-y-auto overflow-x-auto rounded-lg border border-gray-200">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead>
            <tr>
                <th class="sticky top-0 z-10 border-b border-gray-200 bg-gray-50 px-3 py-2 text-left font-medium text-gray-700">Referentie</th>
                <th class="sticky top-0 z-10 border-b border-gray-200 bg-gray-50 px-3 py-2 text-left font-medium text-gray-700">EAN</th>
                <th class="sticky top-0 z-10 border-b border-gray-200 bg-gray-50 px-3 py-2 text-left font-medium text-gray-700">Opmerkingen</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 bg-white">
            @forelse ($rows as $row)
                <tr>
                    <td class="px-3 py-2 whitespace-nowrap text-gray-950">{{ $row->reference ?? '—' }}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ $row->ean_nr ?? '—' }}</td>
                    <td class="px-3 py-2 min-w-[180px]">
                        <input
                            type="text"
                            wire:model.defer="exportRowComments.{{ $row->getKey() }}"
                            class="send-export-rmas-comment-input block w-full rounded-lg bg-white px-2 py-1 text-sm text-gray-950 dark:bg-gray-900 dark:text-white"
                            aria-label="Opmerkingen voor {{ $row->reference ?? 'importregel' }}"
                            placeholder="Opmerking"
                        />
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="px-3 py-2 text-gray-500">Geen RMA&apos;s gevonden voor deze importtaak.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
