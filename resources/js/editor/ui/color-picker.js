/**
 * HSV color picker popover + presets.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
const COLOR_PRESETS = [
  '#111827', '#1d4ed8', '#0ea5e9', '#06b6d4', '#14b8a6',
  '#10b981', '#22c55e',
  '#84cc16', '#f59e0b', '#f97316', '#ef4444', '#be185d',
  '#8b5cf6', '#4f46e5',
  '#f8fafc', '#e2e8f0', '#94a3b8', '#64748b', '#334155',
  '#1e293b', '#020617',
];

// ── Color conversion helpers ────────────────────────────────────────────────
function hexToRgb(hex) {
  let h = String(hex || '').replace(/^#/, '');
  if (h.length === 3) h = h.split('').map(c => c + c).join('');
  if (!/^[0-9a-fA-F]{6}$/.test(h)) return null;
  const n = parseInt(h, 16);
  return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
}

function rgbToHsv(r, g, b) {
  r /= 255; g /= 255; b /= 255;
  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  const d = max - min;
  let h = 0;
  if (d !== 0) {
    if (max === r) h = ((((g - b) / d) % 6) + 6) % 6;
    else if (max === g) h = (b - r) / d + 2;
    else h = (r - g) / d + 4;
    h *= 60;
  }
  return { h, s: max === 0 ? 0 : d / max, v: max };
}

function hsvToHex(h, s, v) {
  const c = v * s;
  const hp = ((((h % 360) + 360) % 360) / 60);
  const x = c * (1 - Math.abs((hp % 2) - 1));
  let rgb;
  if (hp < 1) rgb = [c, x, 0];
  else if (hp < 2) rgb = [x, c, 0];
  else if (hp < 3) rgb = [0, c, x];
  else if (hp < 4) rgb = [0, x, c];
  else if (hp < 5) rgb = [x, 0, c];
  else rgb = [c, 0, x];
  const m = v - c;
  return '#' + rgb.map(n => Math.round((n + m) * 255).toString(16).padStart(2, '0')).join('');
}

// ── Shared color picker popover ─────────────────────────────────────────────
const COLOR_SWATCH_HINT = 'Click to open the color palette';

/** Tooltip for a swatch that opens the palette: the setting name plus the action. */
function colorSwatchTitle(label) {
  const hint = typeof tjs === 'function' ? tjs(COLOR_SWATCH_HINT) : COLOR_SWATCH_HINT;
  return label ? `${label} — ${hint}` : hint;
}

