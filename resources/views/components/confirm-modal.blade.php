<div id="confirm-modal"
     x-data="{
        isOpen: false,
        title: '',
        description: '',
        confirmLabel: 'Verwijderen & opslaan',
        confirmClass: '',
        onConfirm: null,
        originalEvent: null
    }"
     x-on:open-confirm-modal.window="
        title = $event.detail.title;
        description = $event.detail.description;
        confirmLabel = $event.detail.confirmLabel || 'Verwijderen & opslaan';
        confirmClass = $event.detail.confirmClass || '';
        onConfirm = $event.detail.onConfirm;
        originalEvent = $event.detail.originalEvent;
        isOpen = true;
    "
     x-show="isOpen"
     x-cloak
     style="display: none;"
     class="fixed inset-0 z-50 overflow-y-auto"
     aria-labelledby="modal-title"
     role="dialog"
     aria-modal="true"
>
    <!-- Background overlay -->
    <div
        class="overlay-bg fixed inset-0 bg-gray-500 transition-opacity"
        x-on:click="isOpen = false"
    ></div>

    <!-- Modal panel -->
    <div class="modal-wrapper flex min-h-full items-center justify-center p-4 sm:p-0">
        <div
            class="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg"
            x-on:click.stop
        >
            <!-- Modal content -->
            <div class="bg-white px-6 pb-6 pt-6 content-row">
                <!-- Close button -->
                <div class="absolute right-0 top-0 pr-4 pt-4">
                    <button
                        type="button"
                        class="text-gray-600 hover:text-gray-800"
                        x-on:click="isOpen = false"
                    >
                        <span class="sr-only">Sluiten</span>
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Modal header -->
                <div class="pr-8">
                    <h3
                        class="text-lg font-bold leading-6 text-gray-900 mb-4 text-left"
                        id="modal-title"
                        x-text="title"
                    ></h3>
                    <div class="mt-2">
                        <p class="text-sm text-gray-700 leading-relaxed text-left" x-html="description"></p>
                    </div>
                </div>
            </div>

            <!-- Modal footer -->
            <div class="bg-white px-6 pb-6 button-row">
                <button
                    type="button"
                    class="secondary-button w-full sm:w-auto"
                    :class="confirmClass"
                    x-on:click="
                        if (onConfirm) {
                            onConfirm();
                        }
                        isOpen = false;
                    "
                    x-text="confirmLabel"
                ></button>
            </div>
        </div>
    </div>
</div>

