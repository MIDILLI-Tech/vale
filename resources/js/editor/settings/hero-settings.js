/**
 * Hero section settings dialog (declarative schema + a few custom fields).
 *
 * Two variants share the dialog:
 *   - Split Hero: adds an image/media column (image, alt, side) + corner badge.
 *   - CTA Card:  centered, no media column.
 * Common to both: section background, shadow, corner rounding and up to two
 * theme-styled buttons (text, link, target, primary/secondary style).
 */
import { openBlockSettings, renderField, labelEl } from './schema-modal.js';
import { linkField } from './link-button-settings.js';
import { CAROUSEL_SETTINGS_FIELDS, FIT_OPTIONS } from './carousel-settings.js';
import { openMediaBrowserForCarousel, openMediaPickerExtended } from '../media/browse.js';
import { colorSwatchTitle, openColorPicker } from '../ui/color-picker.js';

const VARIANT_OPTIONS = [
  { v: 'split', l: tjs('Split Hero — heading beside media') },
  { v: 'cta', l: tjs('CTA Card — centered caption') },
];

const MEDIA_SIDE_OPTIONS = [
  { v: 'right', l: tjs('Media on the right') },
  { v: 'left', l: tjs('Media on the left') },
];

const LEGACY_BG_COLORS = {
  white: '#ffffff',
  gray: '#f3f4f6',
  dark: '#111827',
};

function buildBackgroundField(ctx) {
  const value = ctx.node?.attrs?.bg || '';
  const initialColor = LEGACY_BG_COLORS[value] || value;
  const wrap = document.createElement('div');
  wrap.appendChild(labelEl(tjs('Section Background')));

  const row = document.createElement('div');
  row.className = 'vl-modal-flex-row';

  const swatch = document.createElement('button');
  swatch.type = 'button';
  swatch.className = 'vl-color-btn';
  swatch.title = colorSwatchTitle(tjs('Section Background'));
  swatch.style.background = initialColor || 'linear-gradient(135deg, color-mix(in srgb, var(--theme-primary) 12%, var(--theme-surface)), var(--theme-surface))';

  const input = document.createElement('input');
  input.type = 'text';
  input.className = 'vl-input flex-1';
  input.placeholder = tjs('Theme tint (default)');
  input.value = /^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i.test(value) ? value : '';

  const reset = document.createElement('button');
  reset.type = 'button';
  reset.className = 'vl-btn vl-btn-secondary';
  reset.textContent = '✕';
  reset.title = tjs('Theme tint (default)');

  const setValue = color => {
    input.value = color;
    swatch.style.background = color || 'linear-gradient(135deg, color-mix(in srgb, var(--theme-primary) 12%, var(--theme-surface)), var(--theme-surface))';
    ctx.setValue('bg', color);
  };

  swatch.addEventListener('click', () => {
    const current = /^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i.test(input.value)
      ? input.value
      : initialColor || '#ffffff';
    openColorPicker({
      anchor: swatch,
      currentColor: current,
      onPreview: color => { swatch.style.background = color; },
      onSelect: setValue,
      onRemove: () => setValue(''),
    });
  });

  input.addEventListener('input', () => {
    const color = input.value.trim();
    if (!color || /^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i.test(color)) {
      swatch.style.background = color || 'linear-gradient(135deg, color-mix(in srgb, var(--theme-primary) 12%, var(--theme-surface)), var(--theme-surface))';
      ctx.setValue('bg', color);
    }
  });
  reset.addEventListener('click', () => setValue(''));

  row.appendChild(swatch);
  row.appendChild(input);
  row.appendChild(reset);
  wrap.appendChild(row);
  return wrap;
}

