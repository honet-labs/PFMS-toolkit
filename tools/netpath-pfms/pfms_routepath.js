/* Pandora FMS NetPath (Custom) - JS
 * - Toast + copy-to-clipboard helper
 * - Modal open/close helpers (Add Segment)
 * - Auto refresh selector + countdown + JS reload
 * - SVG pan/zoom (wheel + drag + optional buttons)
 * - Node/edge selection panel + module link
 * - Double-click node to open module page (if data-url exists)
 */

(function () {
  'use strict';

  // ---------- Toast ----------
  const toastEl = document.getElementById('toast');
  window._toast = (msg) => {
    if (!toastEl) return;
    toastEl.textContent = String(msg ?? '');
    toastEl.classList.add('show');
    clearTimeout(window.__toastT);
    window.__toastT = setTimeout(() => toastEl.classList.remove('show'), 1400);
  };

  // ---------- Copy helper ----------
  window.copyUrl = (btn) => {
    try {
      const url = btn?.getAttribute?.('data-copy') || '';
      if (!url) return;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(() => {
          window._toast && window._toast('Copied!');
        }).catch(() => prompt('Copy URL:', url));
      } else {
        prompt('Copy URL:', url);
      }
    } catch (_) {
      try { prompt('Copy URL:', btn?.getAttribute?.('data-copy') || ''); } catch (_) {}
    }
  };

  // ---------- Modal helpers ----------
  window.openAddSeg = () => {
    const m = document.getElementById('addSegModal');
    if (m) m.style.display = 'flex';
  };
  window.closeAddSeg = () => {
    const m = document.getElementById('addSegModal');
    if (m) m.style.display = 'none';
  };
  window.addEventListener('click', (e) => {
    const m = document.getElementById('addSegModal');
    if (!m || m.style.display !== 'flex') return;
    if (e.target === m) window.closeAddSeg();
  });

  // ---------- Auto refresh (selector + countdown) ----------
  const refreshSel = document.getElementById('refreshSel');
  const countdownEl = document.getElementById('refreshCountdown');

  const readRefreshSeconds = () => {
    const v = document.body?.dataset?.refresh;
    const n = v ? parseInt(v, 10) : 0;
    return Number.isFinite(n) ? n : 0;
  };

  const fmtCountdown = (sec) => {
    if (!Number.isFinite(sec) || sec <= 0) return 'OFF';
    if (sec < 60) return `${sec}s`;
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
  };

  const reloadWithBust = () => {
    const url = new URL(window.location.href);
    url.searchParams.set('_v', Date.now().toString());
    window.location.replace(url.toString());
  };

  // Dropdown change -> reload with new refresh param
  if (refreshSel) {
    refreshSel.addEventListener('change', () => {
      const url = new URL(window.location.href);
      let v = refreshSel.value;

      if (v === 'custom') {
        const cur = readRefreshSeconds() > 0 ? readRefreshSeconds() : 15;
        const ans = prompt('Custom auto refresh (seconds). 0 = disable:', String(cur));
        if (ans === null) return;
        const n = parseInt(ans, 10);
        if (!Number.isFinite(n) || n < 0) return;
        v = String(Math.min(Math.max(n, 0), 3600));
      } else {
        const n = parseInt(v, 10);
        v = String(Number.isFinite(n) ? n : 0);
      }

      url.searchParams.set('refresh', v);
      url.searchParams.delete('refresh_s');
      url.searchParams.set('_v', Date.now().toString());
      window.location.href = url.toString();
    });
  }

  // Countdown + reload
  (() => {
    const refreshSec = readRefreshSeconds();
    if (!countdownEl) return;
    if (!refreshSec || refreshSec <= 0) {
      countdownEl.textContent = 'OFF';
      return;
    }
    let remain = refreshSec;
    countdownEl.textContent = fmtCountdown(remain);

    let lastTick = Date.now();
    window.setInterval(() => {
      const now = Date.now();
      const diff = Math.floor((now - lastTick) / 1000);
      if (diff <= 0) return;
      lastTick += diff * 1000;
      remain -= diff;
      if (remain <= 0) {
        countdownEl.textContent = '0s';
        reloadWithBust();
        return;
      }
      countdownEl.textContent = fmtCountdown(remain);
    }, 250);
  })();

  // ---------- SVG Pan/Zoom + Selection ----------
  const svg = document.getElementById('netSvg');
  const viewport = document.getElementById('viewport');
  if (!svg || !viewport) return;

  const get = (id) => document.getElementById(id);
  const panel = {
    title: get('pTitle'),
    sub: get('pSub'),
    type: get('pType'),
    status: get('pStatus'),
    dot: get('pDot'),
    moduleLink: get('pModuleLink'),
    ms: get('pMs'),
    min: get('pMin'),
    max: get('pMax'),
    info: get('pInfo'),
  };
  const hasPanel = !!(panel.title && panel.type && panel.status && panel.dot);

  const rootStyle = getComputedStyle(document.documentElement);
  const cssVar = (name, fallback) => (rootStyle.getPropertyValue(name).trim() || fallback);
  const statusToText = (s) => (String(s || 'na')).toUpperCase();
  const statusToColor = (s) => {
    switch (s) {
      case 'ok': return cssVar('--ok', '#22c55e');
      case 'warn': return cssVar('--warn', '#f59e0b');
      case 'crit': return cssVar('--crit', '#ef4444');
      default: return cssVar('--na', '#94a3b8');
    }
  };

  const clearActive = () => {
    svg.querySelectorAll('.node.active').forEach(n => n.classList.remove('active'));
    svg.querySelectorAll('.edge.active').forEach(e => e.classList.remove('active'));
  };

  const selectNode = (g) => {
    if (!g) return;
    clearActive();
    g.classList.add('active');

    if (!hasPanel) return;
    const st = g.dataset.status || 'na';
    panel.title.textContent = g.dataset.title || 'Node';
    if (panel.sub) panel.sub.textContent = g.dataset.sub || '';
    panel.type.textContent = 'Node';
    panel.status.textContent = statusToText(st);
    panel.dot.style.background = statusToColor(st);

    if (panel.moduleLink) {
      const moduleName = g.dataset.module || '-';
      const moduleId = g.dataset.mid || '';
      const url = g.dataset.url || '';
      panel.moduleLink.textContent = moduleId ? `${moduleName} (#${moduleId})` : moduleName;
      panel.moduleLink.href = url || '#';
      panel.moduleLink.style.pointerEvents = url ? 'auto' : 'none';
      panel.moduleLink.style.opacity = url ? '1' : '.6';
    }

    if (panel.ms) panel.ms.textContent = g.dataset.ms || '-';
    if (panel.min) panel.min.textContent = g.dataset.min || '-';
    if (panel.max) panel.max.textContent = g.dataset.max || '-';
    if (panel.info) panel.info.textContent = 'Double click node untuk buka module.';
  };

  const selectEdge = (edgeG) => {
    if (!edgeG) return;
    clearActive();
    edgeG.classList.add('active');

    if (!hasPanel) return;
    const st = edgeG.dataset.status || 'na';
    const from = edgeG.dataset.from || '';
    const to = edgeG.dataset.to || '';
    panel.title.textContent = 'Edge';
    if (panel.sub) panel.sub.textContent = `${from} → ${to}`;
    panel.type.textContent = 'Edge';
    panel.status.textContent = statusToText(st);
    panel.dot.style.background = statusToColor(st);

    if (panel.moduleLink) {
      panel.moduleLink.textContent = `${from} → ${to}`;
      panel.moduleLink.href = '#';
      panel.moduleLink.style.pointerEvents = 'none';
      panel.moduleLink.style.opacity = '.6';
    }

    if (panel.ms) panel.ms.textContent = edgeG.dataset.label || '-';
    if (panel.min) panel.min.textContent = '-';
    if (panel.max) panel.max.textContent = '-';
    if (panel.info) panel.info.textContent = 'Label edge mengikuti latency node tujuan (child).';
  };

  svg.addEventListener('click', (e) => {
    const node = e.target?.closest?.('.node');
    if (node) { selectNode(node); return; }
    const edge = e.target?.closest?.('.edge');
    if (edge) { selectEdge(edge); return; }
  });

  // Preselect first node
  const firstNode = svg.querySelector('.node');
  if (firstNode) selectNode(firstNode);

  // ---------- Pan/Zoom mechanics ----------
  let scale = 1;
  let tx = 0, ty = 0;

  const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
  const apply = () => viewport.setAttribute('transform', `translate(${tx},${ty}) scale(${scale})`);

  // wheel zoom
  svg.addEventListener('wheel', (evt) => {
    evt.preventDefault();
    const delta = evt.deltaY > 0 ? -0.08 : 0.08;
    scale = clamp(scale + delta, 0.45, 3.0);
    apply();
  }, { passive: false });

  // optional zoom buttons (full view)
  const zoomInBtn = get('zoomIn');
  const zoomOutBtn = get('zoomOut');
  const resetBtn = get('reset');
  const zoomBy = (delta) => { scale = clamp(scale + delta, 0.45, 3.0); apply(); };

  if (zoomInBtn) zoomInBtn.addEventListener('click', () => zoomBy(0.15));
  if (zoomOutBtn) zoomOutBtn.addEventListener('click', () => zoomBy(-0.15));
  if (resetBtn) resetBtn.addEventListener('click', () => { scale = 1; tx = 0; ty = 0; apply(); });

  // dblclick: open module if on node with url; otherwise reset view
  svg.addEventListener('dblclick', (evt) => {
    const node = evt.target?.closest?.('.node');
    if (node) {
      const url = node.dataset.url || '';
      if (url) {
        window.open(url, '_blank', 'noopener');
        evt.preventDefault();
        evt.stopPropagation();
        return;
      }
    }
    scale = 1; tx = 0; ty = 0; apply();
  });

  // drag to pan (avoid when clicking node/edge)
  let dragging = false;
  let lastX = 0, lastY = 0;
  const point = (evt) => ({ x: evt.clientX, y: evt.clientY });

  svg.addEventListener('mousedown', (evt) => {
    if (evt.target?.closest?.('.node, .edge')) return;
    dragging = true;
    const p = point(evt);
    lastX = p.x; lastY = p.y;
  });

  window.addEventListener('mousemove', (evt) => {
    if (!dragging) return;
    const p = point(evt);
    tx += (p.x - lastX);
    ty += (p.y - lastY);
    lastX = p.x; lastY = p.y;
    apply();
  });

  window.addEventListener('mouseup', () => { dragging = false; });

})();
