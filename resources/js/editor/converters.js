/**
 * HTML <-> editor round-trip converters (shortcodes, more/nextpage comments, spacer).
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { Mark } from '@tiptap/core';

import { TextColor } from './extensions/text-color.js';
import { NextPageNode } from './nodes/next-page-node.js';
import { bp, getCsrfToken, embedBaseUrl } from './utils.js';

// Serialize a single <div data-link-button> match into a [link_button] shortcode.
function linkButtonDivToShortcode(match, content) {
  let href = (match.match(/data-href="([^"]*)"/) || [])[1] || '';
  let target = (match.match(/data-target="([^"]*)"/) || [])[1] || '_self';

  // If data-href is empty, extract href from inner <a> tag (bubble menu link)
  if (!href && content) {
    const innerLink = content.match(/<a\b[^>]*href="([^"]*)"[^>]*>/i);
    if (innerLink) href = innerLink[1];
    const innerTarget = content.match(/<a\b[^>]*target="([^"]*)"[^>]*>/i);
    if (innerTarget) target = innerTarget[1];
  }

  // Strip inner <a> tags — the button itself is the link
  const cleanContent = content.replace(/<a\b[^>]*>(.*?)<\/a>/gi, '$1').trim();

  const bgColor = (match.match(/data-bg-color="([^"]*)"/) || [])[1] || '#000000';
  const textColor = (match.match(/data-text-color="([^"]*)"/) || [])[1] || '#ffffff';
  const borderWidth = (match.match(/data-border-width="([^"]*)"/) || [])[1] || '0px';
  const borderStyle = (match.match(/data-border-style="([^"]*)"/) || [])[1] || 'none';
  const borderColor = (match.match(/data-border-color="([^"]*)"/) || [])[1] || '#000000';
  const borderRadius = (match.match(/data-border-radius="([^"]*)"/) || [])[1] || '4px';
  const padding = (match.match(/data-padding="([^"]*)"/) || [])[1] || '10px 20px';
  const alignment = (match.match(/data-alignment="([^"]*)"/) || [])[1] || 'center';
  const fullWidth = (match.match(/data-full-width="([^"]*)"/) || [])[1] || 'false';

  const internalType = (match.match(/data-internal-type="([^"]*)"/) || [])[1] || '';
  const internalId = (match.match(/data-internal-id="([^"]*)"/) || [])[1] || '';
  let internalAttrs = '';
  if (internalType && internalId) {
    internalAttrs = ` internal_type="${internalType}" internal_id="${internalId}"`;
  }

  return `[link_button href="${href}" target="${target}"${internalAttrs} bgColor="${bgColor}" textColor="${textColor}" borderWidth="${borderWidth}" borderStyle="${borderStyle}" borderColor="${borderColor}" borderRadius="${borderRadius}" padding="${padding}" alignment="${alignment}" fullWidth="${fullWidth}"]${cleanContent}[/link_button]`;
}

// Build a single <div data-link-button> from a [link_button ...] shortcode's attrs+content.
function linkButtonShortcodeToDiv(attrs, content) {
  const grab = (name) => (attrs || '').match(new RegExp(name + '=(?:["\']|&quot;)([^"]*)(?:["\']|&quot;)'));
  const attrMap = {
    href: 'data-href',
    target: 'data-target',
    internalType: 'data-internal-type',
    internalId: 'data-internal-id',
    bgColor: 'data-bg-color',
    textColor: 'data-text-color',
    borderWidth: 'data-border-width',
    borderStyle: 'data-border-style',
    borderColor: 'data-border-color',
    borderRadius: 'data-border-radius',
    padding: 'data-padding',
    alignment: 'data-alignment',
    fullWidth: 'data-full-width',
  };
  let div = '<div data-link-button="1"';
  Object.entries(attrMap).forEach(([key, dataAttr]) => {
    const m = grab(key);
    if (m) div += ` ${dataAttr}="${m[1]}"`;
  });
  div += `>${content}</div>`;
  return div;
}

function formEmbedToShortcode(html) {
  // Serialize the schema HTML for one Hero block; node view chrome is not part of getHTML().
  html = html.replace(
    /<div([^>]*data-hero="1"[^>]*)>[\s\S]*?<h2([^>]*data-hero-heading="[^"]*"[^>]*)>([\s\S]*?)<\/h2>[\s\S]*?<p([^>]*data-hero-subtitle="[^"]*"[^>]*)>([\s\S]*?)<\/p>[\s\S]*?<\/div>/g,
    (match, openTag, h2Tag, heading, pTag, subtitle) => heroDivToShortcode(match, openTag, h2Tag, heading, pTag, subtitle)
  );
  // Link button groups (first — they contain child button divs).
  html = html.replace(
    /<div([^>]*data-link-button-group="1"[^>]*)>((?:<div[^>]*data-link-button="1"[^>]*>[\s\S]*?<\/div>\s*)*)<\/div>/g,
    (match, gattrs, inner) => {
      const layout = (gattrs.match(/data-layout="([^"]*)"/) || [])[1] || 'vertical';
      const gap = (gattrs.match(/data-gap="([^"]*)"/) || [])[1] || '12';
      let buttons = '';
      inner.replace(/<div[^>]*data-link-button="1"[^>]*>([\s\S]*?)<\/div>/g, (bm, bc) => {
        buttons += linkButtonDivToShortcode(bm, bc) + '\n';
        return bm;
      });
      return `[link_button_group layout="${layout}" gap="${gap}"]\n${buttons}[/link_button_group]`;
    }
  );
  // Standalone button (legacy / lone) → serialize as a single-button group.
  html = html.replace(
    /<div[^>]*data-link-button="1"[^>]*>([\s\S]*?)<\/div>/g,
    (match, content) => `[link_button_group layout="vertical" gap="12"]\n${linkButtonDivToShortcode(match, content)}\n[/link_button_group]`
  );
  // Card grid: editor divs → [card_group]…[card_item]…[/card_item]…[/card_group].
  // Input is renderHTML output (no editor chrome): each item holds exactly a
  // title div + desc div, so items are replaced first and the group second
  // (its inner HTML is shortcode-only by then).
  html = html.replace(
    /<div([^>]*data-card-item="1"[^>]*)>\s*<div[^>]*data-card-title="[^"]*"[^>]*>([\s\S]*?)<\/div>\s*<div[^>]*data-card-desc="[^"]*"[^>]*>([\s\S]*?)<\/div>\s*<\/div>/g,
    (match, openTag, title, desc) => {
      const grab = (name) => (openTag.match(new RegExp(name + '="([^"]*)"')) || [])[1] || '';
      const image = grab('data-image');
      const fit = grab('data-fit') || 'cover';
      const link = grab('data-link');
      const target = grab('data-target') || '_self';
      const bg = grab('data-bg');
      const autoplay = grab('data-autoplay') || 'false';
      let sc = `[card_item image="${image}" link="${link}" target="${target}" bg="${bg}" fit="${fit}"`;
      if (autoplay === 'true') sc += ` autoplay="true"`;
      sc += `][card_title]${title.trim()}[/card_title][card_desc]${desc.trim()}[/card_desc][/card_item]`;
      return sc;
    }
  );
  html = html.replace(
    /<div([^>]*data-card-group="1"[^>]*)>([\s\S]*?)<\/div>/g,
    (match, openTag, inner) => {
      const grab = (name) => (openTag.match(new RegExp(name + '="([^"]*)"')) || [])[1] || '';
      const gap = grab('data-gap') || '12';
      const bg = grab('data-bg');
      const radius = grab('data-radius') || '12px';
      const align = grab('data-align') || 'center';
      const width = grab('data-width') || '';
      const halign = grab('data-halign') || '';
      const lightbox = grab('data-lightbox') || '';
      let shortcode = `[card_group gap="${gap}"`;
      if (bg) shortcode += ` bg="${bg}"`;
      if (radius !== '12px') shortcode += ` radius="${radius}"`;
      if (align !== 'center') shortcode += ` align="${align}"`;
      if (halign && halign !== 'center') shortcode += ` halign="${halign}"`;
      if (width && width !== 'auto') shortcode += ` width="${width}"`;
      if (lightbox === 'true') shortcode += ` lightbox="true"`;
      shortcode += `]\n${inner.trim()}\n[/card_group]`;
      return shortcode;
    }
  );
  html = html.replace(
    /<div[^>]*data-newsletter="1"[^>]*>.*?<\/div>/gs,
    (match) => {
      const ph = (match.match(/data-placeholder="([^"]*)"/) || [])[1] || '';
      const btn = (match.match(/data-button="([^"]*)"/) || [])[1] || '';
      const priv = (match.match(/data-privacy-page="([^"]*)"/) || [])[1] || '';
      const tag = (match.match(/data-tag="([^"]*)"/) || [])[1] || '';
      const honeypotName = (match.match(/data-honeypot-name="([^"]*)"/) || [])[1] || '';
      const captcha = (match.match(/data-captcha="([^"]*)"/) || [])[1] || '';
      const width = (match.match(/data-width="([^"]*)"/) || [])[1] || '';

      let shortcode = `[newsletter_form placeholder="${ph}" button="${btn}"`;
      if (priv) shortcode += ` privacy_page="${priv}"`;
      if (tag) shortcode += ` tag="${tag}"`;
      if (captcha && captcha !== 'disabled') shortcode += ` captcha="${captcha}"`;
      if (honeypotName) shortcode += ` honeypot_name="${honeypotName}"`;
      if (width && width !== 'auto') shortcode += ` width="${width}"`;
      shortcode += ']';
      return shortcode;
    }
  );
  html = html.replace(
    /<div[^>]*data-form-slug="([^"]+)"[^>]*>[^<]*<\/div>/g,
    (_, slug) => `[form slug="${slug}"]`,
  );
  // data-toc-max may appear BEFORE data-toc in attribute order, so extract it via a
  // callback instead of trying to capture both in a single left-to-right regex.
  html = html.replace(
    /<div[^>]*data-toc="[^"]*"[^>]*>.*?<\/div>/gs,
    (match) => {
      const m = match.match(/data-toc-max="(\d)"/);
      const max = m ? m[1] : '3';
      return max !== '3' ? `[toc max_level="${max}"]` : '[toc]';
    },
  );
  html = html.replace(
    /<div[^>]*data-pages="[^"]*"[^>]*>.*?<\/div>/gs,
    (match) => {
      const max = (match.match(/data-pages-max="(\d)"/) || [])[1] || '1';
      const parent = (match.match(/data-pages-parent="([^"]+)"/) || [])[1] || '';
      let shortcode = '[pages';
      if (parent) shortcode += ` parent="${parent}"`;
      if (max !== '1') shortcode += ` max_level="${max}"`;
      shortcode += ']';
      return shortcode;
    },
  );
  html = html.replace(
    /<div[^>]*data-carousel="[^"]*"[^>]*>.*?<\/div>/gs,
    (match) => {
      const images = (match.match(/images="([^"]*)"/) || [])[1] || '';
      const autoplay = (match.match(/autoplay="([^"]*)"/) || [])[1] || 'true';
      const duration = (match.match(/duration="([^"]*)"/) || [])[1] || '5000';
      const arrows = (match.match(/showArrows="([^"]*)"/) || [])[1] || 'true';
      const dots = (match.match(/showDots="([^"]*)"/) || [])[1] || 'true';
      const pauseBtn = (match.match(/pauseBtn="([^"]*)"/i) || match.match(/pausebtn="([^"]*)"/) || match.match(/pause-button="([^"]*)"/) || [])[1] || '';
      const fit = (match.match(/fit="([^"]*)"/) || [])[1] || '';
      const lightbox = (match.match(/lightbox="([^"]*)"/) || [])[1] || '';
      const bg = (match.match(/bg="([^"]*)"/) || [])[1] || '';
      const height = (match.match(/height="([^"]*)"/) || [])[1] || '';
      const transition = (match.match(/transition="([^"]*)"/) || [])[1] || '';

      let shortcode = `[carousel images="${images}"`;
      if (autoplay !== 'true') shortcode += ` autoplay="${autoplay}"`;
      if (duration !== '5000') shortcode += ` duration="${duration}"`;
      if (arrows !== 'true') shortcode += ` arrows="${arrows}"`;
      if (dots !== 'true') shortcode += ` dots="${dots}"`;
      if (pauseBtn === 'true') shortcode += ` pauseBtn="${pauseBtn}"`;
      if (fit) shortcode += ` fit="${fit}"`;
      if (lightbox === 'true') shortcode += ` lightbox="${lightbox}"`;
      if (bg) shortcode += ` bg="${bg}"`;
      if (height && height !== '200px') shortcode += ` height="${height}"`;
      if (transition && transition !== 'fade') shortcode += ` transition="${transition}"`;
      shortcode += ']';
      return shortcode;
    },
  );
  html = html.replace(
    /<div[^>]*data-product="[^"]*"[^>]*>.*?<\/div>/gs,
    (match) => {
      const productId = (match.match(/data-product-id="(\d+)"/) || [])[1] || '';
      const variationId = (match.match(/data-variation-id="(\d+)"/) || [])[1] || '';
      const normBool = (v) => (v === 'true' || v === '1' || v === '' ? 'true' : 'false');
      const showImage = normBool((match.match(/data-show-image="([^"]*)"/) || [])[1] ?? 'true');
      const showPrice = normBool((match.match(/data-show-price="([^"]*)"/) || [])[1] ?? 'true');
      const showDesc = normBool((match.match(/data-show-desc="([^"]*)"/) || [])[1] ?? 'true');
      const bg = (match.match(/data-product-bg="([^"]*)"/) || [])[1] || '';
      // Id-only: name/price/image/desc/slug are never stored, they are
      // resolved live from the server on every render.
      let shortcode = `[product id="${productId}"`;
      if (variationId && variationId !== '0') shortcode += ` variation_id="${variationId}"`;
      if (showImage !== 'true') shortcode += ` show_image="false"`;
      if (showPrice !== 'true') shortcode += ` show_price="false"`;
      if (showDesc !== 'true') shortcode += ` show_description="false"`;
      if (bg) shortcode += ` bg="${bg}"`;
      shortcode += ']';
      return shortcode;
    },
  );
  html = html.replace(
    /<div[^>]*data-generate-coupon="[^"]*"[^>]*>.*?<\/div>/gs,
    (match) => {
      const dp = (match.match(/data-discount-percent="([^"]*)"/) || [])[1] || '';
      const df = (match.match(/data-discount-fixed="([^"]*)"/) || [])[1] || '';
      const prefix = (match.match(/data-prefix="([^"]*)"/) || [])[1] || '';
      const expiresDays = (match.match(/data-expires-days="([^"]*)"/) || [])[1] || '';
      const maxUsesPerEmail = (match.match(/data-max-uses-per-email="([^"]*)"/) || [])[1] || '';
      const startsAt = (match.match(/data-starts-at="([^"]*)"/) || [])[1] || '';

      let shortcode = '[generate_coupon';
      if (dp) shortcode += ` discount_percent="${dp}"`;
      if (df) shortcode += ` discount_fixed="${df}"`;
      if (prefix) shortcode += ` prefix="${prefix}"`;
      if (expiresDays) shortcode += ` expires_days="${expiresDays}"`;
      if (maxUsesPerEmail) shortcode += ` max_uses_per_email="${maxUsesPerEmail}"`;
      if (startsAt) shortcode += ` starts_at="${startsAt}"`;
      shortcode += ']';
      return shortcode;
    },
  );
  html = html.replace(
    /<div[^>]*data-latest-posts="[^"]*"[^>]*>.*?<\/div>/gs,
    (match) => {
      const count = (match.match(/data-count="([^"]*)"/) || [])[1] || '5';
      const contentType = (match.match(/data-content-type="([^"]*)"/) || [])[1] || 'post';
      const category = (match.match(/data-category="([^"]*)"/) || [])[1] || '';
      const tag = (match.match(/data-tag="([^"]*)"/) || [])[1] || '';

      let shortcode = `[latest_posts count="${count}" type="${contentType}"`;
      if (category) shortcode += ` category="${category}"`;
      if (tag) shortcode += ` tag="${tag}"`;
      shortcode += ']';
      return shortcode;
    },
  );
  html = html.replace(/<div[^>]*data-unsubscribe-url="[^"]*"[^>]*>.*?<\/div>/gs, '[unsubscribe_url]');
  html = html.replace(/<div[^>]*data-open-pixel="[^"]*"[^>]*>.*?<\/div>/gs, '[open_pixel]');
  html = html.replace(
    /<div[^>]*data-redirect="[^"]*"[^>]*>.*?<\/div>/gs,
    (match) => {
      const url = (match.match(/data-url="([^"]*)"/) || [])[1] || '';
      const seconds = (match.match(/data-seconds="([^"]*)"/) || [])[1] || '5';
      const type = (match.match(/data-internal-type="([^"]*)"/) || [])[1] || '';
      const id = (match.match(/data-internal-id="([^"]*)"/) || [])[1] || '';
      let internalAttrs = '';
      if (type && id) {
        internalAttrs = ` internal_type="${type}" internal_id="${id}"`;
      }
      return `[redirect url="${url}" seconds="${seconds}"${internalAttrs}]`;
    },
  );
  return html;
}

// Convert [form slug="..."] and [toc] shortcodes in raw HTML to Tiptap node divs
function shortcodeToFormEmbed(html) {
  // Recover text that older Hero parsing left as adjacent plain headings and
  // paragraphs. Empty duplicate blocks from those round-trips are discarded.
  html = html.replace(
    /(\[hero(?:\s+[^\]]*)?\]\[hero_heading\])\s*\[\/hero_heading\](\[hero_subtitle\])\s*\[\/hero_subtitle\](\[\/hero\])((?:\s*<h2\b[^>]*>\s*<\/h2>\s*<p\b[^>]*>\s*<\/p>)*)\s*<h2\b[^>]*>([\s\S]*?)<\/h2>\s*<p\b[^>]*>([\s\S]*?)<\/p>/gi,
    (_, headingOpen, subtitleOpen, heroClose, _emptyBlocks, heading, subtitle) =>
      `${headingOpen}${heading}[/hero_heading]${subtitleOpen}${subtitle}[/hero_subtitle]${heroClose}`
  );
  // [link_button_group layout="..." gap="..."] [link_button ...]...[/link_button] ... [/link_button_group]
  html = html.replace(
    /(?:<p>\s*)?\[link_button_group(?:\s+([^\]]*))?\]([\s\S]*?)\[\/link_button_group\](?:\s*<\/p>)?/gs,
    (_, gattrs, inner) => {
      const layout = (gattrs || '').match(/layout=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const gap = (gattrs || '').match(/gap=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      let buttonsDivs = '';
      inner.replace(/\[link_button(?:\s+([^\]]*))?\](.*?)\[\/link_button\]/gs, (m2, battrs, bcontent) => {
        buttonsDivs += linkButtonShortcodeToDiv(battrs, bcontent);
        return m2;
      });
      let div = '<div data-link-button-group="1"';
      div += ` data-layout="${layout ? layout[1] : 'vertical'}"`;
      div += ` data-gap="${gap ? gap[1] : '12'}"`;
      div += `>${buttonsDivs}</div>`;
      return div;
    }
  );
  // Legacy single button → keep rendering, but open in the editor as a group.
  html = html.replace(
    /(?:<p>\s*)?\[link_button(?:\s+([^\]]*))?\](.*?)\[\/link_button\](?:\s*<\/p>)?/gs,
    (_, attrs, content) => `<div data-link-button-group="1" data-layout="vertical" data-gap="12">${linkButtonShortcodeToDiv(attrs, content)}</div>`
  );
  // [card_group gap="..." bg="..." radius="..." align="..." width="..."]
  // [card_item image="..." link="..." target="..." bg="..."][card_title]..[/card_title][card_desc]..[/card_desc][/card_item]
  // [/card_group]
  html = html.replace(
    /(?:<p>\s*)?\[card_group(?:\s+([^\]]*))?\]([\s\S]*?)\[\/card_group\](?:\s*<\/p>)?/gs,
    (_, gattrs, inner) => {
      const grab = (name) => (gattrs || '').match(new RegExp(name + '=(?:["\']|&quot;)([^"]*)(?:["\']|&quot;)'));
      const gap = (grab('gap') || [])[1] || '12';
      const bg = (grab('bg') || [])[1] || '';
      const radius = (grab('radius') || [])[1] || '12px';
      const align = (grab('align') || [])[1] || 'center';
      const width = (grab('width') || [])[1] || '';
      const halign = (grab('halign') || [])[1] || '';
      const lightbox = (grab('lightbox') || [])[1] || '';
      let cardsDivs = '';
      inner.replace(/\[card_item(?:\s+([^\]]*))?\][\s\S]*?\[card_title\]([\s\S]*?)\[\/card_title\][\s\S]*?\[card_desc\]([\s\S]*?)\[\/card_desc\][\s\S]*?\[\/card_item\]/gs, (m2, battrs, title, desc) => {
        const bgrab = (name) => (battrs || '').match(new RegExp(name + '=(?:["\']|&quot;)([^"]*)(?:["\']|&quot;)'));
        const image = (bgrab('image') || [])[1] || '';
        const link = (bgrab('link') || [])[1] || '';
        const target = (bgrab('target') || [])[1] || '_self';
        const cbg = (bgrab('bg') || [])[1] || '';
        const cfit = (bgrab('fit') || [])[1] || 'cover';
        const cautoplay = (bgrab('autoplay') || [])[1] || 'false';
        cardsDivs += `<div data-card-item="1" data-image="${image.replace(/"/g, '&quot;')}" data-link="${link.replace(/"/g, '&quot;')}" data-target="${target}" data-bg="${cbg.replace(/"/g, '&quot;')}" data-fit="${cfit.replace(/"/g, '&quot;')}" data-autoplay="${cautoplay}"><div data-card-title="1">${title}</div><div data-card-desc="1">${desc}</div></div>`;
        return m2;
      });
      let div = '<div data-card-group="1"';
      div += ` data-gap="${gap}"`;
      if (bg) div += ` data-bg="${bg.replace(/"/g, '&quot;')}"`;
      div += ` data-radius="${radius}"`;
      div += ` data-align="${align}"`;
      if (width) div += ` data-width="${width.replace(/"/g, '&quot;')}"`;
      if (halign) div += ` data-halign="${halign}"`;
      if (lightbox === 'true') div += ` data-lightbox="true"`;
      div += `>${cardsDivs}</div>`;
      return div;
    }
  );

  // [hero …][hero_heading]…[/hero_heading][hero_subtitle]…[/hero_subtitle][/hero]
  html = html.replace(
    /(?:<p>\s*)?\[hero(?:\s+([^\]]*))?\][\s\S]*?\[hero_heading\]([\s\S]*?)\[\/hero_heading\][\s\S]*?\[hero_subtitle\]([\s\S]*?)\[\/hero_subtitle\][\s\S]*?\[\/hero\](?:\s*<\/p>)?/gs,
    (_, attrs, heading, subtitle) => heroShortcodeToDiv(attrs, heading, subtitle)
  );

  // [newsletter_form placeholder="..." button="..." privacy_page="..." tag="..."]
  html = html.replace(
    /(?:<p>\s*)?\[newsletter_form(?:\s+([^\]]*))?\](?:\s*<\/p>)?/g,
    (_, attrs) => {
      const ph = (attrs || '').match(/placeholder=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const btn = (attrs || '').match(/button=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const priv = (attrs || '').match(/privacy_page=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const tag = (attrs || '').match(/tag=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const honeypotName = (attrs || '').match(/honeypot_name=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const captcha = (attrs || '').match(/captcha=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const width = (attrs || '').match(/width=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);

      let div = '<div data-newsletter="1"';
      div += ` data-placeholder="${(ph ? ph[1] : 'Enter your email')}"`;
      div += ` data-button="${(btn ? btn[1] : 'Join Now')}"`;
      if (priv) div += ` data-privacy-page="${priv[1]}"`;
      if (tag) div += ` data-tag="${tag[1]}"`;
      if (honeypotName) div += ` data-honeypot-name="${honeypotName[1]}"`;
      if (captcha) div += ` data-captcha="${captcha[1]}"`;
      if (width) div += ` data-width="${width[1]}"`;
      div += '></div>';
      return div;
    }
  );
  html = html.replace(
    /(?:<p>\s*)?\[form\s+slug=(?:["']|&quot;)([a-z0-9_-]+)(?:["']|&quot;)\s*\](?:\s*<\/p>)?/g,
    (_, slug) => `<div data-form-slug="${slug}" data-form-name="${slug}"></div>`,
  );
  // [toc] or [toc max_level="N"]
  html = html.replace(
    /(?:<p>\s*)?\[toc(?:\s+max_level=["'](\d)["'])?\](?:\s*<\/p>)?/g,
    (_, max) => `<div data-toc="1" data-toc-max="${max || '3'}"></div>`,
  );
  // [pages] or [pages max_level="N" parent="slug"]
  html = html.replace(
    /(?:<p>\s*)?\[pages(?:\s+([^\]]*))?\](?:\s*<\/p>)?/g,
    (_, attrs) => {
      const max = (attrs || '').match(/max_level=["'](\d)["']/);
      const parent = (attrs || '').match(/parent=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);

      let div = '<div data-pages="1"';
      if (max) div += ` data-pages-max="${max[1]}"`;
      if (parent) div += ` data-pages-parent="${parent[1]}"`;
      div += '></div>';
      return div;
    },
  );
  // [carousel images="..." autoplay="..." duration="..." arrows="..." dots="..." pauseBtn="..." fit="..." lightbox="..." bg="..."]
  html = html.replace(
    /(?:<p>\s*)?\[carousel(?:\s+([^\]]*))?\](?:\s*<\/p>)?/g,
    (_, attrs) => {
      const images = (attrs || '').match(/images=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const autoplay = (attrs || '').match(/autoplay=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const duration = (attrs || '').match(/duration=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const arrows = (attrs || '').match(/arrows=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const dots = (attrs || '').match(/dots=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const pauseBtn = (attrs || '').match(/pauseBtn=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const fit = (attrs || '').match(/fit=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const lightbox = (attrs || '').match(/lightbox=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const bg = (attrs || '').match(/bg=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const height = (attrs || '').match(/height=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const transition = (attrs || '').match(/transition=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);

      let div = '<div data-carousel="1"';
      if (images) div += ` images="${images[1]}"`;
      if (autoplay) div += ` autoplay="${autoplay[1]}"`;
      if (duration) div += ` duration="${duration[1]}"`;
      if (arrows) div += ` showArrows="${arrows[1]}"`;
      if (dots) div += ` showDots="${dots[1]}"`;
      if (pauseBtn) div += ` pauseBtn="${pauseBtn[1]}"`;
      if (fit) div += ` fit="${fit[1]}"`;
      if (lightbox) div += ` lightbox="${lightbox[1]}"`;
      if (bg) div += ` bg="${bg[1]}"`;
      if (height) div += ` height="${height[1]}"`;
      if (transition) div += ` transition="${transition[1]}"`;
      div += '></div>';
      return div;
    },
  );
  // [product id="5" show_image="..." show_price="..." show_description="..." bg="..."]
  // Id-only: legacy snapshot attrs (name/price/image/desc/slug) are ignored.
  html = html.replace(
    /(?:<p>\s*)?\[product(?:\s+([^\]]*))?\](?:\s*<\/p>)?/g,
    (_, attrs) => {
      const id = (attrs || '').match(/id=(?:["']|&quot;)(\d+)(?:["']|&quot;)/);
      const variationId = (attrs || '').match(/variation_id=(?:["']|&quot;)(\d+)(?:["']|&quot;)/);
      const normFlag = (re) => {
        const m = (attrs || '').match(re);
        if (!m) return null;
        const v = m[1];
        return (v === 'true' || v === '1') ? 'true' : 'false';
      };
      const showImage = normFlag(/show_image=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const showPrice = normFlag(/show_price=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const showDesc = normFlag(/show_description=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const bg = (attrs || '').match(/bg=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      let div = '<div data-product="1"';
      if (id) div += ` data-product-id="${id[1]}"`;
      if (variationId) div += ` data-variation-id="${variationId[1]}"`;
      if (showImage) div += ` data-show-image="${showImage}"`;
      if (showPrice) div += ` data-show-price="${showPrice}"`;
      if (showDesc) div += ` data-show-desc="${showDesc}"`;
      if (bg) div += ` data-product-bg="${bg[1]}"`;
      div += '></div>';
      return div;
    },
  );
  // [generate_coupon discount_percent="10" prefix="SALE" expires_days="30" max_uses_per_email="1"]
  html = html.replace(
    /(?:<p>\s*)?\[generate_coupon(?:\s+([^\]]*))?\](?:\s*<\/p>)?/g,
    (_, attrs) => {
      const dp = (attrs || '').match(/discount_percent=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const df = (attrs || '').match(/discount_fixed=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const prefix = (attrs || '').match(/prefix=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const expiresDays = (attrs || '').match(/expires_days=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const maxUsesPerEmail = (attrs || '').match(/max_uses_per_email=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const startsAt = (attrs || '').match(/starts_at=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);

      let div = '<div data-generate-coupon="1"';
      if (dp) div += ` data-discount-percent="${dp[1]}"`;
      if (df) div += ` data-discount-fixed="${df[1]}"`;
      if (prefix) div += ` data-prefix="${prefix[1]}"`;
      if (expiresDays) div += ` data-expires-days="${expiresDays[1]}"`;
      if (maxUsesPerEmail) div += ` data-max-uses-per-email="${maxUsesPerEmail[1]}"`;
      if (startsAt) div += ` data-starts-at="${startsAt[1]}"`;
      div += '></div>';
      return div;
    },
  );
  // [latest_posts count="5" type="post" category="" tag=""]
  html = html.replace(
    /(?:<p>\s*)?\[latest_posts(?:\s+([^\]]*))?\](?:\s*<\/p>)?/g,
    (_, attrs) => {
      const count = (attrs || '').match(/count=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const type = (attrs || '').match(/type=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const category = (attrs || '').match(/category=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const tag = (attrs || '').match(/tag=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);

      let div = '<div data-latest-posts="1"';
      div += ` data-count="${count ? count[1] : '5'}"`;
      div += ` data-content-type="${type ? type[1] : 'post'}"`;
      if (category) div += ` data-category="${category[1]}"`;
      if (tag) div += ` data-tag="${tag[1]}"`;
      div += '></div>';
      return div;
    },
  );
  // [unsubscribe_url]
  html = html.replace(
    /(?:<p>\s*)?\[unsubscribe_url\](?:\s*<\/p>)?/g,
    '<div data-unsubscribe-url="1"></div>'
  );
  // [open_pixel]
  html = html.replace(
    /(?:<p>\s*)?\[open_pixel\](?:\s*<\/p>)?/g,
    '<div data-open-pixel="1"></div>'
  );
  // [redirect url="..." seconds="..."]
  html = html.replace(
    /(?:<p>\s*)?\[redirect(?:\s+([^\]]*))?\](?:\s*<\/p>)?/g,
    (_, attrs) => {
      const url = (attrs || '').match(/url=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const seconds = (attrs || '').match(/seconds=(?:["']|&quot;)(\d+)(?:["']|&quot;)/);
      const type = (attrs || '').match(/internal_type=(?:["']|&quot;)([^"]*)(?:["']|&quot;)/);
      const id = (attrs || '').match(/internal_id=(?:["']|&quot;)(\d+)(?:["']|&quot;)/);

      let div = '<div data-redirect="1"';
      div += ` data-url="${url ? url[1] : ''}"`;
      div += ` data-seconds="${seconds ? seconds[1] : '5'}"`;
      if (type && id) {
        div += ` data-internal-type="${type[1]}" data-internal-id="${id[1]}"`;
      }
      div += '></div>';
      return div;
    },
  );
  return html;
}

// Build a single <div data-hero="1" …> from a [hero …][hero_heading]…[/hero_heading][hero_subtitle]…[/hero_subtitle][/hero] shortcode.
function heroShortcodeToDiv(attrs, heading, subtitle) {
  const grab = (name) => (attrs || '').match(new RegExp(name + '=(?:["\']|&quot;)([^"]*)(?:["\']|&quot;)'));
  const headingAlign = (grab('headingAlign') || [])[1] || '';
  const subtitleAlign = (grab('subtitleAlign') || [])[1] || '';
  const headingStyle = headingAlign ? ` style="text-align:${headingAlign}"` : '';
  const subtitleStyle = subtitleAlign ? ` style="text-align:${subtitleAlign}"` : '';

  const fieldMap = {
    variant: 'data-variant',
    bg: 'data-bg',
    shadow: 'data-shadow',
    shadowColor: 'data-shadow-color',
    radius: 'data-radius',
    width: 'data-width',
    image: 'data-image-src',
    imageAlt: 'data-image-alt',
    imageFit: 'data-image-fit',
    mediaType: 'data-media-type',
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

  let div = '<div data-hero="1" class="vl-hero-embed-block"';
  Object.entries(fieldMap).forEach(([key, dataAttr]) => {
    const m = grab(key);
    if (m) div += ` ${dataAttr}="${m[1].replace(/"/g, '&quot;')}"`;
  });
  div += `><h2 class="vl-hero-heading" data-hero-heading="1"${headingStyle}>${heading}</h2><p class="vl-hero-subtitle" data-hero-subtitle="1"${subtitleStyle}>${subtitle}</p></div>`;
  return div;
}

// Serialize a <div data-hero="1" …> into a [hero …][hero_heading]…[/hero_heading][hero_subtitle]…[/hero_subtitle][/hero] shortcode.
function heroDivToShortcode(match, openTag, h2Tag, heading, pTag, subtitle) {
  const grab = (name) => (openTag.match(new RegExp(name + '="([^"]*)"')) || [])[1] || '';
  const headingAlign = (h2Tag.match(/text-align:\s*(\w+)/) || [])[1] || '';
  const subtitleAlign = (pTag.match(/text-align:\s*(\w+)/) || [])[1] || '';

  const variant = grab('data-variant') || 'split';
  const bg = grab('data-bg');
  const shadow = grab('data-shadow');
  const shadowColor = grab('data-shadow-color');
  const radius = grab('data-radius');
  const width = grab('data-width');
  const image = grab('data-image-src');
  const imageAlt = grab('data-image-alt');
  const imageFit = grab('data-image-fit');
  const mediaAutoplay = grab('data-media-autoplay') || 'false';
  const mediaType = grab('data-media-type') || 'image';
  const carouselImages = grab('data-carousel-images');
  const carouselAutoplay = grab('data-carousel-autoplay');
  const carouselDuration = grab('data-carousel-duration');
  const carouselShowArrows = grab('data-carousel-show-arrows');
  const carouselShowDots = grab('data-carousel-show-dots');
  const carouselPauseBtn = grab('data-carousel-pause-btn');
  const carouselFit = grab('data-carousel-fit');
  const carouselHeight = grab('data-carousel-height');
  const carouselTransition = grab('data-carousel-transition');
  const carouselBg = grab('data-carousel-bg');
  const lightbox = grab('data-lightbox') === 'true';
  const mediaSide = grab('data-media-side') || 'right';
  const badge = grab('data-badge');
  const badgeBg = grab('data-badge-bg');
  const badgeTextColor = grab('data-badge-text-color');
  const b1Text = grab('data-btn1-text');
  const b1Url = grab('data-btn1-url');
  const b1Target = grab('data-btn1-target') || '_self';
  const b1Style = grab('data-btn1-style') || 'primary';
  const b1Color = grab('data-btn1-color');
  const b1TextColor = grab('data-btn1-text-color');
  const b2Text = grab('data-btn2-text');
  const b2Url = grab('data-btn2-url');
  const b2Target = grab('data-btn2-target') || '_self';
  const b2Style = grab('data-btn2-style') || 'secondary';
  const b2Color = grab('data-btn2-color');
  const b2TextColor = grab('data-btn2-text-color');

  const esc = (v) => v.replace(/"/g, '&quot;');

  let sc = `[hero variant="${variant}"`;
  if (bg) sc += ` bg="${esc(bg)}"`;
  if (shadow) sc += ` shadow="${esc(shadow)}"`;
  if (shadowColor) sc += ` shadowColor="${esc(shadowColor)}"`;
  if (radius) sc += ` radius="${esc(radius)}"`;
  if (width) sc += ` width="${esc(width)}"`;
  if (image) sc += ` image="${esc(image)}"`;
  if (imageAlt) sc += ` imageAlt="${esc(imageAlt)}"`;
  if (imageFit && imageFit !== 'cover') sc += ` imageFit="${esc(imageFit)}"`;
  if (mediaAutoplay === 'true') sc += ' mediaAutoplay="true"';
  if (mediaType !== 'image') sc += ` mediaType="${esc(mediaType)}"`;
  if (carouselImages) sc += ` carouselImages="${esc(carouselImages)}"`;
  if (mediaType === 'carousel') {
    if (carouselAutoplay && carouselAutoplay !== 'true') sc += ` carouselAutoplay="${esc(carouselAutoplay)}"`;
    if (carouselDuration && carouselDuration !== '5000') sc += ` carouselDuration="${esc(carouselDuration)}"`;
    if (carouselShowArrows && carouselShowArrows !== 'true') sc += ` carouselShowArrows="${esc(carouselShowArrows)}"`;
    if (carouselShowDots && carouselShowDots !== 'true') sc += ` carouselShowDots="${esc(carouselShowDots)}"`;
    if (carouselPauseBtn === 'true') sc += ' carouselPauseBtn="true"';
    if (carouselFit && carouselFit !== 'cover') sc += ` carouselFit="${esc(carouselFit)}"`;
    if (carouselHeight && carouselHeight !== '100%') sc += ` carouselHeight="${esc(carouselHeight)}"`;
    if (carouselTransition && carouselTransition !== 'fade') sc += ` carouselTransition="${esc(carouselTransition)}"`;
    if (carouselBg) sc += ` carouselBg="${esc(carouselBg)}"`;
  }
  if (lightbox) sc += ' lightbox="true"';
  if (mediaSide !== 'right') sc += ` mediaSide="${esc(mediaSide)}"`;
  if (badge) sc += ` badge="${esc(badge)}"`;
  if (badgeBg) sc += ` badgeBg="${esc(badgeBg)}"`;
  if (badgeTextColor) sc += ` badgeTextColor="${esc(badgeTextColor)}"`;
  if (b1Text) sc += ` button1Text="${esc(b1Text)}"`;
  if (b1Url) sc += ` button1Url="${esc(b1Url)}"`;
  if (b1Target !== '_self') sc += ` button1Target="${esc(b1Target)}"`;
  if (b1Style !== 'primary') sc += ` button1Style="${esc(b1Style)}"`;
  if (b1Color) sc += ` button1Color="${esc(b1Color)}"`;
  if (b1TextColor) sc += ` button1TextColor="${esc(b1TextColor)}"`;
  if (b2Text) sc += ` button2Text="${esc(b2Text)}"`;
  if (b2Url) sc += ` button2Url="${esc(b2Url)}"`;
  if (b2Target !== '_self') sc += ` button2Target="${esc(b2Target)}"`;
  if (b2Style !== 'secondary') sc += ` button2Style="${esc(b2Style)}"`;
  if (b2Color) sc += ` button2Color="${esc(b2Color)}"`;
  if (b2TextColor) sc += ` button2TextColor="${esc(b2TextColor)}"`;
  if (headingAlign) sc += ` headingAlign="${esc(headingAlign)}"`;
  if (subtitleAlign) sc += ` subtitleAlign="${esc(subtitleAlign)}"`;
  sc += `][hero_heading]${heading}[/hero_heading][hero_subtitle]${subtitle}[/hero_subtitle][/hero]`;
  return sc;
}

// Build the same standalone iframe markup as ShortcodeProcessor::renderEmbed()
function embedIframeHtml(url) {
  let src = null;
  const yt = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
  const vi = url.match(/vimeo\.com\/(\d+)/);
  if (yt) src = `${embedBaseUrl('youtube')}${yt[1]}`;
  else if (vi) src = `${embedBaseUrl('vimeo')}${vi[1]}`;
  if (!src) return null;
  return `<div class="vl-video-embed" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;max-width:100%;margin:1.5rem 0"><iframe src="${src}" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>`;
}

// Build standalone embed HTML for a URL: video iframe for YouTube/Vimeo,
// <video> for direct files, a plain link paragraph otherwise. Used when
// inserting external media — the videoEmbed node only has an `html` attr.
function videoEmbedHtmlForUrl(rawUrl) {
  const url = (rawUrl || '').trim();
  if (!url) return '';
  const iframe = embedIframeHtml(url);
  if (iframe) return iframe;
  if (url.match(/\.(mp4|webm|ogg|mov|m4v)($|\?)/i)) {
    const esc = url.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    return `<video src="${esc}" controls style="width:100%"></video>`;
  }
  const esc = url.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  return `<p><a href="${esc}">${esc}</a></p>`;
}

// ── Video block width ─────────────────────────────────────────────────────────
// Width is stored inside the embed HTML itself rather than as a node attribute:
// the post body saves this raw HTML and the front end renders it verbatim, so a
// node attr would be invisible there. Styling the wrapper keeps editor, storage
// and front end in sync with no serialization change — content saved before
// this feature simply has no max-width, which means full width.
const VIDEO_WRAP_RE = /<div[^>]*(?:vl-video-embed|vl-video-width-wrap)[^>]*>/i;
const VIDEO_TAG_RE = /<video[^>]*>/i;

function stripVideoWidth(html) {
  return html.replace(/\s*(?:max-width|margin-left|margin-right)\s*:\s*[^;"']+;?/gi, '');
}

function injectVideoWidth(tag, width) {
  const style = `max-width:${width};margin-left:auto;margin-right:auto;`;
  if (/\sstyle="/i.test(tag)) return tag.replace(/\sstyle="/i, ` style="${style}`);
  return tag.replace(/\s*>$/, ` style="${style}">`);
}

function videoWidthFromHtml(html) {
  if (!html) return '';
  const m = html.match(/max-width:\s*([^;"']+)/i);
  return m ? m[1].trim() : '';
}

function setVideoWidth(html, width) {
  if (!html) return html;
  const cleaned = stripVideoWidth(html);
  if (!width || width === '100%') return cleaned;
  if (VIDEO_WRAP_RE.test(cleaned)) {
    return cleaned.replace(VIDEO_WRAP_RE, (tag) => injectVideoWidth(tag, width));
  }
  if (VIDEO_TAG_RE.test(cleaned)) {
    return cleaned.replace(VIDEO_TAG_RE, (tag) => injectVideoWidth(tag, width));
  }
  // Unknown raw HTML (rare): wrap it so the width still applies.
  return `<div class="vl-video-width-wrap" style="max-width:${width};margin-left:auto;margin-right:auto">${cleaned}</div>`;
}

// ── Video block subtitles ─────────────────────────────────────────────────────
// <track> lives inside the stored <video> HTML, so the front end (which renders
// the raw HTML verbatim) needs no change. The media ID is carried on the track
// as data-subtitle-id: no behaviour today, but it lets a future render step
// refresh a moved/renamed file without touching stored content.
function stripSubtitleTracks(html) {
  return html.replace(/\s*<track\b[^>]*>/gi, '');
}

function appendSubtitleTrack(html, track) {
  if (!html || !track?.url) return html;
  if (!/<\/video>/i.test(html)) return html;
  // Empty label → the file name, so the player menu always shows something
  // human-readable instead of a bare URL.
  const label = track.label || String(track.url).split('/').pop() || track.url;
  const attrs = [
    'src="' + track.url + '"',
    'kind="subtitles"',
    track.srclang ? 'srclang="' + track.srclang + '"' : 'srclang="und"',
    'label="' + label + '"',
    track.isDefault ? ' default' : '',
    track.id ? ` data-subtitle-id="${track.id}"` : '',
  ].filter(Boolean).join(' ');
  return html.replace(/<\/video>/i, `<track ${attrs}></video>`);
}

function subtitleTracksFromHtml(html) {
  if (!html) return [];
  const out = [];
  const re = /<track\b([^>]*)>/gi;
  let m;
  while ((m = re.exec(html)) !== null) {
    const attrs = m[1];
    const grab = (name) => {
      const mm = attrs.match(new RegExp(name + '="([^"]*)"', 'i'));
      return mm ? mm[1] : '';
    };
    const url = grab('src');
    if (!url) continue;
    const srclang = grab('srclang');
    out.push({
      url,
      srclang: srclang && srclang !== 'und' ? srclang : '',
      label: grab('label') || url,
      id: grab('data-subtitle-id'),
      isDefault: /\sdefault(\s|=|$)/i.test(attrs),
    });
  }
  return out;
}

// Replace the whole subtitle set in one step (hook shape + width interplay are
// both handled because width and tracks touch different parts of the markup).
// At most one track carries `default` (the first one) — browsers pick the first
// default, so a second one would be dead weight.
function setSubtitleTracks(html, tracks) {
  if (!html) return html;
  let out = stripSubtitleTracks(html);
  let defaultSeen = false;
  for (const t of tracks || []) {
    const isDefault = !!t.isDefault && !defaultSeen;
    if (isDefault) defaultSeen = true;
    out = appendSubtitleTrack(out, { ...t, isDefault });
  }
  return out;
}

// Convert WordPress-style [embed]URL[/embed] shortcodes → video embed nodes
// (bare or <p>-wrapped). Non-video URLs become plain links so nothing is lost.
function shortcodeToVideoEmbed(html) {
  const convert = (match, inner) => {
    const url = inner.replace(/&amp;/g, '&').trim();
    if (!url) return '';
    const iframe = embedIframeHtml(url);
    if (iframe) return `<div data-video-html="${encodeURIComponent(iframe)}"></div>`;
    const esc = url.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    return `<p><a href="${esc}">${esc}</a></p>`;
  };
  html = html.replace(/<p>\s*\[embed\]\s*([\s\S]*?)\s*\[\/embed\]\s*<\/p>/gi, convert);
  html = html.replace(/\[embed\]\s*([\s\S]*?)\s*\[\/embed\]/gi, convert);
  return html;
}

// Convert <iframe>/<video> in raw HTML → <div data-video-html="..."> for Tiptap
function htmlToVideoEmbed(html) {
  html = html.replace(/<iframe\b[^>]*>[\s\S]*?<\/iframe>/gi, m =>
    `<div data-video-html="${encodeURIComponent(m)}"></div>`);
  html = html.replace(/<video\b[^>]*>[\s\S]*?<\/video>/gi, m =>
    `<div data-video-html="${encodeURIComponent(m)}"></div>`);
  return html;
}

// Convert <div data-video-html="..."> → raw iframe/video HTML for storage.
// A pasted bare <iframe> (e.g. YouTube share code with
// style="position:absolute;...width:100%;height:100%") would otherwise be
// stored without its aspect-ratio wrapper and cover the whole screen on the
// front end — wrap it here so storage always matches renderEmbed().
function videoEmbedToHtml(html) {
  return html.replace(/<div[^>]+data-video-html="([^"]*)"[^>]*>[\s\S]*?<\/div>/g,
    (_, enc) => {
      let raw = '';
      try {
        raw = decodeURIComponent(enc);
      } catch (_) {
        return '';
      }
      return wrapBareVideoHtml(raw);
    });
}

// Wrap a bare <iframe> in the responsive .vl-video-embed container.
// Already-wrapped HTML and <video> tags pass through untouched.
function wrapBareVideoHtml(raw) {
  if (!raw || raw.includes('vl-video-embed')) return raw;
  const m = raw.match(/<iframe\b[^>]*>[\s\S]*?<\/iframe>/i);
  if (!m) return raw;
  const srcMatch = m[0].match(/\bsrc=(["'])(.*?)\1/i);
  const src = srcMatch ? srcMatch[2].trim() : '';
  if (!/^https?:\/\/[^"\s<>]+/i.test(src)) return raw;
  const esc = src.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  return `<div class="vl-video-embed" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;max-width:100%;margin:1.5rem 0"><iframe src="${esc}" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe></div>`;
}

// The image node renders a captioned image as <figure class="vl-image-figure">, but the
// node itself is inline, so Tiptap emits <p><figure>...</figure></p>. <figure> is a flow
// element and cannot live inside <p>: the browser parser closes the <p> before it and the
// stray </p> synthesizes an empty <p></p>. Every save/load round-trip then multiplies
// empty paragraphs around the image. Fix: hoist figures out of their <p> wrapper (text
// before/after the figure keeps its own paragraph) and drop empty paragraphs sitting
// directly next to a figure — those are round-trip artifacts, not user content.
function normalizeImageFigures(html) {
  // Hoist: <p (attrs)>before<figure ...>inner</figure>after</p>
  //        → [p(before)]<figure ...>inner</figure>[p(after)]
  html = html.replace(
    /<p([^>]*)>([^<]*)<figure\b([^>]*)>([\s\S]*?)<\/figure>((?:(?!<\/p>)[\s\S])*)<\/p>/gi,
    (match, pAttrs, before, figAttrs, figInner, after) => {
      let out = '';
      if (before.trim()) out += `<p${pAttrs}>${before}</p>`;
      out += `<figure${figAttrs}>${figInner}</figure>`;
      if (after.trim()) out += `<p${pAttrs}>${after}</p>`;
      return out;
    }
  );
  // Drop empty paragraphs stuck directly to a figure (round-trip artifacts).
  html = html.replace(/(?:<p>(?:\s|&nbsp;)*<\/p>)+(<figure\b)/gi, '$1');
  html = html.replace(/(<\/figure>)(?:<p>(?:\s|&nbsp;)*<\/p>)+/gi, '$1');
  return html;
}

// After editor loads, fetch real form names and update any formEmbed nodes
function resolveFormNames(editor) {
  const { doc } = editor.state;
  const slugs = new Set();
  doc.descendants(node => { if (node.type.name === 'formEmbed') slugs.add(node.attrs.slug); });
  if (!slugs.size) return;

  fetch(bp('/admin/forms/list-json'), { headers: { 'X-CSRF-Token': getCsrfToken() } })
    .then(r => r.json())
    .then(forms => {
      const map = {};
      forms.forEach(f => { map[f.slug] = f.name; });
      const { tr } = editor.state;
      let changed = false;
      doc.descendants((node, pos) => {
        if (node.type.name === 'formEmbed' && map[node.attrs.slug] && node.attrs.formName !== map[node.attrs.slug]) {
          tr.setNodeMarkup(pos, undefined, { ...node.attrs, formName: map[node.attrs.slug] });
          changed = true;
        }
      });
      if (changed) {
        // Cosmetic attribute sync — must not mark the editor as dirty
        editor.__suppressUpdate = true;
        editor.view.dispatch(tr);
        editor.__suppressUpdate = false;
      }
    })
    .catch(() => { /* forms list unavailable — keep slugs as names */ });
}