function buildShadowColorField(ctx) {
  const value = ctx.node?.attrs?.shadowColor || '';
  const wrap = document.createElement('div');
  wrap.appendChild(labelEl(tjs('Shadow Color')));

  const row = document.createElement('div');
  row.className = 'vl-modal-flex-row';
  const swatch = document.createElement('button');
  swatch.type = 'button';
  swatch.className = 'vl-color-btn';
  swatch.style.background = value || '#000000';
  swatch.title = colorSwatchTitle(tjs('Shadow Color'));
  swatch.addEventListener('click', () => {
    openColorPicker({
      anchor: swatch,
      currentColor: value || '#000000',
      onPreview: color => { swatch.style.background = color; },
      onSelect: color => {
        swatch.style.background = color;
        ctx.setValue('shadowColor', color);
      },
      onRemove: () => {
        swatch.style.background = '#000000';
        ctx.setValue('shadowColor', '');
      },
    });
  });

  const reset = document.createElement('button');
  reset.type = 'button';
  reset.className = 'vl-btn vl-btn-secondary';
  reset.textContent = tjs('Default');
  reset.addEventListener('click', () => {
    swatch.style.background = '#000000';
    ctx.setValue('shadowColor', '');
  });
  row.appendChild(swatch);
  row.appendChild(reset);
  wrap.appendChild(row);
  return wrap;
}

function buildLightboxField(ctx) {
  const wrap = document.createElement('label');
  wrap.className = 'vl-modal-label-check vl-hero-lightbox-toggle mt-3 mb-2';
  const checkbox = document.createElement('input');
  checkbox.id = 'vl-hero-lightbox-enabled';
  checkbox.name = 'heroLightbox';
  checkbox.type = 'checkbox';
  checkbox.checked = ctx.node?.attrs?.lightbox === 'true' || ctx.node?.attrs?.lightbox === true;
  checkbox.addEventListener('change', () => ctx.setValue('lightbox', checkbox.checked ? 'true' : 'false'));
  wrap.appendChild(checkbox);
  wrap.appendChild(document.createTextNode(' ' + tjs('Enable Lightbox (click to enlarge)')));
  return wrap;
}

const HERO_CAROUSEL_DEFAULTS = {
  autoplay: 'true',
  duration: '5000',
  showArrows: 'true',
  showDots: 'true',
  pauseBtn: 'false',
  fit: 'cover',
  height: '100%',
  transition: 'fade',
  bg: '',
};

function buildCarouselSettings(ctx) {
  const attrs = ctx.node?.attrs || {};
  const panel = document.createElement('div');
  panel.className = 'vl-hero-carousel-settings';
  panel.style.display = attrs.mediaType === 'carousel' ? '' : 'none';
  const heading = document.createElement('h4');
  heading.className = 'vl-modal-subhead';
  heading.textContent = tjs('Carousel Settings');
  panel.appendChild(heading);

  CAROUSEL_SETTINGS_FIELDS.forEach(field => {
    if (field.type === 'divider' || field.key === 'lightbox') return;
    const attr = 'carousel' + field.key[0].toUpperCase() + field.key.slice(1);
    const stored = attrs[attr] ?? HERO_CAROUSEL_DEFAULTS[field.key];
    const value = field.type === 'checkbox'
      ? (stored === true || stored === 'true')
      : stored;
    const rendered = renderField(ctx.editor, { ...field, key: attr }, value, ctx);
    rendered.el.style.marginBottom = '10px';
    const input = rendered.el.querySelector('input, select');
    const eventName = field.type === 'checkbox' || field.type === 'select' ? 'change' : 'input';
    input?.addEventListener(eventName, () => {
      const next = rendered.read();
      ctx.setValue(attr, field.type === 'checkbox' ? (next ? 'true' : 'false') : String(next));
    });
    panel.appendChild(rendered.el);
  });
  return panel;
}

const SHADOW_OPTIONS = [
  { v: '', l: tjs('Soft (default)') },
  { v: 'none', l: tjs('None') },
  { v: 'sm', l: tjs('Small') },
  { v: 'md', l: tjs('Medium') },
  { v: 'lg', l: tjs('Large') },
  { v: 'xl', l: tjs('Extra large') },
];

