// Variáveis globais
let voltageChart = null;
let lineChart = null;
let tapChart = null;
let lastSimResult = null;
let toastTimeout = null; // [CORREÇÃO] Variável para evitar o sumiço rápido dos avisos

// [CORREÇÃO] Proteção contra erro de escopo do 'event' no Firefox e Mobile
function showSection(name, btnElement = null) {
  document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
  
  const section = document.getElementById('sec-' + name);
  if (section) section.classList.add('active');

  if (btnElement) {
    btnElement.classList.add('active');
  } else if (typeof event !== 'undefined' && event.currentTarget) {
    event.currentTarget.classList.add('active');
  } else {
    const btn = document.querySelector(`.nav-btn[onclick*="${name}"]`);
    if (btn) btn.classList.add('active');
  }

  if (name === 'topology' && lastSimResult) renderTopology(lastSimResult);
}

// [CORREÇÃO] Verificação se o slider e a label existem antes de atualizar
function updateLabel(sliderId, labelId, scale, decimals) {
  const el = document.getElementById(sliderId);
  const labelEl = document.getElementById(labelId);
  if (!el || !labelEl) return;

  const v = el.value;
  let display;
  if (scale !== undefined) {
    display = (v * scale).toFixed(decimals !== undefined ? decimals : 2);
  } else {
    display = v;
  }
  labelEl.textContent = display;
}

// [CORREÇÃO] Valores padrão seguros (0) caso falte algum slider na tela
function getParams() {
  return {
    gd_kw: parseFloat(document.getElementById('sl-gd')?.value || 0),
    vm_pu: parseFloat(document.getElementById('sl-vm')?.value || 100) / 100,
    load_a_kw: parseFloat(document.getElementById('sl-la')?.value || 0),
    load_b_kw: parseFloat(document.getElementById('sl-lb')?.value || 0),
    load_c_kw: parseFloat(document.getElementById('sl-lc')?.value || 0),
  };
}

async function runSimulation() {
  showToast('Executando simulação...');
  try {
    const res = await fetch('/api/simulate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(getParams()),
    });
    const data = await res.json();
    if (data.error) { showToast('Erro: ' + data.error, 'danger'); return; }
    
    lastSimResult = data;
    updateKPIs(data);
    updateBusTable(data.buses);
    updateLineTable(data.lines);
    updateTrafoTable(data.trafos);
    updateVoltageChart(data.buses);
    updateLineChart(data.lines);
    updateAlerts(data);
    updateSidebarStatus(data);
    
    if (document.getElementById('sec-topology')?.classList.contains('active')) {
      renderTopology(data);
    }
    
    showToast('Simulação concluída!', 'success');
  } catch (e) {
    console.error(e);
    showToast('Falha na conexão', 'danger');
  }
}

function updateKPIs(data) {
  const elGen = document.getElementById('kpi-gen');
  const elLoad = document.getElementById('kpi-load');
  const elNet = document.getElementById('kpi-net');
  const elTap = document.getElementById('kpi-tap');

  if (elGen) elGen.textContent = data.total_generation_kw;
  if (elLoad) elLoad.textContent = data.total_load_kw;
  
  if (elNet) {
    const net = data.net_injection_kw;
    elNet.textContent = (net >= 0 ? '+' : '') + net;
    elNet.style.color = net > 0 ? '#c62828' : '#2e7d32';
  }
  
  if (elTap) {
    const tap = data.trafos && data.trafos.length ? data.trafos[0].tap_pos : '—';
    elTap.textContent = (tap !== '—' && tap >= 0 ? '+' : '') + tap;
  }
}

function updateSidebarStatus(data) {
  const badge = document.getElementById('net-status-badge');
  const revBadge = document.getElementById('reverse-badge');
  
  if (badge) {
    const hasViolation = data.buses.some(b => b.status !== 'normal');
    badge.className = 'status-badge ' + (hasViolation ? 'status-warning' : 'status-normal');
    badge.textContent = hasViolation ? 'Violação' : 'Normal';
  }
  if (revBadge) {
    revBadge.className = 'status-badge ' + (data.has_reverse_flow ? 'status-warning' : 'status-normal');
    revBadge.textContent = data.has_reverse_flow ? 'Detectado' : 'Ausente';
  }
}

