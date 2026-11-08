/**
 * Slash command items, coupon picker and suggestion plugin.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import tippy from 'tippy.js';
import { Suggestion } from '@tiptap/suggestion';

import { openExternalMediaDialog, openMediaBrowser } from './media/browse.js';
import { videoEmbedHtmlForUrl } from './converters.js';
import { triggerFileUpload } from './media/upload.js';
import { insertTable } from './table-controls.js';
import { openFormPicker } from './ui/form-picker.js';
import { openLinkDialog } from './ui/link-dialog.js';
import { openProductPicker } from './ui/product-picker.js';
import { openRedirectSettings } from './settings/redirect-settings.js';
import { _getEditorContentType, bp, getCsrfToken } from './utils.js';
import { insertHero } from './embeds/hero-embed.js';

const SLASH_ITEMS = [
  {
    icon: '¶',
    title: tjs('Paragraph'),
    description: tjs('Plain text paragraph'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).setParagraph().run();
    },
  },
  {
    icon: 'H1',
    title: tjs('Heading 1'),
    description: tjs('Large section heading'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).setHeading({ level: 1 }).run();
    },
  },
  {
    icon: 'H2',
    title: tjs('Heading 2'),
    description: tjs('Medium section heading'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).setHeading({ level: 2 }).run();
    },
  },
  {
    icon: 'H3',
    title: tjs('Heading 3'),
    description: tjs('Small section heading'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).setHeading({ level: 3 }).run();
    },
  },
  {
    icon: '❝',
    title: tjs('Quote'),
    description: tjs('Blockquote'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).setBlockquote().run();
    },
  },
  {
    icon: '•',
    title: tjs('Bullet List'),
    description: tjs('Unordered list'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).toggleBulletList().run();
    },
  },
  {
    icon: '1.',
    title: tjs('Numbered List'),
    description: tjs('Ordered list'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).toggleOrderedList().run();
    },
  },
  {
    icon: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>',
    title: tjs('Code Block'),
    description: tjs('Syntax-highlighted code'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).setCodeBlock().run();
    },
  },
  {
    icon: '⏎',
    title: tjs('Hard Break'),
    description: tjs('Line break'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).setHardBreak().run();
    },
  },
  {
    icon: '—',
    title: tjs('Divider'),
    description: tjs('Horizontal rule'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).setHorizontalRule().run();
    },
  },
  {
    icon: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="3" y1="15" x2="21" y2="15"></line><line x1="9" y1="3" x2="9" y2="21"></line><line x1="15" y1="3" x2="15" y2="21"></line></svg>',
    title: tjs('Table'),
    description: tjs('Insert a table'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      insertTable(editor);
    },
  },
  {
    icon: '🎠',
    title: tjs('Carousel'),
    description: tjs('Image slider with autoplay'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      editor.commands.insertContent({ type: 'carouselEmbed' });
    },
  },
  {
    icon: '📧',
    title: tjs('Newsletter Form'),
    description: tjs('Single-input email subscription form'),
    visible: () => window._VL_NEWSLETTER_ENABLED !== '0',
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      editor.commands.insertContent({ type: 'newsletterEmbed' });
    },
  },
  {
    icon: '📰',
    title: tjs('Latest Posts Feed'),
    description: tjs('Dynamic recent posts feed tag for emails'),
    visible: () => window._VL_NEWSLETTER_ENABLED !== '0' && _getEditorContentType() === 'newsletter',
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      editor.commands.insertContent({ type: 'latestPostsEmbed' });
    },
  },
  {
    icon: '👤',
    title: tjs('Subscriber Name Tag'),
    description: tjs('Insert subscriber name shortcode with fallback'),
    visible: () => window._VL_NEWSLETTER_ENABLED !== '0' && _getEditorContentType() === 'newsletter',
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      openSubscriberNamePicker(editor);
    },
  },
  {
    icon: '🔕',
    title: tjs('Unsubscribe Link'),
    description: tjs('Insert unsubscribe URL tag'),
    visible: () => window._VL_NEWSLETTER_ENABLED !== '0' && _getEditorContentType() === 'newsletter',
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      editor.commands.insertContent({ type: 'unsubscribeEmbed' });
    },
  },
  {
    icon: '👁',
    title: tjs('Open Track Tag'),
    description: tjs('Insert email open tracking pixel tag'),
    visible: () => window._VL_NEWSLETTER_ENABLED !== '0' && _getEditorContentType() === 'newsletter',
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      editor.commands.insertContent({ type: 'openPixelEmbed' });
    },
  },
  {
    icon: '📑',
    title: tjs('Table of Contents'),
    description: tjs('Auto-generated TOC from headings'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      editor.commands.insertContent({ type: 'tocEmbed' });
    },
  },
  {
    icon: '👶',
    title: tjs('Child Pages'),
    description: tjs('Nested list of child pages'),
    visible: () => _getEditorContentType() === 'page',
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      editor.commands.insertContent({ type: 'pagesEmbed' });
    },
  },
  {
    icon: '↗',
    title: tjs('Redirect'),
    description: tjs('Timed redirect to another URL'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      editor.commands.insertContent({ type: 'redirectEmbed' });
      openRedirectSettingsForLastBlock(editor);
    },
  },
  {
    icon: '⬪',
    title: tjs('Align Left'),
    description: tjs('Left-align text'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).setTextAlign('left').run();
    },
  },
  {
    icon: '⫻',
    title: tjs('Align Center'),
    description: tjs('Center-align text'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).setTextAlign('center').run();
    },
  },
  {
    icon: '⬫',
    title: tjs('Align Right'),
    description: tjs('Right-align text'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).setTextAlign('right').run();
    },
  },
  {
    icon: '🔗',
    title: tjs('Link'),
    description: tjs('Insert or edit a link'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      openLinkDialog(editor);
    },
  },
  {
    icon: '🔘',
    title: tjs('Link Buttons'),
    description: tjs('Add a group of custom styled link buttons'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      editor.commands.insertContent({
        type: 'linkButtonGroup',
        content: [{ type: 'linkButton', content: [{ type: 'text', text: tjs('Button Text') }] }]
      });
    },
  },
  {
    icon: '🃏',
    title: tjs('Cards'),
    description: tjs('Add a responsive grid of image cards'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      editor.commands.insertContent({
        type: 'cardGroup',
        content: [{
          type: 'cardItem',
          content: [
            { type: 'cardTitle', content: [{ type: 'text', text: tjs('Card Title') }] },
            { type: 'cardDesc', content: [{ type: 'text', text: tjs('Card description') }] },
          ],
        }]
      });
    },
  },
  {
    icon: '🏁',
    title: tjs('Hero'),
    description: tjs('Marketing hero — split or centered CTA'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      insertHero(editor);
    },
  },
  {
    icon: '🖼',
    title: tjs('Upload'),
    description: tjs('Upload image, video or file'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      triggerFileUpload(editor);
    },
  },
  {
    icon: '🔗',
    title: tjs('External Media'),
    description: tjs('Insert image, YouTube, Vimeo or MP4'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      openExternalMediaDialog({
        onSelect: (url) => {
          const isImg = url.match(/\.(jpeg|jpg|gif|png|webp|svg)($|\?)/i);
          if (isImg) {
            editor.chain().focus().setImage({ src: url }).run();
          } else {
            editor.chain().focus().insertContent({ type: 'videoEmbed', attrs: { html: videoEmbedHtmlForUrl(url) } }).run();
          }
        }
      });
    },
  },
  {
    icon: '📁',
    title: tjs('Media Library'),
    description: tjs('Insert image, video or file from library'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      openMediaBrowser(editor);
    },
  },
  {
    icon: '🖼',
    title: tjs('Media + Text'),
    description: tjs('Image with side text'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      editor.commands.insertContent({ type: 'mediaText' });
    },
  },
  {
    icon: '📋',
    title: tjs('Form'),
    description: tjs('Embed a form'),
    visible: () => window._VL_FORMS_ENABLED !== '0',
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      openFormPicker(editor);
    },
  },
  {
    icon: '🎟',
    title: tjs('Coupon'),
    description: tjs('Insert a discount coupon code'),
    shopOnly: true,
    visible: () => (window._VL_SHOP_ENABLED === '1' || window._VL_MEMBERSHIP_ENABLED === '1') && _getEditorContentType() === 'newsletter',
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      openCouponPicker(editor);
    },
  },
  {
    icon: '🛒',
    title: tjs('Product'),
    description: tjs('Embed a product from the shop'),
    shopOnly: true,
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      openProductPicker(editor);
    },
  },
  {
    icon: '↕',
    title: tjs('Spacer'),
    description: tjs('Invisible space block'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).run();
      editor.commands.insertContent({ type: 'spacerBlock' });
    },
  },
  {
    icon: '⊡',
    title: tjs('More Break'),
    description: tjs('Content before this break shows in blog listings'),
    visible: () => {
      const ct = _getEditorContentType();
      return ct !== 'page' && ct !== 'product';
    },
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).insertContent({ type: 'more' }).run();
    },
  },
  {
    icon: '📄',
    title: tjs('Next Page'),
    description: tjs('Page break / pagination divider'),
    command: ({ editor, range }) => {
      editor.chain().focus().deleteRange(range).insertContent({ type: 'nextpage' }).run();
    },
  },
];

// ── Coupon picker dialog ─────────────────────────────────────────────────────
function getCouponPresets() {
  const sym = window.CURRENCY_SYMBOL || '$';
  return [
    {
      label: '10% off — 30 days (single-use)',
      attrs: { discountPercent: '10', prefix: 'SALE', expiresDays: '30', maxUsesPerEmail: '1' },
      tag: '[generate_coupon discount_percent="10" prefix="SALE" expires_days="30" max_uses_per_email="1"]',
    },
    {
      label: '15% off — 14 days (single-use)',
      attrs: { discountPercent: '15', prefix: 'PROMO', expiresDays: '14', maxUsesPerEmail: '1' },
      tag: '[generate_coupon discount_percent="15" prefix="PROMO" expires_days="14" max_uses_per_email="1"]',
    },
    {
      label: '20% off — 7 days (single-use)',
      attrs: { discountPercent: '20', prefix: 'FLASH', expiresDays: '7', maxUsesPerEmail: '1' },
      tag: '[generate_coupon discount_percent="20" prefix="FLASH" expires_days="7" max_uses_per_email="1"]',
    },
    {
      label: sym + '5 off — 30 days',
      attrs: { discountFixed: '5', prefix: 'SAVE5', expiresDays: '30', maxUsesPerEmail: '' },
      tag: '[generate_coupon discount_fixed="5" prefix="SAVE5" expires_days="30"]',
    },
    {
      label: sym + '10 off — 14 days (single-use)',
      attrs: { discountFixed: '10', prefix: 'OFF10', expiresDays: '14', maxUsesPerEmail: '1' },
      tag: '[generate_coupon discount_fixed="10" prefix="OFF10" expires_days="14" max_uses_per_email="1"]',
    },
  ];
}

async function openCouponPicker(editor, { allowGenerator = true } = {}) {
  const overlay = document.createElement('div');
  overlay.className = 'vl-overlay';

  const box = document.createElement('div');
  box.className = 'vl-modal vl-modal-md vl-modal--structured'; box.style.maxHeight = '90vh';

  const head = document.createElement('div');
  head.className = 'vl-editor-modal-head';
  const body = document.createElement('div');
  body.className = 'vl-editor-modal-body';
  const foot = document.createElement('div');
  foot.className = 'vl-editor-modal-foot';
  box.append(head, body, foot);

  const close = () => { overlay.remove(); document.removeEventListener('keydown', escFn); };
  const escFn = e => { if (e.key === 'Escape') close(); };
  document.addEventListener('keydown', escFn);
  let pd = false;
  overlay.addEventListener('pointerdown', e => { pd = (e.target === overlay); });
  overlay.addEventListener('click', e => { if (e.target === overlay && pd) close(); });

  // Title
  const title = document.createElement('p');
  title.textContent = tjs('Insert Coupon');
  title.className = 'vl-editor-modal-title';
  head.appendChild(title);

  const sub = document.createElement('p');
  sub.textContent = allowGenerator
    ? tjs('Select from existing coupons, or create a new one with parameters below.')
    : tjs('Select an existing coupon to insert.');
  sub.className = 'vl-editor-modal-sub';
  body.appendChild(sub);

  // ── Section 1: existing coupons ──────────────────────────────────────────
  const secLabel1 = document.createElement('p');
  secLabel1.textContent = tjs('Existing coupons');
  secLabel1.className = 'vl-editor-modal-section-label';
  body.appendChild(secLabel1);

  const existingWrap = document.createElement('div');
  existingWrap.style.cssText = 'margin-bottom:20px';
  body.appendChild(existingWrap);

  try {
    const coupons = window._VL_COUPONS ?? [];
    existingWrap.innerHTML = '';

    if (!Array.isArray(coupons) || coupons.length === 0) {
      const none = document.createElement('p');
      none.textContent = allowGenerator
        ? tjs('No coupons found. Create one under Shop → Coupons.')
        : tjs('No coupons found. Create one under Shop → Coupons or Membership → Plans.');
      none.style.cssText = 'font-size:13px;color:#9ca3af';
      existingWrap.appendChild(none);
    } else {
      // Autocomplete search field (same UX as order product search):
      // type to filter, click a result to fill the field.
      const searchWrap = document.createElement('div');
      searchWrap.style.cssText = 'position:relative;margin-bottom:12px';

      const searchInp = document.createElement('input');
      searchInp.type = 'text';
      searchInp.placeholder = tjs('Search coupons...');
      searchInp.className = 'vl-input';
      searchInp.autocomplete = 'off';
      searchInp.style.cssText = 'width:100%;font-size:13px;';
      searchWrap.appendChild(searchInp);

      const ddl = document.createElement('div');
      ddl.className = 'vl-coupon-dd';
      ddl.style.display = 'none';
      searchWrap.appendChild(ddl);

      // If the server-side list was truncated (CLIENT_SIDE_SEARCH_FETCH_LIMIT),
      // lazily fetch the full list on first search for client-side filtering.
      let allCoupons = window._VL_COUPONS ?? [];
      let fullListLoaded = !window._VL_COUPONS_TRUNCATED;
      let selected = null;

      async function ensureFullList() {
        if (fullListLoaded) return;
        try {
          const res = await fetch(bp('/admin/coupons/list-json'), { headers: { 'X-CSRF-Token': getCsrfToken() } });
          const data = await res.json();
          allCoupons = (data || []).map(c => ({
            code: c.code, type: c.type, amount: c.amount,
            expires_at: c.expires_at ? String(c.expires_at).slice(0, 10) : null,
            enabled: !!c.enabled,
          }));
        } catch (_) { /* keep truncated list on failure */ }
        fullListLoaded = true;
      }

      function couponLabel(c) {
        const sym = window.CURRENCY_SYMBOL || '$';
        const discount = c.type === 'percent'
          ? c.code + ' (' + c.amount + '% off)'
          : c.code + ' (' + sym + c.amount + ' off)';
        const expiry = c.expires_at ? 'expiration: ' + c.expires_at : 'expiration: never';
        return discount + ' — ' + expiry + (c.enabled ? '' : ' [disabled]');
      }

      function renderList(filterText = '') {
        ddl.innerHTML = '';
        const q = filterText.toLowerCase().trim();
        const filtered = allCoupons.filter(c => !q || c.code.toLowerCase().includes(q) || (c.amount + '').includes(q));

        if (!filtered.length) {
          const empty = document.createElement('div');
          empty.className = 'vl-coupon-dd-empty';
          empty.textContent = tjs('No matching coupons');
          ddl.appendChild(empty);
          return;
        }
        filtered.forEach(c => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'vl-coupon-dd-item';
          btn.textContent = couponLabel(c);
          btn.addEventListener('click', () => {
            selected = c;
            searchInp.value = c.code;
            ddl.style.display = 'none';
          });
          ddl.appendChild(btn);
        });
      }

      const hideDdl = () => { ddl.style.display = 'none'; };

      searchInp.addEventListener('focus', async () => {
        await ensureFullList();
        renderList(searchInp.value === (selected && selected.code) ? '' : searchInp.value);
        ddl.style.display = 'block';
      });
      searchInp.addEventListener('input', async () => {
        selected = null;
        await ensureFullList();
        renderList(searchInp.value);
        ddl.style.display = 'block';
      });
      searchInp.addEventListener('keydown', e => { if (e.key === 'Escape') { hideDdl(); e.stopPropagation(); } });
      document.addEventListener('click', function outsideHandler(e) {
        if (!document.body.contains(searchWrap)) { document.removeEventListener('click', outsideHandler); return; }
        if (!searchWrap.contains(e.target)) hideDdl();
      });

      const row = document.createElement('div');
      row.style.marginTop = '10px';

      const pickBtn = document.createElement('button');
      pickBtn.type = 'button';
      pickBtn.textContent = tjs('Insert');
      pickBtn.className = 'vl-btn vl-btn-primary';
      pickBtn.style.width = '100%';
      pickBtn.addEventListener('click', () => {
        const code = selected ? selected.code : searchInp.value.trim();
        if (!code) return;
        editor.chain().focus().insertContent(code).run();
        close();
      });
      row.appendChild(pickBtn);
      existingWrap.appendChild(searchWrap);
      existingWrap.appendChild(row);
    }
  } catch (_) {
    existingWrap.innerHTML = `<p class="text-xs text-red-500">${tjs('Could not load coupons.')}</p>`;
  }

  if (allowGenerator) {
  // Divider
  const hr = document.createElement('hr');
  hr.style.cssText = 'border:none;border-top:1px solid #e5e7eb;margin:0 0 16px';
  body.appendChild(hr);

  // ── Section 2: generate new coupon per recipient ─────────────────────────
  const secLabel2 = document.createElement('p');
  secLabel2.textContent = tjs('Generate unique coupon per recipient (on send)');
  secLabel2.className = 'vl-editor-modal-section-label';
  body.appendChild(secLabel2);

  // Visual Form Builder for Generate Coupon
  const builderWrap = document.createElement('div');
  builderWrap.className = 'vl-coupon-builder-wrap';
  builderWrap.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;background:#f9fafb;padding:12px;border-radius:6px;border:1px solid #e5e7eb;';

  // Type
  const typeGroup = document.createElement('div');
  const typeLabel = document.createElement('label');
  typeLabel.textContent = tjs('Discount Type');
  typeLabel.className = 'vl-coupon-builder-label';
  typeLabel.style.cssText = 'display:block;font-size:11px;font-weight:600;margin-bottom:4px;color:#374151;';
  const typeSel = document.createElement('select');
  typeSel.className = 'vl-select'; typeSel.style.width = '100%';
  typeSel.innerHTML = `<option value="percent">${tjs('Percentage (%)')}</option><option value="fixed">${tjs('Fixed Amount')}</option>`;
  typeGroup.appendChild(typeLabel); typeGroup.appendChild(typeSel);
  builderWrap.appendChild(typeGroup);

  // Amount
  const amountGroup = document.createElement('div');
  const amountLabel = document.createElement('label');
  amountLabel.textContent = tjs('Amount');
  amountLabel.className = 'vl-coupon-builder-label';
  amountLabel.style.cssText = 'display:block;font-size:11px;font-weight:600;margin-bottom:4px;color:#374151;';
  const amountInp = document.createElement('input');
  amountInp.type = 'number'; amountInp.placeholder = '10'; amountInp.value = '10'; amountInp.className = 'vl-input'; amountInp.style.width = '100%';
  amountGroup.appendChild(amountLabel); amountGroup.appendChild(amountInp);
  builderWrap.appendChild(amountGroup);

  // Prefix
  const prefixGroup = document.createElement('div');
  const prefixLabel = document.createElement('label');
  prefixLabel.textContent = tjs('Code Prefix');
  prefixLabel.className = 'vl-coupon-builder-label';
  prefixLabel.style.cssText = 'display:block;font-size:11px;font-weight:600;margin-bottom:4px;color:#374151;';
  const prefixInp = document.createElement('input');
  prefixInp.type = 'text'; prefixInp.placeholder = 'SALE'; prefixInp.value = 'SALE'; prefixInp.className = 'vl-input'; prefixInp.style.width = '100%';
  prefixGroup.appendChild(prefixLabel); prefixGroup.appendChild(prefixInp);
  builderWrap.appendChild(prefixGroup);

  // Expiry Days
  const expiryGroup = document.createElement('div');
  const expiryLabel = document.createElement('label');
  expiryLabel.textContent = tjs('Expires (Days)');
  expiryLabel.className = 'vl-coupon-builder-label';
  expiryLabel.style.cssText = 'display:block;font-size:11px;font-weight:600;margin-bottom:4px;color:#374151;';
  const expiryInp = document.createElement('input');
  expiryInp.type = 'number'; expiryInp.placeholder = '30'; expiryInp.value = '30'; expiryInp.className = 'vl-input'; expiryInp.style.width = '100%';
  expiryGroup.appendChild(expiryLabel); expiryGroup.appendChild(expiryInp);
  builderWrap.appendChild(expiryGroup);

  // Max Uses Per Email
  const maxUsesPerEmailGroup = document.createElement('div');
  const maxUsesPerEmailLabel = document.createElement('label');
  maxUsesPerEmailLabel.textContent = tjs('Max Uses Per Email (0 = unlimited)');
  maxUsesPerEmailLabel.className = 'vl-coupon-builder-label';
  maxUsesPerEmailLabel.style.cssText = 'display:block;font-size:11px;font-weight:600;margin-bottom:4px;color:#374151;';
  const maxUsesPerEmailInp = document.createElement('input');
  maxUsesPerEmailInp.type = 'number'; maxUsesPerEmailInp.placeholder = '1'; maxUsesPerEmailInp.value = '1'; maxUsesPerEmailInp.className = 'vl-input'; maxUsesPerEmailInp.style.width = '100%';
  maxUsesPerEmailGroup.appendChild(maxUsesPerEmailLabel); maxUsesPerEmailGroup.appendChild(maxUsesPerEmailInp);
  builderWrap.appendChild(maxUsesPerEmailGroup);

  // Starts At (optional)
  const startsGroup = document.createElement('div');
  const startsLabel = document.createElement('label');
  startsLabel.textContent = tjs('Starts At (optional)');
  startsLabel.className = 'vl-coupon-builder-label';
  startsLabel.style.cssText = 'display:block;font-size:11px;font-weight:600;margin-bottom:4px;color:#374151;';
  const startsInp = document.createElement('input');
  startsInp.type = 'datetime-local';
  startsInp.className = 'vl-input';
  startsInp.style.width = '100%';
  startsGroup.appendChild(startsLabel); startsGroup.appendChild(startsInp);
  builderWrap.appendChild(startsGroup);

  // Insert generated button
  const genBtn = document.createElement('button');
  genBtn.type = 'button';
  genBtn.textContent = tjs('Generate & Insert Shortcode');
  genBtn.className = 'vl-btn vl-btn-primary';
  genBtn.style.gridColumn = 'span 2';
  genBtn.addEventListener('click', () => {
    const isPercent = typeSel.value === 'percent';
    const amt = parseFloat(amountInp.value || '0');
    const prefix = (prefixInp.value.trim() || 'CAMP').toUpperCase();
    const exp = parseInt(expiryInp.value || '0', 10);
    const maxUsesPerEmail = parseInt(maxUsesPerEmailInp.value || '0', 10);
    const startsAt = startsInp.value;

    editor.chain().focus().insertContent({
      type: 'generateCouponEmbed',
      attrs: {
        discountPercent: isPercent ? String(amt) : '',
        discountFixed: !isPercent ? String(amt) : '',
        prefix,
        expiresDays: String(exp),
        maxUsesPerEmail: String(maxUsesPerEmail),
        startsAt,
      },
    }).run();
    close();
  });
  builderWrap.appendChild(genBtn);

  body.appendChild(builderWrap);

  // Presets list
  const presetLabel = document.createElement('p');
  presetLabel.textContent = tjs('Or select a preset:');
  presetLabel.className = 'vl-editor-modal-help-text';
  body.appendChild(presetLabel);

  const list = document.createElement('div');
  list.style.cssText = 'display:flex;flex-direction:column;gap:6px;margin-bottom:16px';
  getCouponPresets().forEach(preset => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = preset.label;
    btn.className = 'vl-coupon-preset';
    btn.className = 'vl-editor-modal-item';
    btn.addEventListener('mouseenter', () => { btn.classList.add('vl-coupon-preset-hover'); });
    btn.addEventListener('mouseleave', () => { btn.classList.remove('vl-coupon-preset-hover'); });
    btn.addEventListener('click', () => {
      editor.chain().focus().insertContent(preset.attrs ? { type: 'generateCouponEmbed', attrs: preset.attrs } : preset.tag).run();
      close();
    });
    list.appendChild(btn);
  });
  body.appendChild(list);

  // ── Section 3: custom shortcode ──────────────────────────────────────────
  const customLabel = document.createElement('p');
  customLabel.textContent = tjs('Or enter a custom shortcode:');
  customLabel.className = 'vl-editor-modal-help-text';
  body.appendChild(customLabel);

  const customRow = document.createElement('div');
  customRow.className = 'vl-editor-modal-input-row';

  const customInput = document.createElement('input');
  customInput.type = 'text';
  customInput.placeholder = '[generate_coupon discount_percent="10" prefix="SALE" expires_days="30"]';
  customInput.className = 'vl-input'; customInput.style.flex = '1'; customInput.style.fontFamily = 'monospace';
  customRow.appendChild(customInput);

  const insertBtn = document.createElement('button');
  insertBtn.type = 'button';
  insertBtn.textContent = tjs('Insert');
  insertBtn.className = 'vl-btn vl-btn-primary';
  insertBtn.addEventListener('click', () => {
    const val = customInput.value.trim();
    if (val) { editor.chain().focus().insertContent(val).run(); close(); }
  });
  customRow.appendChild(insertBtn);
  body.appendChild(customRow);
  }

  const cancelBtn = document.createElement('button');
  cancelBtn.type = 'button';
  cancelBtn.textContent = tjs('Cancel');
  cancelBtn.className = 'vl-btn vl-btn-secondary';
  cancelBtn.addEventListener('click', close);
  foot.appendChild(cancelBtn);

  overlay.appendChild(box);
  document.body.appendChild(overlay);
}

