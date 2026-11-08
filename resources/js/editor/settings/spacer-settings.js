/**
 * Spacer block settings dialog (declarative schema).
 */
import { openBlockSettings } from './schema-modal.js';

export function openSpacerSettings(editor, node, pos) {
  openBlockSettings(editor, pos, {
    title: tjs('Spacer Settings'),
    fields: [
      {
        key: 'size',
        type: 'number',
        label: tjs('Height'),
        min: 1,
        max: 9999,
        parse: v => parseInt(v, 10) || 50,
        suffixSelect: { key: 'unit', options: ['px', '%'] },
      },
    ],
  });
}
