/**
 * Hero section block.
 *
 * A full-width marketing hero with two variants:
 *   - split: heading + subtitle + buttons beside an optional media/photo column
 *     with a corner badge (mirrors the reference landing hero).
 *   - cta:   centered caption card with heading + subtitle + buttons.
 *
 * The heading and subtitle are real textblock children (heroHeading →
 * `<h2 data-hero-heading>`, heroSubtitle → `<p data-hero-subtitle>`), so the
 * toolbar marks (bold/italic/underline, links, text color) and text alignment
 * keep working while editing them. Buttons and optional media are block attrs
 * configured through the settings dialog.
 *
 * Stored as the `[hero …][hero_heading]…[/hero_heading][hero_subtitle]…[/hero_subtitle][/hero]`
 * shortcode; the PHP side renders theme-variable colors (var(--theme-*)).
 */
import { NodeSelection } from '@tiptap/pm/state';
import { Node, mergeAttributes } from '@tiptap/core';

import { openMediaBrowserForCarousel, openMediaPickerExtended } from '../media/browse.js';
import { toEmbedUrl } from '../utils.js';
import { openHeroSettings } from '../settings/hero-settings.js';
import { makeDuplicateButton } from '../ui/duplicate.js';

const VARIANT_LABELS = {
  split: () => tjs('Split Hero'),
  cta: () => tjs('CTA Card'),
};

// Preview shadows mirroring ShortcodeProcessor::heroShadow() (default black).
const SHADOW_PREVIEW = {
  none: 'none',
  sm: '0 1px 2px rgba(0,0,0,.05)',
  md: '0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -2px rgba(0,0,0,.1)',
  lg: '0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1)',
  xl: '0 20px 25px -5px rgba(0,0,0,.1),0 8px 10px -6px rgba(0,0,0,.1)',
};

const DEFAULT_HERO_ATTRS = {
  variant: 'split',
  bg: '',
  shadow: '',
  shadowColor: '',
  radius: '',
  width: '',
  imageSrc: '',
  imageAlt: '',
  imageFit: 'cover',
  mediaType: 'image',
  mediaAutoplay: 'false',
  carouselImages: '',
  carouselAutoplay: 'true',
  carouselDuration: '5000',
  carouselShowArrows: 'true',
  carouselShowDots: 'true',
  carouselPauseBtn: 'false',
  carouselFit: 'cover',
  carouselHeight: '100%',
  carouselTransition: 'fade',
  carouselBg: '',
  lightbox: 'false',
  mediaSide: 'right',
  badge: '',
  badgeBg: '',
  badgeTextColor: '',
  button1Text: '',
  button1Url: '',
  button1Target: '_self',
  button1Style: 'primary',
  button1Color: '',
  button1TextColor: '',
  button2Text: '',
  button2Url: '',
  button2Target: '_self',
  button2Style: 'secondary',
  button2Color: '',
  button2TextColor: '',
};

const DATA_ATTR_MAP = {
  variant: 'data-variant',
  bg: 'data-bg',
  shadow: 'data-shadow',
  shadowColor: 'data-shadow-color',
  radius: 'data-radius',
  width: 'data-width',
  imageSrc: 'data-image-src',
  imageAlt: 'data-image-alt',
  imageFit: 'data-image-fit',
  mediaType: 'data-media-type',
  mediaAutoplay: 'data-media-autoplay',
  carouselImages: 'data-carousel-images',
  carouselAutoplay: 'data-carousel-autoplay',
  carouselDuration: 'data-carousel-duration',
  carouselShowArrows: 'data-carousel-show-arrows',
  carouselShowDots: 'data-carousel-show-dots',
  carouselPauseBtn: 'data-carousel-pause-btn',
  carouselFit: 'data-carousel-fit',
  carouselHeight: 'data-carousel-height',
  carouselTransition: 'data-carousel-transition',
  carouselBg: 'data-carousel-bg',
  lightbox: 'data-lightbox',
  mediaSide: 'data-media-side',
  badge: 'data-badge',
  badgeBg: 'data-badge-bg',
  badgeTextColor: 'data-badge-text-color',
  button1Text: 'data-btn1-text',
  button1Url: 'data-btn1-url',
  button1Target: 'data-btn1-target',
  button1Style: 'data-btn1-style',
  button1Color: 'data-btn1-color',
  button1TextColor: 'data-btn1-text-color',
  button2Text: 'data-btn2-text',
  button2Url: 'data-btn2-url',
  button2Target: 'data-btn2-target',
  button2Style: 'data-btn2-style',
  button2Color: 'data-btn2-color',
  button2TextColor: 'data-btn2-text-color',
};

