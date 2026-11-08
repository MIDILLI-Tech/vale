import { CodeJar } from 'codejar'
import Prism from 'prismjs'
import 'prismjs/components/prism-markup-templating.js'
import 'prismjs/components/prism-twig.js'

// Simple Prism line numbers generator for CodeJar
function withLineNumbers(highlight) {
    const countLines = (editor) => (editor.textContent || '').split('\n').length;

    const paintGutter = (editor, gutter) => {
        // Count logical lines from text (immune to Prism markup).
        const lineCount = countLines(editor);
        let gutterHtml = '';
        for (let i = 1; i <= lineCount; i++) {
            gutterHtml += `<div>${i}</div>`;
        }
        gutter.innerHTML = gutterHtml;
        // Re-anchor on every repaint: content switches or layout
        // changes (e.g. fullscreen) can clamp the editor scroll
        // without firing a scroll event, leaving a stale offset.
        gutter.style.transform = `translateY(${-editor.scrollTop}px)`;
        window.console?.log?.('[codejar-gutter] painted lines=' + lineCount);
    };

    const syncGutter = (editor, gutter) => {
        // Self-heal: repaint when the gutter line count drifted from the
        // editor content (stale/partial fill), then re-anchor.
        if (gutter.childElementCount !== countLines(editor)) {
            paintGutter(editor, gutter);
        } else {
            gutter.style.transform = `translateY(${-editor.scrollTop}px)`;
        }
    };

    return (editor) => {
        highlight(editor);
        // If line numbering overlay is enabled, update gutter
        const container = editor.parentElement;
        if (container) {
            const gutter = container.querySelector('.codejar-gutter');
            if (gutter) {
                paintGutter(editor, gutter);

                // Sync gutter scroll with editor scroll. translateY is used
                // instead of scrollTop: the gutter box has no definite
                // height in normal (non-fullscreen) mode, so it is not
                // programmatically scrollable — transform always works.
                if (!gutter._scrollSynced) {
                    gutter._scrollSynced = true;
                    editor.addEventListener('scroll', () => syncGutter(editor, gutter));
                }
            }
        }
    };
}

export function initTwigEditor(element, options = {}) {
    if (!element) return null;

    const highlight = (editor) => {
        const code = editor.textContent;
        editor.innerHTML = Prism.highlight(code, Prism.languages.twig || Prism.languages.markup, 'twig');
    };

    const jar = CodeJar(element, withLineNumbers(highlight), {
        tab: '  ',
        catchTab: true,
        ...options
    });

    // CodeJar forces pre-wrap + break-word (long lines wrap, breaking the
    // 1:1 mapping between gutter numbers and visual rows). Code editors
    // scroll horizontally instead — our CSS already provides overflow-x.
    element.style.whiteSpace = 'pre';
    element.style.overflowWrap = 'normal';

    let lastPos = null;

    const updatePos = () => {
        const sel = window.getSelection();
        if (sel && sel.rangeCount > 0) {
            const range = sel.getRangeAt(0);
            if (element.contains(range.commonAncestorContainer)) {
                try {
                    const p = jar.save();
                    if (p) lastPos = { start: p.start, end: p.end };
                } catch (e) {}
            }
        }
    };

    element.addEventListener('keyup', updatePos);
    element.addEventListener('mouseup', updatePos);
    element.addEventListener('focus', updatePos);

    jar.insertAtCursor = (text) => {
        let pos = null;
        const sel = window.getSelection();
        if (sel && sel.rangeCount > 0) {
            const range = sel.getRangeAt(0);
            if (element.contains(range.commonAncestorContainer)) {
                try {
                    pos = jar.save();
                } catch (e) {
                    pos = null;
                }
            }
        }

        const currentCode = jar.toString() || '';
        if (!pos && lastPos && typeof lastPos.start === 'number') {
            pos = {
                start: Math.min(lastPos.start, currentCode.length),
                end: Math.min(lastPos.end, currentCode.length)
            };
        }

        if (!pos) {
            pos = { start: currentCode.length, end: currentCode.length };
        }

        const start = Math.min(pos.start, pos.end);
        const end = Math.max(pos.start, pos.end);

        const newCode = currentCode.substring(0, start) + text + currentCode.substring(end);
        jar.updateCode(newCode);

        const newCursor = start + text.length;
        lastPos = { start: newCursor, end: newCursor };

        element.focus();
        try {
            jar.restore({ start: newCursor, end: newCursor, dir: '->' });
        } catch (e) {}
    };

    return jar;
}

window.initTwigEditor = initTwigEditor;
