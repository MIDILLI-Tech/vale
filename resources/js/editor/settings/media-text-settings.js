/**
 * Media + Text block settings dialog.
 */
import { openBlockSettings } from './schema-modal.js';

export function openMediaTextSettings(editor, node, pos) {
  const src = node.attrs.imageSrc || '';
  const isVid = /\.(mp4|webm|ogg|mov|m4v)(\?|$)/i.test(src)
    || src.includes('youtube.com') || src.includes('youtu.be') || src.includes('vimeo.com');

  const fields = [
    {
      key: 'width',
      type: 'text',
      label: tjs('Width'),
      placeholder: tjs('e.g. 50%, 800px, auto'),
      help: tjs('Section width: percent, pixels or auto for full width.'),
    },
    {
      key: 'autoplay',
      type: 'checkbox',
      label: tjs('Autoplay (video only)'),
    },
  ];

  // Lightbox only makes sense for image media (incl. GIFs) — videos carry
  // their own playback controls and the storefront lightbox is image-based.
  if (src && !isVid) {
    fields.push({
      key: 'lightbox',
      type: 'checkbox',
      label: tjs('Lightbox (click to enlarge)'),
    });
  }

  openBlockSettings(editor, pos, {
    title: tjs('Media + Text Settings'),
    fields,
  });
}