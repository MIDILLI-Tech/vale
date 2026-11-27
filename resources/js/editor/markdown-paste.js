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
// Markdown brings node types the editor schema does not have (task lists are
// the common one: "- [ ] item"). One unknown node used to make insertContent
// throw and demote the whole paste to plain text, so every node is mapped onto
// the schema first and unknown blocks are unwrapped to their content.
const NODE_ALIASES = {
    taskList: 'bulletList',
    taskItem: 'listItem',
};

const BLOCK_CONTAINERS = new Set(['doc', 'listItem', 'blockquote', 'tableCell', 'tableHeader']);

function wrapInlineRuns(children, schema) {
    const blocks = [];
    let run = [];

    const flush = () => {
        if (run.length) blocks.push({ type: 'paragraph', content: run });
        run = [];
    };

    for (const child of children) {
        const type = schema.nodes[child.type];
        if (type && type.isBlock) {
            flush();
            blocks.push(child);
        } else {
            run.push(child);
        }
    }
    flush();

    return blocks;
}

function normalizeNode(node, schema) {
    if (!node || typeof node !== 'object') return null;
    if (node.type === 'text') return node;

    const type = NODE_ALIASES[node.type] || node.type;
    const children = [];

    for (const child of node.content || []) {
        const normalized = normalizeNode(child, schema);
        if (Array.isArray(normalized)) children.push(...normalized);
        else if (normalized) children.push(normalized);
    }

    if (!schema.nodes[type]) {
        if (children.length) return children;
        return node.text ? { type: 'text', text: String(node.text) } : null;
    }

    const normalized = { ...node, type };
    let finalChildren = children;
    if (BLOCK_CONTAINERS.has(type)) finalChildren = wrapInlineRuns(children, schema);

    if (finalChildren.length) normalized.content = finalChildren;
    else delete normalized.content;

    return normalized;
}

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

        let content;
        try {
            const doc = markdown.parse(source);
            const nodes = Array.isArray(doc?.content) ? doc.content : Array.isArray(doc) ? doc : [];
            content = nodes.flatMap((node) => {
                const normalized = normalizeNode(node, editor.schema);
                return Array.isArray(normalized) ? normalized : normalized ? [normalized] : [];
            });
        } catch (error) {
            console.warn('[Vale] Markdown paste could not be parsed, inserted as plain text:', error);
            return false;
        }

        if (!content.length) return false;

        try {
            editor.commands.insertContent(content);
        } catch (error) {
            // A single leftover node must not demote the whole document: insert
            // what the schema accepts, one block at a time.
            let inserted = 0;
            for (const node of content) {
                try {
                    editor.commands.insertContent(node);
                    inserted++;
                } catch (nodeError) {
                    // Skip the offending block, keep the rest.
                }
            }
            if (!inserted) {
                console.warn('[Vale] Markdown paste failed, inserted as plain text:', error);
                return false;
            }
        }

        return true;
    };
}
