/**
 * Block duplication shared by all editor embed blocks.
 *
 * The clone keeps attrs + content as-is (no "(copy)" suffix); referenced
 * resources (form slugs, image URLs, page links) are safe to share.
 */

export function duplicateNodeAtPos(editor, pos) {
  if (typeof pos !== 'number') return false;
  const node = editor.state.doc.nodeAt(pos);
  if (!node || node.type.name === 'doc' || node.isText) return false;
  const copy = node.type.create(node.attrs, node.content, node.marks);
  editor.view.dispatch(editor.state.tr.insert(pos + node.nodeSize, copy));
  return true;
}

export function duplicateSelectedBlock(editor) {
  const { selection } = editor.state;
  if (selection.node) {
    return duplicateNodeAtPos(editor, selection.from);
  }
  const $from = selection.$from;
  if ($from.depth === 0) return false;
  return duplicateNodeAtPos(editor, $from.before(1));
}

export function makeDuplicateButton(editor, getPos) {
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.innerHTML = '⧉';
  btn.title = tjs('Duplicate block');
  btn.dataset.duplicate = '1';
  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    duplicateNodeAtPos(editor, getPos());
  });
  return btn;
}
