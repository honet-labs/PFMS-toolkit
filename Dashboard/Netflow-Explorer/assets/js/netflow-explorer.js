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

    function bytesFmtJs(b) {
      if (b === 0) return '0 B';
      const units = ['B','KB','MB','GB','TB'];
      let i = 0;
      let val = Number(b);
      while (val >= 1024 && i < units.length - 1) { val /= 1024; i++; }
      if (i === 0) return Math.round(val) + ' ' + units[i];
      return val.toFixed(2) + ' ' + units[i];
    }

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
        let labelText = nodes[i] + ' -> ' + nodes[i + 1] +
          '<br>Total path: ' + pathId +
          '<br>Bytes: ' + bytesFmtJs(row.value || 0) +
          '<br>Packets: ' + Number(row.packets || 0).toLocaleString() +
          '<br>Flows: ' + Number(row.flows || 0).toLocaleString();

        if (row.details && Object.keys(row.details).length > 0) {
          labelText += '<br><br><b>Breakdown:</b>';
          const sortedDetails = Object.keys(row.details).map(function (k) {
            return {
              path: k,
              value: Number(row.details[k].value || 0),
              packets: Number(row.details[k].packets || 0)
            };
          }).sort(function (a, b) { return b.value - a.value; });

          const showLimit = 6;
          const displayDetails = sortedDetails.slice(0, showLimit);
          displayDetails.forEach(function (d) {
            labelText += '<br>• ' + d.path + ': ' + bytesFmtJs(d.value);
          });
          if (sortedDetails.length > showLimit) {
            labelText += '<br>• ...and ' + (sortedDetails.length - showLimit) + ' more';
          }
        }

        linkLabels.push(labelText);
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
      arrangement: 'snap',
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
    let baseUrl = '';
    try {
      if (window.parent && window.parent.location && window.parent.location.pathname.indexOf('custom-index.php') !== -1) {
        baseUrl = window.parent.location.origin + window.parent.location.pathname;
      }
    } catch (e) {}

    if (!baseUrl) {
      const pathParts = window.location.pathname.split('/');
      if (pathParts.length >= 4) {
        pathParts.splice(-3);
      }
      baseUrl = window.location.origin + pathParts.join('/') + '/custom-index.php';
    }

    const url = new URL(baseUrl);
    url.searchParams.set('page', 'Dashboard/Netflow-Explorer/netflow-explorer.php');
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
        window.setTimeout(function () {
          renderSankey();
          if (typeof Plotly !== 'undefined') {
            Plotly.Plots.resize(chartEl);
          }
        }, 150);
      }
    });
  }

  scheduleRefresh();

  if (panel && sankeyOpenDefault) {
    panel.classList.add('open');
    setSankeyState(true);
    window.setTimeout(function () {
      renderSankey();
      if (typeof Plotly !== 'undefined') {
        Plotly.Plots.resize(chartEl);
      }
    }, 150);
  }

  function escapeHtml(str) {
    if (!str) return '';
    return str.toString()
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  let inMemoryWidgets = null;
  function getStoredWidgets() {
    try {
      const data = localStorage.getItem('nfx_dashboard_widgets');
      if (data) return JSON.parse(data);
    } catch (e) {
      console.warn("Storage read denied: ", e);
    }
    return inMemoryWidgets;
  }

  function setStoredWidgets(widgets) {
    inMemoryWidgets = widgets;
    try {
      localStorage.setItem('nfx_dashboard_widgets', JSON.stringify(widgets));
    } catch (e) {
      console.warn("Storage write denied: ", e);
    }
  }

  function loadWidgets() {
    const container = document.getElementById('dynamicWidgetsContainer');
    if (!container) return;
    
    let widgets = getStoredWidgets();
    
    if (!Array.isArray(widgets) || !widgets.length) {
      widgets = [
        {id: 'w_top_conv', title: 'Top Conversations (Src -> Dst)', agg: 'srcip,dstip', sort: 'bytes', limit: 10},
        {id: 'w_top_src', title: 'Top Source IP', agg: 'srcip', sort: 'bytes', limit: 10},
        {id: 'w_top_dst', title: 'Top Destination IP', agg: 'dstip', sort: 'bytes', limit: 10},
        {id: 'w_top_dport', title: 'Top Destination Port', agg: 'dstport', sort: 'bytes', limit: 10}
      ];
      setStoredWidgets(widgets);
    }
    
    container.innerHTML = '';
    
    widgets.forEach(function(w) {
      const col = document.createElement('div');
      col.className = 'col-lg-6 mb-4';
      col.id = 'widget_col_' + w.id;
      
      let html = '<div class="dashboard-card">';
      html += '  <div class="dashboard-card-header">';
      html += '    <h5 class="dashboard-card-title"><span class="material-symbols-outlined" style="font-size:18px!important; color:#004d40;">dashboard</span> ' + escapeHtml(w.title) + '</h5>';
      html += '    <div style="display:flex; gap:10px; align-items:center;">';
      html += '      <span style="background:#e0e4e8; padding:2px 8px; border-radius:4px; font-size:10px; font-weight: normal; text-transform: uppercase;">' + w.limit + ' ROWS</span>';
      html += '      <button type="button" class="btn-delete-widget" data-id="' + w.id + '" style="padding: 2px 6px; border: none; background: transparent; cursor: pointer; color: #e74c3c !important; display: inline-flex; align-items: center;" title="Delete Panel">';
      html += '        <span class="material-symbols-outlined" style="font-size:16px!important; color: #e74c3c !important;">delete</span>';
      html += '      </button>';
      html += '    </div>';
      html += '  </div>';
      html += '  <div class="dashboard-card-body" id="widget_body_' + w.id + '">';
      html += '    <div style="padding: 20px; text-align: center; color: #7f8c8d;"><span class="material-symbols-outlined" style="font-size:18px!important; display: inline-block; animation: spin 2s linear infinite; vertical-align: middle; margin-right: 5px;">sync</span> Loading...</div>';
      html += '  </div>';
      html += '</div>';
      
      col.innerHTML = html;
      container.appendChild(col);
      
      const btnDelete = col.querySelector('.btn-delete-widget');
      if (btnDelete) {
        btnDelete.addEventListener('click', function(e) {
          e.stopPropagation();
          e.preventDefault();
          if (confirm('Are you sure you want to delete this custom panel?')) {
            deleteWidget(w.id);
          }
        });
      }
      
      fetchWidgetData(w);
    });
  }

  function fetchWidgetData(w) {
    const bodyEl = document.getElementById('widget_body_' + w.id);
    if (!bodyEl) return;
    
    const queryParams = config.queryParams || {};
    const url = new URL(window.location.href);
    const pageParam = url.searchParams.get('page');
    
    const params = new URLSearchParams();
    if (pageParam) {
      params.set('page', pageParam);
    }
    params.set('api', 'query_widget');
    params.set('agg', w.agg);
    params.set('sort', w.sort);
    params.set('limit', w.limit);
    
    Object.keys(queryParams).forEach(function(k) {
      if (queryParams[k]) {
        params.set(k, queryParams[k]);
      }
    });
    
    fetch(url.pathname + '?' + params.toString())
      .then(function(res) { return res.json(); })
      .then(function(res) {
        if (!res.ok) {
          bodyEl.innerHTML = '<div style="padding: 20px; color: #ef4444;">Error: ' + escapeHtml(res.error || 'Failed to fetch data') + '</div>';
          return;
        }
        
        if (!res.rows || !res.rows.length) {
          bodyEl.innerHTML = '<table class="table-pfms"><thead><tr><th>No Data</th></tr></thead><tbody><tr><td class="text-center text-muted">No data available.</td></tr></tbody></table>';
          return;
        }
        
        let tableHtml = '<table class="table-pfms"><thead><tr>';
        res.headers.forEach(function(h) {
          tableHtml += '<th>' + escapeHtml(h) + '</th>';
        });
        tableHtml += '<th>Flows</th><th>Packets</th><th>Bytes</th><th>B/s</th></tr></thead><tbody>';
        
        res.rows.forEach(function(row) {
          tableHtml += '<tr>';
          res.fields.forEach(function(f) {
            const val = row[f] || '';
            const isIp = f === 'srcip' || f === 'dstip';
            tableHtml += '<td class="' + (isIp ? 'code-ip' : '') + '">' + escapeHtml(val) + '</td>';
          });
          tableHtml += '<td>' + Number(row.flw || 0).toLocaleString() + '</td>';
          tableHtml += '<td>' + Number(row.pkt || 0).toLocaleString() + '</td>';
          tableHtml += '<td class="code-mono">' + escapeHtml(row.byt_fmt || '0 B') + '</td>';
          tableHtml += '<td class="code-mono">' + escapeHtml(row.bps_fmt || '0 B/s') + '</td>';
          tableHtml += '</tr>';
        });
        
        tableHtml += '</tbody></table>';
        bodyEl.innerHTML = tableHtml;
      })
      .catch(function(err) {
        bodyEl.innerHTML = '<div style="padding: 20px; color: #ef4444;">Network error occurred.</div>';
      });
  }

  function deleteWidget(id) {
    let widgets = getStoredWidgets();
    if (Array.isArray(widgets)) {
      widgets = widgets.filter(function(w) { return w.id !== id; });
      setStoredWidgets(widgets);
      loadWidgets();
    }
  }

  const btnAddWidget = document.getElementById('btnAddWidget');
  const widgetModal = document.getElementById('widgetModal');
  const btnCloseWidgetModal = document.getElementById('btnCloseWidgetModal');
  const btnCancelWidget = document.getElementById('btnCancelWidget');
  const widgetForm = document.getElementById('widgetForm');
  
  if (btnAddWidget && widgetModal) {
    btnAddWidget.addEventListener('click', function() {
      widgetModal.style.display = 'flex';
      
      // Dynamic positioning to center in the visible viewport
      let scrollTop = 0;
      try {
        if (window.parent && window.parent.scrollY !== undefined) {
          let iframeOffset = 0;
          try {
            const iframes = window.parent.document.getElementsByTagName('iframe');
            for (let i = 0; i < iframes.length; i++) {
              if (iframes[i].contentWindow === window) {
                iframeOffset = iframes[i].getBoundingClientRect().top + window.parent.scrollY;
                break;
              }
            }
          } catch(e) {}
          scrollTop = Math.max(0, window.parent.scrollY - iframeOffset + 100);
        } else {
          scrollTop = window.scrollY + 100;
        }
      } catch (e) {
        scrollTop = window.scrollY + 100;
      }
      
      widgetModal.style.position = 'absolute';
      widgetModal.style.top = scrollTop + 'px';
      widgetModal.style.bottom = 'auto';
      
      document.getElementById('widgetTitle').value = '';
      document.getElementById('widgetTitle').focus();
    });
  }
  
  function closeModal() {
    if (widgetModal) widgetModal.style.display = 'none';
  }
  if (btnCloseWidgetModal) btnCloseWidgetModal.addEventListener('click', closeModal);
  if (btnCancelWidget) btnCancelWidget.addEventListener('click', closeModal);
  
  if (widgetForm) {
    widgetForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const title = document.getElementById('widgetTitle').value;
      const agg = document.getElementById('widgetAgg').value;
      const sort = document.getElementById('widgetSort').value;
      const limit = parseInt(document.getElementById('widgetLimit').value, 10) || 10;
      
      let widgets = getStoredWidgets();
      if (!Array.isArray(widgets)) widgets = [];
      
      const newWidget = {
        id: 'w_' + Date.now(),
        title: title,
        agg: agg,
        sort: sort,
        limit: limit
      };
      
      widgets.push(newWidget);
      setStoredWidgets(widgets);
      closeModal();
      loadWidgets();
    });
  }

  // Event delegation for widget deletion is now replaced by direct binding

  // Flows search and export CSV
  const flowsSearchInput = document.getElementById('flowsSearchInput');
  if (flowsSearchInput) {
    flowsSearchInput.addEventListener('keyup', function() {
      const query = this.value.toLowerCase();
      const rows = document.querySelectorAll('#flowsTable tbody tr');
      rows.forEach(function(row) {
        if (row.cells.length === 1 && row.cells[0].classList.contains('text-center')) {
          return;
        }
        let text = '';
        for (let i = 0; i < row.cells.length; i++) {
          text += row.cells[i].textContent.toLowerCase() + ' ';
        }
        if (text.indexOf(query) !== -1) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    });
  }

  const btnExportFlowsCsv = document.getElementById('btnExportFlowsCsv');
  if (btnExportFlowsCsv) {
    btnExportFlowsCsv.addEventListener('click', function() {
      const rows = document.querySelectorAll('#flowsTable tr');
      let csvContent = '';
      rows.forEach(function(row) {
        if (row.style.display === 'none') return;
        
        const cols = row.querySelectorAll('th, td');
        const rowData = [];
        cols.forEach(function(col) {
          let text = col.textContent.trim().replace(/"/g, '""');
          rowData.push('"' + text + '"');
        });
        csvContent += rowData.join(',') + '\r\n';
      });
      
      const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.setAttribute('href', url);
      link.setAttribute('download', 'flows_export.csv');
      link.style.visibility = 'hidden';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    });
  }

  loadWidgets();
}());
