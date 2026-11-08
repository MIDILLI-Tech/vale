/**
 * Shortcode paste handler.
 *
 * The Tiptap schema only knows embed blocks as HTML atoms
 * (parseHTML: ['div[data-form-slug]']), so a pasted or typed
 * [form slug="..."] stays plain text inside the editor. The
 * shortcodeToFormEmbed() pipeline only runs when the whole document is
 * re-parsed — at mount, HTML-mode toggle and copy-from-original — so a
 * pasted shortcode needed a save+reload (or a source-code round-trip) to
 * become a visual block.
 *
 * This handler closes that gap: when the clipboard carries a known
 * shortcode, it runs the same converters and inserts the resulting nodes
 * immediately. [form slug="contact"], [toc], [product id="5"], …
 * become visual blocks right after the paste; prose text, rich HTML and
 * unknown bracketed text keep the default paste behavior.
 */
import { htmlToSpacer, htmlToVideoEmbed, resolveFormNames, shortcodeToFormEmbed, shortcodeToVideoEmbed } from './converters.js';

// Plain-text clipboard data is not HTML — escape &, < and > before the
// converters run so they survive the DOMParser round-trip. Quotes are left
// as-is: they are legal inside <p> text, and the shortcode regexes rely on
// literal " as attribute delimiters ([^"]* spans across &quot;).
// Already-encoded entities such as &quot; are preserved, not double-escaped.
// Blank lines keep their paragraph breaks, single newlines become <br>.
function plainTextToHtml(text) {
  const escaped = text
    .replace(/&(?![a-zA-Z#][a-zA-Z0-9]*;)/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
  return escaped
    .split(/\n{2,}/)
    .map(block => `<p>${block.replace(/\r?\n/g, '<br>')}</p>`)
    .join('');
}

// Returns the converted HTML, or null when no known shortcode is present.
function convertShortcodes(source, isPlainText) {
  if (!source) return null;
  const html = isPlainText ? plainTextToHtml(source) : source;
  if (!html.includes('[')) return null;
  const processed = htmlToSpacer(htmlToVideoEmbed(shortcodeToVideoEmbed(shortcodeToFormEmbed(html))));
  return processed !== html ? processed : null;
}

export function createShortcodePasteHandler(getEditor) {
  return (view, event) => {
    // Shift+paste means "paste as plain text" — leave it raw.
    if (event.shiftKey) return false;
    const editor = getEditor();
    if (!editor || !event.clipboardData) return false;

    // Prefer the richest representation first, like the default paste:
    // text/html keeps the rest of the pasted content formatted.
    const html = event.clipboardData.getData('text/html');
    const text = event.clipboardData.getData('text/plain') || '';

    let processed = convertShortcodes(html, false);
    if (processed === null) processed = convertShortcodes(text, true);
    if (processed === null) return false; // no known shortcode — default paste

    editor.commands.insertContent(processed).run();
    resolveFormNames(editor); // live form titles, same as editor mount
    return true; // returning true stops ProseMirror's default paste
  };
}
