function $(id) {
  const el = document.getElementById(id);
  if (!el) throw new Error(`Missing element #${id}`);
  return el;
}

const videoEl = $('checkin-video');
const startBtn = $('checkin-start');
const stopBtn = $('checkin-stop');
const switchBtn = $('checkin-switch');
const formEl = $('checkin-form');
const tokenEl = $('qr_token');
const msgEl = $('checkin-message');
const ticketEl = $('checkin-ticket');

const tabsEl = document.getElementById('checkin-tabs');
const lookupFormEl = document.getElementById('lookup-form');
const lookupQEl = document.getElementById('lookup-q');
const lookupStatusEl = document.getElementById('lookup-status');
const lookupResultsEl = document.getElementById('lookup-results');

let toastEl = document.getElementById('scan-toast');

// Some layouts can interfere with popover-based positioning. Ensure the toast lives at document.body.
function ensureToast() {
  if (toastEl && toastEl.parentElement !== document.body) {
    document.body.appendChild(toastEl);
  }
  if (!toastEl) {
    toastEl = document.createElement('wa-toast');
    toastEl.id = 'scan-toast';
    toastEl.setAttribute('placement', 'top-end');
    document.body.appendChild(toastEl);
  }
}

let stream = null;
let detector = null;
let cameraDeviceIds = [];
let currentDeviceId = null;

let scanTimer = null;
let lastToken = '';
let lastScanAt = 0;
let busy = false;
let zxingControls = null;
let lastToastAt = 0;
const RECENT_TOKEN_MS = 8000;
const recentTokenTimes = new Map();

function debounce(fn, ms) {
  let t = null;
  return (...args) => {
    if (t) window.clearTimeout(t);
    t = window.setTimeout(() => fn(...args), ms);
  };
}

function wasTokenSeenRecently(token) {
  const now = Date.now();
  // Keep the map bounded and prevent unbounded growth.
  for (const [t, ts] of recentTokenTimes.entries()) {
    if ((now - ts) > RECENT_TOKEN_MS) recentTokenTimes.delete(t);
  }
  const prev = recentTokenTimes.get(token);
  if (prev && (now - prev) < RECENT_TOKEN_MS) return true;
  recentTokenTimes.set(token, now);
  return false;
}

function showMessage(ok, text) {
  msgEl.style.display = 'block';
  msgEl.innerHTML = `
    <wa-callout variant="${ok ? 'success' : 'danger'}">
      ${escapeHtml(text)}
    </wa-callout>
  `;
}

function clearMessage() {
  msgEl.style.display = 'none';
  msgEl.innerHTML = '';
}

function scrollMessageIntoView() {
  try {
    msgEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
  } catch {
    // ignore
  }
}