function openColorPicker({ anchor, currentColor, onSelect, onRemove, onPreview }) {
  const existing = document.body.querySelector('.vl-color-picker');
  if (existing) existing.remove();

  const initialRgb = typeof currentColor === 'string' ? hexToRgb(currentColor) : null;
  const hsv = initialRgb ? rgbToHsv(initialRgb.r, initialRgb.g, initialRgb.b) : { h: 210, s: 0.85, v: 0.9 };
  const currentHex = () => hsvToHex(hsv.h, hsv.s, hsv.v);

  const popover = document.createElement('div');
  popover.className = 'vl-color-picker';

  const emitPreview = () => { if (typeof onPreview === 'function') onPreview(currentHex()); };

  // ── Theme colors ──────────────────────────────────────────────────────────
  const themeRaw = (window.VL_THEME_COLORS && typeof window.VL_THEME_COLORS === 'object') ? window.VL_THEME_COLORS : {};
  const themeEntries = Object.entries(themeRaw)
    .map(([name, info]) => ({
      name,
      label: info && typeof info === 'object' && info.label ? String(info.label) : String(name),
      value: info && typeof info === 'object' ? info.value : info,
    }))
    .filter(entry => typeof entry.value === 'string' && /^#[0-9a-fA-F]{3,8}$/.test(entry.value));

  if (themeEntries.length > 0) {
    const sectionLabel = document.createElement('div');
    sectionLabel.className = 'vl-color-section-label';
    sectionLabel.textContent = tjs('Theme Colors');
    popover.appendChild(sectionLabel);

    const themeSwatches = document.createElement('div');
    themeSwatches.className = 'vl-color-swatches';
    themeEntries.forEach(({ name, label, value }) => {
      const swatch = document.createElement('button');
      swatch.type = 'button';
      swatch.className = 'vl-color-swatch' + (value.toLowerCase() === String(currentColor || '').toLowerCase() ? ' is-active' : '');
      swatch.style.background = value;
      swatch.title = label + ' (' + name + ')';
      swatch.addEventListener('mousedown', e => {
        e.preventDefault();
        onSelect(value);
        popover.remove();
      });
      themeSwatches.appendChild(swatch);
    });
    popover.appendChild(themeSwatches);
  }

  // ── Saturation / Value area + Hue slider ─────────────────────────────────
  const svArea = document.createElement('div');
  svArea.className = 'vl-color-sv';
  const svPointer = document.createElement('div');
  svPointer.className = 'vl-color-sv-pointer';
  svArea.appendChild(svPointer);

  const hueTrack = document.createElement('div');
  hueTrack.className = 'vl-color-hue';
  const huePointer = document.createElement('div');
  huePointer.className = 'vl-color-hue-pointer';
  hueTrack.appendChild(huePointer);

  popover.appendChild(svArea);
  popover.appendChild(hueTrack);

  // ── Presets ───────────────────────────────────────────────────────────────
  const swatches = document.createElement('div');
  swatches.className = 'vl-color-swatches';
  COLOR_PRESETS.forEach(hex => {
    const swatch = document.createElement('button');
    swatch.type = 'button';
    swatch.className = 'vl-color-swatch' + (hex === currentColor ? ' is-active' : '');
    swatch.style.background = hex;
    swatch.addEventListener('mousedown', e => {
      e.preventDefault();
      onSelect(hex);
      popover.remove();
    });
    swatches.appendChild(swatch);
  });
  popover.appendChild(swatches);

  // ── Hex row ───────────────────────────────────────────────────────────────
  const hexRow = document.createElement('div');
  hexRow.className = 'vl-color-hex-row';

  const currentChip = document.createElement('span');
  currentChip.className = 'vl-color-current';

  const hexLabel = document.createElement('span');
  hexLabel.textContent = '#';
  hexLabel.style.cssText = 'font-size:13px;color:#9ca3af';

  const hexInput = document.createElement('input');
  hexInput.type = 'text';
  hexInput.className = 'vl-color-hex-input';
  hexInput.placeholder = 'ff0000';
  hexInput.value = (currentColor || '').replace(/^#/, '');

  const renderScale = () => {
    svArea.style.background =
      'linear-gradient(to top, #000, rgba(0,0,0,0)), linear-gradient(to right, #fff, rgba(255,255,255,0)), ' +
      hsvToHex(hsv.h, 1, 1);
    svPointer.style.left = (hsv.s * 100) + '%';
    svPointer.style.top = ((1 - hsv.v) * 100) + '%';
    huePointer.style.left = (hsv.h / 360) * 100 + '%';
    currentChip.style.background = currentHex();
  };

  const syncFromHex = val => {
    const rgb = hexToRgb(val);
    if (!rgb) return;
    const next = rgbToHsv(rgb.r, rgb.g, rgb.b);
    hsv.h = next.h;
    hsv.s = next.s;
    hsv.v = next.v;
    renderScale();
  };

  hexInput.addEventListener('input', () => {
    let val = hexInput.value.trim();
    if (!val) {
      swatches.querySelectorAll('.vl-color-swatch').forEach(s => s.classList.remove('is-active'));
      return;
    }
    if (/^[0-9a-fA-F]{6}$/.test(val)) {
      swatches.querySelectorAll('.vl-color-swatch').forEach(s => s.classList.remove('is-active'));
      hexInput.value = val.toLowerCase();
      syncFromHex(val.toLowerCase());
    } else if (/^#[0-9a-fA-F]{6}$/.test(val)) {
      swatches.querySelectorAll('.vl-color-swatch').forEach(s => s.classList.remove('is-active'));
      hexInput.value = val.slice(1).toLowerCase();
      syncFromHex(val.slice(1).toLowerCase());
    }
  });
  hexInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      let val = hexInput.value.trim();
      if (val) {
        if (/^[0-9a-fA-F]{6}$/.test(val)) onSelect('#' + val);
        else if (/^#[0-9a-fA-F]{6}$/.test(val)) onSelect(val);
        else onSelect(val);
      }
    }
  });

  // ── Drag handling (mouse + touch via Pointer Events) ─────────────────────
  let dragMode = null;

  const trackSv = e => {
    const rect = svArea.getBoundingClientRect();
    hsv.s = rect.width ? Math.min(Math.max((e.clientX - rect.left) / rect.width, 0), 1) : 0;
    hsv.v = rect.height ? 1 - Math.min(Math.max((e.clientY - rect.top) / rect.height, 0), 1) : 0;
    renderScale();
    hexInput.value = currentHex().slice(1);
    emitPreview();
  };
  const trackHue = e => {
    const rect = hueTrack.getBoundingClientRect();
    hsv.h = rect.width ? Math.min(Math.max((e.clientX - rect.left) / rect.width, 0), 1) * 360 : 0;
    renderScale();
    hexInput.value = currentHex().slice(1);
    emitPreview();
  };
  const stopDrag = () => {
    if (!dragMode) return;
    dragMode = null;
    onSelect(currentHex());
  };

  svArea.addEventListener('pointerdown', e => {
    e.preventDefault();
    dragMode = 'sv';
    svArea.setPointerCapture(e.pointerId);
    trackSv(e);
  });
  svArea.addEventListener('pointermove', e => {
    if (dragMode === 'sv') trackSv(e);
  });
  hueTrack.addEventListener('pointerdown', e => {
    e.preventDefault();
    dragMode = 'hue';
    hueTrack.setPointerCapture(e.pointerId);
    trackHue(e);
  });
  hueTrack.addEventListener('pointermove', e => {
    if (dragMode === 'hue') trackHue(e);
  });
  ['pointerup', 'pointercancel'].forEach(evt => {
    svArea.addEventListener(evt, stopDrag);
    hueTrack.addEventListener(evt, stopDrag);
  });

  if (onRemove) {
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'vl-color-remove-btn';
    removeBtn.textContent = '✕ ' + tjs('Remove');
    removeBtn.addEventListener('mousedown', e => {
      e.preventDefault();
      onRemove();
      popover.remove();
    });
    hexRow.appendChild(removeBtn);
  }

  hexRow.appendChild(currentChip);
  hexRow.appendChild(hexLabel);
  hexRow.appendChild(hexInput);
  popover.appendChild(hexRow);
  document.body.appendChild(popover);

  renderScale();

  const ar = anchor.getBoundingClientRect();
  const pw = popover.offsetWidth;
  const ph = popover.offsetHeight;
  let left = Math.max(4, ar.left - 4);
  let top = ar.bottom + 4;
  if (left + pw > window.innerWidth - 4) left = window.innerWidth - pw - 4;
  if (top + ph > window.innerHeight - 4) top = Math.max(4, ar.top - ph - 4);
  popover.style.left = left + 'px';
  popover.style.top = top + 'px';

  setTimeout(() => {
    const close = e => {
      if (!popover.contains(e.target) && e.target !== anchor) {
        popover.remove();
        document.removeEventListener('mousedown', close, true);
      }
    };
    document.addEventListener('mousedown', close, true);
  }, 0);
}

// ── Text color picker helper (for toolbar button) ──────────────────────────
function __openTextColorPicker(editor) {
  const btn = document.querySelector('.vl-editor-toolbar .vl-tc-btn');
  if (!btn) return;
  const color = editor.getAttributes('textColor').color || '#000';
  openColorPicker({
    anchor: btn,
    currentColor: color,
    onSelect(hex) {
      editor.chain().focus().toggleMark('textColor', { color: hex }).run();
    },
    onRemove() {
      editor.chain().focus().unsetMark('textColor').run();
    },
  });
}

// ── Emoji presets ────────────────────────────────────────────────────────────

export { COLOR_PRESETS, COLOR_SWATCH_HINT, colorSwatchTitle, hexToRgb, rgbToHsv, hsvToHex, openColorPicker, __openTextColorPicker };
