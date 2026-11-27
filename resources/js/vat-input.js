// VAT number fields (`data-vl-vat`): uppercase while typing and drop spaces,
// so "de 123 456 789" becomes "DE123456789" before it is submitted.
// VatNumber::fromString() on the server stays the authority for validation.

const SELECTOR = '[data-vl-vat]';

function normalizeVatInput(el) {
    const raw = el.value;
    const clean = raw.replace(/\s+/g, '').toUpperCase();
    if (clean === raw) {
        return;
    }

    const caret = el.selectionStart;
    const cleanCaret = caret === null
        ? clean.length
        : raw.slice(0, caret).replace(/\s+/g, '').length;

    el.value = clean;

    // Notify x-model (and any other listener) that the value changed outside a
    // native input event; the listener re-runs once and returns early.
    el.dispatchEvent(new Event('input', { bubbles: true }));

    if (document.activeElement === el && el.selectionStart !== null) {
        el.setSelectionRange(cleanCaret, cleanCaret);
    }
}

document.addEventListener('input', (event) => {
    const el = event.target;
    if (el instanceof HTMLInputElement && el.matches(SELECTOR)) {
        normalizeVatInput(el);
    }
});

// Values restored from a saved setting or a failed submission may still carry
// spaces, so normalize them once on load as well.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll(SELECTOR).forEach((el) => {
        if (el instanceof HTMLInputElement) {
            normalizeVatInput(el);
        }
    });
});
