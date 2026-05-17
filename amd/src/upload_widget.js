// AMD module for the mod_fastpix activity edit form upload widget.
//
// Phase C unified panel:
// - Single panel showing drop zone + URL row at once (no pill toggle).
// - File drop or click → set hidden source_type='upload', auto-start upload.
// - URL + Upload button → set hidden source_type='urlpull', validate via
//   local_fastpix_create_url_pull_session.
// - Hidden source_type field is mutated via .value= and a dispatched
//   change event so any consumer (server-side validation, mform hideIf)
//   sees the active mode.
//
// Calls local_fastpix_create_upload_session / local_fastpix_create_url_pull_session
// via core/ajax (CC2). Per A2: zero direct calls to the video CDN — the
// signed upload URL is PUT to using fetch/XHR but the URL is supplied
// by local_fastpix, not constructed here.

import {call as ajaxCall} from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';

const SELECTORS = {
    region:           '[data-region="fastpix-upload-widget"]',
    picker:           '[data-region="fastpix-upload-picker"]',
    dropzone:         '[data-region="fastpix-upload-dropzone"]',
    input:            '[data-region="fastpix-upload-input"]',
    browseLink:       '[data-action="fastpix-browse-trigger"]',
    progressWrap:     '[data-region="fastpix-upload-progress"]',
    progressBar:      '[data-region="fastpix-upload-bar"]',
    progressBarFill:  '[data-region="fastpix-upload-bar-fill"]',
    progressPct:      '[data-region="fastpix-upload-percent"]',
    status:           '[data-region="fastpix-upload-status"]',
    urlStatus:        '[data-region="fastpix-urlpull-status"]',
    sourceType:       '[name="source_type"]',
    sourceUrl:        '[name="source_url"]',
    validateBtn:      '[name="validate_url"]',
};

const getSessionField = (fieldname) => document.querySelector(`input[name="${fieldname}"]`);

const setSourceType = (value) => {
    const el = document.querySelector(SELECTORS.sourceType);
    if (!el) { return; }
    el.value = value;
    el.dispatchEvent(new Event('change', {bubbles: true}));
};

const setStatus = (region, message, kind) => {
    const el = region.querySelector(SELECTORS.status);
    if (!el) { return; }
    el.hidden = false;
    el.textContent = message;
    el.className = `mt-3 small alert alert-${kind}`;
};

const clearStatus = (region) => {
    const el = region.querySelector(SELECTORS.status);
    if (!el) { return; }
    el.hidden = true;
    el.textContent = '';
    el.className = 'mt-3 small';
};

const setUrlStatus = (message, kind) => {
    const el = document.querySelector(SELECTORS.urlStatus);
    if (!el) { return; }
    el.textContent = message;
    el.className = `text-${kind === 'success' ? 'success' : kind === 'danger' ? 'danger' : 'body-secondary'} small mt-2`;
};

/**
 * PUT bytes to the signed upload URL with progress reporting.
 *
 * CORS-after-100% workaround: FastPix's signed-PUT bucket doesn't return
 * Access-Control-Allow-Origin on the PUT response, so the browser fires
 * `error` even after bytes are accepted server-side. Treat "progress
 * reached 100% then error fired" as success.
 */
const putToSignedUrl = (file, uploadUrl, onProgress) => new Promise((resolve, reject) => {
    let bytesUploaded = false;
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', uploadUrl);
    xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            if (pct >= 100) { bytesUploaded = true; }
            onProgress(pct);
        }
    });
    xhr.upload.addEventListener('load', () => { bytesUploaded = true; });
    xhr.addEventListener('load', () => {
        if (xhr.status >= 200 && xhr.status < 300) {
            resolve();
        } else if (xhr.status === 0 && bytesUploaded) {
            resolve();
        } else {
            reject(new Error(`upload_failed_${xhr.status}`));
        }
    });
    xhr.addEventListener('error', () => {
        if (bytesUploaded) { resolve(); return; }
        reject(new Error('upload_network_error'));
    });
    xhr.addEventListener('abort', () => reject(new Error('upload_aborted')));
    xhr.send(file);
});

const showProgressUI = (region) => {
    const dropzone = region.querySelector(SELECTORS.dropzone);
    if (dropzone) { dropzone.hidden = true; }
    const progress = region.querySelector(SELECTORS.progressWrap);
    if (progress) { progress.hidden = false; }
};

const showSuccessUI = (region, filename) => {
    const progress = region.querySelector(SELECTORS.progressWrap);
    if (progress) { progress.hidden = true; }
    setStatus(region, `Uploaded ${filename}. Save the activity to finalise.`, 'success');
};

const showDropzoneUI = (region) => {
    const dropzone = region.querySelector(SELECTORS.dropzone);
    if (dropzone) { dropzone.hidden = false; }
    const progress = region.querySelector(SELECTORS.progressWrap);
    if (progress) { progress.hidden = true; }
};

