/**
 * Card item (leaf node).
 *
 * Lives inside a CardGroup block. Carries the card image/link/background as
 * attrs and two inline-editable text children (cardTitle + cardDesc), so the
 * toolbar marks (bold, italic, links) keep working on both texts.
 */
import { Node, mergeAttributes } from '@tiptap/core';

import { openMediaPickerExtended } from '../media/browse.js';
import { toEmbedUrl } from '../utils.js';

const DEFAULT_CARD_ATTRS = {
  image: '',
  link: '',
  target: '_self',
  bg: '',
  autoplay: 'false',
  fit: 'cover',
};

function defaultCardContent() {
  return {
    type: 'cardItem',
    attrs: { ...DEFAULT_CARD_ATTRS },
    content: [
      { type: 'cardTitle', content: [{ type: 'text', text: tjs('Card Title') }] },
      { type: 'cardDesc', content: [{ type: 'text', text: tjs('Card description') }] },
    ],
  };
}

function setCardAttr(editor, pos, key, value) {
  const n = editor?.state?.doc?.nodeAt(pos);
  if (!n) return;
  const scrollY = window.scrollY;
  editor.chain().focus().command(({ tr }) => {
    tr.setNodeMarkup(pos, undefined, { ...n.attrs, [key]: value });
    return true;
  }).run();
  editor.view.dom.focus({ preventScroll: true });
  requestAnimationFrame(() => window.scrollTo(0, scrollY));
}

/**
 * Move the card at pos one sibling up (dir -1) or down (dir +1).
 */
function moveCard(editor, pos, dir) {
  const doc = editor?.state?.doc;
  if (!doc) return;
  const $pos = doc.resolve(pos);
  const parent = $pos.parent;
  if (!parent || parent.type.name !== 'cardGroup') return;
  const index = $pos.index();
  const target = index + dir;
  if (target < 0 || target >= parent.childCount) return;
  const node = parent.child(index);
  const sibling = parent.child(target);
  const tr = editor.state.tr;
  tr.delete(pos, pos + node.nodeSize);
  tr.insert(dir < 0 ? pos - sibling.nodeSize : pos + sibling.nodeSize, node);
  const scrollY = window.scrollY;
  editor.view.dispatch(tr);
  editor.view.dom.focus({ preventScroll: true });
  requestAnimationFrame(() => window.scrollTo(0, scrollY));
}

const CardTitle = Node.create({
  name: 'cardTitle',
  group: 'block',
  content: 'inline*',
  defining: true,

  parseHTML() {
    return [{ tag: 'div[data-card-title]' }];
  },

  renderHTML({ HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'data-card-title': '1',
      class: 'vl-card-title',
    }), 0];
  },
});

const CardDesc = Node.create({
  name: 'cardDesc',
  group: 'block',
  content: 'inline*',
  defining: true,

  parseHTML() {
    return [{ tag: 'div[data-card-desc]' }];
  },

  renderHTML({ HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'data-card-desc': '1',
      class: 'vl-card-desc',
    }), 0];
  },
});