const RADIUS_OPTIONS = [
  { v: '', l: tjs('Default') },
  { v: '0px', l: '0px' },
  { v: '4px', l: '4px' },
  { v: '8px', l: '8px' },
  { v: '12px', l: '12px' },
  { v: '16px', l: '16px' },
  { v: '24px', l: '24px' },
  { v: '32px', l: '32px' },
];

const TARGET_OPTIONS = [
  { v: '_self', l: tjs('Same window') },
  { v: '_blank', l: tjs('New tab') },
];

const STYLE_OPTIONS = [
  { v: 'primary', l: tjs('Primary (theme accent)') },
  { v: 'secondary', l: tjs('Secondary (outline)') },
];

/**
 * Variant selector + show/hide of the split-only media/photo group.
 */
function buildVariantField(ctx) {
  const attrs = ctx.node?.attrs || {};
  const current = attrs.variant || 'split';

  const wrap = document.createElement('div');
  wrap.className = 'vl-modal-flex-col';
  wrap.appendChild(labelEl(tjs('Variants')));

  const sel = document.createElement('select');
  sel.className = 'vl-select w-full';
  VARIANT_OPTIONS.forEach(o => {
    const opt = document.createElement('option');
    opt.value = o.v;
    opt.textContent = tjs(o.l);
    if (current === o.v) opt.selected = true;
    sel.appendChild(opt);
  });

  function toggleVisibility(variant) {
    const splitGroup = ctx.modal.querySelector('[data-hero-split-group]');
    if (splitGroup) splitGroup.style.display = variant === 'split' ? '' : 'none';
  }

  sel.addEventListener('change', () => {
    ctx.setValue('variant', sel.value);
    toggleVisibility(sel.value);
  });

  wrap.appendChild(sel);
  toggleVisibility(current);
  return wrap;
}

/**
 * Image/media column group (split only).
 */
