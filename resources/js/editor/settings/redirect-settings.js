/**
 * Redirect embed settings dialog (declarative schema + hybrid delay field).
 *
 * Delay is a preset dropdown (Instant / 3 / 5 / 10 / 30 s / Custom...);
 * picking Custom reveals a number input (0–300 s).
 */
import { openBlockSettings } from './schema-modal.js';
import { internalLinkField } from '../ui/internal-link-field.js';

const PRESET_SECONDS = ['0', '3', '5', '10', '30'];

function buildUrlField(ctx) {
  // Internal/external link field (same as link buttons): the internal tab
  // searches posts, pages, products, custom content types and the static
  // site links; the external tab takes any address.
  return internalLinkField({
    label: tjs('Target URL'),
    value: ctx.node?.attrs?.url ?? '',
    onChange: (url, item) => {
      ctx.setValue('url', url);
      if (item && item.id && item.type) {
        ctx.setValue('internalType', item.type);
        ctx.setValue('internalId', String(item.id));
      } else {
        ctx.setValue('internalType', '');
        ctx.setValue('internalId', '');
      }
    },
  });
}

function buildDelayField(ctx) {
  const raw = String(ctx.node?.attrs?.seconds ?? '5');
  const isPreset = PRESET_SECONDS.includes(raw);

  const wrap = document.createElement('div');

  const label = document.createElement('label');
  label.className = 'vl-label';
  label.textContent = tjs('Delay');
  wrap.appendChild(label);

  const sel = document.createElement('select');
  sel.className = 'vl-select w-full';
  const opts = [
    { v: '0', l: tjs('Instant (302 Redirect)') },
    { v: '3', l: tjs('3 seconds') },
    { v: '5', l: tjs('5 seconds') },
    { v: '10', l: tjs('10 seconds') },
    { v: '30', l: tjs('30 seconds') },
    { v: 'custom', l: tjs('Custom...') },
  ];
  for (const o of opts) {
    const opt = document.createElement('option');
    opt.value = o.v;
    opt.textContent = o.l;
    sel.appendChild(opt);
  }
  sel.value = isPreset ? raw : 'custom';
  wrap.appendChild(sel);

  const numWrap = document.createElement('div');
  numWrap.style.marginTop = '8px';
  numWrap.style.display = isPreset ? 'none' : '';
  const numLabel = document.createElement('label');
  numLabel.className = 'vl-label';
  numLabel.textContent = tjs('Custom delay (seconds)');
  const num = document.createElement('input');
  num.type = 'number';
  num.className = 'vl-input w-full';
  num.min = '0';
  num.max = '300';
  num.value = isPreset ? '5' : raw;
  numWrap.appendChild(numLabel);
  numWrap.appendChild(num);
  wrap.appendChild(numWrap);

  function clamp(n) {
    if (!Number.isFinite(n)) return 5;
    return Math.max(0, Math.min(300, n));
  }

  function writePatch() {
    if (sel.value === 'custom') {
      ctx.setValue('seconds', clamp(parseInt(num.value, 10)));
    } else {
      ctx.setValue('seconds', parseInt(sel.value, 10));
    }
  }

  sel.addEventListener('change', () => {
    numWrap.style.display = sel.value === 'custom' ? '' : 'none';
    writePatch();
  });
  num.addEventListener('input', writePatch);

  writePatch();
  return wrap;
}

export function openRedirectSettings(editor, node, pos) {
  openBlockSettings(editor, pos, {
    title: tjs('Redirect Settings'),
    fields: [
      { type: 'custom', render: buildUrlField },
      { type: 'custom', render: buildDelayField },
    ],
  });
}