function setTicket(ticket) {
  if (!ticket) {
    ticketEl.style.display = 'none';
    ticketEl.innerHTML = '';
    return;
  }

  const modifiers = Array.isArray(ticket.modifiers) ? ticket.modifiers : [];
  const modifierLines = modifiers
    .map((m) => {
      const name = String(m?.modifier_name || m?.name || '').trim();
      if (!name) return '';
      const type = String(m?.modifier_type || '').toLowerCase();
      const rawVal = m?.value;
      let val = rawVal === null || rawVal === undefined ? '' : String(rawVal).trim();
      if (val.startsWith('[')) {
        try {
          const parsed = JSON.parse(val);
          if (Array.isArray(parsed)) {
            val = parsed.map((v) => String(v).trim()).filter(Boolean).join(', ');
          }
        } catch {
          // ignore
        }
      }

      // For boolean-style modifiers, the presence of the modifier is sufficient.
      // In some DBs this may come through as "1" — don't render that.
      const looksTruthy = val === '1' || val.toLowerCase() === 'true' || val.toLowerCase() === 'yes' || val.toLowerCase() === 'on';
      const isBoolean = type === 'bool' || type === 'boolean' || type === 'checkbox';
      if (isBoolean && (val === '' || looksTruthy)) {
        return `<div class="checkin-mod"><span class="checkin-mod-name">${escapeHtml(name)}</span></div>`;
      }

      if (val === '' && looksTruthy) {
        return `<div class="checkin-mod"><span class="checkin-mod-name">${escapeHtml(name)}</span></div>`;
      }

      if (val === '' || looksTruthy) {
        return `<div class="checkin-mod"><span class="checkin-mod-name">${escapeHtml(name)}</span></div>`;
      }

      return `<div class="checkin-mod"><span class="checkin-mod-name">${escapeHtml(name)}</span><span class="checkin-mod-val">${escapeHtml(val)}</span></div>`;
    })
    .filter(Boolean)
    .join('');

  const addons = Array.isArray(ticket.addons) ? ticket.addons : [];
  const addonLines = addons
    .map((a) => {
      const name = String(a?.addon_name || a?.name || '').trim();
      if (!name) return '';
      const qty = Number(a?.quantity || 0) || 0;
      const qtyText = `× ${qty > 0 ? qty : 1}`;
      return `<div class="checkin-mod"><span class="checkin-mod-name">${escapeHtml(name)}</span><span class="checkin-mod-val">${escapeHtml(qtyText)}</span></div>`;
    })
    .filter(Boolean)
    .join('');

  const checkedInText = ticket.checked_in_at_display || ticket.checked_in_at || '';
  const checkedInAt = checkedInText ? `<div class="checkin-meta"><span class="checkin-meta-label">Checked In</span><span class="checkin-meta-val mono">${escapeHtml(checkedInText)}</span></div>` : '';

  const sections = [];
  if (modifierLines) {
    sections.push(`
      <wa-divider style="margin: 10px 0;"></wa-divider>
      <div class="checkin-mods">${modifierLines}</div>
    `);
  }
  if (addonLines) {
    sections.push(`
      <wa-divider style="margin: 10px 0;"></wa-divider>
      <div class="checkin-section-title">Add-ons</div>
      <div class="checkin-mods">${addonLines}</div>
    `);
  }
  if (checkedInAt) {
    sections.push(`
      <wa-divider style="margin: 10px 0;"></wa-divider>
      ${checkedInAt}
    `);
  }

  ticketEl.style.display = 'block';
  ticketEl.innerHTML = `
    <div class="checkin-ticket">
      <div class="checkin-head">
        <div class="checkin-event">${escapeHtml(ticket.event_name || '')}</div>
        ${ticket.variation_name ? `<div class="checkin-variation">${escapeHtml(ticket.variation_name || '')}</div>` : ''}
      </div>
      ${sections.join('')}
    </div>
  `;
}

function escapeHtml(s) {
  return String(s)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function friendlyCameraError(err) {
  const name = String(err?.name || '').trim();
  const msg = String(err?.message || '').trim();

  if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
    return 'Camera permission was denied. Allow camera access in your browser settings and try again.';
  }
  if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
    return 'No camera was found. Plug in a camera (or enable one) and try again.';
  }
  if (name === 'NotReadableError' || name === 'TrackStartError') {
    return 'The camera is in use by another app or the browser could not start it. Close other apps using the camera and try again.';
  }
  if (name === 'OverconstrainedError' || name === 'ConstraintNotSatisfiedError') {
    return 'The selected camera configuration is not available. Try switching cameras or using a different device.';
  }
  if (name === 'SecurityError') {
    return 'Camera access is blocked by browser security settings. If you are not on localhost, you may need HTTPS.';
  }
  if (msg.toLowerCase() === 'the object can not be found here.') {
    return 'Camera could not be started. Check that your camera is connected and not being used by another app, then try again.';
  }

  return msg || 'Failed to initialize camera.';
}

async function postCheckIn(token) {
  if (busy) return;
  busy = true;
  try {
    clearMessage();
    setTicket(null);

    const res = await fetch('/admin/checkin/ajax', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '')
      },
      body: JSON.stringify({ qr_token: token })
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data) {
      showMessage(false, data?.message || 'Check-in failed.');
      return;
    }

    if (data.ticket) setTicket(data.ticket);
    showMessage(!!data.ok, data.message || (data.ok ? 'Checked in.' : 'Failed.'));
  } finally {
    busy = false;
  }
}