function buildMediaGroup(ctx) {
  const attrs = ctx.node?.attrs || {};
  const wrap = document.createElement('div');
  wrap.className = 'vl-hero-media-settings vl-modal-flex-col';
  wrap.setAttribute('data-hero-split-group', '1');
  wrap.style.display = attrs.variant === 'cta' ? 'none' : '';

  const mediaLabel = labelEl(tjs('Hero Media'));
  mediaLabel.htmlFor = 'vl-hero-media-type';
  wrap.appendChild(mediaLabel);
  const mediaType = document.createElement('select');
  mediaType.id = 'vl-hero-media-type';
  mediaType.name = 'heroMediaType';
  mediaType.className = 'vl-select w-full mb-2';
  [
    { value: 'image', label: tjs('Single Media') },
    { value: 'carousel', label: tjs('Carousel') },
  ].forEach(item => {
    const option = document.createElement('option');
    option.value = item.value;
    option.textContent = item.label;
    mediaType.appendChild(option);
  });
  mediaType.value = attrs.mediaType === 'carousel' ? 'carousel' : 'image';
  mediaType.addEventListener('change', () => {
    ctx.setValue('mediaType', mediaType.value);
    updateMediaVisibility();
  });
  wrap.appendChild(mediaType);

  const imageControls = document.createElement('div');
  imageControls.className = 'vl-modal-flex-col';
  const prev = document.createElement('div');
  prev.className = 'vl-card-settings-preview';
  const row = document.createElement('div');
  row.className = 'vl-modal-flex-row mb-2';
  const input = document.createElement('input');
  input.type = 'text';
  input.className = 'vl-input flex-1';
  input.placeholder = tjs('Image URL or pick from library…');
  input.value = attrs.imageSrc || '';
  const pick = document.createElement('button');
  pick.type = 'button';
  pick.className = 'vl-btn vl-btn-secondary';
  pick.textContent = '🖼';
  pick.title = tjs('Pick from media library');
  pick.addEventListener('click', () => {
    openMediaPickerExtended(ctx.editor, (url) => {
      input.value = url || '';
      ctx.setValue('imageSrc', input.value.trim());
      paintPreview();
    }, 'image/', input.value.trim());
  });
  const clear = document.createElement('button');
  clear.type = 'button';
  clear.className = 'vl-btn vl-btn-secondary';
  clear.textContent = '✕';
  clear.title = tjs('Remove media');
  clear.addEventListener('click', () => {
    input.value = '';
    ctx.setValue('imageSrc', '');
    paintPreview();
  });

  function isVideoUrl(u) { return /\.(mp4|webm|ogg|mov|m4v)(\?|$)/i.test(u) || u.includes('youtube.com') || u.includes('youtu.be') || u.includes('vimeo.com'); }
  function paintPreview() {
    const u = input.value.trim();
    if (!u) { prev.innerHTML = `<span>${tjs('No image')}</span>`; return; }
    if (isVideoUrl(u) && !u.includes('youtube.com') && !u.includes('youtu.be') && !u.includes('vimeo.com')) {
      prev.innerHTML = `<video src="${u.replace(/"/g, '&quot;')}" muted playsinline style="width:100%;height:auto;display:block;"></video>`;
    } else if (isVideoUrl(u)) {
      prev.innerHTML = `<div style="aspect-ratio:16/9;display:flex;align-items:center;justify-content:center;">🎬</div>`;
    } else {
      prev.innerHTML = `<img src="${u.replace(/"/g, '&quot;')}" alt="">`;
    }
  }
  paintPreview();
  imageControls.appendChild(prev);
  row.appendChild(input);
  row.appendChild(pick);
  row.appendChild(clear);
  imageControls.appendChild(row);
  const fitRendered = renderField(ctx.editor, { key: 'imageFit', type: 'select', label: tjs('Media Fit'), options: FIT_OPTIONS }, attrs.imageFit || 'cover', ctx);
  fitRendered.el.style.marginBottom = '10px';
  fitRendered.el.querySelector('select').addEventListener('change', event => ctx.setValue('imageFit', event.target.value));
  imageControls.appendChild(fitRendered.el);

  const autoplayLbl = document.createElement('label');
  autoplayLbl.className = 'vl-modal-label-check mb-2';
  const autoplayInput = document.createElement('input');
  autoplayInput.type = 'checkbox';
  autoplayInput.checked = (attrs.mediaAutoplay || 'false') === 'true';
  autoplayInput.addEventListener('change', () => ctx.setValue('mediaAutoplay', autoplayInput.checked ? 'true' : 'false'));
  autoplayLbl.appendChild(autoplayInput);
  autoplayLbl.appendChild(document.createTextNode(' ' + tjs('Autoplay (video only)')));
  imageControls.appendChild(autoplayLbl);

  const carouselControls = document.createElement('div');
  const carouselPreview = document.createElement('div');
  carouselPreview.className = 'vl-card-settings-preview';
  const carouselRow = document.createElement('div');
  carouselRow.className = 'vl-modal-flex-row mb-2';
  const carouselCount = document.createElement('span');
  carouselCount.className = 'vl-modal-subtext flex-1';
  const pickCarousel = document.createElement('button');
  pickCarousel.type = 'button';
  pickCarousel.className = 'vl-btn vl-btn-secondary';
  pickCarousel.textContent = tjs('Select Carousel Images');
  pickCarousel.addEventListener('click', () => {
    const current = (ctx.getValue('carouselImages') || '').split(',').filter(Boolean);
    openMediaBrowserForCarousel(ctx.editor, urls => {
      ctx.setValue('carouselImages', urls.join(','));
      paintCarouselPreview(urls);
    }, current);
  });
  function paintCarouselPreview(urls) {
    const selected = urls || (attrs.carouselImages || '').split(',').filter(Boolean);
    carouselCount.textContent = selected.length
      ? tjs(':count images selected', { ':count': selected.length })
      : tjs('No images selected');
    carouselPreview.replaceChildren();
    selected.slice(0, 4).forEach(url => {
      const img = document.createElement('img');
      img.src = url;
      img.alt = '';
      carouselPreview.appendChild(img);
    });
  }
  paintCarouselPreview();
  carouselRow.appendChild(carouselCount);
  carouselRow.appendChild(pickCarousel);
  carouselControls.appendChild(carouselPreview);
  carouselControls.appendChild(carouselRow);
  wrap.appendChild(imageControls);
  wrap.appendChild(carouselControls);
  const carouselSettings = buildCarouselSettings(ctx);
  wrap.appendChild(carouselSettings);

  const altRendered = renderField(ctx.editor, { key: '__alt', type: 'text', label: tjs('Image Alt Text') }, attrs.imageAlt || '', ctx);
  altRendered.el.style.marginBottom = '10px';
  wrap.appendChild(buildLightboxField(ctx));
  wrap.appendChild(altRendered.el);

  const sideRendered = renderField(ctx.editor, { key: 'mediaSide', type: 'select', label: tjs('Media Side'), options: MEDIA_SIDE_OPTIONS }, attrs.mediaSide || 'right', ctx);
  sideRendered.el.querySelector('select').addEventListener('change', event => ctx.setValue('mediaSide', event.target.value));
  wrap.appendChild(sideRendered.el);

  const badgeRendered = renderField(ctx.editor, { key: 'badge', type: 'text', label: tjs('Corner Badge'), placeholder: tjs('e.g. Since 2024') }, attrs.badge || '', ctx);
  wrap.appendChild(badgeRendered.el);
  wrap.appendChild(buildBadgeColorFields(ctx));

  function updateMediaVisibility() {
    const isCarousel = mediaType.value === 'carousel';
    imageControls.style.display = isCarousel ? 'none' : '';
    carouselControls.style.display = isCarousel ? '' : 'none';
    carouselSettings.style.display = isCarousel ? '' : 'none';
    altRendered.el.style.display = isCarousel ? 'none' : '';
  }
  updateMediaVisibility();

  input.addEventListener('input', () => ctx.setValue('imageSrc', input.value.trim()));
  input.addEventListener('change', () => ctx.setValue('imageSrc', input.value.trim()));
  altRendered.read = () => {};
  altRendered.el.querySelector('input').addEventListener('input', () => ctx.setValue('imageAlt', altRendered.el.querySelector('input').value.trim()));
  badgeRendered.el.querySelector('input').addEventListener('input', () => ctx.setValue('badge', badgeRendered.el.querySelector('input').value.trim()));

  return wrap;
}