// Funções de criação de células seguras
function makeCell(text) {
  const td = document.createElement('td');
  td.textContent = text;
  return td;
}

function makeBadgeCell(text, className) {
  const td = document.createElement('td');
  const span = document.createElement('span');
  span.className = 'badge ' + className;
  span.textContent = text;
  td.appendChild(span);
  return td;
}

function updateBusTable(buses) {
  const tbody = document.querySelector('#tbl-buses tbody');
  if (!tbody) return; // [CORREÇÃO] Proteção caso a tabela não exista
  tbody.replaceChildren(...buses.map(b => {
    const sClass = b.status === 'normal' ? 'badge-ok' : (b.status === 'sobretensão' ? 'badge-danger' : 'badge-warning');
    const tr = document.createElement('tr');
    tr.appendChild(makeCell(b.id));
    tr.appendChild(makeCell(b.name));
    const vmCell = document.createElement('td');
    const strong = document.createElement('strong');
    strong.textContent = b.vm_pu;
    vmCell.appendChild(strong);
    tr.appendChild(vmCell);
    tr.appendChild(makeCell(b.va_degree + '°'));
    tr.appendChild(makeBadgeCell(b.status.toUpperCase(), sClass));
    return tr;
  }));
}

function updateLineTable(lines) {
  const tbody = document.querySelector('#tbl-lines tbody');
  if (!tbody) return;
  tbody.replaceChildren(...lines.map(l => {
    const cls = l.reverse_flow ? 'badge-reverse' : 'badge-normal';
    const label = l.reverse_flow ? '← REVERSO' : '→ NORMAL';
    const loadCls = l.loading_percent > 80 ? 'badge-danger' : (l.loading_percent > 60 ? 'badge-warning' : 'badge-ok');
    const tr = document.createElement('tr');
    tr.appendChild(makeCell(l.id));
    tr.appendChild(makeCell('Bus ' + l.from_bus + ' → Bus ' + l.to_bus));
    tr.appendChild(makeCell(l.p_from_kw));
    tr.appendChild(makeCell(l.p_to_kw));
    tr.appendChild(makeBadgeCell(l.loading_percent + '%', loadCls));
    tr.appendChild(makeBadgeCell(label, cls));
    return tr;
  }));
}

function updateTrafoTable(trafos) {
  const tbody = document.querySelector('#tbl-trafos tbody');
  if (!tbody) return;
  tbody.replaceChildren(...trafos.map(t => {
    const cls = t.reverse_flow ? 'badge-reverse' : 'badge-normal';
    const label = t.reverse_flow ? '← REVERSO (GD→SE)' : '→ NORMAL (SE→GD)';
    const loadCls = t.loading_percent > 80 ? 'badge-danger' : (t.loading_percent > 60 ? 'badge-warning' : 'badge-ok');
    const tr = document.createElement('tr');
    tr.appendChild(makeCell(t.p_hv_kw));
    tr.appendChild(makeCell(t.p_lv_kw));
    tr.appendChild(makeBadgeCell(t.loading_percent + '%', loadCls));
    tr.appendChild(makeCell((t.tap_pos >= 0 ? '+' : '') + t.tap_pos));
    tr.appendChild(makeBadgeCell(label, cls));
    return tr;
  }));
}