async function postCheckInById(purchaseTicketId) {
  if (busy) return;
  busy = true;
  try {
    clearMessage();
    setTicket(null);

    const res = await fetch('/admin/checkin/ajax/by-id', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '')
      },
      body: JSON.stringify({ purchase_ticket_id: purchaseTicketId })
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data) {
      showMessage(false, data?.message || 'Check-in failed.');
      return;
    }

    if (data.ticket) setTicket(data.ticket);
    showMessage(!!data.ok, data.message || (data.ok ? 'Checked in.' : 'Failed.'));
    scrollMessageIntoView();
  } finally {
    busy = false;
  }
}

async function scanHitFeedback() {
  const now = Date.now();
  if ((now - lastToastAt) < 700) return;
  lastToastAt = now;

  try {
    if (navigator.vibrate) navigator.vibrate(20);
  } catch {
    // ignore
  }

  ensureToast();
  if (!toastEl) return;
  try {
    await customElements.whenDefined('wa-toast');
    const hasCreate = typeof toastEl.create === 'function';
    if (!hasCreate) {
      console.warn('[checkin] wa-toast missing .create(). Did the component load?');
      return;
    }
    await toastEl.create('Scan detected', { duration: 1100, icon: { name: 'qrcode', variant: 'solid' } });
  } catch {
    // fail silently
  }
}

async function refreshCameraDevices() {
  if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return [];
  try {
    const devices = await navigator.mediaDevices.enumerateDevices();
    cameraDeviceIds = (devices || [])
      .filter((d) => d && d.kind === 'videoinput')
      .map((d) => d.deviceId)
      .filter(Boolean);
  } catch {
    cameraDeviceIds = [];
  }

  const running = !!stream || !!zxingControls;
  switchBtn.disabled = !(running && cameraDeviceIds.length > 1);
  return cameraDeviceIds;
}

function detectCurrentDeviceIdFromVideo() {
  try {
    const obj = videoEl && videoEl.srcObject;
    if (!obj || typeof obj.getVideoTracks !== 'function') return null;
    const t = obj.getVideoTracks()[0];
    if (!t || typeof t.getSettings !== 'function') return null;
    const id = t.getSettings().deviceId;
    return id || null;
  } catch {
    return null;
  }
}

async function cycleCamera() {
  await refreshCameraDevices();
  if (cameraDeviceIds.length <= 1) {
    showMessage(false, 'No alternate camera found on this device.');
    return;
  }

  if (!currentDeviceId) {
    currentDeviceId = detectCurrentDeviceIdFromVideo();
  }

  const idx = currentDeviceId ? cameraDeviceIds.indexOf(currentDeviceId) : -1;
  const next = cameraDeviceIds[(idx >= 0 ? idx + 1 : 0) % cameraDeviceIds.length];
  currentDeviceId = next;

  await startCamera(next);
}

async function startCamera(deviceId = null) {
  stopCamera();

  if (!('mediaDevices' in navigator) || !navigator.mediaDevices.getUserMedia) {
    showMessage(false, 'Camera not supported in this browser.');
    return;
  }

  try {
    if ('BarcodeDetector' in window) {
      try {
        stream = await navigator.mediaDevices.getUserMedia({
          video: deviceId ? { deviceId: { exact: deviceId } } : { facingMode: { ideal: 'environment' } },
          audio: false
        });
      } catch {
        stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
      }

      videoEl.srcObject = stream;
      await videoEl.play();

      currentDeviceId = detectCurrentDeviceIdFromVideo() || deviceId || null;
      await refreshCameraDevices();

      startBtn.disabled = true;
      stopBtn.disabled = false;

      detector = new BarcodeDetector({ formats: ['qr_code'] });
      scanTimer = window.setInterval(scanFrame, 250);
    } else {
      startBtn.disabled = true;
      stopBtn.disabled = false;
      await startZxing(deviceId);
      currentDeviceId = detectCurrentDeviceIdFromVideo() || deviceId || null;
      await refreshCameraDevices();
    }
  } catch (err) {
    console.error('Scanner init failed', err);
    showMessage(false, friendlyCameraError(err));
  }
}

