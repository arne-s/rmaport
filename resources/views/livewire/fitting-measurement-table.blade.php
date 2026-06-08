<div
    id="fitting-measurement-table"
    wire:key="fitting-measurement-table-{{ $ownerId }}"
    class="fitting-measurement-table"
    data-measurement-row-count="{{ count($rows) }}"
    x-data="{
        measurementColumns: ['code', 'current', 'new'],
        focusMeasurementInput(row, col) {
            const input = this.$root.querySelector(
                `[data-measurement-row='${row}'][data-measurement-col='${col}']`
            );
            if (! input || input.readOnly) {
                return false;
            }
            input.focus();
            if (typeof input.select === 'function') {
                input.select();
            }
            return true;
        },
        navigateMeasurementRow(event, direction) {
            const input = event.target;
            if (! input?.dataset?.measurementRow) {
                return;
            }
            const col = input.dataset.measurementCol;
            const rowCount = parseInt(this.$root.dataset.measurementRowCount ?? '0', 10);
            let row = parseInt(input.dataset.measurementRow, 10) + direction;
            while (row >= 0 && row < rowCount) {
                if (this.focusMeasurementInput(row, col)) {
                    event.preventDefault();
                    return;
                }
                if (col === 'code' && this.focusMeasurementInput(row, 'current')) {
                    event.preventDefault();
                    return;
                }
                row += direction;
            }
        },
        navigateMeasurementColumn(event, direction) {
            const input = event.target;
            if (! input?.dataset?.measurementRow) {
                return;
            }
            const row = parseInt(input.dataset.measurementRow, 10);
            let colIndex = this.measurementColumns.indexOf(input.dataset.measurementCol);
            if (colIndex === -1) {
                return;
            }
            colIndex += direction;
            while (colIndex >= 0 && colIndex < this.measurementColumns.length) {
                if (this.focusMeasurementInput(row, this.measurementColumns[colIndex])) {
                    event.preventDefault();
                    return;
                }
                colIndex += direction;
            }
        },
        onMeasurementKeydown(event) {
            if (event.key === 'ArrowDown') {
                this.navigateMeasurementRow(event, 1);
            } else if (event.key === 'ArrowUp') {
                this.navigateMeasurementRow(event, -1);
            } else if (event.key === 'ArrowRight') {
                this.navigateMeasurementColumn(event, 1);
            } else if (event.key === 'ArrowLeft') {
                this.navigateMeasurementColumn(event, -1);
            }
        },
    }"
    x-on:keydown="onMeasurementKeydown($event)"
>
    <div class="overflow-x-auto">
        <table class="fitting-measurement-table__grid min-w-full border-collapse">
            <thead>
                <tr class="bg-gray-100 dark:bg-gray-700">
                    <th class="fitting-measurement-table__th">Afkorting</th>
                    <th class="fitting-measurement-table__th">Huidige stoel</th>
                    <th class="fitting-measurement-table__th">Nieuwe stoel</th>
                    <th class="fitting-measurement-table__th fitting-measurement-table__th--icon" aria-label="Verwijderen"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $index => $row)
                    <tr wire:key="fitting-measurement-row-{{ $ownerId }}-{{ $index }}">
                        <td class="fitting-measurement-table__td p-0">
                            <div class="fitting-measurement-table__input-wrp">
                                @if ($row['custom'] ?? false)
                                    <input
                                        type="text"
                                        data-measurement-row="{{ $index }}"
                                        data-measurement-col="code"
                                        wire:model.blur="rows.{{ $index }}.code"
                                        class="fitting-measurement-table__input min-w-0"
                                    />
                                @else
                                    <input
                                        type="text"
                                        data-measurement-row="{{ $index }}"
                                        data-measurement-col="code"
                                        wire:model="rows.{{ $index }}.code"
                                        class="fitting-measurement-table__input min-w-0"
                                        readonly
                                        tabindex="-1"
                                    />
                                @endif
                            </div>
                        </td>
                        <td class="fitting-measurement-table__td p-0">
                            <div class="fitting-measurement-table__input-wrp">
                                <input
                                    type="text"
                                    data-measurement-row="{{ $index }}"
                                    data-measurement-col="current"
                                    wire:model.blur="rows.{{ $index }}.current"
                                    class="fitting-measurement-table__input min-w-0"
                                />
                            </div>
                        </td>
                        <td class="fitting-measurement-table__td p-0">
                            <div class="fitting-measurement-table__input-wrp">
                                <input
                                    type="text"
                                    data-measurement-row="{{ $index }}"
                                    data-measurement-col="new"
                                    wire:model.blur="rows.{{ $index }}.new"
                                    class="fitting-measurement-table__input min-w-0"
                                />
                            </div>
                        </td>
                        <td class="fitting-measurement-table__td fitting-measurement-table__td--icon">
                            @if ($row['custom'] ?? false)
                                <button
                                    type="button"
                                    wire:click="removeRow({{ $index }})"
                                    class="p-1 text-gray-500 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400 rounded inline-flex items-center justify-center"
                                    title="Rij verwijderen"
                                    aria-label="Rij verwijderen"
                                >
                                    <span class="icon-dl" aria-hidden="true">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" class="block">
                                            <title>trash</title>
                                            <path d="M17 2h-3.5l-1-1h-5l-1 1H3v2h14zM4 17a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V5H4z" fill="currentColor"></path>
                                        </svg>
                                    </span>
                                </button>
                            @else
                                <span class="fitting-measurement-table__icon-disabled inline-flex items-center justify-center p-1 cursor-not-allowed" aria-hidden="true" title="Standaard afkorting kan niet worden verwijderd">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="block">
                                        <title>trash</title>
                                        <path d="M17 2h-3.5l-1-1h-5l-1 1H3v2h14zM4 17a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V5H4z" fill="currentColor"></path>
                                    </svg>
                                </span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-2 flex justify-center">
        <button
            type="button"
            wire:click="addRow"
            class="inline-flex items-center gap-1 text-sm text-primary-600 dark:text-primary-400 hover:underline"
        >
            <span aria-hidden="true">+</span>
            <span>Rij toevoegen</span>
        </button>
    </div>
</div>
