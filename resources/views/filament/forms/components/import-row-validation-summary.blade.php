@php
    /** @var \App\Services\Import\ImportRowValidationResult $result */
@endphp

<div class="import-row-validation-summary space-y-3">
    <p class="text-sm font-medium text-gray-950">
        {{ $result->summaryLabel() }}
    </p>

    @if ($result->overviewIssues() !== [])
        <div class="max-h-[280px] overflow-y-auto overflow-x-auto rounded-lg border border-gray-200">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr>
                        <th class="sticky top-0 z-10 border-b border-gray-200 bg-gray-50 px-3 py-2 text-left font-medium text-gray-700">Rij</th>
                        <th class="sticky top-0 z-10 border-b border-gray-200 bg-gray-50 px-3 py-2 text-left font-medium text-gray-700">Referentie</th>
                        <th class="sticky top-0 z-10 border-b border-gray-200 bg-gray-50 px-3 py-2 text-left font-medium text-gray-700">EAN</th>
                        <th class="sticky top-0 z-10 border-b border-gray-200 bg-gray-50 px-3 py-2 text-left font-medium text-gray-700">Reden</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @foreach ($result->overviewIssues() as $issue)
                        <tr>
                            <td class="px-3 py-2 whitespace-nowrap text-gray-950">{{ $issue->rowNumber }}</td>
                            <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ $issue->reference ?? '—' }}</td>
                            <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ $issue->eanNr ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ $issue->overviewLabel() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