function stopCamera() {
  if (scanTimer) {
    window.clearInterval(scanTimer);
    scanTimer = null;
  }
  if (zxingControls) {
    try { zxingControls.stop(); } catch { /* ignore */ }
    zxingControls = null;
  }
  if (videoEl) {
    videoEl.pause();
    videoEl.srcObject = null;
  }
  if (stream) {
    for (const t of stream.getTracks()) t.stop();
    stream = null;
  }

  startBtn.disabled = false;
  stopBtn.disabled = true;
  switchBtn.disabled = true;
}

async function scanFrame() {
  if (!detector || !videoEl || videoEl.readyState < 2) return;
  if (busy) return;

  try {
    const codes = await detector.detect(videoEl);
    if (!codes || codes.length === 0) return;

    const raw = String(codes[0].rawValue || '').trim();
    if (!raw) return;
    const now = Date.now();
    if (raw === lastToken && (now - lastScanAt) < 3000) return;

    lastToken = raw;
    lastScanAt = now;
    const token = extractToken(raw);
    if (wasTokenSeenRecently(token)) return;
    tokenEl.value = token;
    scanHitFeedback();
    await postCheckIn(token);
  } catch {
    // ignore
  }
}

function loadScript(src) {
  return new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = src;
    s.async = true;
    s.onload = () => resolve();
    s.onerror = () => reject(new Error(`Failed to load ${src}`));
    document.head.appendChild(s);
  });
}

async function startZxing(deviceId = null) {
  if (!window.ZXingBrowser) {
    await loadScript('/assets/vendor/zxing/zxing-browser.min.js');
  }
  const ZX = window.ZXingBrowser;
  if (!ZX || !ZX.BrowserQRCodeReader) {
    showMessage(false, 'QR scanning is not supported in this browser. Use manual entry below.');
    return;
  }

  const reader = new ZX.BrowserQRCodeReader();
  zxingControls = await reader.decodeFromVideoDevice(deviceId || null, videoEl, (result, err, controls) => {
    if (controls) zxingControls = controls;
    if (!result || busy) return;
    const raw = String(result.getText ? result.getText() : (result.text || '')).trim();
    if (!raw) return;
    const now = Date.now();
    if (raw === lastToken && (now - lastScanAt) < 3000) return;
    lastToken = raw;
    lastScanAt = now;
    const token = extractToken(raw);
    if (wasTokenSeenRecently(token)) return;
    tokenEl.value = token;
    scanHitFeedback();
    postCheckIn(token);
  });
}

function extractToken(raw) {
  try {
    const u = new URL(raw);
    const qp = u.searchParams.get('token') || u.searchParams.get('qr_token');
    if (qp) return qp;
    const parts = u.pathname.split('/').filter(Boolean);
    return parts.length ? parts[parts.length - 1] : raw;
  } catch {
    return raw;
  }
}

function setLookupStatus(text) {
  if (!lookupStatusEl) return;
  lookupStatusEl.textContent = text || '';
}

