/**
 * Product embed settings dialog (declarative schema + live server info).
 *
 * The block stores only the product id + display flags; name/price are
 * fetched live so the dialog always shows current data.
 */
import { openBlockSettings } from './schema-modal.js';
import { fetchProduct, findVariation, isPubliclyVisible, variationLabel, variationPriceRange } from '../ui/product-data.js';

function buildInfoBox(ctx) {
  const sym = window.CURRENCY_SYMBOL || '$';
  const info = document.createElement('div');
  info.className = 'vl-product-settings-info';
  info.textContent = tjs('Loading...');

  const id = ctx.node?.attrs?.productId || 0;
  if (!id) {
    info.textContent = tjs('No product selected.');
    return info;
  }

  fetchProduct(id).then((data) => {
    if (!data) {
      info.textContent = tjs('Product not found. It may have been deleted.');
      return;
    }
    const variationId = ctx.node?.attrs?.variationId || 0;
    const variation = findVariation(data, variationId);
    const displayName = variation ? `${data.name || ''} — ${variationLabel(variation)}` : (data.name || '');
    let price;
    if (variation) {
      const eff = parseFloat(variation.effective_price ?? variation.price ?? 0);
      const orig = parseFloat(variation.price ?? 0);
      price = orig > eff ? `${sym}${eff.toFixed(2)} <s>${sym}${orig.toFixed(2)}</s>` : `${sym}${eff.toFixed(2)}`;
    } else if (Array.isArray(data.variations) && data.variations.length) {
      const range = variationPriceRange(data);
      price = range && range.min !== range.max
        ? `${sym}${range.min.toFixed(2)} – ${sym}${range.max.toFixed(2)}`
        : `${sym}${parseFloat(data.effective_price ?? data.price ?? 0).toFixed(2)}`;
    } else {
      const eff = parseFloat(data.effective_price ?? data.price ?? 0);
      const orig = parseFloat(data.price ?? 0);
      price = data.on_sale && orig > eff ? `${sym}${eff.toFixed(2)} <s>${sym}${orig.toFixed(2)}</s>` : `${sym}${eff.toFixed(2)}`;
    }
    const badge = isPubliclyVisible(data) ? '' : ` &mdash; ${tjs('Not publicly visible')}`;
    info.innerHTML = `<strong>${displayName}</strong><br>${price}${data.short_description ? ' &mdash; ' + data.short_description : ''}${badge}`;
  });

  return info;
}

function buildVariationSelect(ctx) {
  const id = ctx.node?.attrs?.productId || 0;
  if (!id) return null;

  const wrap = document.createElement('div');
  wrap.className = 'vl-product-settings-variation';
  wrap.style.marginTop = '8px';
  wrap.textContent = tjs('Loading...');

  fetchProduct(id).then((data) => {
    const variations = Array.isArray(data?.variations) ? data.variations : [];
    if (!variations.length) {
      wrap.remove();
      return;
    }
    wrap.textContent = '';
    const label = document.createElement('label');
    label.className = 'vl-product-settings-label';
    label.textContent = tjs('Variation');
    label.style.display = 'block';
    label.style.fontSize = '12px';
    label.style.color = 'var(--vl-text-muted, #6b7280)';
    label.style.marginBottom = '4px';
    wrap.appendChild(label);

    const select = document.createElement('select');
    select.className = 'vl-input';
    select.style.width = '100%';
    const noneOpt = document.createElement('option');
    noneOpt.value = '0';
    noneOpt.textContent = tjs('All variations (price range)');
    select.appendChild(noneOpt);
    variations.forEach(v => {
      const opt = document.createElement('option');
      opt.value = v.id;
      const lbl = variationLabel(v);
      const price = parseFloat(v.effective_price ?? v.price ?? 0);
      opt.textContent = `${lbl} — ${price.toFixed(2)}`;
      select.appendChild(opt);
    });
    select.value = String(ctx.node?.attrs?.variationId || '0');
    select.addEventListener('change', () => {
      const variationId = parseInt(select.value, 10) || 0;
      const current = ctx.editor.state.doc.nodeAt(ctx.pos);
      if (current && current.type.name === 'productEmbed') {
        ctx.editor.chain().focus().command(({ tr }) => {
          tr.setNodeMarkup(ctx.pos, undefined, { ...current.attrs, variationId });
          return true;
        }).run();
      }
    });
    wrap.appendChild(select);
  });

  return wrap;
}

function buildChangeProductButton(ctx) {
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'vl-btn vl-btn-secondary';
  btn.style.marginTop = '8px';
  btn.textContent = tjs('Change Product');
  btn.addEventListener('click', () => {
    // Close current settings modal first
    if (ctx.modal && typeof ctx.modal.close === 'function') {
      ctx.modal.close();
    }
    // Open product picker
    import('../ui/product-picker.js').then(({ openProductPicker }) => {
      openProductPicker(ctx.editor, (product, variationId) => {
        const pos = ctx.pos;
        const current = ctx.editor.state.doc.nodeAt(pos);
        if (current && current.type.name === 'productEmbed') {
          ctx.editor.chain().focus().command(({ tr }) => {
            tr.setNodeMarkup(pos, undefined, {
              ...current.attrs,
              productId: product.id,
              variationId: variationId || 0,
            });
            return true;
          }).run();
        }
      });
    });
  });
  return btn;
}

export function openProductSettings(editor, node, pos) {
  openBlockSettings(editor, pos, {
    title: tjs('Product Settings'),
    fields: [
      { key: 'showImage', type: 'checkbox', label: tjs('Show product image') },
      { key: 'showPrice', type: 'checkbox', label: tjs('Show product price') },
      { key: 'showDescription', type: 'checkbox', label: tjs('Show short description') },
      { key: 'bg', type: 'color', label: tjs('Background'), pickerDefault: '#ffffff', btnFallback: 'var(--vl-bg-surface)' },
      {
        type: 'custom',
        render: ctx => buildInfoBox(ctx),
      },
      {
        type: 'custom',
        render: ctx => buildVariationSelect(ctx),
      },
      {
        type: 'custom',
        render: ctx => buildChangeProductButton(ctx),
      },
    ],
  });
}