const CardItem = Node.create({
  name: 'cardItem',
  group: 'block',
  content: 'cardTitle cardDesc',
  selectable: true,
  draggable: false,

  addAttributes() {
    return {
      image: {
        default: '',
        parseHTML: el => el.getAttribute('data-image') || '',
        renderHTML: attrs => ({ 'data-image': attrs.image }),
      },
      link: {
        default: '',
        parseHTML: el => el.getAttribute('data-link') || '',
        renderHTML: attrs => ({ 'data-link': attrs.link }),
      },
      target: {
        default: '_self',
        parseHTML: el => el.getAttribute('data-target') || '_self',
        renderHTML: attrs => ({ 'data-target': attrs.target }),
      },
      bg: {
        default: '',
        parseHTML: el => el.getAttribute('data-bg') || '',
        renderHTML: attrs => ({ 'data-bg': attrs.bg }),
       },
       autoplay: {
         default: 'false',
         parseHTML: el => el.getAttribute('data-autoplay') || 'false',
         renderHTML: attrs => ({ 'data-autoplay': attrs.autoplay }),
      },
      fit: {
         default: 'cover',
         parseHTML: el => el.getAttribute('data-fit') || 'cover',
         renderHTML: attrs => ({ 'data-fit': attrs.fit }),
      },
    };
  },

  parseHTML() {
    return [{
      tag: 'div[data-card-item]',
      getAttrs(dom) {
        return {
          image: dom.getAttribute('data-image') || '',
          link: dom.getAttribute('data-link') || '',
          target: dom.getAttribute('data-target') || '_self',
          bg: dom.getAttribute('data-bg') || '',
          autoplay: dom.getAttribute('data-autoplay') || 'false',
          fit: dom.getAttribute('data-fit') || 'cover',
        };
      },
    }];
  },

  renderHTML({ node, HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'data-card-item': '1',
      class: 'vl-card-item-block',
      'data-autoplay': node.attrs.autoplay || 'false',
    }), 0];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-card-item-block';
      dom.setAttribute('data-card-item', '1');

      // Mini toolbar: move up/down, delete card. Image pick happens by
      // clicking the image slot itself (placeholder text or current image).
      const tools = document.createElement('div');
      tools.className = 'vl-card-item-tools';
      tools.contentEditable = 'false';

      const upBtn = document.createElement('button');
      upBtn.type = 'button';
      upBtn.textContent = '↑';
      upBtn.title = tjs('Move card up');
      upBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        moveCard(editor, getPos(), -1);
      });
      tools.appendChild(upBtn);

      const downBtn = document.createElement('button');
      downBtn.type = 'button';
      downBtn.textContent = '↓';
      downBtn.title = tjs('Move card down');
      downBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        moveCard(editor, getPos(), 1);
      });
      tools.appendChild(downBtn);

      const delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'vl-card-del-btn';
      delBtn.textContent = '✕';
      delBtn.title = tjs('Delete card');
      delBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const pos = getPos();
        const $pos = editor.state.doc.resolve(pos);
        const parent = $pos.parent;
        // Deleting the last card removes the whole group instead of
        // leaving an empty (schema-invalid) group behind.
        const scrollY = window.scrollY;
        if (parent && parent.type.name === 'cardGroup' && parent.childCount <= 1) {
          const groupPos = $pos.before($pos.depth);
          const g = editor.state.doc.nodeAt(groupPos);
          editor.commands.deleteRange({ from: groupPos, to: groupPos + (g ? g.nodeSize : 0) });
          requestAnimationFrame(() => window.scrollTo(0, scrollY));
          return;
        }
        editor.commands.deleteRange({ from: pos, to: pos + node.nodeSize });
        requestAnimationFrame(() => window.scrollTo(0, scrollY));
      });
      tools.appendChild(delBtn);
      dom.appendChild(tools);

      const imgWrap = document.createElement('div');
      imgWrap.className = 'vl-card-image-wrap';
      imgWrap.contentEditable = 'false';
      imgWrap.style.cursor = 'pointer';
      imgWrap.title = tjs('Click to add or replace media');
      imgWrap.addEventListener('click', (e) => {
        e.stopPropagation();
        openMediaPickerExtended(editor, (url) => {
          setCardAttr(editor, getPos(), 'image', url || '');
        }, 'image/', node.attrs.image || '');
      });

      const rmImgBtn = document.createElement('button');
      rmImgBtn.type = 'button';
      rmImgBtn.className = 'vl-card-remove-img';
      rmImgBtn.textContent = '✕';
      rmImgBtn.title = tjs('Remove media');
      rmImgBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        setCardAttr(editor, getPos(), 'image', '');
      });
      imgWrap.appendChild(rmImgBtn);
      dom.appendChild(imgWrap);

      const linkBadge = document.createElement('div');
      linkBadge.className = 'vl-card-link-badge';
      linkBadge.contentEditable = 'false';
      dom.appendChild(linkBadge);

      const body = document.createElement('div');
      body.className = 'vl-card-item-body';
      dom.appendChild(body);

      function renderCard() {
        imgWrap.querySelectorAll('img, video, span, iframe').forEach(el => el.remove());
        imgWrap.style.aspectRatio = '';
        const isVid = node.attrs.image && (/\.(mp4|webm|ogg|mov|m4v)(\?|$)/i.test(node.attrs.image) || node.attrs.image.includes('youtube.com') || node.attrs.image.includes('youtu.be') || node.attrs.image.includes('vimeo.com'));
        if (node.attrs.image) {
          if (isVid && !node.attrs.image.includes('youtube.com') && !node.attrs.image.includes('youtu.be') && !node.attrs.image.includes('vimeo.com')) {
            const vid = document.createElement('video');
            vid.src = node.attrs.image;
            vid.muted = true;
            vid.playsInline = true;
            vid.style.width = '100%';
            vid.style.height = 'auto';
            vid.style.objectFit = node.attrs.fit || 'cover';
            vid.style.display = 'block';
            vid.style.margin = '0';
            // Videos follow the media's intrinsic ratio instead of the fixed
            // 16/9 slot, so contain never letterboxes them.
            imgWrap.style.aspectRatio = 'auto';
            if (node.attrs.autoplay === 'true') { vid.autoplay = true; } else { vid.controls = true; }
            imgWrap.appendChild(vid);
          } else if (isVid) {
            const iframe = document.createElement('iframe');
            iframe.src = toEmbedUrl(node.attrs.image, node.attrs.autoplay === 'true' || node.attrs.autoplay === true);
            iframe.style.width = '100%';
            iframe.style.height = '100%';
            iframe.style.border = 'none';
            iframe.allow = 'autoplay; fullscreen';
            imgWrap.appendChild(iframe);
          } else {
            const img = document.createElement('img');
            img.src = node.attrs.image;
            img.alt = '';
            img.style.width = '100%';
            img.style.height = '100%';
            img.style.objectFit = node.attrs.fit || 'cover';
            img.style.display = 'block';
            let groupLb = false;
            try {
              const pos = typeof getPos === 'function' ? getPos() : -1;
              if (pos >= 0) {
                const $p = editor.state.doc.resolve(pos);
                for (let d = $p.depth; d > 0; d--) {
                  const pn = $p.node(d);
                  if (pn.type.name === 'cardGroup') { groupLb = pn.attrs.lightbox === 'true' || pn.attrs.lightbox === true; break; }
                }
              }
            } catch (e) { groupLb = false; }
            if (groupLb) {
              img.setAttribute('data-lightbox', 'true');
              img.setAttribute('data-lightbox-src', node.attrs.image);
              img.style.cursor = 'zoom-in';
              img.title = tjs('Click to enlarge');
            }
            imgWrap.appendChild(img);
          }
          rmImgBtn.style.display = '';
        } else {
          const ph = document.createElement('span');
          ph.textContent = tjs('Click here to add media');
          imgWrap.appendChild(ph);
          rmImgBtn.style.display = 'none';
        }
        if (node.attrs.link) {
          linkBadge.textContent = `🔗 ${node.attrs.link}`;
          linkBadge.style.display = '';
        } else {
          linkBadge.textContent = '';
          linkBadge.style.display = 'none';
        }
        dom.style.background = node.attrs.bg || 'transparent';
      }
      renderCard();

      return {
        dom,
        contentDOM: body,
        stopEvent(event) {
          const t = event.target;
          return t === upBtn || t === downBtn || t === delBtn || t === rmImgBtn
            || Boolean(t?.closest?.('.vl-card-item-tools'))
            || Boolean(imgWrap.contains(t));
        },
        ignoreMutation() {
          return true;
        },
        update(updatedNode) {
          if (updatedNode.type.name !== 'cardItem') return false;
          node = updatedNode;
          renderCard();
          return true;
        },
      };
    };
  },
});

export { CardItem, CardTitle, CardDesc, DEFAULT_CARD_ATTRS, defaultCardContent, setCardAttr, moveCard };
