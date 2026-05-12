// AMD module for the mod_fastpix activity edit form upload widget.
//
// Calls local_fastpix_create_upload_session / local_fastpix_create_url_pull_session
// via core/ajax (CC2). On success, stashes the returned session_id onto the
// hidden form field named by config.fieldnameSession.
//
// Per A2: this module makes ZERO direct calls to the video CDN. The signed
// upload URL is fetched from local_fastpix and PUT to as a vendor-supplied
// signed URL — that PUT is the only egress and it is to a host chosen by
// local_fastpix, not by us.
//
// Phase C UI redesign:
// - Pill-toggle (rendered by mod_form HTML) drives the hidden <select name="source_type">.
// - Drop zone replaces the file-input + Start-upload pair. Click or drop both
//   trigger the hidden <input type="file">. Selecting a file auto-starts the upload.
// - Post-upload preview row replaces the inline alert on success.

import {call as ajaxCall} from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';

const SELECTORS = {
    region:           '[data-region="fastpix-upload-widget"]',
    picker:           '[data-region="fastpix-upload-picker"]',
    dropzone:         '[data-region="fastpix-upload-dropzone"]',
    input:            '[data-region="fastpix-upload-input"]',
    progressWrap:     '[data-region="fastpix-upload-progress"]',
    progressBar:      '[data-region="fastpix-upload-bar"]',
    progressBarFill:  '[data-region="fastpix-upload-bar-fill"]',
    progressPct:      '[data-region="fastpix-upload-percent"]',
    status:           '[data-region="fastpix-upload-status"]',
    urlSection:       '[data-region="fastpix-urlpull-section"]',
    urlStatus:        '[data-region="fastpix-urlpull-status"]',
    urlPreview:       '[data-region="fastpix-urlpull-preview"]',
    urlPreviewUrl:    '[data-region="fastpix-urlpull-preview-url"]',
    sourceType:       '[name="source_type"]',
    sourceUrl:        '[name="source_url"]',
    validateBtn:      '[name="validate_url"]',
    sourceTab:        '[data-action="fastpix-source-tab"]',
};

const getSessionField = (fieldname) => document.querySelector(`input[name="${fieldname}"]`);

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
    el.className = `text-${kind === 'success' ? 'success' : kind === 'danger' ? 'danger' : 'body-secondary'} small mt-1`;
};