// ── Latest Posts picker dialog ───────────────────────────────────────────────
function openLatestPostsPicker(editor) {
  const overlay = document.createElement('div');
  overlay.className = 'vl-overlay';

  const box = document.createElement('div');
  box.className = 'vl-modal vl-modal-md vl-modal--structured';

  const head = document.createElement('div');
  head.className = 'vl-editor-modal-head';
  const body = document.createElement('div');
  body.className = 'vl-editor-modal-body';
  const foot = document.createElement('div');
  foot.className = 'vl-editor-modal-foot';
  box.append(head, body, foot);

  const close = () => { overlay.remove(); document.removeEventListener('keydown', escFn); };
  const escFn = e => { if (e.key === 'Escape') close(); };
  document.addEventListener('keydown', escFn);
  let pd = false;
  overlay.addEventListener('pointerdown', e => { pd = (e.target === overlay); });
  overlay.addEventListener('click', e => { if (e.target === overlay && pd) close(); });

  const title = document.createElement('p');
  title.textContent = tjs('Insert Latest Posts Feed');
  title.className = 'vl-editor-modal-title';
  head.appendChild(title);

  const sub = document.createElement('p');
  sub.textContent = tjs('Configure parameters for dynamic recent articles feed tag.');
  sub.className = 'vl-editor-modal-sub';
  body.appendChild(sub);

  const grid = document.createElement('div');
  grid.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;';

  // Count
  const countGroup = document.createElement('div');
  const countLabel = document.createElement('label');
  countLabel.textContent = tjs('Post Count');
  countLabel.style.cssText = 'display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#374151;';
  const countInp = document.createElement('input');
  countInp.type = 'number'; countInp.value = '5'; countInp.className = 'vl-input'; countInp.style.width = '100%';
  countGroup.appendChild(countLabel); countGroup.appendChild(countInp);
  grid.appendChild(countGroup);

  // Content Type Dropdown
  const typeGroup = document.createElement('div');
  const typeLabel = document.createElement('label');
  typeLabel.textContent = tjs('Content Type');
  typeLabel.style.cssText = 'display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#374151;';
  const typeSel = document.createElement('select');
  typeSel.className = 'vl-select'; typeSel.style.width = '100%';
  if (window._VL_BLOG_ENABLED !== '0') {
    typeSel.innerHTML += `<option value="post">${tjs('Posts')}</option>`;
  }
  const cpts = window._VL_ACTIVE_CPTS || [];
  cpts.forEach(ct => {
    typeSel.innerHTML += `<option value="${ct.slug}">${ct.name}</option>`;
  });
  typeGroup.appendChild(typeLabel); typeGroup.appendChild(typeSel);
  grid.appendChild(typeGroup);

  // Category Slug
  const catGroup = document.createElement('div');
  const catLabel = document.createElement('label');
  catLabel.textContent = tjs('Category Slug (optional)');
  catLabel.style.cssText = 'display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#374151;';
  const catInp = document.createElement('input');
  catInp.type = 'text'; catInp.placeholder = 'news'; catInp.className = 'vl-input'; catInp.style.width = '100%';
  catGroup.appendChild(catLabel); catGroup.appendChild(catInp);
  grid.appendChild(catGroup);

  // Tag Slug
  const tagGroup = document.createElement('div');
  const tagLabel = document.createElement('label');
  tagLabel.textContent = tjs('Tag Slug (optional)');
  tagLabel.style.cssText = 'display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#374151;';
  const tagInp = document.createElement('input');
  tagInp.type = 'text'; tagInp.placeholder = 'featured'; tagInp.className = 'vl-input'; tagInp.style.width = '100%';
  tagGroup.appendChild(tagLabel); tagGroup.appendChild(tagInp);
  grid.appendChild(tagGroup);

  // Language Dropdown (if multi-lang enabled)
  if (window._VL_MULTI_LANG_ENABLED === '1') {
    const langGroup = document.createElement('div');
    langGroup.style.gridColumn = 'span 2';
    const langLabel = document.createElement('label');
    langLabel.textContent = tjs('Language');
    langLabel.style.cssText = 'display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#374151;';
    const langSel = document.createElement('select');
    langSel.className = 'vl-select'; langSel.style.width = '100%';
    const langs = window._VL_CONTENT_LANGUAGES || ['en'];
    langs.forEach(l => {
      langSel.innerHTML += `<option value="${l}">${l.toUpperCase()}</option>`;
    });
    langGroup.appendChild(langLabel); langGroup.appendChild(langSel);
    grid.appendChild(langGroup);
  }

  body.appendChild(grid);

  const row = document.createElement('div');
  row.className = 'vl-editor-modal-input-row';
  row.style.justifyContent = 'flex-end';

  const cancelBtn = document.createElement('button');
  cancelBtn.type = 'button'; cancelBtn.textContent = tjs('Cancel'); cancelBtn.className = 'vl-btn vl-btn-secondary';
  cancelBtn.addEventListener('click', close);
  row.appendChild(cancelBtn);

  const insertBtn = document.createElement('button');
  insertBtn.type = 'button'; insertBtn.textContent = tjs('Insert'); insertBtn.className = 'vl-btn vl-btn-primary';
  insertBtn.addEventListener('click', () => {
    const count = parseInt(countInp.value || '5', 10);
    const type = typeSel.value || 'post';
    const cat = catInp.value.trim();
    const tag = tagInp.value.trim();

    editor.chain().focus().insertContent({
      type: 'latestPostsEmbed',
      attrs: {
        count: String(count),
        contentType: type,
        category: cat,
        tag: tag,
      },
    }).run();
    close();
  });
  row.appendChild(insertBtn);

  foot.appendChild(row);
  overlay.appendChild(box);
  document.body.appendChild(overlay);
}