function buildBadgeColorFields(ctx) {
  const attrs = ctx.node?.attrs || {};
  const wrap = document.createElement('div');
  const themeBg = window.VL_THEME_COLORS?.['--theme-primary']?.value || '#4f46e5';
  [
    { key: 'badgeBg', label: tjs('Badge Background'), fallback: themeBg },
    { key: 'badgeTextColor', label: tjs('Badge Text Color'), fallback: '#ffffff' },
  ].forEach(({ key, label, fallback }) => {
    wrap.appendChild(labelEl(label));
    const row = document.createElement('div');
    row.className = 'vl-modal-flex-row mb-2';
    const swatch = document.createElement('button');
    swatch.type = 'button';
    swatch.className = 'vl-color-btn';
    swatch.title = colorSwatchTitle(label);
    swatch.style.background = attrs[key] || fallback;
    swatch.addEventListener('click', () => {
      openColorPicker({
        anchor: swatch,
        currentColor: attrs[key] || fallback,
        onPreview: color => { swatch.style.background = color; },
        onSelect: color => {
          swatch.style.background = color;
          ctx.setValue(key, color);
        },
        onRemove: () => {
          swatch.style.background = fallback;
          ctx.setValue(key, '');
        },
      });
    });
    const def = document.createElement('button');
    def.type = 'button';
    def.className = 'vl-btn vl-btn-secondary';
    def.textContent = tjs('Default');
    def.addEventListener('click', () => {
      swatch.style.background = fallback;
      ctx.setValue(key, '');
    });
    row.appendChild(swatch);
    row.appendChild(def);
    wrap.appendChild(row);
  });
  return wrap;
}

