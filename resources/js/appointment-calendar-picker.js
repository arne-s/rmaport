/**
 * Alpine grid drag/select for AppointmentCalendarPicker (Livewire).
 * Exposed on window.appointmentCalendarGrid so x-data works even if Alpine.data
 * registration runs after the first Livewire morph (Filament modal).
 */
export function appointmentCalendarGridFactory(cfg) {
    return {
        readOnly: cfg.readOnly,
        pxPerHour: cfg.pxPerHour,
        gridMaxPx: cfg.totalPx,
        gridStartMinutes: cfg.gridStartMinutes,
        todayDate: cfg.todayDate,
        nowPx: cfg.nowPx,
        dayCount: cfg.dayCount,

        dragging: false,
        moveMode: false,
        moveOffsetY: 0,
        moveDurationPx: 0,
        moveDragDate: null,
        moveColEl: null,
        dragDate: null,
        dragColIndex: -1,
        colEl: null,
        pointerId: null,
        startPx: 0,
        endPx: cfg.pxPerHour,
        overlayStyle: 'display:none',
        committedSelection: cfg.selection ?? null,
        _boundPointerMove: null,
        _boundPointerUp: null,
        _onScrollOrResize: null,
        _onSelectionCleared: null,
        _columnRects: null,
        _pendingPointerEvent: null,
        _rafId: null,
        _scrollInitialPx: 0,
        _scrollObservers: null,
        _scrollRetryTimers: [],

        weekStart: cfg.weekStart ?? null,

        init() {
            this.clearMorphPause();

            const stored = this.loadStoredCommittedSelection();
            if (!this.committedSelection && stored) {
                this.committedSelection = stored;
            } else if (this.committedSelection) {
                if (!this.committedSelection.weekStart) {
                    this.committedSelection.weekStart = this.weekStart;
                }
                this.persistCommittedSelection();
            }

            this._onSelectionCleared = () => this.clearCommittedSelection();
            window.addEventListener('appointment-picker-cleared', this._onSelectionCleared);
            window.addEventListener('appointment-picker-reset', this._onSelectionCleared);

            const fromDataset = parseInt(this.$el.dataset.scrollInitialPx ?? '', 10);
            this._scrollInitialPx = Number.isFinite(fromDataset)
                ? fromDataset
                : cfg.scrollInitialPx ?? cfg.pxPerHour * 7;

            this.setupInitialScroll();

            return () => {
                window.removeEventListener('appointment-picker-cleared', this._onSelectionCleared);
                window.removeEventListener('appointment-picker-reset', this._onSelectionCleared);
                this.teardownInitialScroll();
            };
        },

        scrollToInitialPosition() {
            const el = this.$el;

            if (!el || this._scrollInitialPx <= 0) {
                return;
            }

            el.scrollTop = this._scrollInitialPx;
        },

        setupInitialScroll() {
            this.scrollToInitialPosition();

            if (typeof this.$nextTick === 'function') {
                this.$nextTick(() => this.scrollToInitialPosition());
            }

            requestAnimationFrame(() => {
                this.scrollToInitialPosition();
                requestAnimationFrame(() => this.scrollToInitialPosition());
            });

            this._scrollRetryTimers = [50, 150, 350, 600].map((delay) =>
                window.setTimeout(() => this.scrollToInitialPosition(), delay),
            );

            if (typeof ResizeObserver !== 'undefined') {
                const resizeObserver = new ResizeObserver(() => {
                    if (this.$el.scrollHeight > this.$el.clientHeight) {
                        this.scrollToInitialPosition();
                    }
                });
                resizeObserver.observe(this.$el);
                this._scrollObservers = { resizeObserver };
            }

            if (typeof IntersectionObserver !== 'undefined') {
                const intersectionObserver = new IntersectionObserver((entries) => {
                    for (const entry of entries) {
                        if (entry.isIntersecting) {
                            this.scrollToInitialPosition();
                        }
                    }
                });
                intersectionObserver.observe(this.$el);
                this._scrollObservers = {
                    ...this._scrollObservers,
                    intersectionObserver,
                };
            }
        },

        teardownInitialScroll() {
            for (const timerId of this._scrollRetryTimers) {
                window.clearTimeout(timerId);
            }
            this._scrollRetryTimers = [];

            if (!this._scrollObservers) {
                return;
            }

            this._scrollObservers.resizeObserver?.disconnect();
            this._scrollObservers.intersectionObserver?.disconnect();
            this._scrollObservers = null;
        },

        destroy() {
            this.teardownInitialScroll();
            this.finishPointerDrag();
            this.clearMorphPause();
        },

        storageKey() {
            const wireId = this.$wire?.id;

            return wireId ? 'acp-committed:' + wireId : 'acp-committed:unknown';
        },

        loadStoredCommittedSelection() {
            try {
                const raw = sessionStorage.getItem(this.storageKey());

                if (!raw) {
                    return null;
                }

                const parsed = JSON.parse(raw);

                if (!parsed?.weekStart || parsed.weekStart !== this.weekStart) {
                    sessionStorage.removeItem(this.storageKey());

                    return null;
                }

                return parsed;
            } catch (err) {
                return null;
            }
        },

        persistCommittedSelection() {
            if (!this.committedSelection) {
                sessionStorage.removeItem(this.storageKey());

                return;
            }

            sessionStorage.setItem(this.storageKey(), JSON.stringify(this.committedSelection));
        },

        clearCommittedSelection() {
            this.committedSelection = null;
            sessionStorage.removeItem(this.storageKey());
        },

        setCommittedFromDrag(date, colIndex) {
            this.committedSelection = {
                date: date,
                colIndex: Number(colIndex),
                startPx: this.topPx(),
                heightPx: this.heightPx(),
                weekStart: this.weekStart,
            };
            this.persistCommittedSelection();
        },

        committedSelectionVisible() {
            return Boolean(this.committedSelection) && !this.showDragOverlay();
        },

        committedSelectionStyle() {
            const selection = this.committedSelection;

            if (!selection) {
                return 'display:none';
            }

            const i = Number(selection.colIndex);
            const n = this.dayCount;

            return (
                'top:' +
                selection.startPx +
                'px;height:' +
                selection.heightPx +
                'px;left:calc(2.5rem + (100% - 2.5rem) * ' +
                i +
                ' / ' +
                n +
                ');width:calc((100% - 2.5rem) / ' +
                n +
                ');'
            );
        },

        clearMorphPause() {
            this.$el.closest('.appointment-calendar-picker')?.removeAttribute('wire:ignore');
        },

        topPx() {
            return Math.min(this.startPx, this.endPx);
        },

        heightPx() {
            return Math.max(15, Math.abs(this.endPx - this.startPx));
        },

        showDragOverlay() {
            return (this.dragging || this.moveMode) && this.dragColIndex >= 0;
        },

        updateOverlayStyle() {
            const i = Number(this.dragColIndex);
            const n = this.dayCount;

            if (!this.showDragOverlay()) {
                if (this.overlayStyle !== 'display:none') {
                    this.overlayStyle = 'display:none';
                }

                return;
            }

            const top = this.topPx();
            const height = this.heightPx();

            let style =
                'position:absolute;transform:translate3d(0,' +
                top +
                'px,0);height:' +
                height +
                'px;left:calc(2.5rem + (100% - 2.5rem) * ' +
                i +
                ' / ' +
                n +
                ');width:calc((100% - 2.5rem) / ' +
                n +
                ');will-change:transform,height;';

            if (this.moveMode) {
                style += 'cursor:grabbing;touch-action:none;';
            }

            if (style !== this.overlayStyle) {
                this.overlayStyle = style;
            }
        },

        computeDurationMinutes(timeFrom, timeTo) {
            const parse = (time) => {
                if (!time || !/^\d{1,2}:\d{2}$/.test(time)) {
                    return 0;
                }

                const parts = time.split(':').map(Number);

                return parts[0] * 60 + parts[1];
            };

            return Math.max(0, parse(timeTo) - parse(timeFrom));
        },

        normalizeDatetimePayload(payload, date, from, to) {
            if (payload && typeof payload === 'object' && payload.timeFrom) {
                const selectedDate = payload.selectedDate ?? date;
                const timeFrom = payload.timeFrom;
                const timeTo = payload.timeTo ?? to;

                return {
                    datetime: payload.datetime ?? selectedDate + ' ' + timeFrom,
                    selectedDate: selectedDate,
                    timeFrom: timeFrom,
                    timeTo: timeTo,
                    durationMinutes:
                        payload.durationMinutes ??
                        this.computeDurationMinutes(timeFrom, timeTo),
                };
            }

            return {
                datetime: date + ' ' + from,
                selectedDate: date,
                timeFrom: from,
                timeTo: to,
                durationMinutes: this.computeDurationMinutes(from, to),
            };
        },

        syncToAppointmentTimeFields(detail) {
            const fields =
                document.querySelector('.fi-modal .appointment-time-fields') ??
                document.querySelector('.appointment-time-fields');

            if (!fields || typeof Alpine === 'undefined' || typeof Alpine.$data !== 'function') {
                return false;
            }

            const data = Alpine.$data(fields);

            if (!data) {
                return false;
            }

            data.selectedDate = detail.selectedDate ?? null;
            data.timeFrom = detail.timeFrom ?? '09:00';
            data.timeTo = detail.timeTo ?? '10:00';
            data.dateError = false;

            if (typeof data.syncBoundaryTimesFromTravelDurations === 'function') {
                data.syncBoundaryTimesFromTravelDurations();
            }

            if (typeof data.recalculateCustomerTimes === 'function') {
                data.recalculateCustomerTimes();
            }

            return true;
        },

        broadcastDatetimeToAppointmentForm(payload, date, from, to) {
            const detail = this.normalizeDatetimePayload(payload, date, from, to);

            if (!detail.selectedDate || !detail.timeFrom) {
                return;
            }

            const runSync = () => {
                const viaDom = this.syncToAppointmentTimeFields(detail);
                const viaGlobal =
                    !viaDom && typeof window.__rdmSyncAppointmentPickerDatetime === 'function';

                if (viaGlobal) {
                    window.__rdmSyncAppointmentPickerDatetime(detail);
                }

                window.dispatchEvent(
                    new CustomEvent('appointment-picker-datetime-updated', {
                        bubbles: true,
                        composed: true,
                        detail: detail,
                    }),
                );
            };

            if (typeof Alpine !== 'undefined' && typeof Alpine.nextTick === 'function') {
                Alpine.nextTick(runSync);
            } else {
                queueMicrotask(runSync);
            }
        },

        async completeSelectRange(date, colIndex, from, to) {
            this.setCommittedFromDrag(date, colIndex);

            try {
                const payload = await this.$wire.selectRange(date, from, to);
                this.broadcastDatetimeToAppointmentForm(payload, date, from, to);
            } catch (err) {
                this.broadcastDatetimeToAppointmentForm(null, date, from, to);
            }
        },

        px2time(px) {
            const pph = this.pxPerHour;
            const clamped = Math.max(0, Math.min(this.gridMaxPx, px));
            const totalMins = Math.min(
                24 * 60 - 1,
                Math.round((clamped / pph) * 60) + this.gridStartMinutes,
            );
            const h = Math.floor(totalMins / 60);
            const m = totalMins % 60;
            return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
        },

        snap15(px) {
            const step = this.pxPerHour / 4;
            return Math.round(px / step) * step;
        },

        isPastSlot(date, yPx) {
            if (date < this.todayDate) {
                return true;
            }
            if (date === this.todayDate && yPx < this.nowPx) {
                return true;
            }
            return false;
        },

        slotFitsOnDate(date, topPx, heightPx) {
            if (date < this.todayDate) {
                return false;
            }
            if (date === this.todayDate && topPx < this.nowPx) {
                return false;
            }
            if (topPx + heightPx > this.gridMaxPx + 1) {
                return false;
            }
            return true;
        },

        clampTopForDate(date, topPx, heightPx) {
            let top = Math.max(0, Math.min(this.gridMaxPx - heightPx, topPx));
            if (date === this.todayDate && top < this.nowPx) {
                top = this.snap15(this.nowPx);
            }
            if (!this.slotFitsOnDate(date, top, heightPx)) {
                return null;
            }
            return top;
        },

        refreshColumnCache() {
            const grid = this.$refs.dayColumns;
            if (!grid) {
                this._columnRects = null;

                return;
            }

            const rects = [];
            for (const col of grid.querySelectorAll('[data-date]')) {
                const rect = col.getBoundingClientRect();
                rects.push({
                    el: col,
                    date: col.dataset.date,
                    colIndex: parseInt(col.dataset.col, 10),
                    isPast: col.dataset.isPast === '1',
                    left: rect.left,
                    right: rect.right,
                    top: rect.top,
                    bottom: rect.bottom,
                });
            }
            this._columnRects = rects;
        },

        resolveColumnFromPointer(e) {
            if (!this._columnRects?.length) {
                this.refreshColumnCache();
            }

            const x = e.clientX;
            const y = e.clientY;

            for (const col of this._columnRects ?? []) {
                if (x >= col.left && x <= col.right && y >= col.top && y <= col.bottom) {
                    return {
                        el: col.el,
                        date: col.date,
                        colIndex: col.colIndex,
                        isPast: col.isPast,
                    };
                }
            }

            return null;
        },

        bindGlobalPointerHandlers() {
            this.unbindGlobalPointerHandlers();
            this.refreshColumnCache();
            this._boundPointerMove = (e) => this.onPointerMove(e);
            this._boundPointerUp = (e) => this.onPointerUp(e);
            this._onScrollOrResize = () => this.refreshColumnCache();
            window.addEventListener('pointermove', this._boundPointerMove, { passive: true });
            window.addEventListener('pointerup', this._boundPointerUp);
            window.addEventListener('pointercancel', this._boundPointerUp);
            this.$el.addEventListener('scroll', this._onScrollOrResize, { passive: true });
            window.addEventListener('resize', this._onScrollOrResize, { passive: true });
        },

        unbindGlobalPointerHandlers() {
            if (this._boundPointerMove) {
                window.removeEventListener('pointermove', this._boundPointerMove);
                window.removeEventListener('pointerup', this._boundPointerUp);
                window.removeEventListener('pointercancel', this._boundPointerUp);
            }

            if (this._onScrollOrResize) {
                this.$el.removeEventListener('scroll', this._onScrollOrResize);
                window.removeEventListener('resize', this._onScrollOrResize);
            }

            if (this._rafId !== null) {
                cancelAnimationFrame(this._rafId);
                this._rafId = null;
            }

            this._boundPointerMove = null;
            this._boundPointerUp = null;
            this._onScrollOrResize = null;
            this._pendingPointerEvent = null;
            this._columnRects = null;
        },

        clientYFromEvent(e) {
            return typeof e.clientY === 'number' && !isNaN(e.clientY) ? e.clientY : 0;
        },

        clientYInColumn(e, colEl) {
            const cached = this._columnRects?.find((col) => col.el === colEl);
            if (cached) {
                return this.clientYFromEvent(e) - cached.top;
            }

            return this.clientYFromEvent(e) - colEl.getBoundingClientRect().top;
        },

        onGridPointerDown(e) {
            const sel = e.target.closest('.acp-selection-handle');

            if (sel && this.$el.contains(sel)) {
                this.onSelectionPointerDown(e, sel);

                return;
            }

            const grid = this.$refs.dayColumns;

            if (!grid || !grid.contains(e.target)) {
                return;
            }

            const el = e.target.closest('[data-date]');

            if (!el || !grid.contains(el)) {
                return;
            }

            this.startColumnDrag(e, el);
        },

        startColumnDrag(e, el) {
            const date = el.dataset.date;
            const cached = this._columnRects?.find((col) => col.el === el);
            const y = cached
                ? this.clientYFromEvent(e) - cached.top
                : this.clientYFromEvent(e) - el.getBoundingClientRect().top;
            if (this.readOnly) {
                return;
            }
            if (!e.isPrimary) {
                return;
            }
            if (e.pointerType === 'mouse' && e.button !== 0) {
                return;
            }
            this.moveMode = false;
            const colIndex = parseInt(el.dataset.col, 10);
            if (this.isPastSlot(date, y)) {
                return;
            }
            e.preventDefault();
            try {
                el.setPointerCapture(e.pointerId);
            } catch (err) {
                /* ignore */
            }
            this.pointerId = e.pointerId;
            this.dragDate = date;
            this.dragColIndex = colIndex;
            this.colEl = el;
            this.dragging = true;
            this.startPx = this.snap15(Math.max(0, Math.min(this.gridMaxPx, y)));
            this.endPx = Math.min(this.gridMaxPx, this.startPx + this.pxPerHour);
            this.bindGlobalPointerHandlers();
            this.updateOverlayStyle();
        },

        onSelectionPointerDown(e, sel) {
            if (this.readOnly) {
                return;
            }
            if (!e.isPrimary) {
                return;
            }
            if (e.pointerType === 'mouse' && e.button !== 0) {
                return;
            }
            if (!this.committedSelection) {
                return;
            }

            const grid = this.$refs.dayColumns;
            const colEl = grid?.querySelector(
                '[data-date="' + this.committedSelection.date + '"]',
            );

            if (!colEl) {
                return;
            }

            const date = this.committedSelection.date;
            const colIndex = this.committedSelection.colIndex;
            const y = this.clientYInColumn(e, colEl);
            const selTop = Number(this.committedSelection.startPx);
            const selH = Number(this.committedSelection.heightPx);
            e.preventDefault();
            e.stopPropagation();
            try {
                colEl.setPointerCapture(e.pointerId);
            } catch (err) {
                /* ignore */
            }
            this.pointerId = e.pointerId;
            this.moveMode = true;
            this.moveColEl = colEl;
            this.moveDragDate = date;
            this.moveOffsetY = y - selTop;
            this.moveDurationPx = Math.max(15, selH);
            this.dragColIndex = colIndex;
            this.colEl = colEl;
            this.dragging = true;
            let top = this.snap15(selTop);
            if (date === this.todayDate && top < this.nowPx) {
                top = this.snap15(this.nowPx);
            }
            this.startPx = top;
            this.endPx = top + this.moveDurationPx;
            this.bindGlobalPointerHandlers();
            this.updateOverlayStyle();
        },

        onPointerMove(e) {
            if (this.pointerId !== null && e.pointerId !== this.pointerId) {
                return;
            }

            this._pendingPointerEvent = e;

            if (this._rafId !== null) {
                return;
            }

            this._rafId = requestAnimationFrame(() => {
                this._rafId = null;
                const event = this._pendingPointerEvent;
                this._pendingPointerEvent = null;

                if (!event) {
                    return;
                }

                this.processPointerMove(event);
            });
        },

        processPointerMove(e) {
            if (this.moveMode) {
                const col = this.resolveColumnFromPointer(e);
                if (!col || col.isPast) {
                    return;
                }

                const y = this.clientYInColumn(e, col.el);
                let newTop = this.snap15(y - this.moveOffsetY);
                newTop = this.clampTopForDate(col.date, newTop, this.moveDurationPx);
                if (newTop === null) {
                    return;
                }

                const nextStart = newTop;
                const nextEnd = newTop + this.moveDurationPx;

                if (
                    this.moveDragDate === col.date &&
                    this.dragColIndex === col.colIndex &&
                    this.startPx === nextStart &&
                    this.endPx === nextEnd
                ) {
                    return;
                }

                this.moveDragDate = col.date;
                this.dragColIndex = col.colIndex;
                this.moveColEl = col.el;
                this.colEl = col.el;
                this.startPx = nextStart;
                this.endPx = nextEnd;
                this.updateOverlayStyle();

                return;
            }

            if (!this.dragging || !this.colEl) {
                return;
            }

            const col = this.resolveColumnFromPointer(e);
            const activeCol =
                col && !col.isPast
                    ? col
                    : {
                          el: this.colEl,
                          date: this.dragDate,
                          colIndex: this.dragColIndex,
                          isPast: false,
                      };

            if (col && !col.isPast && col.date !== this.dragDate) {
                const top = this.topPx();
                const height = this.heightPx();
                if (!this.slotFitsOnDate(col.date, top, height)) {
                    return;
                }
                this.dragDate = col.date;
                this.dragColIndex = col.colIndex;
                this.colEl = col.el;
                this.updateOverlayStyle();

                return;
            }

            const y = this.clientYInColumn(e, activeCol.el);
            if (this.isPastSlot(activeCol.date, y)) {
                return;
            }

            const nextEnd = this.snap15(Math.max(0, Math.min(this.gridMaxPx, y)));

            if (this.endPx === nextEnd) {
                return;
            }

            this.endPx = nextEnd;
            this.updateOverlayStyle();
        },

        finishPointerDrag() {
            this.unbindGlobalPointerHandlers();
            this.clearMorphPause();

            if (this.colEl !== null && this.pointerId !== null) {
                try {
                    this.colEl.releasePointerCapture(this.pointerId);
                } catch (err) {
                    /* ignore */
                }
            }
            this.pointerId = null;
            this.moveMode = false;
            this.dragging = false;
            this.moveColEl = null;
            this.moveDragDate = null;
            this.dragDate = null;
            this.dragColIndex = -1;
            this.colEl = null;
            this.overlayStyle = 'display:none';
        },

        onPointerUp() {
            if (this.moveMode) {
                const date = this.moveDragDate;
                const colIndex = this.dragColIndex;
                const from = this.px2time(this.startPx);
                const to = this.px2time(this.endPx);
                this.finishPointerDrag();
                if (!date) {
                    return;
                }
                this.completeSelectRange(date, colIndex, from, to);

                return;
            }
            if (!this.dragging) {
                return;
            }
            const date = this.dragDate;
            const colIndex = this.dragColIndex;
            const minEnd = this.startPx + 6;
            const endPx = Math.max(this.endPx, minEnd);
            const from = this.px2time(Math.min(this.startPx, endPx));
            const to = this.px2time(Math.max(this.startPx, endPx));
            this.finishPointerDrag();
            if (!date) {
                return;
            }
            this.completeSelectRange(date, colIndex, from, to);
        },
    };
}

export function registerAppointmentCalendarGrid(Alpine) {
    if (typeof window !== 'undefined') {
        window.appointmentCalendarGrid = appointmentCalendarGridFactory;
    }

    if (Alpine && typeof Alpine.data === 'function') {
        Alpine.data('appointmentCalendarGrid', appointmentCalendarGridFactory);
    }
}
