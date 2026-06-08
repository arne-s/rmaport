import {
    appointmentCalendarGridFactory,
    registerAppointmentCalendarGrid,
} from './appointment-calendar-picker';

window.appointmentCalendarGrid = appointmentCalendarGridFactory;

function applyAcpGridInitialScroll(root = document) {
    const scope = root instanceof Element ? root : document;

    scope.querySelectorAll('.acp-grid-body[data-scroll-initial-px]').forEach((el) => {
        const px = parseInt(el.dataset.scrollInitialPx ?? '', 10);

        if (!Number.isFinite(px) || px <= 0) {
            return;
        }

        const apply = () => {
            el.scrollTop = px;
        };

        apply();
        requestAnimationFrame(() => requestAnimationFrame(apply));
    });
}

document.addEventListener('alpine:init', () => {
    if (window.Alpine) {
        registerAppointmentCalendarGrid(window.Alpine);
    }
});

document.addEventListener('livewire:init', () => {
    if (window.Alpine) {
        registerAppointmentCalendarGrid(window.Alpine);
    }

    if (typeof Livewire === 'undefined' || typeof Livewire.hook !== 'function') {
        return;
    }

    Livewire.hook('morph.updated', ({ el }) => {
        if (!(el instanceof Element)) {
            return;
        }

        if (el.matches('.acp-grid-body') || el.querySelector('.acp-grid-body')) {
            applyAcpGridInitialScroll(el);
        }
    });
});

if (window.Alpine) {
    registerAppointmentCalendarGrid(window.Alpine);
}
