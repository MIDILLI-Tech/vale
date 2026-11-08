/**
 * Carousel embed settings dialog (declarative schema).
 */
import { openBlockSettings } from './schema-modal.js';

export const FIT_OPTIONS = ['cover', 'contain', 'fill', 'none', 'scale-down'];

const TRANSITION_OPTIONS = [
  { v: 'fade', l: tjs('Fade') },
  { v: 'slide-left', l: tjs('Slide Left') },
  { v: 'slide-right', l: tjs('Slide Right') },
  { v: 'slide-up', l: tjs('Slide Up') },
  { v: 'zoom', l: tjs('Zoom') },
  { v: 'none', l: tjs('None') },
];

export const CAROUSEL_SETTINGS_FIELDS = [
  { key: 'autoplay', type: 'checkbox', label: tjs('Autoplay') },
  { key: 'duration', type: 'number', label: tjs('Slide Duration (ms)'), step: 500, min: 500, parse: v => parseInt(v, 10) || 5000 },
  { key: 'showArrows', type: 'checkbox', label: tjs('Show Arrows') },
  { key: 'showDots', type: 'checkbox', label: tjs('Show Dots') },
  { type: 'divider' },
  { key: 'pauseBtn', type: 'checkbox', label: tjs('Pause Button') },
  { key: 'fit', type: 'select', label: tjs('Media Fit'), options: FIT_OPTIONS },
  {
    key: 'height',
    type: 'text',
    label: tjs('Height'),
    placeholder: '200px',
    help: `${tjs('Examples')}: 200px, 300px, 50vh`,
    parse: v => v || '200px',
  },
  { key: 'transition', type: 'select', label: tjs('Transition'), options: TRANSITION_OPTIONS },
  { key: 'lightbox', type: 'checkbox', label: tjs('Lightbox (click to enlarge)') },
  { key: 'bg', type: 'color', label: tjs('Background'), pickerDefault: '#f3f4f6' },
];

export function openCarouselSettings(editor, node, pos) {
  openBlockSettings(editor, pos, {
    title: tjs('Carousel Settings'),
    fields: CAROUSEL_SETTINGS_FIELDS,
  });
}