function updateVoltageChart(buses) {
  const canvas = document.getElementById('chart-voltage');
  if (!canvas) return;

  const labels = buses.map(b => b.name.replace(' (MT)', '').replace(' (BT)', '').replace(' Trafo', ''));
  const values = buses.map(b => b.vm_pu);
  const colors = buses.map(b =>
    b.status === 'sobretensão' ? '#c62828' :
    b.status === 'subtensão' ? '#6a1b9a' : '#1565c0'
  );
  const ctx = canvas.getContext('2d');
  if (voltageChart) voltageChart.destroy();
  voltageChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'Tensão (p.u.)', data: values, backgroundColor: colors, borderRadius: 6 },
        { label: 'Limite Máx (1.05)', data: Array(buses.length).fill(1.05), type: 'line', borderColor: '#f57f17', borderDash: [5,4], borderWidth: 2, pointRadius: 0, fill: false },
        { label: 'Limite Mín (0.93)', data: Array(buses.length).fill(0.93), type: 'line', borderColor: '#c62828', borderDash: [5,4], borderWidth: 2, pointRadius: 0, fill: false },
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      scales: { y: { min: 0.85, max: 1.10, title: { display: true, text: 'Tensão (p.u.)' } } },
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } }
    }
  });
}

function updateLineChart(lines) {
  const canvas = document.getElementById('chart-lines');
  if (!canvas) return;

  const labels = lines.map(l => `L${l.id}: ${l.from_bus}→${l.to_bus}`);
  const values = lines.map(l => l.p_from_kw);
  const colors = lines.map(l => l.reverse_flow ? '#c62828' : '#2e7d32');
  const ctx = canvas.getContext('2d');
  if (lineChart) lineChart.destroy();
  lineChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{ label: 'Fluxo de Saída (kW)', data: values, backgroundColor: colors, borderRadius: 6 }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      indexAxis: 'y',
      scales: { x: { title: { display: true, text: 'kW (negativo = fluxo reverso)' } } },
      plugins: { legend: { display: false } }
    }
  });
}

function updateAlerts(data) {
  const list = document.getElementById('alerts-list');
  if (!list) return;

  const items = [];
  if (data.has_reverse_flow) {
    const rev = data.trafos.find(t => t.reverse_flow);
    if (rev) items.push({ type: 'warning', msg: `Fluxo reverso no transformador: ${rev.p_hv_kw} kW fluindo da BT para a SE. GD está injetando mais do que consumido.` });
    data.lines.filter(l => l.reverse_flow).forEach(l => {
      items.push({ type: 'warning', msg: `Fluxo reverso na linha ${l.id} (Bus ${l.from_bus}→${l.to_bus}): ${Math.abs(l.p_from_kw)} kW retornando.` });
    });
  }

  data.buses.filter(b => b.status === 'sobretensão').forEach(b => {
    items.push({ type: 'danger', msg: `Sobretensão no barramento "${b.name}": ${b.vm_pu} p.u. (máx PRODIST: 1.05 p.u.)` });
  });
  data.buses.filter(b => b.status === 'subtensão').forEach(b => {
    items.push({ type: 'danger', msg: `Subtensão no barramento "${b.name}": ${b.vm_pu} p.u. (mín PRODIST: 0.93 p.u.)` });
  });

  if (!items.length) items.push({ type: 'info', msg: 'Todos os barramentos dentro dos limites PRODIST. Rede operando normalmente.' });

  list.innerHTML = items.map(i => `<div class="alert-item alert-${i.type}">${i.msg}</div>`).join('');
}

async function runTapOptimization() {
  showToast('Analisando posições de tap...');
  try {
    const res = await fetch('/api/optimize_tap', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(getParams()),
    });
    const data = await res.json();
    renderTapResults(data);
    showToast('Otimização concluída!', 'success');
  } catch (e) {
    showToast('Falha na otimização', 'danger');
  }
}