const handleFileSelected = async (region, sessionField, file) => {
    if (!file) { return; }
    setSourceType('upload');
    clearStatus(region);
    showProgressUI(region);

    let session;
    try {
        [session] = await Promise.all(ajaxCall([{
            methodname: 'local_fastpix_create_upload_session',
            args: { filename: file.name, size: file.size },
        }]));
    } catch (e) {
        Notification.exception(e);
        showDropzoneUI(region);
        setStatus(region, 'Failed to create upload session.', 'danger');
        return;
    }

    const bar = region.querySelector(SELECTORS.progressBar);
    const fill = region.querySelector(SELECTORS.progressBarFill);
    const pct = region.querySelector(SELECTORS.progressPct);

    try {
        await putToSignedUrl(file, session.upload_url, (percent) => {
            if (bar) { bar.value = percent; }
            if (fill) { fill.style.width = `${percent}%`; }
            if (pct) { pct.textContent = `${percent}%`; }
        });
    } catch (e) {
        showDropzoneUI(region);
        setStatus(region, `Upload failed: ${e.message}`, 'danger');
        return;
    }

    sessionField.value = String(session.session_id);
    showSuccessUI(region, file.name);
};

const wireDropzone = (region, sessionField) => {
    const dropzone = region.querySelector(SELECTORS.dropzone);
    const input = region.querySelector(SELECTORS.input);
    const browse = region.querySelector(SELECTORS.browseLink);
    if (!dropzone || !input) { return; }

    // Browse link (rendered above the transparent file input z-index-wise).
    if (browse) {
        browse.addEventListener('click', (e) => {
            e.preventDefault();
            input.click();
        });
    }

    dropzone.addEventListener('click', (e) => {
        // The browse link handles its own click. The native input also catches
        // clicks directly via z-index. Avoid double-trigger.
        if (e.target === input || e.target === browse) { return; }
        input.click();
    });

    dropzone.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            input.click();
        }
    });

    const setDragging = (on) => {
        dropzone.classList.toggle('is-dragging', !!on);
    };

    ['dragenter', 'dragover'].forEach((ev) => dropzone.addEventListener(ev, (e) => {
        e.preventDefault();
        e.stopPropagation();
        setDragging(true);
    }));
    ['dragleave', 'dragend'].forEach((ev) => dropzone.addEventListener(ev, (e) => {
        e.preventDefault();
        e.stopPropagation();
        setDragging(false);
    }));
    dropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        e.stopPropagation();
        setDragging(false);
        const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (file) {
            handleFileSelected(region, sessionField, file);
        }
    });

    input.addEventListener('change', () => {
        const file = input.files && input.files[0];
        if (file) {
            handleFileSelected(region, sessionField, file);
        }
    });
};

const validateUrl = async (sessionField, button) => {
    const urlInput = document.querySelector(SELECTORS.sourceUrl);
    if (!urlInput || !urlInput.value) {
        setUrlStatus('Enter a URL first.', 'warning');
        return;
    }

    setSourceType('urlpull');
    setUrlStatus('Uploading…', 'muted');
    if (button) { button.disabled = true; }

    let session;
    try {
        [session] = await Promise.all(ajaxCall([{
            methodname: 'local_fastpix_create_url_pull_session',
            args: { source_url: urlInput.value },
        }]));
    } catch (e) {
        Notification.exception(e);
        setUrlStatus('URL rejected.', 'danger');
        if (button) { button.disabled = false; }
        return;
    }

    sessionField.value = String(session.session_id);
    setUrlStatus('✓ URL accepted. Save the activity to finalise.', 'success');
    if (button) { button.disabled = false; }
};

const renderInto = async (region) => {
    const {html, js} = await Templates.renderForPromise('mod_fastpix/upload_widget', {});
    Templates.replaceNodeContents(region, html, js);
};

export const init = async (config) => {
    const region = document.querySelector(SELECTORS.region);
    if (!region) { return; }

    const sessionField = getSessionField(config.fieldnameSession);
    if (!sessionField) { return; }

    // Wire the URL Upload button at outer-document scope BEFORE the inner
    // template renders, so the listener is in place even if the user
    // somehow clicks before render completes.
    const validateBtn = document.querySelector(SELECTORS.validateBtn);
    if (validateBtn && validateBtn.getAttribute('data-fastpix-wired') !== '1') {
        validateBtn.setAttribute('data-fastpix-wired', '1');
        validateBtn.addEventListener('click', (e) => {
            e.preventDefault();
            validateUrl(sessionField, validateBtn);
        });
    }

    // Editing the URL invalidates a prior validate (so the form doesn't
    // submit a stale upload_session_id against a new URL).
    const urlInput = document.querySelector(SELECTORS.sourceUrl);
    if (urlInput && urlInput.getAttribute('data-fastpix-wired') !== '1') {
        urlInput.setAttribute('data-fastpix-wired', '1');
        urlInput.addEventListener('input', () => {
            if (sessionField) { sessionField.value = ''; }
            setUrlStatus('', 'muted');
        });
    }

    await renderInto(region);

    // After render, re-query for URL input + validate button (template may
    // replace them) and wire dropzone.
    const renderedUrlInput = document.querySelector(SELECTORS.sourceUrl);
    const renderedValidateBtn = document.querySelector(SELECTORS.validateBtn);
    if (renderedValidateBtn && renderedValidateBtn.getAttribute('data-fastpix-wired') !== '1') {
        renderedValidateBtn.setAttribute('data-fastpix-wired', '1');
        renderedValidateBtn.addEventListener('click', (e) => {
            e.preventDefault();
            validateUrl(sessionField, renderedValidateBtn);
        });
    }
    if (renderedUrlInput && renderedUrlInput.getAttribute('data-fastpix-wired') !== '1') {
        renderedUrlInput.setAttribute('data-fastpix-wired', '1');
        renderedUrlInput.addEventListener('input', () => {
            if (sessionField) { sessionField.value = ''; }
            setUrlStatus('', 'muted');
        });
    }

    wireDropzone(region, sessionField);
};