// ── TextColor Mark ──────────────────────────────────────────────────────────

function moreToComment(html) {
  return html.replace(/<div[^>]*data-more[^>]*>.*?<\/div>/gi, function(match) {
    const textMatch = match.match(/data-text=(?:["']|&quot;)(.*?)(?:["']|&quot;)/i);
    const text = textMatch ? textMatch[1] : '';
    if (text) {
      return '<!--more text="' + text.replace(/"/g, '&quot;') + '"-->';
    }
    return '<!--more-->';
  });
}

// Convert storage format to editor HTML: <!--more--> → more div
function commentToMore(html) {
  return html.replace(/<!--\s*more(?:\s+text=(?:["']|&quot;)(.*?)(?:["']|&quot;))?\s*-->/gi, function(match, text) {
    const t = text ? text.replace(/&quot;/g, '"') : '';
    const attrs = ' class="vl-more-divider" data-more="1"' + (t ? ' data-text="' + t.replace(/"/g, '&quot;') + '"' : '');
    return '<div' + attrs + '>⊡ ' + tjs('MORE') + '</div>';
  });
}

// ── NextPageNode — visual <!--nextpage--> divider ─────────────────────────
// Renders as a styled divider with optional page title in editor, stores as <!--nextpage--> or <!--nextpage title="..."--> in DB.

function nextpageToComment(html) {
  return html.replace(/<div[^>]*data-nextpage[^>]*>(?:.*?)<\/div>/gi, function(match) {
    const titleMatch = match.match(/data-title=("|'|&quot;)(.*?)\1/i);
    const title = titleMatch ? titleMatch[2] : '';
    if (title) {
      return '<!--nextpage title="' + title.replace(/"/g, '&quot;') + '"-->';
    }
    return '<!--nextpage-->';
  });
}

function commentToNextpage(html) {
  return html.replace(/<!--nextpage(?:\s+title=("|'|&quot;)(.*?)\1)?\s*-->/gi, function(match, quote, title) {
    const t = title ? title.replace(/&quot;/g, '"') : '';
    return '<div class="vl-nextpage-divider" data-nextpage="1" data-title="' + t.replace(/"/g, '&quot;') + '">📄 ' + tjs('NEXT PAGE') + (t ? ': ' + t.replace(/"/g, '&quot;') : '') + '</div>';
  });
}

// ── Spacer block ──────────────────────────────────────────────────────────────

function spacerToHtml(html) {
  return html.replace(/<div[^>]*data-spacer[^>]*data-spacer-size="([^"]*)"[^>]*data-spacer-unit="([^"]*)"[^>]*>.*?<\/div>/g,
    (_, size, unit) => `<!--spacer size=${size} unit=${unit}-->`);
}

// Convert storage format to editor HTML: <!--spacer--> → spacer div
function htmlToSpacer(html) {
  return html.replace(/<!--spacer\s+size=(\d+)\s+unit=(\w+)-->/g,
    (_, size, unit) => {
      return `<div class="vl-spacer-block" data-spacer="1" data-spacer-size="${size}" data-spacer-unit="${unit}" contenteditable="false" style="height:${size}${unit}"></div>`;
    });
}

// ── Fullscreen toggle ────────────────────────────────────────────────────────

export { formEmbedToShortcode, shortcodeToFormEmbed, shortcodeToVideoEmbed, videoEmbedHtmlForUrl, videoWidthFromHtml, setVideoWidth, subtitleTracksFromHtml, setSubtitleTracks, stripSubtitleTracks, htmlToVideoEmbed, videoEmbedToHtml, resolveFormNames, moreToComment, commentToMore, nextpageToComment, commentToNextpage, spacerToHtml, htmlToSpacer, normalizeImageFigures };
