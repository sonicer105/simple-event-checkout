function $(id) {
  const el = document.getElementById(id);
  if (!el) throw new Error(`Missing element #${id}`);
  return el;
}

const videoEl = $('checkin-video');
const startBtn = $('checkin-start');
const stopBtn = $('checkin-stop');
const formEl = $('checkin-form');
const tokenEl = $('qr_token');
const msgEl = $('checkin-message');
const ticketEl = $('checkin-ticket');
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
let scanTimer = null;
let lastToken = '';
let lastScanAt = 0;
let busy = false;
let zxingControls = null;
let lastToastAt = 0;
const RECENT_TOKEN_MS = 8000;
const recentTokenTimes = new Map();

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
      const val = rawVal === null || rawVal === undefined ? '' : String(rawVal).trim();

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

      // Text modifiers should show their value; if empty, still show nothing extra.
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
    // Leave debounce state intact so we don't re-fire repeatedly while the camera is still pointed at the same QR.
    // If you need to re-scan the same QR (e.g. after a DB/manual reset), just wait a few seconds.
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
    // If toast isn't available for some reason, fail silently.
  }
}

async function startCamera() {
  // In case the operator double-clicks or restarts quickly, ensure we release resources first.
  stopCamera();

  if (!('mediaDevices' in navigator) || !navigator.mediaDevices.getUserMedia) {
    showMessage(false, 'Camera not supported in this browser.');
    return;
  }

  try {
    if ('BarcodeDetector' in window) {
      // BarcodeDetector path uses our own MediaStream.
      try {
        stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: 'environment' } },
          audio: false
        });
      } catch {
        stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
      }

      videoEl.srcObject = stream;
      await videoEl.play();

      startBtn.disabled = true;
      stopBtn.disabled = false;

      detector = new BarcodeDetector({ formats: ['qr_code'] });
      scanTimer = window.setInterval(scanFrame, 250);
    } else {
      // Firefox doesn't currently support BarcodeDetector; let ZXing manage the camera stream.
      startBtn.disabled = true;
      stopBtn.disabled = false;
      await startZxing();
    }
  } catch (err) {
    console.error('Scanner init failed', err);
    showMessage(false, err?.message || 'Failed to initialize scanner.');
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
    // Common pattern: QR could encode a URL; take last path segment or query token if present.
    const token = extractToken(raw);
    if (wasTokenSeenRecently(token)) return;
    tokenEl.value = token;
    scanHitFeedback();
    await postCheckIn(token);
  } catch {
    // Ignore detection errors; keep scanning.
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

async function startZxing() {
  if (!window.ZXingBrowser) {
    await loadScript('/assets/vendor/zxing/zxing-browser.min.js');
  }
  const ZX = window.ZXingBrowser;
  if (!ZX || !ZX.BrowserQRCodeReader) {
    showMessage(false, 'QR scanning is not supported in this browser. Use manual entry below.');
    return;
  }

  const reader = new ZX.BrowserQRCodeReader();
  try {
    zxingControls = await reader.decodeFromVideoDevice(null, videoEl, (result, err, controls) => {
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
  } catch (err) {
    console.error('ZXing init failed', err);
    throw err;
  }
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

startBtn.addEventListener('click', async (e) => {
  e.preventDefault();
  try {
    await startCamera();
  } catch (err) {
    stopCamera();
    showMessage(false, err?.message || 'Failed to start camera.');
  }
});

stopBtn.addEventListener('click', (e) => {
  e.preventDefault();
  stopCamera();
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

// Clean up camera if you navigate away.
window.addEventListener('beforeunload', () => stopCamera());