// ── Subscriber Name picker dialog ────────────────────────────────────────────
function openSubscriberNamePicker(editor) {
  const overlay = document.createElement('div');
  overlay.className = 'vl-overlay';

  const box = document.createElement('div');
  box.className = 'vl-modal vl-modal-md vl-modal--structured';

  const head = document.createElement('div');
  head.className = 'vl-editor-modal-head';
  const body = document.createElement('div');
  body.className = 'vl-editor-modal-body';
  const foot = document.createElement('div');
  foot.className = 'vl-editor-modal-foot';
  box.append(head, body, foot);

  const close = () => { overlay.remove(); document.removeEventListener('keydown', escFn); };
  const escFn = e => { if (e.key === 'Escape') close(); };
  document.addEventListener('keydown', escFn);
  let pd = false;
  overlay.addEventListener('pointerdown', e => { pd = (e.target === overlay); });
  overlay.addEventListener('click', e => { if (e.target === overlay && pd) close(); });

  const title = document.createElement('p');
  title.textContent = tjs('Subscriber Name Tag');
  title.className = 'vl-editor-modal-title';
  head.appendChild(title);

  const sub = document.createElement('p');
  sub.textContent = tjs('Enter fallback text when subscriber name is empty.');
  sub.className = 'vl-editor-modal-sub';
  body.appendChild(sub);

  const label = document.createElement('label');
  label.textContent = tjs('Fallback Greeting / Text');
  label.style.cssText = 'display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:#374151;';
  body.appendChild(label);

  const inp = document.createElement('input');
  inp.type = 'text'; inp.value = 'Hi there'; inp.className = 'vl-input'; inp.style.cssText = 'width:100%;margin-bottom:16px;';
  body.appendChild(inp);

  const row = document.createElement('div');
  row.className = 'vl-editor-modal-input-row';
  row.style.justifyContent = 'flex-end';

  const cancelBtn = document.createElement('button');
  cancelBtn.type = 'button'; cancelBtn.textContent = tjs('Cancel'); cancelBtn.className = 'vl-btn vl-btn-secondary';
  cancelBtn.addEventListener('click', close);
  row.appendChild(cancelBtn);

  const insertBtn = document.createElement('button');
  insertBtn.type = 'button'; insertBtn.textContent = tjs('Insert'); insertBtn.className = 'vl-btn vl-btn-primary';
  insertBtn.addEventListener('click', () => {
    const fb = inp.value.trim();
    const tag = fb ? `[subscriber_name|${fb}]` : '[subscriber_name]';
    editor.chain().focus().insertContent(tag).run();
    close();
  });
  row.appendChild(insertBtn);

  foot.appendChild(row);
  overlay.appendChild(box);
  document.body.appendChild(overlay);
}

