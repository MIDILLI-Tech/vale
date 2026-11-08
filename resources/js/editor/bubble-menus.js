/**
 * Bubble menu presets and builders (table + text selection).
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { BubbleMenu } from '@tiptap/extension-bubble-menu';

import { openCellProperties, openTableProperties } from './table-controls.js';
import { openLinkDialog } from './ui/link-dialog.js';

const TableBubbleMenu = BubbleMenu.extend({
  name: 'tableBubbleMenu',
});

const TextBubbleMenu = BubbleMenu.extend({
  name: 'textBubbleMenu',
});

function buildTableBubbleMenu(wrapper) {
  const menu = document.createElement('div');
  menu.className = 'vl-bubble-menu vl-table-bubble-menu';

  const render = (editor) => {
    menu.innerHTML = '';
    const items = [
      { icon: '⊞↑', title: tjs('Add Row Above'), action: () => editor.chain().focus().addRowBefore().run() },
      { icon: '⊞↓', title: tjs('Add Row Below'), action: () => editor.chain().focus().addRowAfter().run() },
      { icon: '⊟',  title: tjs('Delete Row'), action: () => editor.chain().focus().deleteRow().run() },
      null,
      { icon: '⊞←', title: tjs('Add Col Left'), action: () => editor.chain().focus().addColumnBefore().run() },
      { icon: '⊞→', title: tjs('Add Col Right'), action: () => editor.chain().focus().addColumnAfter().run() },
      { icon: '◫',  title: tjs('Delete Col'), action: () => editor.chain().focus().deleteColumn().run() },
      null,
      editor.can().mergeCells()
        ? { icon: '⛶', title: tjs('Merge Cells'), action: () => editor.chain().focus().mergeCells().run() }
        : null,
      editor.can().splitCell()
        ? { icon: '⧉', title: tjs('Split Cell'), action: () => editor.chain().focus().splitCell().run() }
        : null,
      { icon: '▦', title: tjs('Cell properties'), action: () => openCellProperties(editor) },
      null,
      { icon: '⚙', title: tjs('Properties'), action: () => openTableProperties(editor) },
      { icon: '✕', title: tjs('Delete Table'), action: () => editor.chain().focus().deleteTable().run() },
    ].filter((item, index, all) => item !== null || (index > 0 && all[index - 1] !== null));

    items.forEach(item => {
      if (item === null) {
        const sep = document.createElement('div');
        sep.className = 'vl-bubble-menu-sep';
        menu.appendChild(sep);
        return;
      }
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'vl-bubble-menu-btn';
      btn.innerHTML = `<span class="vl-bubble-icon">${item.icon}</span>`;
      btn.title = item.title;
      btn.addEventListener('mousedown', (e) => {
        e.preventDefault();
        e.stopPropagation();
        item.action();
      });
      menu.appendChild(btn);
    });
  };

  return { el: menu, render };
}

function buildTextBubbleMenu(wrapper) {
  const menu = document.createElement('div');
  menu.className = 'vl-bubble-menu vl-text-bubble-menu';

  // Inside the Media+Text contentEditable column the selection is not part of
  // the ProseMirror doc — apply native formatting commands there instead.
  const inMediaText = () => {
    const el = document.activeElement;
    return !!(el && el.closest && el.closest('[data-media-text]') && el.isContentEditable);
  };
  const mediaTextCmd = (cmd) => {
    if (!inMediaText()) return false;
    document.execCommand(cmd, false, null);
    return true;
  };

  const render = (editor) => {
    menu.innerHTML = '';

    // A link button is a single link itself — nested <a> links are forbidden
    // inside it, so omit the Link action from the bubble menu there.
    const $from = editor.state?.selection?.$from;
    const inLinkButton = (() => {
      if (!$from) return false;
      for (let d = $from.depth; d >= 0; d--) {
        if ($from.node(d).type.name === 'linkButton') return true;
      }
      return false;
    })();

    const items = [
      { icon: 'B', title: tjs('Bold'), action: () => { if (mediaTextCmd('bold')) return; editor.chain().focus().toggleBold().run(); }, active: () => inMediaText() ? document.queryCommandState('bold') : editor.isActive('bold') },
      { icon: 'I', title: tjs('Italic'), action: () => { if (mediaTextCmd('italic')) return; editor.chain().focus().toggleItalic().run(); }, active: () => inMediaText() ? document.queryCommandState('italic') : editor.isActive('italic') },
      { icon: 'U', title: tjs('Underline'), action: () => { if (mediaTextCmd('underline')) return; editor.chain().focus().toggleUnderline().run(); }, active: () => inMediaText() ? document.queryCommandState('underline') : editor.isActive('underline') },
      { icon: '•', title: tjs('Bullet List'), action: () => { if (mediaTextCmd('insertUnorderedList')) return; editor.chain().focus().toggleBulletList().run(); }, active: () => inMediaText() ? document.queryCommandState('insertUnorderedList') : editor.isActive('bulletList') },
      { icon: '1.', title: tjs('Numbered List'), action: () => { if (mediaTextCmd('insertOrderedList')) return; editor.chain().focus().toggleOrderedList().run(); }, active: () => inMediaText() ? document.queryCommandState('insertOrderedList') : editor.isActive('orderedList') },
    ];
    if (!inLinkButton) {
      items.push({ icon: '🔗', title: tjs('Link'), action: () => openLinkDialog(editor), active: () => editor.isActive('link') });
    }

    items.forEach(item => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'vl-bubble-menu-btn' + (item.active() ? ' is-active' : '');
      btn.innerHTML = `<span class="vl-bubble-icon">${item.icon}</span>`;
      btn.title = item.title;
      btn.addEventListener('mousedown', (e) => {
        e.preventDefault();
        e.stopPropagation();
        item.action();
      });
      menu.appendChild(btn);
    });
  };

  return { el: menu, render };
}

export { TableBubbleMenu, TextBubbleMenu, buildTableBubbleMenu, buildTextBubbleMenu };
