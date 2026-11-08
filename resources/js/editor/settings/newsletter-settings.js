/**
 * Newsletter embed settings dialog (declarative schema).
 */
import { openBlockSettings } from './schema-modal.js';

export function openNewsletterSettings(editor, node, pos) {
  const warn = document.createElement('p');
  warn.className = 'vl-modal-subtext';
  warn.style.cssText = 'color:#b45309;font-style:normal;font-weight:600;';
  const refreshCaptchaWarning = (type) => {
    const configured = window._VL_CAPTCHA || {};
    const needsKeys = type === 'hcaptcha' || type === 'recaptcha' || type === 'turnstile';
    const missing = needsKeys && !configured[type];
    warn.style.display = missing ? '' : 'none';
    if (missing) {
      warn.textContent = '⚠ ' + tjs('This form will not be shown because captcha is not configured.');
    }
  };
  openBlockSettings(editor, pos, {
    title: tjs('Newsletter Settings'),
    fields: [
      { key: 'placeholder', type: 'text', label: tjs('Placeholder') },
      { key: 'buttonText', type: 'text', label: tjs('Button Text') },
      { key: 'privacyPage', type: 'page', label: tjs('Privacy Page'), placeholder: tjs('e.g. privacy-policy') },
      { key: 'tag', type: 'tags', label: tjs('Auto Tags'), placeholder: tjs('Type tag name...'), help: tjs('Separate with comma. New tags are created automatically.'), suggestionsUrl: (window.APP_BASE_PATH || '') + '/admin/newsletter/subscriber-tags/list-json' },
      {
        key: 'captcha',
        type: 'select',
        label: tjs('Captcha Protection'),
        options: [
          { v: 'disabled', l: tjs('Disabled') },
          { v: 'honeypot', l: tjs('Honeypot') },
          { v: 'math', l: tjs('Math Captcha') },
          { v: 'recaptcha', l: tjs('Google reCAPTCHA') },
          { v: 'hcaptcha', l: tjs('hCaptcha') },
          { v: 'turnstile', l: tjs('Cloudflare Turnstile') },
        ],
      },
      {
        key: 'honeypotName',
        type: 'text',
        label: tjs('Honeypot Field Name'),
        placeholder: '_hp_name',
        help: tjs('Only used when Captcha is Honeypot.'),
        showWhen: { key: 'captcha', value: 'honeypot' },
      },
      {
        type: 'custom',
        render: () => {
          warn.style.display = 'none';
          return warn;
        },
      },
      { key: 'width', type: 'text', label: tjs('Width'), placeholder: tjs('e.g. 50%, 400px, auto'), help: tjs('Form width: percent, pixels or auto for full width.') },
    ],
    onInput: (patch, ctx) => {
      refreshCaptchaWarning(patch.captcha ?? ctx.getValue('captcha'));
    },
    onReady: (ctx) => {
      refreshCaptchaWarning(ctx.getValue('captcha'));
    },
  });
}
