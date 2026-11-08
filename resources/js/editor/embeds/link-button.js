/**
 * Single editable link button (leaf node).
 *
 * Lives inside a LinkButtonGroup block. It renders only the visual button with
 * inline-editable text; the group owns the header, gear, add/delete and
 * drag-to-reorder. Spacing between buttons is handled by the group via `gap`,
 * so this block carries no outer margin of its own.
 */
import { Node, mergeAttributes } from '@tiptap/core';

const LinkButton = Node.create({
  name: 'linkButton',
  group: 'block',
  content: 'inline*',
  selectable: true,
  draggable: false,

  addAttributes() {
    return {
      href: {
        default: '',
        parseHTML: el => el.getAttribute('data-href') || el.getAttribute('href') || '',
        renderHTML: attrs => ({ 'data-href': attrs.href }),
      },
      target: {
        default: '_self',
        parseHTML: el => el.getAttribute('data-target') || el.getAttribute('target') || '_self',
        renderHTML: attrs => ({ 'data-target': attrs.target }),
      },
      internalType: {
        default: '',
        parseHTML: el => el.getAttribute('data-internal-type') || '',
        renderHTML: attrs => (attrs.internalType ? { 'data-internal-type': attrs.internalType } : {}),
      },
      internalId: {
        default: '',
        parseHTML: el => el.getAttribute('data-internal-id') || '',
        renderHTML: attrs => (attrs.internalId ? { 'data-internal-id': attrs.internalId } : {}),
      },
      bgColor: {
        default: '#000000',
        parseHTML: el => el.getAttribute('data-bg-color') || el.style.backgroundColor || '#000000',
        renderHTML: attrs => ({ 'data-bg-color': attrs.bgColor }),
      },
      textColor: {
        default: '#ffffff',
        parseHTML: el => el.getAttribute('data-text-color') || el.style.color || '#ffffff',
        renderHTML: attrs => ({ 'data-text-color': attrs.textColor }),
      },
      borderWidth: {
        default: '0px',
        parseHTML: el => el.getAttribute('data-border-width') || el.style.borderWidth || '0px',
        renderHTML: attrs => ({ 'data-border-width': attrs.borderWidth }),
      },
      borderStyle: {
        default: 'none',
        parseHTML: el => el.getAttribute('data-border-style') || el.style.borderStyle || 'none',
        renderHTML: attrs => ({ 'data-border-style': attrs.borderStyle }),
      },
      borderColor: {
        default: '#000000',
        parseHTML: el => el.getAttribute('data-border-color') || el.style.borderColor || '#000000',
        renderHTML: attrs => ({ 'data-border-color': attrs.borderColor }),
      },
      borderRadius: {
        default: '4px',
        parseHTML: el => el.getAttribute('data-border-radius') || el.style.borderRadius || '4px',
        renderHTML: attrs => ({ 'data-border-radius': attrs.borderRadius }),
      },
      padding: {
        default: '10px 20px',
        parseHTML: el => el.getAttribute('data-padding') || el.style.padding || '10px 20px',
        renderHTML: attrs => ({ 'data-padding': attrs.padding }),
      },
      alignment: {
        default: 'center',
        parseHTML: el => el.getAttribute('data-alignment') || 'center',
        renderHTML: attrs => ({ 'data-alignment': attrs.alignment }),
      },
      fullWidth: {
        default: 'false',
        parseHTML: el => el.getAttribute('data-full-width') || 'false',
        renderHTML: attrs => ({ 'data-full-width': attrs.fullWidth }),
      }
    };
  },

  parseHTML() {
    return [{
      tag: 'div[data-link-button]',
      getAttrs(dom) {
        return {
          href: dom.getAttribute('data-href') || '',
          target: dom.getAttribute('data-target') || '_self',
          bgColor: dom.getAttribute('data-bg-color') || '#000000',
          textColor: dom.getAttribute('data-text-color') || '#ffffff',
          borderWidth: dom.getAttribute('data-border-width') || '0px',
          borderStyle: dom.getAttribute('data-border-style') || 'none',
          borderColor: dom.getAttribute('data-border-color') || '#000000',
          borderRadius: dom.getAttribute('data-border-radius') || '4px',
          padding: dom.getAttribute('data-padding') || '10px 20px',
          alignment: dom.getAttribute('data-alignment') || 'center',
          fullWidth: dom.getAttribute('data-full-width') || 'false',
        };
      }
    }];
  },

  renderHTML({ HTMLAttributes }) {
    const align = HTMLAttributes.alignment || 'center';
    const fullWidth = HTMLAttributes.fullWidth;
    let justify = 'center';
    if (fullWidth === 'true') justify = 'stretch';
    else if (align === 'left') justify = 'flex-start';
    else if (align === 'right') justify = 'flex-end';
    return ['div', mergeAttributes(HTMLAttributes, {
      'data-link-button': '1',
      class: 'vl-link-button-block',
      style: `display:flex;width:100%;justify-content:${justify}`
    }), 0];
  },

  addNodeView() {
    return ({ node, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-link-button-block';
      dom.setAttribute('data-link-button', '1');

      const body = document.createElement('div');
      body.className = 'vl-link-button-body';
      body.style.display = 'flex';

      function applyAlignmentAndWidth() {
        if (node.attrs.fullWidth === 'true') {
          body.style.justifyContent = 'stretch';
        } else {
          const align = node.attrs.alignment || 'center';
          if (align === 'left') body.style.justifyContent = 'flex-start';
          else if (align === 'right') body.style.justifyContent = 'flex-end';
          else body.style.justifyContent = 'center';
        }
      }
      applyAlignmentAndWidth();

      const button = document.createElement('div');
      button.className = 'vl-editable-button';
      button.style.backgroundColor = node.attrs.bgColor || '#000000';
      button.style.color = node.attrs.textColor || '#ffffff';
      button.style.borderWidth = node.attrs.borderWidth || '0px';
      button.style.borderStyle = node.attrs.borderStyle || 'none';
      button.style.borderColor = node.attrs.borderColor || '#000000';
      button.style.borderRadius = node.attrs.borderRadius || '4px';
      button.style.padding = node.attrs.padding || '10px 20px';
      button.style.fontFamily = 'inherit';
      button.style.fontSize = '14px';
      button.style.fontWeight = '500';
      button.style.textDecoration = node.attrs.href ? 'underline' : 'none';
      button.style.textAlign = 'center';
      button.style.cursor = 'text';
      button.style.userSelect = 'text';

      if (node.attrs.fullWidth === 'true') {
        button.style.width = '100%';
        button.style.display = 'block';
      } else {
        button.style.width = 'auto';
        button.style.display = 'inline-block';
      }

      const contentDOM = button;

      body.appendChild(button);
      dom.appendChild(body);

      return {
        dom,
        contentDOM,
        stopEvent(event) {
          return false;
        },
        ignoreMutation() {
          return true;
        },
        update(updatedNode) {
          if (updatedNode.type.name !== 'linkButton') return false;
          node = updatedNode;

          button.style.backgroundColor = node.attrs.bgColor || '#000000';
          button.style.color = node.attrs.textColor || '#ffffff';
          button.style.borderWidth = node.attrs.borderWidth || '0px';
          button.style.borderStyle = node.attrs.borderStyle || 'none';
          button.style.borderColor = node.attrs.borderColor || '#000000';
          button.style.borderRadius = node.attrs.borderRadius || '4px';
          button.style.padding = node.attrs.padding || '10px 20px';
          button.style.textDecoration = node.attrs.href ? 'underline' : 'none';

          if (node.attrs.fullWidth === 'true') {
            button.style.width = '100%';
            button.style.display = 'block';
          } else {
            button.style.width = 'auto';
            button.style.display = 'inline-block';
          }

          applyAlignmentAndWidth();
          return true;
        }
      };
    };
  }
});

export { LinkButton };