function heroAttrsToData(attrs) {
  const out = {};
  Object.entries(DATA_ATTR_MAP).forEach(([key, dataAttr]) => {
    out[dataAttr] = attrs[dataAttr] ?? attrs[key] ?? DEFAULT_HERO_ATTRS[key];
  });
  return out;
}

function dataToHeroAttrs(dom) {
  const attrs = {};
  Object.entries(DATA_ATTR_MAP).forEach(([key, dataAttr]) => {
    const v = dom.getAttribute(dataAttr);
    attrs[key] = v == null ? DEFAULT_HERO_ATTRS[key] : v;
  });
  return attrs;
}

function defaultHeroContent(variant = 'split') {
  return {
    type: 'heroEmbed',
    attrs: { ...DEFAULT_HERO_ATTRS, variant },
    content: [
      { type: 'heroHeading', content: [{ type: 'text', text: tjs('Your hero heading') }] },
      { type: 'heroSubtitle', content: [{ type: 'text', text: tjs('Supporting text goes here…') }] },
    ],
  };
}

function setHeroAttr(editor, pos, key, value) {
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

function insertHero(editor, variant = 'split') {
  editor.chain().focus().insertContent(defaultHeroContent(variant)).run();
}

const HeroHeading = Node.create({
  name: 'heroHeading',
  group: 'block',
  content: 'inline*',
  defining: true,
  selectable: true,
  draggable: false,

  parseHTML() {
    return [{ tag: 'h2[data-hero-heading]', priority: 100 }];
  },

  renderHTML({ HTMLAttributes }) {
    return ['h2', mergeAttributes(HTMLAttributes, {
      'data-hero-heading': '1',
      class: 'vl-hero-heading',
    }), 0];
  },
});

const HeroSubtitle = Node.create({
  name: 'heroSubtitle',
  group: 'block',
  content: 'inline*',
  defining: true,
  selectable: true,
  draggable: false,

  parseHTML() {
    return [{ tag: 'p[data-hero-subtitle]', priority: 100 }];
  },

  renderHTML({ HTMLAttributes }) {
    return ['p', mergeAttributes(HTMLAttributes, {
      'data-hero-subtitle': '1',
      class: 'vl-hero-subtitle',
    }), 0];
  },
});

const HeroEmbed = Node.create({
  name: 'heroEmbed',
  group: 'block',
  content: 'heroHeading heroSubtitle',
  selectable: true,
  draggable: true,

  addAttributes() {
    const attrs = {};
    Object.entries(DATA_ATTR_MAP).forEach(([key, dataAttr]) => {
      attrs[key] = {
        default: DEFAULT_HERO_ATTRS[key],
        parseHTML: el => el.getAttribute(dataAttr) ?? DEFAULT_HERO_ATTRS[key],
        renderHTML: attributes => ({ [dataAttr]: attributes[key] ?? DEFAULT_HERO_ATTRS[key] }),
      };
    });
    return attrs;
  },

  parseHTML() {
    return [{
      tag: 'div[data-hero]',
      getAttrs(dom) { return dataToHeroAttrs(dom); },
    }];
  },

  renderHTML({ HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, {
      ...heroAttrsToData(HTMLAttributes),
      'data-hero': '1',
      class: 'vl-hero-embed-block',
    }), 0];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const dom = document.createElement('div');
      dom.className = 'vl-hero-embed-block';
      dom.setAttribute('data-hero', '1');

      // Header: label + variant toggle + settings + delete
      const header = document.createElement('div');
      header.className = 'vl-hero-header';
      header.contentEditable = 'false';

      const titleEl = document.createElement('span');
      titleEl.className = 'flex-1';

      const variantBtn = document.createElement('button');
      variantBtn.type = 'button';
      variantBtn.innerHTML = '🔁';
      variantBtn.title = tjs('Toggle variant');
      variantBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const next = node.attrs.variant === 'split' ? 'cta' : 'split';
        setHeroAttr(editor, getPos(), 'variant', next);
      });

      const settingsBtn = document.createElement('button');
      settingsBtn.type = 'button';
      settingsBtn.innerHTML = '⚙';
      settingsBtn.title = tjs('Hero Settings');
      settingsBtn.dataset.settings = '1';
      settingsBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        openHeroSettings(editor, node, getPos());
      });

      const delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'vl-form-embed-delete';
      delBtn.textContent = '✕';
      delBtn.title = tjs('Delete Hero');
      delBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const pos = getPos();
        editor.chain().focus().deleteRange({ from: pos, to: pos + node.nodeSize }).run();
      });

      header.appendChild(titleEl);
      header.appendChild(variantBtn);
      header.appendChild(settingsBtn);
      header.appendChild(makeDuplicateButton(editor, getPos));
      header.appendChild(delBtn);

      // Body
      const body = document.createElement('div');
      body.className = 'vl-hero-body';

      const row = document.createElement('div');
      row.className = 'vl-hero-row';

      const textWrap = document.createElement('div');
      textWrap.className = 'vl-hero-text';

      // contentDOM — holds the heroHeading + heroSubtitle textblocks
      const textCol = document.createElement('div');
      textCol.className = 'vl-hero-text-col';

      const buttonsRow = document.createElement('div');
      buttonsRow.className = 'vl-hero-buttons';
      buttonsRow.contentEditable = 'false';

      textWrap.appendChild(textCol);
      textWrap.appendChild(buttonsRow);

      // Media column (split variant only)
      const mediaCol = document.createElement('div');
      mediaCol.className = 'vl-hero-media';
      mediaCol.contentEditable = 'false';
      mediaCol.style.cursor = 'pointer';
      mediaCol.title = tjs('Click to add or replace media');
      mediaCol.addEventListener('click', (e) => {
        e.stopPropagation();
        if (e.target === rmMediaBtn || rmMediaBtn.contains(e.target)) return;
        if (e.target === badgeEl || badgeEl.contains(e.target)) return;
        if (node.attrs.mediaType === 'carousel') {
          openMediaBrowserForCarousel(editor, urls => setHeroAttr(editor, getPos(), 'carouselImages', urls.join(',')), (node.attrs.carouselImages || '').split(',').filter(Boolean));
        } else {
          openMediaPickerExtended(editor, (url, alt) => {
            setHeroAttr(editor, getPos(), 'imageSrc', url || '');
            setHeroAttr(editor, getPos(), 'imageAlt', alt || '');
          }, 'image/', node.attrs.imageSrc || '');
        }
      });

      const rmMediaBtn = document.createElement('button');
      rmMediaBtn.type = 'button';
      rmMediaBtn.className = 'vl-card-remove-img';
      rmMediaBtn.textContent = '✕';
      rmMediaBtn.title = tjs('Remove media');
      rmMediaBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (node.attrs.mediaType === 'carousel') setHeroAttr(editor, getPos(), 'carouselImages', '');
        else setHeroAttr(editor, getPos(), 'imageSrc', '');
        setHeroAttr(editor, getPos(), 'imageAlt', '');
      });
      mediaCol.appendChild(rmMediaBtn);

      const badgeEl = document.createElement('div');
      badgeEl.className = 'vl-hero-badge';
      badgeEl.contentEditable = 'false';
      mediaCol.appendChild(badgeEl);

      row.appendChild(textWrap);
      row.appendChild(mediaCol);
      body.appendChild(row);

      dom.appendChild(header);
      dom.appendChild(body);

      function renderMedia() {
        mediaCol.querySelectorAll(':scope > *:not(.vl-card-remove-img):not(.vl-hero-badge)').forEach(el => {
          el.remove();
        });
        const src = node.attrs.imageSrc || '';
        const carouselUrls = (node.attrs.carouselImages || '').split(',').filter(Boolean);
        if (node.attrs.mediaType === 'carousel') {
          mediaCol.title = tjs('Select Carousel Images');
          mediaCol.style.cursor = 'pointer';
          mediaCol.style.background = '';
          const preview = document.createElement('div');
          preview.className = 'vl-hero-carousel-preview';
          carouselUrls.slice(0, 3).forEach(url => {
            const img = document.createElement('img');
            img.src = url;
            img.alt = '';
            preview.appendChild(img);
          });
          if (!carouselUrls.length) preview.textContent = tjs('Select Carousel Images');
          mediaCol.appendChild(preview);
          rmMediaBtn.style.display = carouselUrls.length ? '' : 'none';
          return;
        }
        const alt = node.attrs.imageAlt || '';
        const lightbox = node.attrs.lightbox === 'true' || node.attrs.lightbox === true;
        mediaCol.title = lightbox && src ? tjs('Click to enlarge') : tjs('Click to add or replace media');
        mediaCol.style.cursor = lightbox && src ? 'zoom-in' : 'pointer';
        const isVid = src && (/\.(mp4|webm|ogg|mov|m4v)(\?|$)/i.test(src) || src.includes('youtube.com') || src.includes('youtu.be') || src.includes('vimeo.com'));
        if (src) {
          mediaCol.style.background = (node.attrs.imageFit && node.attrs.imageFit !== 'cover') ? 'transparent' : '';
          if (isVid && !src.includes('youtube.com') && !src.includes('youtu.be') && !src.includes('vimeo.com')) {
            const vid = document.createElement('video');
            vid.src = src;
            vid.className = 'vl-hero-media-img';
            vid.style.objectFit = node.attrs.imageFit || 'cover';
            vid.muted = true;
            vid.playsInline = true;
            if (node.attrs.mediaAutoplay === 'true') { vid.autoplay = true; } else { vid.controls = true; }
            mediaCol.appendChild(vid);
          } else if (isVid) {
            const iframe = document.createElement('iframe');
            iframe.src = toEmbedUrl(src, node.attrs.mediaAutoplay === 'true' || node.attrs.mediaAutoplay === true);
            iframe.className = 'vl-hero-media-img';
            iframe.style.width = '100%';
            iframe.style.height = '100%';
            iframe.style.border = 'none';
            iframe.allow = 'autoplay; fullscreen';
            mediaCol.appendChild(iframe);
          } else {
            const img = document.createElement('img');
            img.src = src;
            img.alt = alt;
            img.className = 'vl-hero-media-img';
            img.style.objectFit = node.attrs.imageFit || 'cover';
            if (lightbox) {
              img.title = tjs('Click to enlarge');
              img.style.cursor = 'zoom-in';
            }
            mediaCol.appendChild(img);
          }
          rmMediaBtn.style.display = '';
        } else {
          const ph = document.createElement('span');
          ph.className = 'vl-hero-media-ph';
          ph.textContent = tjs('Add a media — click here');
          mediaCol.appendChild(ph);
          mediaCol.style.background = '';
          rmMediaBtn.style.display = 'none';
        }
      }

      function renderButtons() {
        buttonsRow.innerHTML = '';
        const configs = [
          { prefix: 'button1' },
          { prefix: 'button2' },
        ];
        configs.forEach(({ prefix }) => {
          const text = node.attrs[prefix + 'Text'] || '';
          if (!text) return;
          const btn = document.createElement('span');
          const style = node.attrs[prefix + 'Style'] === 'secondary' ? 'secondary' : 'primary';
          const themeColors = window.VL_THEME_COLORS || {};
          const colorValue = name => themeColors[name]?.value || `var(${name})`;
          btn.className = 'vl-hero-btn-preview vl-hero-btn-' + style;
          btn.textContent = text;
          if (style === 'primary') {
            btn.style.backgroundColor = colorValue('--theme-primary');
            btn.style.color = '#fff';
          } else {
            btn.style.backgroundColor = colorValue('--theme-surface');
            btn.style.color = colorValue('--theme-text');
            btn.style.borderColor = colorValue('--theme-secondary');
          }
          if (node.attrs[prefix + 'Color']) {
            btn.style.backgroundColor = node.attrs[prefix + 'Color'];
            btn.style.borderColor = node.attrs[prefix + 'Color'];
          }
          if (node.attrs[prefix + 'TextColor']) btn.style.color = node.attrs[prefix + 'TextColor'];
          buttonsRow.appendChild(btn);
        });
      }

      function renderBadge() {
        const badge = node.attrs.badge || '';
        badgeEl.textContent = badge;
        badgeEl.style.display = badge ? '' : 'none';
        // CSS var(--theme-primary) is not defined in admin scope, so resolve
        // the theme color via VL_THEME_COLORS; custom picker values win.
        const themeBg = window.VL_THEME_COLORS?.['--theme-primary']?.value || '';
        badgeEl.style.background = node.attrs.badgeBg || themeBg || '';
        badgeEl.style.color = node.attrs.badgeTextColor || '';
      }

      function applyLayout() {
        const isCta = node.attrs.variant === 'cta';
        titleEl.textContent = '🏁 ' + (VARIANT_LABELS[node.attrs.variant] || VARIANT_LABELS.split)();

        mediaCol.style.display = isCta ? 'none' : '';
        badgeEl.style.display = isCta ? 'none' : (node.attrs.badge ? '' : 'none');

        // Mirror the frontend flex render (order-first handled via flex-direction)
        row.style.flexDirection = (!isCta && node.attrs.mediaSide === 'left') ? 'row-reverse' : 'row';
        textWrap.style.textAlign = isCta ? 'center' : 'left';
        buttonsRow.style.justifyContent = isCta ? 'center' : 'flex-start';
        row.classList.toggle('vl-hero-row-cta', isCta);

        // Apply width constraint to the outer container
        const w = node.attrs.width || '';
        if (w) {
          row.style.maxWidth = w;
          row.style.margin = '0 auto';
        } else {
          row.style.maxWidth = '';
          row.style.margin = '';
        }

        // Mirror frontend: shadow (+color) renders under the whole block
        const sh = node.attrs.shadow || '';
        if (sh && SHADOW_PREVIEW[sh]) {
          let preview = SHADOW_PREVIEW[sh];
          const sc = node.attrs.shadowColor || '';
          const sm = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(sc);
          if (sm && sh !== 'none') {
            let hex = sm[1];
            if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
            const rgb = [0, 2, 4].map(i => parseInt(hex.substr(i, 2), 16)).join(',');
            preview = preview.replace(/rgba\(0,0,0/g, `rgba(${rgb}`);
          }
          dom.style.boxShadow = preview;
        } else {
          dom.style.boxShadow = '';
        }
      }

      renderMedia();
      renderButtons();
      renderBadge();
      applyLayout();

      const selectHero = (event) => {
        event.preventDefault();
        event.stopPropagation();
        const pos = getPos();
        if (typeof pos !== 'number') return;
        const { state, dispatch } = editor.view;
        const hero = state.doc.nodeAt(pos);
        if (!hero || hero.type.name !== 'heroEmbed') return;
        editor.view.focus();
        dispatch(state.tr.setSelection(NodeSelection.create(state.doc, pos)));
      };
      header.addEventListener('click', selectHero);

      return {
        dom,
        contentDOM: textCol,
        stopEvent(event) {
          const t = event.target;
          return t === variantBtn || t === settingsBtn || t === delBtn || t === rmMediaBtn
            || Boolean(t?.closest?.('.vl-hero-header'))
            || Boolean(mediaCol.contains(t))
            || Boolean(buttonsRow.contains(t))
            || t === badgeEl || badgeEl.contains(t);
        },
        ignoreMutation() {
          // ProseMirror handles input events inside contentDOM itself. Ignore its
          // resulting DOM mutations, as in the other editor block node views.
          return true;
        },
        update(updatedNode) {
          if (updatedNode.type.name !== 'heroEmbed') return false;
          node = updatedNode;
          renderMedia();
          renderButtons();
          renderBadge();
          applyLayout();
          return true;
        },
      };
    };
  },
});

export { HeroEmbed, HeroHeading, HeroSubtitle, DEFAULT_HERO_ATTRS, defaultHeroContent, insertHero, setHeroAttr };