function renderTapResults(data) {
  const container = document.getElementById('tap-results');
  if (!container || !data.results) return;

  const rec = data.best_tap !== null
    ? `<div class="alert-item alert-info" style="margin-bottom:14px">✅ <strong>Recomendação: Tap ${data.best_tap >= 0 ? '+' : ''}${data.best_tap}</strong> — melhor posição dentro dos limites PRODIST.</div>`
    : `<div class="alert-item alert-danger" style="margin-bottom:14px">⚠️ <strong>Nenhuma posição de tap</strong> manteve todas as tensões dentro dos limites. Considere reduzir a geração GD.</div>`;

  const rows = data.results.map(r => {
    const isBest = r.tap === data.best_tap;
    const rowCls = isBest ? 'tap-best' : (r.status === 'VIOLAÇÃO' ? 'tap-violation' : '');
    const badge = r.status === 'OK' ? '<span class="badge badge-ok">OK</span>' : '<span class="badge badge-danger">VIOLAÇÃO</span>';
    return `<tr class="${rowCls}">
      <td><strong>${r.tap >= 0 ? '+' : ''}${r.tap}</strong>${isBest ? ' ⭐' : ''}</td>
      <td>${r.v_min || '—'}</td>
      <td>${r.v_max || '—'}</td>
      <td>${r.deviation || '—'}</td>
      <td>${badge}</td>
    </tr>`;
  }).join('');

  container.innerHTML = rec + `
    <table class="tap-table">
      <thead><tr><th>Posição</th><th>V Mín (p.u.)</th><th>V Máx (p.u.)</th><th>Desvio Total</th><th>Status PRODIST</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>`;

  renderTapChart(data.results, data.best_tap);
}

function renderTapChart(results, bestTap) {
  const canvas = document.getElementById('chart-tap');
  if (!canvas) return;

  const valid = results.filter(r => r.v_min !== undefined);
  const labels = valid.map(r => 'Tap ' + (r.tap >= 0 ? '+' : '') + r.tap);
  const vmins = valid.map(r => r.v_min);
  const vmaxs = valid.map(r => r.v_max);
  const ctx = canvas.getContext('2d');
  
  if (tapChart) tapChart.destroy();
  tapChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: 'V Mínima', data: vmins, borderColor: '#6a1b9a', backgroundColor: 'rgba(106,27,154,.1)', fill: false, pointRadius: 6 },
        { label: 'V Máxima', data: vmaxs, borderColor: '#c62828', backgroundColor: 'rgba(198,40,40,.1)', fill: false, pointRadius: 6 },
        { label: 'Lim. Máx 1.05', data: Array(labels.length).fill(1.05), borderColor: '#f57f17', borderDash: [5,4], borderWidth: 1.5, pointRadius: 0, fill: false },
        { label: 'Lim. Mín 0.93', data: Array(labels.length).fill(0.93), borderColor: '#1565c0', borderDash: [5,4], borderWidth: 1.5, pointRadius: 0, fill: false },
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      scales: { y: { min: 0.87, max: 1.10, title: { display: true, text: 'Tensão (p.u.)' } } },
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } }
    }
  });
}