function renderLookupTickets(tickets) {
  if (!lookupResultsEl) return;
  if (!tickets || tickets.length === 0) {
    lookupResultsEl.innerHTML = '<wa-callout variant="neutral">No tickets found.</wa-callout>';
    return;
  }

  lookupResultsEl.innerHTML = tickets
    .map((t) => {
      const id = Number(t?.id || 0) || 0;
      const email = String(t?.purchase_email || '').trim();
      const eventName = String(t?.event_name || '').trim();
      const variation = String(t?.variation_name || '').trim();
      const checkedIn = t?.checked_in_at ? String(t.checked_in_at) : '';
      const refunded = t?.refunded_at ? String(t.refunded_at) : '';

      const mods = Array.isArray(t?.modifiers) ? t.modifiers : [];
      const modLines = mods
        .map((m) => {
          const name = String(m?.modifier_name || m?.name || '').trim();
          if (!name) return '';
          let val = m?.value === null || m?.value === undefined ? '' : String(m.value).trim();
          if (val.startsWith('[')) {
            try {
              const parsed = JSON.parse(val);
              if (Array.isArray(parsed)) {
                val = parsed.map((v) => String(v).trim()).filter(Boolean).join(', ');
              }
            } catch {
              // ignore
            }
          }
          if (!val || val === '1') return `<div class="checkin-mod"><span class="checkin-mod-name">${escapeHtml(name)}</span></div>`;
          return `<div class="checkin-mod"><span class="checkin-mod-name">${escapeHtml(name)}</span><span class="checkin-mod-val">${escapeHtml(val)}</span></div>`;
        })
        .filter(Boolean)
        .join('');

      const statusBits = [
        refunded ? '<wa-badge variant="danger">Refunded</wa-badge>' : '',
        checkedIn ? '<wa-badge variant="success">Checked In</wa-badge>' : ''
      ].filter(Boolean).join(' ');

      return `
        <wa-card class="checkin-lookup-card">
          <div class="wa-split" style="gap: 8px; align-items: start;">
            <div class="wa-stack wa-gap-2xs">
              <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <strong>${escapeHtml(email || '(no email)')}</strong>
                ${statusBits}
              </div>
              <div class="wa-caption-s">${escapeHtml(eventName)}${variation ? ' — ' + escapeHtml(variation) : ''}</div>
              ${modLines ? `<div class="checkin-mods" style="margin-top: 4px;">${modLines}</div>` : ''}
            </div>
            <div style="display:flex; gap:8px;">
              <wa-button variant="brand" size="s" data-ticket-id="${id}" ${checkedIn || refunded ? 'disabled' : ''}>Check In</wa-button>
            </div>
          </div>
        </wa-card>
      `;
    })
    .join('');

  lookupResultsEl.querySelectorAll('wa-button[data-ticket-id]').forEach((btn) => {
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      const tid = Number(btn.getAttribute('data-ticket-id') || 0) || 0;
      if (!tid) return;
      const ok = window.confirm('Check this ticket in? This cannot be undone.');
      if (!ok) return;
      await postCheckInById(tid);
      await fetchLookup();
    });
  });
}

async function fetchLookup() {
  if (!lookupResultsEl || !lookupQEl) return;
  const q = String(lookupQEl.value || '').trim();
  setLookupStatus('Loading…');

  try {
    const url = new URL('/admin/checkin/lookup/ajax', window.location.origin);
    if (q) url.searchParams.set('q', q);
    const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
    const data = await res.json().catch(() => ({}));

    if (!res.ok || !data?.ok) {
      setLookupStatus('Failed to load.');
      lookupResultsEl.innerHTML = '<wa-callout variant="danger">Lookup failed.</wa-callout>';
      return;
    }

    const tickets = Array.isArray(data.tickets) ? data.tickets : [];
    setLookupStatus(tickets.length ? `Showing ${tickets.length} ticket(s).` : 'No results.');
    renderLookupTickets(tickets);
  } catch (err) {
    console.error('Lookup failed', err);
    setLookupStatus('Failed to load.');
    lookupResultsEl.innerHTML = '<wa-callout variant="danger">Lookup failed.</wa-callout>';
  }
}

const debouncedLookup = debounce(fetchLookup, 250);

if (lookupFormEl) {
  lookupFormEl.addEventListener('submit', (e) => {
    e.preventDefault();
    fetchLookup();
  });
}

if (lookupQEl) {
  // wa-input/wa-change are emitted by Web Awesome inputs; 'input' may not fire reliably on the custom element.
  lookupQEl.addEventListener('wa-input', () => debouncedLookup());
  lookupQEl.addEventListener('wa-change', () => debouncedLookup());
}

if (tabsEl) {
  tabsEl.addEventListener('wa-tab-show', (e) => {
    const detail = e?.detail || {};
    const name = detail.name || detail.panel;
    if (name === 'lookup') {
      fetchLookup();
    }
  });
}

startBtn.addEventListener('click', async (e) => {
  e.preventDefault();
  await startCamera();
});

stopBtn.addEventListener('click', (e) => {
  e.preventDefault();
  stopCamera();
});

switchBtn.addEventListener('click', async (e) => {
  e.preventDefault();
  try {
    await cycleCamera();
  } catch (err) {
    console.error('Camera switch failed', err);
    showMessage(false, err?.message || 'Failed to switch camera.');
  }
});

formEl.addEventListener('submit', async (e) => {
  e.preventDefault();
  const token = String(tokenEl.value || '').trim();
  if (!token) {
    showMessage(false, 'Enter a QR token.');
    return;
  }
  await postCheckIn(token);
});

window.addEventListener('beforeunload', () => stopCamera());