/**
 * One button group: text, link (via linkField proxied to button{N}Url), target + style.
 */
function buildButtonGroup(ctx, prefix, title) {
  const attrs = ctx.node?.attrs || {};
  const wrap = document.createElement('div');

  const h4 = document.createElement('h4');
  h4.className = 'vl-modal-subhead';
  h4.textContent = tjs(title);
  wrap.appendChild(h4);

  const textRendered = renderField(ctx.editor, { key: prefix + 'Text', type: 'text', label: tjs('Button Label') }, attrs[prefix + 'Text'] || '', ctx);
  const textInput = textRendered.el.querySelector('input');
  textInput.addEventListener('input', () => ctx.setValue(prefix + 'Text', textInput.value.trim()));
  wrap.appendChild(textRendered.el);

  const linkCtx = {
    ...ctx,
    getValue: k => ctx.getValue(k === 'href' ? prefix + 'Url' : k),
    setValue: (k, v) => ctx.setValue(k === 'href' ? prefix + 'Url' : k, v),
  };
  wrap.appendChild(linkField().render(linkCtx));

  const targetRendered = renderField(ctx.editor, { key: prefix + 'Target', type: 'select', label: tjs('Target'), options: TARGET_OPTIONS }, attrs[prefix + 'Target'] || '_self', ctx);
  targetRendered.el.querySelector('select').addEventListener('change', event => ctx.setValue(prefix + 'Target', event.target.value));
  wrap.appendChild(targetRendered.el);

  const defaultStyle = prefix === 'button2' ? 'secondary' : 'primary';
  const styleRendered = renderField(ctx.editor, { key: prefix + 'Style', type: 'select', label: tjs('Style'), options: STYLE_OPTIONS }, attrs[prefix + 'Style'] || defaultStyle, ctx);
  const styleSelect = styleRendered.el.querySelector('select');
  // Mirror the block preview (see hero-embed renderButtons): the swatches
  // show the custom color when set, otherwise the selected style's theme
  // default — so switching Style refreshes the preview automatically.
  const paintStylePreview = () => {
    const style = styleSelect.value === 'secondary' ? 'secondary' : 'primary';
    const themeBg = style === 'primary'
      ? (window.VL_THEME_COLORS?.['--theme-primary']?.value || '#1d4ed8')
      : (window.VL_THEME_COLORS?.['--theme-secondary']?.value || '#475569');
    const themeText = style === 'primary'
      ? '#ffffff'
      : (window.VL_THEME_COLORS?.['--theme-text']?.value || '#111827');
    swatch.style.background = ctx.getValue(colorKey) || themeBg;
    textColorSwatch.style.background = ctx.getValue(textColorKey) || themeText;
  };
  styleSelect.addEventListener('change', event => {
    ctx.setValue(prefix + 'Style', event.target.value);
    paintStylePreview();
  });
  wrap.appendChild(styleRendered.el);

  const colorLabel = labelEl(tjs('Button Color'));
  wrap.appendChild(colorLabel);
  const colorRow = document.createElement('div');
  colorRow.className = 'vl-modal-flex-row';
  const swatch = document.createElement('button');
  swatch.type = 'button';
  swatch.className = 'vl-color-btn';
  const cssVar = prefix === 'button1' ? '--theme-primary' : '--theme-secondary';
  const themeColor = window.VL_THEME_COLORS?.[cssVar]?.value || (prefix === 'button1' ? '#1d4ed8' : '#475569');
  const colorKey = prefix + 'Color';
  const currentColor = attrs[colorKey] || '';
  swatch.style.background = currentColor || `var(${cssVar}, ${themeColor})`;
  swatch.title = colorSwatchTitle(tjs('Button Color'));
  swatch.addEventListener('click', () => {
    openColorPicker({
      anchor: swatch,
      currentColor: currentColor || themeColor,
      onPreview: color => { swatch.style.background = color; },
      onSelect: color => {
        swatch.style.background = color;
        ctx.setValue(colorKey, color);
      },
      onRemove: () => {
        swatch.style.background = `var(${cssVar}, ${themeColor})`;
        ctx.setValue(colorKey, '');
      },
    });
  });
  const defaultButton = document.createElement('button');
  defaultButton.type = 'button';
  defaultButton.className = 'vl-btn vl-btn-secondary';
  defaultButton.textContent = tjs('Default');
  defaultButton.addEventListener('click', () => {
    swatch.style.background = `var(${cssVar}, ${themeColor})`;
    ctx.setValue(colorKey, '');
  });
  colorRow.appendChild(swatch);
  colorRow.appendChild(defaultButton);
  wrap.appendChild(colorRow);

  const textColorKey = prefix + 'TextColor';
  const textColorLabel = labelEl(tjs('Button Text Color'));
  wrap.appendChild(textColorLabel);
  const textColorRow = document.createElement('div');
  textColorRow.className = 'vl-modal-flex-row';
  const textColorSwatch = document.createElement('button');
  textColorSwatch.type = 'button';
  textColorSwatch.className = 'vl-color-btn';
  const defaultTextColor = prefix === 'button1' ? '#ffffff' : (window.VL_THEME_COLORS?.['--theme-text']?.value || '#111827');
  const currentTextColor = attrs[textColorKey] || '';
  textColorSwatch.style.background = currentTextColor || defaultTextColor;
  textColorSwatch.title = colorSwatchTitle(tjs('Button Text Color'));
  textColorSwatch.addEventListener('click', () => {
    openColorPicker({
      anchor: textColorSwatch,
      currentColor: currentTextColor || defaultTextColor,
      onPreview: color => { textColorSwatch.style.background = color; },
      onSelect: color => {
        textColorSwatch.style.background = color;
        ctx.setValue(textColorKey, color);
      },
      onRemove: () => {
        textColorSwatch.style.background = defaultTextColor;
        ctx.setValue(textColorKey, '');
      },
    });
  });
  const defaultTextButton = document.createElement('button');
  defaultTextButton.type = 'button';
  defaultTextButton.className = 'vl-btn vl-btn-secondary';
  defaultTextButton.textContent = tjs('Default');
  defaultTextButton.addEventListener('click', () => {
    textColorSwatch.style.background = defaultTextColor;
    ctx.setValue(textColorKey, '');
  });
  textColorRow.appendChild(textColorSwatch);
  textColorRow.appendChild(defaultTextButton);
  wrap.appendChild(textColorRow);

  paintStylePreview();

  return wrap;
}

export function openHeroSettings(editor, node, pos) {
  openBlockSettings(editor, pos, {
    title: tjs('Hero Settings'),
    size: 'vl-modal-md p-5',
    scrollBody: true,
    backdropGuardMs: 500,
    fields: [
      { type: 'custom', render: buildVariantField },
      { type: 'custom', render: buildMediaGroup },
      { type: 'divider' },
      { type: 'custom', render: buildBackgroundField },
      { key: 'shadow', type: 'select', label: tjs('Shadow'), options: SHADOW_OPTIONS },
      { type: 'custom', render: buildShadowColorField },
      { key: 'radius', type: 'select', label: tjs('Rounded'), options: RADIUS_OPTIONS },
      { key: 'width', type: 'text', label: tjs('Width'), placeholder: tjs('e.g. 50%, 800px, auto'), help: tjs('Section width: percent, pixels or auto for full width.') },
      { type: 'divider' },
      { type: 'custom', render: c => buildButtonGroup(c, 'button1', 'Primary Button') },
      { type: 'divider' },
      { type: 'custom', render: c => buildButtonGroup(c, 'button2', 'Secondary Button (optional)') },
    ],
  });
}