function renderTopology(data) {
  const svg = document.getElementById('topo-svg');
  if (!svg) return;

  const buses = data.buses || [];
  const lines = data.lines || [];
  const trafos = data.trafos || [];

  const positions = {
    0: { x: 170, y: 0 },
    1: { x: 170, y: 120 },
    2: { x: 170, y: 240 },
    3: { x: 40, y: 390 },
    4: { x: 300, y: 390 },
  };

  const groups = { 0: 'mt', 1: 'bt', 2: 'bt', 3: 'gd', 4: 'bt' };
  const busColors = { mt: '#1565c0', bt: '#2e7d32', gd: '#f57f17' };

  let html = '';

  const addEdge = (x1, y1, x2, y2, reverse, label, isTrafo) => {
    const color = reverse ? '#c62828' : '#2e7d32';
    const dash = isTrafo ? '6,3' : 'none';
    const mid = { x: (x1 + x2) / 2, y: (y1 + y2) / 2 };
    const arrowDir = reverse ? -1 : 1;
    html += `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="${color}" stroke-width="3" stroke-dasharray="${dash}" />`;
    const dx = x2 - x1, dy = y2 - y1;
    const len = Math.sqrt(dx * dx + dy * dy);
    
    // [CORREÇÃO] Evita divisão por zero
    if (len > 0) {
      const nx = dx / len, ny = dy / len;
      const ax = mid.x + arrowDir * nx * 10;
      const ay = mid.y + arrowDir * ny * 10;
      const px = -ny * 8, py = nx * 8;
      html += `<polygon points="${ax},${ay} ${ax - arrowDir * nx * 14 + px},${ay - arrowDir * ny * 14 + py} ${ax - arrowDir * nx * 14 - px},${ay - arrowDir * ny * 14 - py}" fill="${color}" />`;
    }
    
    if (label) {
      html += `<rect x="${mid.x - 22}" y="${mid.y - 9}" width="44" height="16" rx="4" fill="white" fill-opacity="0.85"/>`;
      html += `<text x="${mid.x}" y="${mid.y + 3}" text-anchor="middle" font-size="9" fill="${color}" font-weight="600">${label}</text>`;
    }
  };

  if (trafos.length) {
    const t = trafos[0];
    const p0 = positions[0], p1 = positions[1];
    if (p0 && p1) addEdge(p0.x, p0.y + 28, p1.x, p1.y - 24, t.reverse_flow, t.reverse_flow ? 'REVERSO' : 'NORMAL', true);
  }

  lines.forEach((l) => {
    const p1 = positions[l.from_bus];
    const p2 = positions[l.to_bus];
    if (!p1 || !p2) return;
    addEdge(p1.x, p1.y + 24, p2.x, p2.y - 24, l.reverse_flow, `${Math.abs(l.p_from_kw)}kW`);
  });

  buses.forEach(b => {
    const pos = positions[b.id];
    if (!pos) return;
    const base = groups[b.id] || 'bt';
    let fillColor = busColors[base];
    if (b.status === 'sobretensão') fillColor = '#c62828';
    else if (b.status === 'subtensão') fillColor = '#6a1b9a';

    const isGD = b.id === 3;
    html += `<circle cx="${pos.x}" cy="${pos.y}" r="22" fill="${fillColor}" stroke="white" stroke-width="3" />`;
    if (isGD) {
      html += `<text x="${pos.x}" y="${pos.y + 1}" text-anchor="middle" dominant-baseline="middle" font-size="16" fill="white">☀️</text>`;
    } else {
      html += `<text x="${pos.x}" y="${pos.y + 1}" text-anchor="middle" dominant-baseline="middle" font-size="11" fill="white" font-weight="700">B${b.id}</text>`;
    }
    const shortName = b.name.split(' ').slice(0, 2).join(' ');
    html += `<text x="${pos.x}" y="${pos.y + 34}" text-anchor="middle" font-size="9.5" fill="#37474f" font-weight="600">${shortName}</text>`;
    html += `<text x="${pos.x}" y="${pos.y + 46}" text-anchor="middle" font-size="10" fill="${fillColor}" font-weight="700">${b.vm_pu} p.u.</text>`;
  });

  const hasSgen = (data.total_generation_kw || 0) > 0;
  if (hasSgen && positions[3]) {
    const p = positions[3];
    html += `<rect x="${p.x - 32}" y="${p.y + 56}" width="64" height="18" rx="4" fill="#fff3e0" stroke="#f57f17" stroke-width="1"/>`;
    html += `<text x="${p.x}" y="${p.y + 68}" text-anchor="middle" font-size="9" fill="#e65100" font-weight="700">GD: ${data.total_generation_kw}kW</text>`;
  }

  svg.innerHTML = html;
}

// [CORREÇÃO] Gerenciamento da fila de alertas (Timeout Reset) para não sumir rápido
function showToast(msg, type) {
  const t = document.getElementById('toast');
  if (!t) return;
  t.textContent = msg;
  t.className = 'toast';
  t.classList.remove('hidden');

  if (type === 'danger') t.style.background = '#c62828';
  else if (type === 'success') t.style.background = '#2e7d32';
  else t.style.background = '#1a2332';
  
  if (toastTimeout) clearTimeout(toastTimeout);
  
  toastTimeout = setTimeout(() => {
    t.classList.add('hidden');
  }, 2800);
}

document.addEventListener('DOMContentLoaded', () => {
  updateLabel('sl-vm', 'lbl-vm', 0.01, 2);
  runSimulation();
});