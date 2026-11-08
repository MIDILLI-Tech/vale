/**
 * Newsletter signup embed block.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { Node, mergeAttributes } from '@tiptap/core';

import { openNewsletterSettings } from '../settings/newsletter-settings.js';
import { makeDuplicateButton } from '../ui/duplicate.js';

const NewsletterEmbed = Node.create({
  name: 'newsletterEmbed',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() {
    return {
      placeholder: {
        default: 'Enter your email',
        parseHTML: el => el.getAttribute('data-placeholder') || 'Enter your email',
      },
      buttonText: {
        default: 'Join Now',
        parseHTML: el => el.getAttribute('data-button') || 'Join Now',
      },
      privacyPage: {
        default: '',
        parseHTML: el => el.getAttribute('data-privacy-page') || '',
      },
      tag: {
        default: '',
        parseHTML: el => el.getAttribute('data-tag') || '',
      },
      honeypotName: {
        default: '',
        parseHTML: el => el.getAttribute('data-honeypot-name') || '',
      },
      captcha: {
        default: 'disabled',
        parseHTML: el => el.getAttribute('data-captcha') || 'disabled',
      },
      width: {
        default: 'auto',
        parseHTML: el => el.getAttribute('data-width') || 'auto',
      },
    };
  },

  parseHTML() {
    return [{ tag: 'div[data-newsletter]' }];
  },

  renderHTML({ node, HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      'class': 'vl-newsletter-embed-block',
      'data-newsletter': '1',
      'data-placeholder': node.attrs.placeholder,
      'data-button': node.attrs.buttonText,
      'data-privacy-page': node.attrs.privacyPage,
      'data-tag': node.attrs.tag,
      'data-honeypot-name': node.attrs.honeypotName || '',
      'data-captcha': node.attrs.captcha || 'disabled',
      'data-width': node.attrs.width || 'auto',
      'contenteditable': 'false',
    })];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-newsletter-embed-block';
      dom.contentEditable = 'false';
      dom.setAttribute('data-newsletter', '1');

      const header = document.createElement('div');
      header.className = 'vl-newsletter-header';
      header.innerHTML = `<span class="flex-1">📧 ${tjs('Newsletter Form')}</span>`;

      const settingsBtn = document.createElement('button');
      settingsBtn.type = 'button';
      settingsBtn.innerHTML = '⚙';
      settingsBtn.title = tjs('Newsletter Settings');
      settingsBtn.dataset.settings = '1';
      settingsBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        openNewsletterSettings(editor, node, getPos());
      });
      header.appendChild(settingsBtn);
      header.appendChild(makeDuplicateButton(editor, getPos));

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'vl-form-embed-delete';
      del.textContent = '✕';
      del.addEventListener('click', () => {
        const pos = getPos();
        editor.chain().focus().deleteRange({ from: pos, to: pos + node.nodeSize }).run();
      });
      header.appendChild(del);

      // Body
      const body = document.createElement('div');
      body.className = 'vl-newsletter-body';

      function renderPreview() {
        body.innerHTML = `
          <div class="vl-modal-flex-row mb-2">
            <input type="text" disabled placeholder="${node.attrs.placeholder}" class="vl-input vl-newsletter-input flex-1">
            <button type="button" disabled class="vl-btn vl-btn-primary vl-newsletter-btn">${node.attrs.buttonText}</button>
          </div>
          ${node.attrs.privacyPage ? `<div class="vl-newsletter-meta">⚖ ${tjs('Privacy Page')}: ${node.attrs.privacyPage}</div>` : ''}
          ${node.attrs.tag ? `<div class="vl-newsletter-meta">🏷 ${tjs('Tag')}: ${node.attrs.tag}</div>` : ''}
          ${node.attrs.captcha === 'honeypot' && node.attrs.honeypotName ? `<div class="vl-newsletter-meta">🍯 ${tjs('Honeypot Field')}: ${node.attrs.honeypotName}</div>` : ''}
          ${node.attrs.captcha && node.attrs.captcha !== 'disabled' ? `<div class="vl-newsletter-meta">🛡 ${tjs('Captcha')}: ${node.attrs.captcha}</div>` : ''}
        `;
        const w = (node.attrs.width || 'auto').trim();
        if (/^\d+(\.\d+)?(px|%)$/.test(w) && w !== '100%') {
          dom.style.maxWidth = w;
          dom.style.marginLeft = 'auto';
          dom.style.marginRight = 'auto';
        } else {
          dom.style.maxWidth = '';
          dom.style.marginLeft = '';
          dom.style.marginRight = '';
        }
      }

      renderPreview();
      dom.appendChild(header);
      dom.appendChild(body);

      return {
        dom,
        update(updatedNode) {
          if (updatedNode.type.name !== 'newsletterEmbed') return false;
          node = updatedNode;
          renderPreview();
          return true;
        },
      };
    };
  },
});

export { NewsletterEmbed };
