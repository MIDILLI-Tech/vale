/**
 * Direct file upload flow (image, video, or file → content block/link).
 *
 * Images keep the original setImage path (WebP variant preferred). Videos are
 * inserted as videoEmbed blocks via the shared embed-HTML helper, so an
 * uploaded file renders exactly like the same URL added through External
 * Media. Documents/archives (audio, PDF, ZIP) are inserted as named text links
 * via the shared file-link dialog — same behavior as picking them from the
 * media library.
 *
 * Phase 1 mechanical split from resources/js/editor.js (behavior-preserving).
 */
import { Image } from '@tiptap/extension-image';

import { videoEmbedHtmlForUrl } from '../converters.js';
import { openFileLinkDialog } from './browse.js';
import { bp, getCsrfToken } from '../utils.js';

const UPLOAD_FILE_EXTENSIONS = [
  'pdf', 'zip', 'rar', '7z', 'gz', 'tar', 'bz2',
  'mp3', 'txt', 'rtf', 'srt', 'sub', 'vtt', 'docx', 'xlsx',
];

// Progress toast: fetch() has no upload progress events, so the request goes
// over XMLHttpRequest. Endpoint and payload are unchanged; only the transport
// differs so the bar can be driven.
function showUploadToast(fileName) {
  const toast = document.createElement('div');
  toast.className = 'vl-upload-toast';
  toast.setAttribute('role', 'status');
  toast.innerHTML = `
    <div class="vl-upload-toast-row">
      <span class="vl-upload-spinner" aria-hidden="true"></span>
      <span class="vl-upload-label"></span>
      <span class="vl-upload-name"></span>
    </div>
    <div class="vl-upload-bar"><div class="vl-upload-bar-fill"></div></div>`;
  const label = toast.querySelector('.vl-upload-label');
  const fill = toast.querySelector('.vl-upload-bar-fill');
  label.textContent = tjs('Uploading...');
  toast.querySelector('.vl-upload-name').textContent = fileName || '';
  document.body.appendChild(toast);
  requestAnimationFrame(() => toast.classList.add('is-visible'));

  let dismissed = false;
  return {
    setProgress(percent) {
      if (dismissed) return;
      label.textContent = `${tjs('Uploading...')} ${Math.round(percent)}%`;
      fill.style.width = `${percent}%`;
    },
    fail(message) {
      if (dismissed) return;
      dismissed = true;
      toast.classList.add('is-error');
      label.textContent = message;
      const bar = toast.querySelector('.vl-upload-bar');
      const spinner = toast.querySelector('.vl-upload-spinner');
      if (bar) bar.remove();
      if (spinner) spinner.remove();
      setTimeout(() => {
        toast.classList.remove('is-visible');
        setTimeout(() => toast.remove(), 250);
      }, 4000);
    },
    done() {
      if (dismissed) return;
      dismissed = true;
      toast.classList.remove('is-visible');
      setTimeout(() => toast.remove(), 250);
    },
  };
}

async function uploadFileAndInsert(editor, file) {
  const isVideo = file.type.startsWith('video/');
  const isImage = file.type.startsWith('image/');
  const extension = (file.name.split('.').pop() || '').toLowerCase();
  const isFile = !isVideo && !isImage && (
    file.type.startsWith('application/') ||
    file.type.startsWith('audio/') ||
    UPLOAD_FILE_EXTENSIONS.includes(extension)
  );
  if (!isVideo && !isImage && !isFile) {
    alert(tjs('This file type cannot be inserted into content.'));
    return;
  }

  const csrfToken = getCsrfToken();
  const formData = new FormData();
  formData.append('file', file);
  formData.append('_csrf_token', csrfToken);

  const toast = showUploadToast(file.name);
  const fail = (message) => {
    toast.fail(message);
    alert(message);
  };

  try {
    const data = await new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', bp('/admin/media/upload-json'));
      xhr.setRequestHeader('X-CSRF-Token', csrfToken);
      xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) toast.setProgress((e.loaded / e.total) * 100);
      });
      xhr.addEventListener('load', () => {
        let parsed = null;
        try {
          parsed = JSON.parse(xhr.responseText);
        } catch (_) { /* non-JSON error page */ }
        if (xhr.status >= 200 && xhr.status < 300 && parsed?.url) {
          resolve(parsed);
        } else {
          reject(new Error(parsed?.error || tjs('Upload failed.')));
        }
      });
      xhr.addEventListener('error', () => reject(new Error(tjs('Upload failed.'))));
      xhr.addEventListener('abort', () => reject(new Error(tjs('Upload failed.'))));
      xhr.send(formData);
    });

    if (isVideo) {
      editor.chain().focus().insertContent({
        type: 'videoEmbed',
        attrs: { html: videoEmbedHtmlForUrl(data.url) },
      }).run();
    } else if (isFile) {
      openFileLinkDialog(editor, {
        url: data.url,
        name: file.name,
        size: file.size,
        mime: file.type,
      });
    } else {
      const webpSrc = data.thumbnails?.webp || null;
      editor.chain().focus().setImage({ src: webpSrc || data.url, _thumbnailSize: 'full', _thumbnails: data.thumbnails || null }).run();
    }
    toast.done();
  } catch (err) {
    fail(err.message || tjs('Upload failed.'));
  }
}

function triggerFileUpload(editor) {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = 'image/*,video/*,audio/mpeg,application/pdf,application/zip,'
    + '.rar,.7z,.gz,.tar,.bz2,.txt,.rtf,.srt,.sub,.vtt,.docx,.xlsx';
  input.addEventListener('change', async () => {
    const file = input.files?.[0];
    if (!file) return;
    await uploadFileAndInsert(editor, file);
  });
  input.click();
}

function setupEditorDropHandler(editor, wrapper) {
  let dragCounter = 0;

  function isExternalFileDrag(e) {
    if (editor.__isHtmlMode && editor.__isHtmlMode()) return false;
    if (!e.dataTransfer) return false;
    const types = Array.from(e.dataTransfer.types || []);
    if (types.includes('text/vale-image')) return false;
    return types.includes('Files') || (e.dataTransfer.files && e.dataTransfer.files.length > 0);
  }

  wrapper.addEventListener('dragenter', (e) => {
    if (!isExternalFileDrag(e)) return;
    e.preventDefault();
    e.stopPropagation();
    dragCounter++;
    if (dragCounter === 1) {
      wrapper.classList.add('is-dragover');
    }
  });

  wrapper.addEventListener('dragover', (e) => {
    if (!isExternalFileDrag(e)) return;
    e.preventDefault();
    e.stopPropagation();
    e.dataTransfer.dropEffect = 'copy';
  });

  wrapper.addEventListener('dragleave', (e) => {
    if (!isExternalFileDrag(e)) return;
    e.preventDefault();
    e.stopPropagation();
    dragCounter--;
    if (dragCounter <= 0) {
      dragCounter = 0;
      wrapper.classList.remove('is-dragover');
    }
  });

  wrapper.addEventListener('drop', async (e) => {
    if (!isExternalFileDrag(e)) return;
    e.preventDefault();
    e.stopPropagation();
    dragCounter = 0;
    wrapper.classList.remove('is-dragover');

    if (window.VL_CAN_UPLOAD_MEDIA === false) {
      alert(tjs("You don't have permission to upload files."));
      return;
    }

    const files = Array.from(e.dataTransfer?.files || []);
    if (files.length === 0) return;

    for (const file of files) {
      await uploadFileAndInsert(editor, file);
    }
  });
}

export { triggerFileUpload, setupEditorDropHandler, uploadFileAndInsert };