<script>
    window.openConfirmModal = function(event, element) {
        event.preventDefault();
        event.stopPropagation();

        const title = element.getAttribute('data-confirm-modal-title') || 'Let op!';
        const description = element.getAttribute('data-confirm-modal-description') || '';
        const confirmLabel = element.getAttribute('data-confirm-button-label') || 'Verwijderen & opslaan';
        const confirmClass = element.getAttribute('data-confirm-button-class') || '';

        let onConfirm = null;

        const wireClick = element.getAttribute('wire:click');
        if (wireClick) {
            onConfirm = function() {
                element.dataset.confirmListenerAttached = 'false';

                const originalTitle = element.getAttribute('data-confirm-modal-title');
                const originalDesc = element.getAttribute('data-confirm-modal-description');
                const originalLabel = element.getAttribute('data-confirm-button-label');

                element.removeAttribute('data-confirm-modal-title');
                element.removeAttribute('data-confirm-modal-description');
                element.removeAttribute('data-confirm-button-label');

                const clone = element.cloneNode(true);
                clone.removeAttribute('data-confirm-listener-attached');
                clone.removeAttribute('data-confirm-modal-title');
                clone.removeAttribute('data-confirm-modal-description');
                clone.removeAttribute('data-confirm-button-label');

                const parent = element.parentNode;
                parent.replaceChild(clone, element);

                setTimeout(() => {
                    clone.click();
                }, 10);

                setTimeout(() => {
                    if (clone.parentNode === parent) {
                        parent.replaceChild(element, clone);
                    }
                    element.dataset.confirmListenerAttached = 'true';
                    if (originalTitle) element.setAttribute('data-confirm-modal-title', originalTitle);
                    if (originalDesc) element.setAttribute('data-confirm-modal-description', originalDesc);
                    if (originalLabel) element.setAttribute('data-confirm-button-label', originalLabel);
                }, 200);
            };
        } else {
            const form = element.closest('form');
            if (form) {
                onConfirm = function() {
                    element.dataset.confirmListenerAttached = 'false';
                    form.submit();
                    setTimeout(() => {
                        element.dataset.confirmListenerAttached = 'true';
                    }, 100);
                };
            } else {
                const href = element.getAttribute('href');
                if (href && href !== '#') {
                    onConfirm = function() {
                        window.location.href = href;
                    };
                } else {
                    const parentForm = element.closest('form');
                    if (parentForm) {
                        onConfirm = function() {
                            element.dataset.confirmListenerAttached = 'false';
                            const submitBtn = parentForm.querySelector('button[type="submit"]');
                            if (submitBtn) {
                                submitBtn.click();
                            } else {
                                parentForm.submit();
                            }
                            setTimeout(() => {
                                element.dataset.confirmListenerAttached = 'true';
                            }, 100);
                        };
                    } else {
                        onConfirm = function() {
                            element.dataset.confirmListenerAttached = 'false';
                            const originalTitle = element.getAttribute('data-confirm-modal-title');
                            const originalDesc = element.getAttribute('data-confirm-modal-description');
                            const originalLabel = element.getAttribute('data-confirm-button-label');

                            element.removeAttribute('data-confirm-modal-title');
                            element.removeAttribute('data-confirm-modal-description');
                            element.removeAttribute('data-confirm-button-label');

                            const clickEvent = new MouseEvent('click', {
                                bubbles: true,
                                cancelable: true,
                                view: window
                            });

                            element.dispatchEvent(clickEvent);

                            setTimeout(() => {
                                element.dataset.confirmListenerAttached = 'true';
                                if (originalTitle) element.setAttribute('data-confirm-modal-title', originalTitle);
                                if (originalDesc) element.setAttribute('data-confirm-modal-description', originalDesc);
                                if (originalLabel) element.setAttribute('data-confirm-button-label', originalLabel);
                            }, 100);
                        };
                    }
                }
            }
        }
        const customEvent = new CustomEvent('open-confirm-modal', {
            detail: {
                title: title,
                description: description,
                confirmLabel: confirmLabel,
                confirmClass: confirmClass,
                onConfirm: onConfirm,
                originalEvent: event
            }
        });

        window.dispatchEvent(customEvent);
    };

    // Auto-attach event listeners to buttons with data-confirm-modal-title
    // This is needed because Filament doesn't support x-on:click via extraAttributes
    (function() {
        function attachConfirmListeners() {
            const buttons = document.querySelectorAll('button[data-confirm-modal-title]');

            buttons.forEach((btn) => {
                if (btn.dataset.confirmListenerAttached) {
                    return;
                }

                btn.dataset.confirmListenerAttached = 'true';

                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();

                    if (typeof window.openConfirmModal !== 'function') {
                        console.error('[ConfirmModal] openConfirmModal function not found!');
                        return;
                    }

                    window.openConfirmModal(e, this);
                }, true);
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                attachConfirmListeners();
            });
        } else {
            attachConfirmListeners();
        }

        if (window.Livewire) {
            document.addEventListener('livewire:load', function() {
                setTimeout(attachConfirmListeners, 100);
            });
            document.addEventListener('livewire:update', function() {
                setTimeout(attachConfirmListeners, 100);
            });
            document.addEventListener('livewire:swap', function() {
                setTimeout(attachConfirmListeners, 100);
            });
            document.addEventListener('livewire:morph-updated', function() {
                setTimeout(attachConfirmListeners, 100);
            });
        }

        if (window.MutationObserver) {
            const observer = new MutationObserver(function(mutations) {
                const hasNewButtons = mutations.some(mutation => {
                    return Array.from(mutation.addedNodes).some(node => {
                        if (node.nodeType === 1) {
                            return node.matches && (
                                node.matches('button[data-confirm-modal-title]') ||
                                node.querySelector && node.querySelector('button[data-confirm-modal-title]')
                            );
                        }
                        return false;
                    });
                });

                if (hasNewButtons) {
                    setTimeout(attachConfirmListeners, 50);
                }
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        }
    })();
</script>

