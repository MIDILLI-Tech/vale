/*
 * Conditional field visibility for Vale forms.
 *
 * A field wrapper carrying `data-vl-conditions` is shown only while its rules
 * match the current answers. Hidden wrappers are also disabled, so the browser
 * neither validates nor submits them — a required conditional field must not
 * block a submission while it is hidden.
 *
 * Loaded by the standalone form page and by the [form] shortcode. The helper is
 * idempotent: the second and later copies re-scan the document instead of
 * binding a second set of listeners.
 */
(function () {
    'use strict';

    if (window.__vlFormConditionsLoaded) {
        if (typeof window.__vlFormConditionsScan === 'function') {
            window.__vlFormConditionsScan();
        }
        return;
    }
    window.__vlFormConditionsLoaded = true;

    function hasValue(value) {
        if (Array.isArray(value)) {
            return value.length > 0;
        }

        return String(value === null || value === undefined ? '' : value).trim() !== '';
    }

    function equals(value, expected) {
        if (Array.isArray(value)) {
            return false;
        }

        return String(value === null || value === undefined ? '' : value).trim() === expected;
    }

    function contains(value, expected) {
        var values = Array.isArray(value) ? value : [value];

        for (var i = 0; i < values.length; i++) {
            var item = values[i] === null || values[i] === undefined ? '' : values[i];
            if (String(item).trim() === expected) {
                return true;
            }
        }

        return false;
    }

    function readValue(form, name) {
        var single = 'fields[' + name + ']';
        var multi = single + '[]';
        var nodes = form.querySelectorAll('input, select, textarea');
        var value = null;
        var values = [];

        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];
            var nodeName = node.getAttribute('name') || '';

            if (nodeName === multi) {
                if (node.type === 'checkbox' && node.checked) {
                    values.push(node.value);
                }
            } else if (nodeName === single) {
                if (node.type === 'checkbox') {
                    value = node.checked ? (node.value || '1') : '';
                } else if (node.type === 'radio') {
                    if (node.checked) {
                        value = node.value;
                    }
                } else {
                    value = node.value;
                }
            }
        }

        return values.length > 0 ? values : (value === null ? '' : value);
    }

    function matches(form, rule) {
        var value = readValue(form, rule.field || '');
        var expected = rule.value || '';

        switch (rule.operator) {
            case 'checked':
                return hasValue(value);
            case 'unchecked':
                return !hasValue(value);
            case 'contains':
                return contains(value, expected);
            case 'not_contains':
                return !contains(value, expected);
            case 'not_equals':
                return !equals(value, expected);
            default:
                return equals(value, expected);
        }
    }

    function apply(wrapper, form) {
        var conditions = conditionsFor(wrapper);
        var visible = true;

        if (conditions && conditions.rules.length > 0) {
            var results = conditions.rules.map(function (rule) {
                return matches(form, rule);
            });

            visible = conditions.logic === 'any'
                ? results.indexOf(true) !== -1
                : results.indexOf(false) === -1;
        }

        wrapper.style.display = visible ? '' : 'none';

        var nodes = wrapper.querySelectorAll('input, select, textarea');
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].disabled = !visible;
        }
    }

    function conditionsFor(wrapper) {
        if (wrapper.__vlConditions === undefined) {
            var parsed = null;

            try {
                parsed = JSON.parse(wrapper.getAttribute('data-vl-conditions') || 'null');
            } catch (error) {
                parsed = null;
            }

            wrapper.__vlConditions = parsed && Array.isArray(parsed.rules) ? parsed : null;
        }

        return wrapper.__vlConditions;
    }

    function refresh(form) {
        var wrappers = form.querySelectorAll('[data-vl-conditions]');

        for (var i = 0; i < wrappers.length; i++) {
            apply(wrappers[i], form);
        }
    }

    function setup(form) {
        if (form.__vlConditionsReady) {
            refresh(form);
            return;
        }

        form.__vlConditionsReady = true;
        form.addEventListener('change', function () {
            refresh(form);
        });
        refresh(form);
    }

    function scan() {
        var forms = document.querySelectorAll('form');

        for (var i = 0; i < forms.length; i++) {
            if (forms[i].querySelector('[data-vl-conditions]')) {
                setup(forms[i]);
            }
        }
    }

    window.__vlFormConditionsScan = scan;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }
})();
