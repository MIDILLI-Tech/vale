/**
 * Right-Rail Collapse + Sticky Action Bar — Vale CMS Admin
 *
 * Mirrors the left sidebar toggle: hides the right-hand form rail
 * ([data-panel-container="right"]) in bulk across all edit screens so the
 * main column gets the full width. The preference is persisted in the
 * `vl_admin_right_rail` cookie (same pattern as `vl_admin_theme`).
 *
 * While the rail is hidden a sticky action bar carries the screen's
 * primary actions (status switch, Publish, primary submit, Preview) so
 * nothing is lost without expanding the rail again.
 */

const RAIL_COOKIE = 'vl_admin_right_rail';

function railCookie() {
    const match = document.cookie.match(/(?:^|;\s*)vl_admin_right_rail=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : null;
}

function isRailCollapsed() {
    return railCookie() === '0';
}

function setRailCollapsed(collapsed) {
    document.cookie = RAIL_COOKIE + '=' + (collapsed ? '0' : '1') + '; path=/; max-age=31536000';
    document.documentElement.classList.toggle('rail-collapsed', collapsed);
}

/** Set a select's value and notify Alpine's x-model via the change event. */
function setSelectValue(select, value) {
    if (!select || select.value === value) return;
    select.value = value;
    select.dispatchEvent(new Event('change', { bubbles: true }));
}

/**
 * Quick-publish: flip the status select to `published`, drop the pending
 * schedule (so a future date cannot mask the publish as "scheduled") and
 * submit the form. No-op guards keep unauthorized users safe — the button
 * that triggers this is only rendered when the select offers `published`,
 * and the server re-validates the permission anyway.
 */
export function vlPublishNow(form) {
    const targetForm = form
        || document.querySelector('[data-panel-container="right"]')?.closest('form')
        || document.querySelector('form[data-autosave]');
    if (!targetForm) return;

    const statusSelect = targetForm.querySelector('select[name="status"]');
    if (statusSelect && statusSelect.querySelector('option[value="published"]')) {
        setSelectValue(statusSelect, 'published');
    }

    const schedule = targetForm.querySelector('input[name="set_schedule"]');
    if (schedule && schedule.checked) {
        schedule.checked = false;
        schedule.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Drop a stale publish date so the reloaded form shows a clean state
    // (the server also discards a future marker when scheduling is off).
    targetForm.querySelectorAll('input[name="published_at"]').forEach(function (el) {
        el.value = '';
    });

    targetForm.requestSubmit();
}

function buildStatusMirror(barStatus, realSelect) {
    if (!realSelect) {
        const wrap = barStatus.closest('[data-rail-status-wrap]');
        if (wrap) wrap.remove();
        return null;
    }

    barStatus.innerHTML = realSelect.innerHTML;
    barStatus.value = realSelect.value;

    // Real select (or Alpine x-model) changed → keep the mirror in sync.
    realSelect.addEventListener('change', function () {
        barStatus.value = realSelect.value;
    });
    return realSelect;
}

export function initRightRail() {
    // Only pages that carry an edit rail ([data-panel-container="right"],
    // flagged server-side via `panel_rail`) get the bar and the toggle.
    const rightRail = document.querySelector('[data-panel-container="right"]');
    if (!rightRail) return;

    // Toggle — rendered at the far right of the page's title row.
    const toggle = document.getElementById('vl-rail-toggle');
    if (toggle) {
        toggle.addEventListener('click', function () {
            setRailCollapsed(!isRailCollapsed());
        });
    }

    // Sticky action bar — populated from the page's own form.
    const bar = document.querySelector('[data-rail-bar]');
    if (bar) {
        const form = rightRail.closest('form');
        if (!form) return; // read-only views have no form to act on
        const statusSelect = form.querySelector('select[name="status"]');
        const canPublish = !!(statusSelect && statusSelect.querySelector('option[value="published"]'));

        // Primary submit label: copy the wording from the rail's own button.
        const railSubmit = rightRail.querySelector('button[type="submit"]');
        const barSubmit = bar.querySelector('[data-rail-submit]');
        if (barSubmit) {
            if (railSubmit && railSubmit.textContent) {
                barSubmit.textContent = railSubmit.textContent.trim();
            }
            barSubmit.addEventListener('click', function () {
                form.requestSubmit();
            });
        }

        const barStatus = bar.querySelector('[data-rail-status]');
        if (barStatus) buildStatusMirror(barStatus, statusSelect);

        // Publish only makes sense while the item is not published yet.
        const barPublish = bar.querySelector('[data-rail-publish]');
        if (barPublish && canPublish && statusSelect) {
            barPublish.addEventListener('click', function () {
                vlPublishNow(form);
            });
            statusSelect.addEventListener('change', function () {
                barPublish.classList.toggle('hidden', statusSelect.value === 'published');
            });
            barPublish.classList.toggle('hidden', statusSelect.value === 'published');
        } else if (barPublish) {
            barPublish.remove();
        }

        // Preview forwards to the form's own preview button.
        const previewBtn = document.querySelector('[data-preview-action]');
        const barPreview = bar.querySelector('[data-rail-preview]');
        if (barPreview && previewBtn) {
            barPreview.addEventListener('click', function () {
                previewBtn.click();
            });
        } else if (barPreview) {
            barPreview.remove();
        }

        const barExpand = bar.querySelector('[data-rail-expand]');
        if (barExpand) {
            barExpand.addEventListener('click', function () {
                setRailCollapsed(false);
            });
        }
    }

    // Publish buttons rendered inside the publish box itself (rail open).
    document.querySelectorAll('[data-publish-action]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            vlPublishNow(btn.closest('form'));
        });
    });
}
