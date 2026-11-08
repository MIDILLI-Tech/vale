/**
 * Link button settings dialog (declarative schema).
 */
import { openBlockSettings } from './schema-modal.js';

const BORDER_WIDTHS = ['0px', '1px', '2px', '3px', '4px', '5px'];
const BORDER_STYLES = ['none', 'solid', 'dashed', 'dotted', 'double'];
const BORDER_RADIUSES = ['0px', '2px', '4px', '6px', '8px', '12px', '20px', '9999px'];

const PADDING_OPTIONS = [
  { v: '6px 12px', l: tjs('Small (6px 12px)') },
  { v: '10px 20px', l: tjs('Medium (10px 20px)') },
  { v: '12px 24px', l: tjs('Large (12px 24px)') },
  { v: '16px 32px', l: tjs('Extra Large (16px 32px)') },
];

// The internal/external link field lives in ui/internal-link-field.js so the
// redirect block and the Insert Link dialog share one implementation (and one
// module-level cache). Kept as a thin wrapper because the block settings
// framework expects { key, type, render(ctx) }.
import { internalLinkField } from '../ui/internal-link-field.js';

function linkField() {
  return {
    key: 'href',
    type: 'custom',
    render(ctx) {
      return internalLinkField({
        label: tjs('Link'),
        value: ctx.getValue('href') || '',
        onChange: (href, item) => {
          ctx.setValue('href', href);
          if (item && item.id && item.type) {
            ctx.setValue('internalType', item.type);
            ctx.setValue('internalId', String(item.id));
          } else {
            ctx.setValue('internalType', '');
            ctx.setValue('internalId', '');
          }
        },
      });
    },
  };
}

export function openLinkButtonSettings(editor, node, pos) {
  openBlockSettings(editor, pos, {
    title: tjs('Link Button Settings'),
    size: 'vl-modal-md p-5',
    scrollBody: true,
    backdropGuardMs: 500,
    fields: [
      linkField(),
      { type: 'divider' },
      { key: 'bgColor', type: 'color', label: tjs('Background Color'), pickerDefault: '#000000', fallbackValue: '#000000' },
      { key: 'textColor', type: 'color', label: tjs('Text Color'), pickerDefault: '#ffffff', fallbackValue: '#ffffff' },
      { key: 'borderWidth', type: 'select', label: tjs('Border Width'), options: BORDER_WIDTHS },
      { key: 'borderStyle', type: 'select', label: tjs('Border Style'), options: BORDER_STYLES },
      { key: 'borderColor', type: 'color', label: tjs('Border Color'), pickerDefault: '#000000', fallbackValue: '#000000' },
      { key: 'borderRadius', type: 'select', label: tjs('Border Radius'), options: BORDER_RADIUSES },
      { key: 'padding', type: 'select', label: tjs('Padding'), options: PADDING_OPTIONS },
      {
        key: 'fullWidth',
        type: 'select',
        label: tjs('Full Width'),
        options: [
          { v: 'false', l: tjs('No') },
          { v: 'true', l: tjs('Yes') },
        ],
      },
    ],
  });
}

export { linkField, BORDER_WIDTHS, BORDER_STYLES, BORDER_RADIUSES, PADDING_OPTIONS, DEFAULT_BUTTON_ATTRS };

const DEFAULT_BUTTON_ATTRS = {
  href: '',
  target: '_self',
  internalType: '',
  internalId: '',
  bgColor: '#000000',
  textColor: '#ffffff',
  borderWidth: '0px',
  borderStyle: 'none',
  borderColor: '#000000',
  borderRadius: '4px',
  padding: '10px 20px',
  alignment: 'center',
  fullWidth: 'false',
};
