/**
 * Dynamic Coupon Generator settings dialog (declarative schema).
 */
import { openBlockSettings } from './schema-modal.js';

/**
 * Renders the discount type dropdown + matching amount field. The two amount
 * attributes (discountPercent / discountFixed) are mutually exclusive — the
 * selected type decides which key gets the value stored.
 */
function buildDiscountTypeField(ctx) {
  const attrs = ctx.node?.attrs || {};
  const currentType = attrs.discountPercent ? 'percent' : 'fixed';
  const currentAmount = currentType === 'percent' ? (attrs.discountPercent || '') : (attrs.discountFixed || '');

  const wrap = document.createElement('div');

  const typeLabel = document.createElement('label');
  typeLabel.className = 'vl-label';
  typeLabel.textContent = tjs('Discount Type');

  const sel = document.createElement('select');
  sel.className = 'vl-select w-full';
  const percOpt = document.createElement('option');
  percOpt.value = 'percent';
  percOpt.textContent = tjs('Percentage (%)');
  const fixedOpt = document.createElement('option');
  fixedOpt.value = 'fixed';
  fixedOpt.textContent = tjs('Fixed Amount');
  if (currentType === 'fixed') fixedOpt.selected = true;
  sel.appendChild(percOpt);
  sel.appendChild(fixedOpt);

  const amountLabel = document.createElement('label');
  amountLabel.className = 'vl-label';
  amountLabel.style.marginTop = '12px';

  const amountRow = document.createElement('div');
  amountRow.className = 'vl-modal-flex-row';
  const input = document.createElement('input');
  input.type = 'number';
  input.className = 'vl-input flex-1';
  input.value = currentAmount;
  input.placeholder = currentType === 'percent' ? '10' : '5';
  amountRow.appendChild(input);

  function writePatch() {
    const isPercent = sel.value === 'percent';
    const value = input.value.trim();
    ctx.setValue('discountPercent', isPercent ? value : '');
    ctx.setValue('discountFixed', isPercent ? '' : value);
  }

  function sync() {
    amountLabel.textContent = sel.value === 'percent' ? tjs('Discount Percent (%)') : tjs('Discount Fixed Amount');
    input.placeholder = sel.value === 'percent' ? '10' : '5';
    writePatch();
  }

  sel.addEventListener('change', sync);
  input.addEventListener('input', writePatch);

  wrap.appendChild(typeLabel);
  wrap.appendChild(sel);
  wrap.appendChild(amountLabel);
  wrap.appendChild(amountRow);

  sync();
  return wrap;
}

export function openCouponSettings(editor, node, pos) {
  openBlockSettings(editor, pos, {
    title: tjs('Dynamic Coupon Settings'),
    fields: [
      { type: 'custom', render: buildDiscountTypeField },
      { key: 'prefix', type: 'text', label: tjs('Code Prefix'), placeholder: 'SALE' },
      { key: 'expiresDays', type: 'number', label: tjs('Expires (Days)') },
      { key: 'maxUsesPerEmail', type: 'number', label: tjs('Max Uses Per Email') },
      { key: 'startsAt', type: 'text', label: tjs('Starts At (optional)'), placeholder: 'YYYY-MM-DDTHH:MM' },
    ],
  });
}