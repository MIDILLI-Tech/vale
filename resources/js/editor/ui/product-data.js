/**
 * Live product data for the product embed block.
 *
 * Id-only storage: name/price/image are never stored in the document, they
 * are fetched from the server and shared through this per-page cache.
 */
import { bp, getCsrfToken } from '../utils.js';

// Shared per-page cache: id -> { state: 'loading'|'loaded'|'error', data?, promise? }
const productCache = new Map();

function fetchProduct(id) {
  const cached = productCache.get(id);
  if (cached) {
    if (cached.state === 'loaded') return Promise.resolve(cached.data);
    if (cached.state === 'loading') return cached.promise;
  }
  const promise = fetch(bp(`/admin/products/${id}/json`), { headers: { 'X-CSRF-Token': getCsrfToken() } })
    .then((r) => {
      if (!r.ok) throw new Error(`HTTP ${r.status}`);
      return r.json();
    })
    .then((data) => {
      productCache.set(id, { state: 'loaded', data });
      return data;
    })
    .catch(() => {
      productCache.set(id, { state: 'error' });
      return null;
    });
  productCache.set(id, { state: 'loading', promise });
  return promise;
}

function getCachedProduct(id) {
  return productCache.get(id) || null;
}

function isPubliclyVisible(data) {
  if (!data) return false;
  if (data.status !== 'published' && data.status !== 'unlisted') return false;
  if (data.expires_at && new Date(data.expires_at).getTime() <= Date.now()) return false;
  return true;
}

function findVariation(data, variationId) {
  if (!data || !variationId) return null;
  const list = Array.isArray(data.variations) ? data.variations : [];
  return list.find((v) => parseInt(v.id, 10) === parseInt(variationId, 10)) || null;
}

function variationLabel(v) {
  if (!v) return '';
  const attrs = v.attributes && typeof v.attributes === 'object' ? v.attributes : {};
  const values = Object.values(attrs).filter((x) => x !== '' && x !== null && x !== undefined);
  if (values.length) return values.join(' / ');
  return v.sku ? `#${v.sku}` : `#${v.id}`;
}

function variationPriceRange(data) {
  const list = Array.isArray(data?.variations) ? data.variations : [];
  const prices = list
    .map((v) => parseFloat(v.effective_price ?? v.price ?? 0))
    .filter((p) => Number.isFinite(p) && p > 0);
  if (!prices.length) return null;
  return { min: Math.min(...prices), max: Math.max(...prices) };
}

export { fetchProduct, findVariation, getCachedProduct, isPubliclyVisible, variationLabel, variationPriceRange };
