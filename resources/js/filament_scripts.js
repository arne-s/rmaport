function detectDevice() {
    const isTouchDevice = navigator.maxTouchPoints > 0;

    // Store the result in a cookie
    document.cookie = `isTouchDevice=${isTouchDevice}; path=/`;
}

detectDevice();

/**
 * Filament OneTimeCodeInput: maxlength is niet altijd genoeg (plakken, IME). Alleen cijfers, max length
 * uit maxlength/length attribuut (default 6), daarna input opnieuw dispatchen voor Livewire/wire:model.
 */
function clampFilamentOneTimeCodeInputValue(event) {
    const el = event.target;
    if (!(el instanceof HTMLInputElement) || !el.classList.contains('fi-one-time-code-input')) {
        return;
    }

    const rawMax = el.getAttribute('maxlength') ?? el.getAttribute('length');
    const max = rawMax !== null && rawMax !== '' ? Number.parseInt(rawMax, 10) : 6;
    const limit = Number.isFinite(max) && max > 0 ? max : 6;

    const digits = el.value.replace(/\D/g, '').slice(0, limit);
    if (el.value === digits) {
        return;
    }

    el.value = digits;
    el.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));
}

if (typeof window !== 'undefined' && !window.__rdFilamentOtpClampBound) {
    window.__rdFilamentOtpClampBound = true;
    document.addEventListener('input', clampFilamentOneTimeCodeInputValue, true);
}

/**
 * Generate a URL-friendly "slug" from a given string.
 *
 * @param {string} title - The string to be converted into a slug.
 * @param {string} separator - The separator to use (default is '-').
 * @param {Object} dictionary - A dictionary of words to replace (default is {'@': 'at'}).
 * @return {string} - The generated slug.
 */
function generateSlug(title, separator = '-', dictionary = { '@': 'at' }) {
    // Normalize the string and remove diacritical marks
    title = title.normalize('NFD').replace(/[\u0300-\u036f]/g, '');

    // Replace dictionary words
    Object.entries(dictionary).forEach(([key, value]) => {
        const replacement = `${separator}${value}${separator}`;
        title = title.replaceAll(key, replacement);
    });

    // Replace dashes/underscores with the separator
    const flip = separator === '-' ? '_' : '-';
    title = title.replace(new RegExp(`[${flip}]+`, 'g'), separator);

    // Remove invalid characters (keep letters, numbers, whitespace, and separator)
    title = title.replace(/[^a-zA-Z0-9\s-]+/g, ''); // Updated regex for compatibility

    // Replace multiple separators or whitespace with a single separator
    title = title.replace(new RegExp(`[${separator}\\s]+`, 'g'), separator);

    // Trim leading and trailing separators
    return title
        .trim(separator)
        .toLowerCase();
}