/**
 * PUT bytes to the signed upload URL with progress reporting.
 *
 * Tracks upload completion separately from response receipt: FastPix's
 * signed-PUT bucket does not return Access-Control-Allow-Origin on the PUT
 * response, so the browser fires `error` even when bytes are accepted
 * server-side. We treat "progress reached 100% then error fired" as success.
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
    // Hand off to the upload-status alert instead of a separate preview
    // row — the row was removed in the C9 cleanup. Filename is announced
    // via aria-live for accessibility.
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
    if (!dropzone || !input) { return; }

    dropzone.addEventListener('click', (e) => {
        // Avoid recursion if the click came from the inner native input.
        if (e.target === input) { return; }
        input.click();
    });

    dropzone.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            input.click();
        }
    });

    const setDragging = (on) => {
        // Visual style is owned by the .is-dragging CSS rule in the template.
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

const validateUrl = async (sessionField) => {
    const urlInput = document.querySelector(SELECTORS.sourceUrl);
    if (!urlInput || !urlInput.value) {
        setUrlStatus('Enter a URL first.', 'warning');
        return;
    }

    setUrlStatus('Validating…', 'muted');

    let session;
    try {
        [session] = await Promise.all(ajaxCall([{
            methodname: 'local_fastpix_create_url_pull_session',
            args: { source_url: urlInput.value },
        }]));
    } catch (e) {
        Notification.exception(e);
        setUrlStatus('URL rejected.', 'danger');
        return;
    }

    sessionField.value = String(session.session_id);

    // Reveal the urlpull-preview row and populate it with the URL value.
    // Use document-scoped queries so this still works even if the preview
    // wrapper sits at a different nesting depth than the URL input.
    const slot = document.querySelector(SELECTORS.urlPreviewUrl);
    if (slot) { slot.textContent = urlInput.value; }
    const preview = document.querySelector(SELECTORS.urlPreview);
    if (preview) { preview.hidden = false; }
    setUrlStatus('✓ URL validated. Save the activity to finalize.', 'success');
};

const renderInto = async (region) => {
    const {html, js} = await Templates.renderForPromise('mod_fastpix/upload_widget', {});
    Templates.replaceNodeContents(region, html, js);
};

const refreshTabVisibility = (region) => {
    const sourceType = document.querySelector(SELECTORS.sourceType);
    if (!sourceType) { return; }
    const isUpload = sourceType.value === 'upload';
    // Toggle the inner picker (rendered into [data-region=fastpix-upload-widget])
    // and the separate urlpull section emitted from mod_form HTML.
    if (region) {
        const picker = region.querySelector(SELECTORS.picker);
        if (picker) { picker.hidden = !isUpload; }
    }
    const urlSection = document.querySelector(SELECTORS.urlSection);
    if (urlSection) { urlSection.hidden = isUpload; }
};

const refreshPillVisuals = () => {
    const sourceType = document.querySelector(SELECTORS.sourceType);
    if (!sourceType) { return; }
    document.querySelectorAll(SELECTORS.sourceTab).forEach((btn) => {
        const active = btn.getAttribute('data-source-type') === sourceType.value;
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
        if (active) {
            btn.classList.add('fw-medium');
            btn.classList.remove('text-body-secondary', 'border-0');
            btn.style.background = '#fff';
            btn.style.border = '1px solid #e5e7eb';
            btn.style.boxShadow = '0 1px 2px rgba(0,0,0,.05)';
        } else {
            btn.classList.add('text-body-secondary', 'fw-medium', 'border-0');
            btn.style.background = 'transparent';
            btn.style.border = '0';
            btn.style.boxShadow = 'none';
        }
    });
};

const wirePillToggle = () => {
    const sourceType = document.querySelector(SELECTORS.sourceType);
    if (!sourceType) { return; }
    document.querySelectorAll(SELECTORS.sourceTab).forEach((btn) => {
        if (btn.getAttribute('data-fastpix-wired') === '1') { return; }
        btn.setAttribute('data-fastpix-wired', '1');
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const target = btn.getAttribute('data-source-type');
            if (sourceType.value === target) { return; }
            sourceType.value = target;
            sourceType.dispatchEvent(new Event('change', {bubbles: true}));
            refreshPillVisuals();
        });
    });
};

export const init = async (config) => {
    const region = document.querySelector(SELECTORS.region);
    if (!region) { return; }

    const sessionField = getSessionField(config.fieldnameSession);
    if (!sessionField) { return; }

    wirePillToggle();
    refreshPillVisuals();

    const sourceType = document.querySelector(SELECTORS.sourceType);
    if (sourceType) {
        sourceType.addEventListener('change', () => {
            refreshTabVisibility(region);
            refreshPillVisuals();
        });
    }

    const validateBtn = document.querySelector(SELECTORS.validateBtn);
    if (validateBtn && validateBtn.getAttribute('data-fastpix-wired') !== '1') {
        validateBtn.setAttribute('data-fastpix-wired', '1');
        validateBtn.addEventListener('click', (e) => {
            e.preventDefault();
            validateUrl(sessionField);
        });
    }

    // Invalidate the urlpull preview + cached session when the URL is edited.
    // Saves teachers from submitting an old session_id against a new URL.
    const urlInput = document.querySelector(SELECTORS.sourceUrl);
    if (urlInput && urlInput.getAttribute('data-fastpix-wired') !== '1') {
        urlInput.setAttribute('data-fastpix-wired', '1');
        urlInput.addEventListener('input', () => {
            const preview = document.querySelector(SELECTORS.urlPreview);
            if (preview) { preview.hidden = true; }
            if (sessionField) { sessionField.value = ''; }
        });
    }

    await renderInto(region);
    refreshTabVisibility(region);
    wireDropzone(region, sessionField);
};
