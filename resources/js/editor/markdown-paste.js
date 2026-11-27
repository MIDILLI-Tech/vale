/**
 * Markdown paste handler.
 *
 * Tiptap input rules only fire while typing, so a pasted "# Heading", "- item"
 * or "> quote" used to stay literal text. When the clipboard carries plain text
 * that looks like Markdown, parse it with the Markdown extension and insert real
 * nodes. Rich HTML, shortcodes and plain prose keep the default paste path.
 */

// Markdown constructs. One of these is enough to treat the paste as Markdown:
// they do not appear in ordinary prose, so a false positive is unlikely.
const SIGNALS = {
    heading: /^#{1,6}[ \t]+\S/m,
    bullet: /^[ \t]*[-*+][ \t]+\S/m,
    ordered: /^[ \t]*\d+[.)][ \t]+\S/m,
    quote: /^[ \t]*>[ \t]?\S/m,
    fence: /^```/m,
    rule: /^(?:---+|\*\*\*+|___+)[ \t]*$/m,
    link: /\[[^\]\n]+\]\([^)\s]+\)/,
};

// Markup that carries its own block formatting — that paste may keep its HTML.
// Inline wrappers are deliberately absent: a chat message or docs page hands
// over <p>/<a>/<br> around *literal* Markdown ("<p># Heading</p>",
// "[site](https://site)"), so paragraphs alone must not block Markdown parsing.
// <pre>/<code> are absent too — that is how browsers expose a copied code block,
// and Markdown inside it still wins over inserting a literal code block.
const STRUCTURED_HTML = /<(?:h[1-6]|ul|ol|li|blockquote|table|figure|img|hr|dl)\b/i;

function detectSignals(text) {
    const found = new Set();
    for (const [name, pattern] of Object.entries(SIGNALS)) {
        if (pattern.test(text)) found.add(name);
    }
    return found;
}

// The clipboard can carry a rendered document (headings as <h2>, bullets as
// <li>) or a mix of rendered blocks and literal Markdown left as text. Only the
// first is a real conversion of the pasted Markdown — in the second the Markdown
// text still wins, otherwise "## Title" would arrive as a paragraph.
function rendersMarkdown(html) {
    if (!STRUCTURED_HTML.test(html)) return false;
    const parsed = new DOMParser().parseFromString(html, 'text/html');
    const text = parsed.body ? parsed.body.textContent || '' : '';
    return !detectSignals(text).size;
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
        if (html && rendersMarkdown(html)) return false;

        const source = stripFrontmatter(text).trim();
        if (!detectSignals(source).size) return false;

        try {
            const doc = markdown.parse(source);
            const content = doc.content ?? doc;
            if (!content || !content.length) return false;
            editor.commands.insertContent(content);
        } catch (error) {
            return false;
        }
        return true;
    };
}
