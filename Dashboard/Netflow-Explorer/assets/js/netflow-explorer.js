(function () {
  'use strict';

  const config = window.NetflowExplorerConfig || {};
  const rawSankeyData = Array.isArray(config.rawSankeyData) ? config.rawSankeyData : [];
  const sankeyLimit = Number(config.sankeyLimit || 12);
  const defaultAutoRefresh = String(config.autoRefresh || '0');
  const panel = document.getElementById('sankeyPanel');
  const btn = document.getElementById('toggleSankey');
  const chartEl = document.getElementById('sankeyChart');
  const shareBtn = document.getElementById('shareSankeyUrl');
  const infoBtn = document.getElementById('toggleSankeyInfo');
  const infoBox = document.getElementById('sankeyInfoBox');
  const refreshSelect = document.getElementById('autoRefreshSelect');
  const sankeyStateInput = document.getElementById('sankeyStateInput');
  const sankeyOpenDefault = Boolean(config.sankeyOpenDefault);
  const refreshCountdown = document.getElementById('autoRefreshCountdown');

  let rendered = false;
  let refreshTimer = null;
  let refreshTicker = null;
  let refreshDeadline = 0;
  let baseLinkColors = [];

  function currentUrl() {
    return new URL(window.location.href);
  }

  function currentAutoRefresh() {
    if (refreshSelect && refreshSelect.value) return refreshSelect.value;
    return defaultAutoRefresh;
  }

  function syncUrlState(updates) {
    const url = currentUrl();
    Object.keys(updates).forEach((key) => {
      let k = key;
      if (k === 'auto_refresh') k = 'ar';
      if (k === 'sankey') k = 'sk';
      url.searchParams.set(k, String(updates[key]));
    });
    window.history.replaceState({}, '', url.toString());
    return url;
  }

  function buildReloadUrl() {
    const url = currentUrl();
    url.searchParams.set('ar', currentAutoRefresh());
    if (sankeyStateInput) {
      url.searchParams.set('sk', sankeyStateInput.value === '1' ? '1' : '0');
    }
    return url.toString();
  }

  function autoRefreshMs() {
    const map = { '1m': 60000, '5m': 300000, '10m': 600000 };
    return map[currentAutoRefresh()] || 0;
  }

  function clearRefreshCountdown() {
    refreshDeadline = 0;

    if (refreshTicker) {
      window.clearInterval(refreshTicker);
      refreshTicker = null;
    }

    if (refreshCountdown) {
      refreshCountdown.hidden = true;
      refreshCountdown.textContent = '';
      refreshCountdown.removeAttribute('title');
    }
  }

  function formatCountdown(ms) {
    const totalSeconds = Math.max(0, Math.ceil(ms / 1000));
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    if (hours > 0) {
      return [hours, String(minutes).padStart(2, '0'), String(seconds).padStart(2, '0')].join(':');
    }

    return String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
  }

  function paintRefreshCountdown() {
    if (!refreshCountdown || refreshDeadline <= 0) return;

    const remaining = refreshDeadline - Date.now();
    if (remaining <= 0) {
      refreshCountdown.hidden = false;
      refreshCountdown.textContent = 'Reloading...';
      return;
    }

    refreshCountdown.hidden = false;
    refreshCountdown.textContent = 'Reload in ' + formatCountdown(remaining);
    refreshCountdown.title = 'Next reload at ' + new Date(refreshDeadline).toLocaleTimeString();
  }

  function startRefreshCountdown(ms) {
    clearRefreshCountdown();
    if (ms <= 0) return;

    refreshDeadline = Date.now() + ms;
    paintRefreshCountdown();
    refreshTicker = window.setInterval(paintRefreshCountdown, 1000);
  }

  function scheduleRefresh() {
    if (refreshTimer) {
      window.clearTimeout(refreshTimer);
      refreshTimer = null;
    }

    clearRefreshCountdown();

    const ms = autoRefreshMs();
    if (ms <= 0) return;

    startRefreshCountdown(ms);

    refreshTimer = window.setTimeout(function () {
      if (refreshCountdown) {
        refreshCountdown.hidden = false;
        refreshCountdown.textContent = 'Reloading...';
      }
      window.location.assign(buildReloadUrl());
    }, ms);
  }

  function buildSankeyRows(rows, maxLinks) {
    if (!Array.isArray(rows)) return [];

    return rows
      .filter(function (row) {
        return Array.isArray(row.nodes) && row.nodes.length >= 2 && Number(row.value || 0) > 0;
      })
      .sort(function (a, b) {
        return Number(b.value || 0) - Number(a.value || 0);
      })
      .slice(0, maxLinks);
  }

  function spacedY(count) {
    if (count <= 1) return [0.5];

    const out = [];
    const start = 0.06;
    const end = 0.94;
    const step = (end - start) / (count - 1);

    for (let i = 0; i < count; i += 1) {
      out.push(start + (step * i));
    }

    return out;
  }

  function renderSankey() {
    if (rendered || !chartEl) return;

    const sankeyData = buildSankeyRows(rawSankeyData, sankeyLimit);
    if (!sankeyData.length) {
      chartEl.innerHTML = '<div class="notice">No conversation data to visualize.</div>';
      rendered = true;
      return;
    }

    if (typeof Plotly === 'undefined') {
      chartEl.innerHTML = '<div class="warn">Plotly.js not loaded. Save Plotly file locally if console has no internet access.</div>';
      rendered = true;
      return;
    }

    const layerCount = sankeyData.reduce(function (maxLayers, row) {
      return Math.max(maxLayers, Array.isArray(row.nodes) ? row.nodes.length : 0);
    }, 2);

    const layerTotals = Array.from({ length: layerCount }, function () {
      return new Map();
    });

    sankeyData.forEach(function (row) {
      row.nodes.forEach(function (label, idx) {
        const layerMap = layerTotals[idx];
        layerMap.set(label, (layerMap.get(label) || 0) + Number(row.value || 0));
      });
    });

    const labels = [];
    const colors = [];
    const baseNodeColors = [];
    const xs = [];
    const ys = [];
    const nodeIndex = new Map();
    const layerColors = layerCount <= 2
      ? ['#4F81BD', '#F28E2B']
      : ['#4F81BD', '#F28E2B', '#10B981', '#8B5CF6'];

    function addNode(layer, label, x, y) {
      const key = layer + ':' + label;
      const idx = labels.length;
      const color = layerColors[layer] || '#94A3B8';

      nodeIndex.set(key, idx);
      labels.push(label);
      colors.push(color);
      baseNodeColors.push(color);
      xs.push(x);
      ys.push(y);
      return idx;
    }

    layerTotals.forEach(function (layerMap, layerIdx) {
      const nodes = Array.from(layerMap.entries()).sort(function (a, b) {
        return b[1] - a[1];
      });
      const yPoints = spacedY(nodes.length);
      const x = layerCount === 1 ? 0.5 : (0.03 + (0.92 * (layerIdx / Math.max(1, layerCount - 1))));

      nodes.forEach(function (entry, idx) {
        addNode(layerIdx, entry[0], x, yPoints[idx]);
      });
    });

    const source = [];
    const target = [];
    const value = [];
    const linkLabels = [];
    const linkColors = [];
    const linkPathIds = [];
    const nodeToLinks = new Map();

    sankeyData.forEach(function (row) {
      const nodes = row.nodes || [];
      const pathId = nodes.join(' -> ');

      for (let i = 0; i < nodes.length - 1; i += 1) {
        const s = nodeIndex.get(i + ':' + nodes[i]);
        const t = nodeIndex.get((i + 1) + ':' + nodes[i + 1]);
        if (s === undefined || t === undefined) continue;

        const linkIdx = source.length;
        source.push(s);
        target.push(t);
        value.push(Number(row.value || 0));
        linkLabels.push(
          nodes[i] + ' -> ' + nodes[i + 1] +
          '<br>Total path: ' + pathId +
          '<br>Bytes: ' + Number(row.value || 0).toLocaleString() +
          '<br>Packets: ' + Number(row.packets || 0).toLocaleString() +
          '<br>Flows: ' + Number(row.flows || 0).toLocaleString()
        );
        linkColors.push('rgba(148, 163, 184, 0.26)');
        linkPathIds.push(pathId);

        if (!nodeToLinks.has(s)) nodeToLinks.set(s, []);
        if (!nodeToLinks.has(t)) nodeToLinks.set(t, []);
        nodeToLinks.get(s).push(linkIdx);
        nodeToLinks.get(t).push(linkIdx);
      }
    });

    baseLinkColors = linkColors.slice();

    Plotly.newPlot(chartEl, [{
      type: 'sankey',
      arrangement: 'fixed',
      node: {
        pad: 24,
        thickness: 12,
        line: { color: 'rgba(100,116,139,0.35)', width: 1 },
        label: labels,
        color: colors,
        x: xs,
        y: ys,
        hovertemplate: '%{label}<extra></extra>'
      },
      link: {
        source: source,
        target: target,
        value: value,
        color: linkColors,
        customdata: linkLabels,
        hovertemplate: '%{customdata}<extra></extra>'
      }
    }], {
      margin: { l: 20, r: 26, t: 28, b: 28 },
      paper_bgcolor: 'white',
      plot_bgcolor: 'white',
      hoverlabel: { bgcolor: '#111827', bordercolor: '#111827', font: { color: '#ffffff', size: 12 } },
      font: { family: 'Inter, sans-serif', size: 12, color: '#111827' }
    }, {
      displayModeBar: false,
      responsive: true
    });

    function isLinkHoverPoint(pt) {
      if (!pt || typeof pt !== 'object') return false;
      return Object.prototype.hasOwnProperty.call(pt, 'source') &&
        Object.prototype.hasOwnProperty.call(pt, 'target');
    }

    function isNodeHoverPoint(pt) {
      if (!pt || typeof pt !== 'object') return false;
      return typeof pt.pointNumber === 'number' && !isLinkHoverPoint(pt);
    }

    chartEl.on('plotly_hover', function (ev) {
      if (!ev || !ev.points || !ev.points.length) return;
      const pt = ev.points[0];

      if (isLinkHoverPoint(pt)) {
        const hoveredLink = Number(pt.pointNumber);
        const hoveredPath = linkPathIds[hoveredLink];
        if (hoveredLink < 0 || hoveredLink >= linkPathIds.length || !hoveredPath) return;

        const newLinkColors = baseLinkColors.map(function (_, idx) {
          return linkPathIds[idx] === hoveredPath ? 'rgba(59,130,246,0.78)' : 'rgba(148,163,184,0.10)';
        });
        const highlightNodes = new Set();

        source.forEach(function (sourceIdx, idx) {
          if (linkPathIds[idx] === hoveredPath) {
            highlightNodes.add(sourceIdx);
            highlightNodes.add(target[idx]);
          }
        });

        const newNodeColors = baseNodeColors.map(function (color, idx) {
          return highlightNodes.has(idx) ? color : 'rgba(203,213,225,0.55)';
        });

        Plotly.restyle(chartEl, { 'link.color': [newLinkColors], 'node.color': [newNodeColors] }, [0]);
        return;
      }

      if (isNodeHoverPoint(pt)) {
        const nodeIdx = Number(pt.pointNumber);
        const related = new Set(nodeToLinks.get(nodeIdx) || []);
        const highlightNodes = new Set([nodeIdx]);

        related.forEach(function (linkIdx) {
          highlightNodes.add(source[linkIdx]);
          highlightNodes.add(target[linkIdx]);
        });

        const newLinkColors = baseLinkColors.map(function (_, idx) {
          return related.has(idx) ? 'rgba(59,130,246,0.72)' : 'rgba(148,163,184,0.10)';
        });
        const newNodeColors = baseNodeColors.map(function (color, idx) {
          return highlightNodes.has(idx) ? color : 'rgba(203,213,225,0.55)';
        });

        Plotly.restyle(chartEl, { 'link.color': [newLinkColors], 'node.color': [newNodeColors] }, [0]);
      }
    });

    chartEl.on('plotly_unhover', function () {
      if (!baseLinkColors.length) return;
      Plotly.restyle(chartEl, { 'link.color': [baseLinkColors], 'node.color': [baseNodeColors] }, [0]);
    });

    rendered = true;
  }

  function setSankeyState(open) {
    if (sankeyStateInput) {
      sankeyStateInput.value = open ? '1' : '0';
    }
    syncUrlState({ sankey: open ? '1' : '0' });
  }

  async function copyShareUrl() {
    const url = new URL(window.location.origin + window.location.pathname);
    url.searchParams.set('sk', panel && panel.classList.contains('open') ? '1' : '0');
    url.searchParams.set('ar', currentAutoRefresh());
    const text = url.toString();

    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
      }

      const ok = document.getElementById('shareSankeyOk');
      if (ok) {
        ok.style.display = 'inline';
        window.setTimeout(function () {
          ok.style.display = 'none';
        }, 1600);
      }
    } catch (err) {
      window.prompt('Copy this URL:', text);
    }
  }

  if (refreshSelect) {
    refreshSelect.addEventListener('change', function () {
      syncUrlState({ auto_refresh: currentAutoRefresh() });
      scheduleRefresh();
    });
  }

  if (shareBtn) {
    shareBtn.addEventListener('click', copyShareUrl);
  }

  if (infoBtn && infoBox) {
    infoBtn.addEventListener('click', function () {
      infoBox.classList.toggle('open');
    });
  }

  if (btn && panel) {
    btn.addEventListener('click', function () {
      panel.classList.toggle('open');
      setSankeyState(panel.classList.contains('open'));
      if (panel.classList.contains('open')) {
        renderSankey();
        if (typeof Plotly !== 'undefined') {
          window.setTimeout(function () {
            Plotly.Plots.resize(chartEl);
          }, 50);
        }
      }
    });
  }

  scheduleRefresh();

  if (panel && sankeyOpenDefault) {
    panel.classList.add('open');
    setSankeyState(true);
    renderSankey();
    if (typeof Plotly !== 'undefined') {
      window.setTimeout(function () {
        Plotly.Plots.resize(chartEl);
      }, 50);
    }
  }
}());