// ── Redirect settings helper ─────────────────────────────────────────────
// After inserting a redirect block, open its settings on the new node so the
// URL + delay are configured immediately (matches the plan: modal on insert).
function openRedirectSettingsForLastBlock(editor) {
  let lastPos = null;
  let lastNode = null;
  editor.state.doc.descendants((node, pos) => {
    if (node.type.name === 'redirectEmbed') {
      lastPos = pos;
      lastNode = node;
    }
  });
  if (lastPos !== null) {
    openRedirectSettings(editor, lastNode, lastPos);
  }
}

// ── Slash menu renderer ──────────────────────────────────────────────────────

// Vanilla JS slash command popup via tippy + @tiptap/suggestion.
// The keyboard handler is attached directly to the editor DOM in capture phase
// so it fires before TipTap's own key handlers (fixes Enter not selecting).
function createSlashMenuPlugin(editor) {
  let tippyInstance = null;
  let selectedIndex = 0;
  let currentItems = [];
  let currentRange = null;
  let currentMenu = null;

  // Capture-phase listener: intercepts Arrow/Enter/Escape before TipTap
  function handleKeydown(e) {
    if (!tippyInstance || currentItems.length === 0) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      e.stopImmediatePropagation();
      selectedIndex = (selectedIndex + 1) % currentItems.length;
      currentMenu && currentMenu.update(selectedIndex);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      e.stopImmediatePropagation();
      selectedIndex = (selectedIndex - 1 + currentItems.length) % currentItems.length;
      currentMenu && currentMenu.update(selectedIndex);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      e.stopImmediatePropagation();
      const item = currentItems[selectedIndex];
      if (item && currentRange) {
        item.command({ editor, range: currentRange });
        tippyInstance && tippyInstance.hide();
      }
    } else if (e.key === 'Escape') {
      e.preventDefault();
      e.stopImmediatePropagation();
      tippyInstance && tippyInstance.hide();
    }
  }

  function renderMenu(items) {
    currentItems = items;
    selectedIndex = 0;

    const el = document.createElement('div');
    el.className = 'vl-slash-menu';

    function update(idx) {
      el.innerHTML = '';
      items.forEach((item, i) => {
        const row = document.createElement('button');
        row.type = 'button';
        row.className = 'vl-slash-item' + (i === idx ? ' is-selected' : '');
        row.innerHTML = `<span class="vl-slash-icon">${item.icon}</span>
          <span class="vl-slash-text">
            <strong>${item.title}</strong>
            <small>${item.description}</small>
          </span>`;
        row.addEventListener('mousedown', (e) => {
          e.preventDefault();
        });
        row.addEventListener('click', () => {
          if (currentRange) item.command({ editor, range: currentRange });
          tippyInstance && tippyInstance.hide();
        });
        el.appendChild(row);
      });
      // Scroll active item into view when navigating with keyboard
      const selected = el.querySelector('.is-selected');
      if (selected) selected.scrollIntoView({ block: 'nearest' });
    }

    update(0);
    return { el, update: (idx) => { selectedIndex = idx; update(idx); } };
  }

  return Suggestion({
    editor,
    char: '/',
    startOfLine: false,
    command: ({ editor, range, props }) => {
      props.command({ editor, range });
    },
    items: ({ query }) => {
      return SLASH_ITEMS.filter(
        item =>
          (item.title.toLowerCase().includes(query.toLowerCase()) ||
          item.description.toLowerCase().includes(query.toLowerCase())) &&
          (!item.shopOnly || window._VL_SHOP_ENABLED === '1') &&
          (!item.visible || item.visible()),
       ).slice(0, 30);
    },
    render: () => {
      return {
        onStart(props) {
          currentRange = props.range;
          currentMenu = renderMenu(props.items);
          tippyInstance = tippy(document.body, {
            getReferenceClientRect: props.clientRect,
            appendTo: () => document.body,
            content: currentMenu.el,
            showOnCreate: true,
            interactive: true,
            trigger: 'manual',
            placement: 'bottom-start',
            theme: 'slash-menu',
          });
          if (props.items.length === 0) tippyInstance.hide();
          editor.view.dom.addEventListener('keydown', handleKeydown, { capture: true });
        },
        onUpdate(props) {
          currentRange = props.range;
          if (props.items.length === 0) {
            currentItems = [];
            if (tippyInstance) tippyInstance.hide();
            return;
          }
          currentMenu = renderMenu(props.items);
          if (tippyInstance) {
            tippyInstance.setContent(currentMenu.el);
            tippyInstance.setProps({ getReferenceClientRect: props.clientRect });
            tippyInstance.show();
          }
        },
        onKeyDown(props) {
          // Tell the suggestion plugin these keys are handled (prevents its defaults)
          return ['ArrowUp', 'ArrowDown', 'Enter', 'Escape'].includes(props.event.key);
        },
        onExit() {
          editor.view.dom.removeEventListener('keydown', handleKeydown, { capture: true });
          tippyInstance && tippyInstance.destroy();
          tippyInstance = null;
          currentMenu = null;
        },
      };
    },
  });
}

// ── Helpers────────

export { SLASH_ITEMS, getCouponPresets, openCouponPicker, openRedirectSettingsForLastBlock, createSlashMenuPlugin };
