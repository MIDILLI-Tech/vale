/**
 * Markdown paste handler.
 *
 * Tiptap input rules only fire while typing, so a pasted "# Heading", "- item"
 * or "> quote" used to stay literal text. When the clipboard carries plain text
 * that looks like Markdown, parse it with the Markdown extension and insert real
 * nodes. Rich HTML, shortcodes and plain prose keep the default paste path.
 */

const SIGNALS = {
    heading: /^#{1,6}[ \t]+\S/m,
    bullet: /^[ \t]*[-*+][ \t]+\S/m,
    ordered: /^[ \t]*\d+[.)][ \t]+\S/m,
    quote: /^[ \t]*>[ \t]?\S/m,
    fence: /^```/m,
    rule: /^(?:---+|\*\*\*+|___+)[ \t]*$/m,
    bold: /\*\*[^*\n]+\*\*/,
};

// Real markup from a rendered page — that paste should keep its HTML formatting.
// A bare <pre>/<code> wrapper is how browsers expose a copied code block, so
// Markdown inside it still wins over inserting a literal code block.
const RICH_HTML = /<(?:p|h[1-6]|ul|ol|li|blockquote|strong|em|table|img|figure)\b/i;

function detectSignals(text) {
    const found = new Set();
    for (const [name, pattern] of Object.entries(SIGNALS)) {
        if (pattern.test(text)) found.add(name);
    }
    return found;
}

// Blog posts are often copied with a YAML header ("---\ntitle: …\n---").
// Drop it instead of pasting metadata as paragraphs.
function stripFrontmatter(text) {
    const match = text.match(/^---\r?\n([\s\S]*?)\r?\n---[ \t]*(?:\r?\n|$)/);
    if (!match) return text;
    if (!/^[A-Za-z_][\w-]*[ \t]*:/m.test(match[1])) return text;
    return text.slice(match[0].length);
}

export function createMarkdownPasteHandler(getEditor) {
    return (view, event) => {
        if (event.shiftKey) return false;
        const editor = getEditor();
        if (!editor || !event.clipboardData) return false;
        const markdown = editor.storage?.markdown?.manager;
        if (!markdown?.parse) return false;
        if (editor.isActive('codeBlock')) return false;

        const text = event.clipboardData.getData('text/plain') || '';
        if (!text.trim()) return false;

        const html = event.clipboardData.getData('text/html') || '';
        if (html && RICH_HTML.test(html)) return false;

        const source = stripFrontmatter(text).trim();
        const signals = detectSignals(source);
        const isMarkdown = signals.size >= 2 || (signals.size === 1 && signals.has('heading'));
        if (!isMarkdown) return false;

        try {
            const doc = markdown.parse(source);
            editor.commands.insertContent(doc.content ?? doc);
        } catch (error) {
            return false;
        }
        return true;
    };
}
