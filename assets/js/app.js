const state = {
  fichaId: null,
  fichaInfo: {},
  explorerRows: [],
  dashboardComps: [],
};

let chartEstados = null;
let chartJuicios = null;
let chartEstadoAvance = null;
let chartModal = null;
let chartModalComp = null;
let currentModalJuicios = [];
let currentModalId = null;
let selectedFile = null;

const $ = id => document.getElementById(id);

const api = (action, params = {}) => {
  const clean = {};
  Object.entries(params).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') clean[key] = value;
  });
  const u = new URLSearchParams({ action, ...clean });
  return fetch(`api_data.php?${u}`).then(r => r.json());
};

function debounce(fn, ms) {
  let t;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...args), ms);
  };
}

const runExplorerDebounced = debounce(() => loadExplorer(), 350);

function debouncedExplorer() {
  runExplorerDebounced();
}

function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  }[char]));
}

function badgeEstado(e) {
  const m = {
    'EN FORMACION': 'badge-blue',
    'RETIRO VOLUNTARIO': 'badge-red',
    'TRASLADADO': 'badge-amber',
    'APLAZADO': 'badge-gray',
  };
  return `<span class="badge ${m[e] || 'badge-gray'}">${esc(e)}</span>`;
}

function badgeJuicio(j) {
  return j === 'APROBADO'
    ? '<span class="badge badge-green">APROBADO</span>'
    : '<span class="badge badge-amber">POR EVALUAR</span>';
}

function barra(pct) {
  const p = Number(pct ?? 0);
  const cls = p >= 70 ? '' : p >= 40 ? 'mid' : 'low';
  return `<div class="bar-wrap">
    <div class="bar-bg"><div class="bar-fill ${cls}" style="width:${Math.min(p, 100)}%"></div></div>
    <span class="bar-pct">${p}%</span>
  </div>`;
}

function fmtFecha(f) {
  if (!f) return '-';
  const d = new Date(f);
  if (Number.isNaN(d.getTime())) return '-';
  return d.toLocaleDateString('es-CO', { year: '2-digit', month: '2-digit', day: '2-digit' });
}

function setPage(name, btn) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('nav button').forEach(b => b.classList.remove('active'));
  $(`page-${name}`).classList.add('active');
  btn.classList.add('active');

  const fichaBar = document.getElementById('ficha-bar-container');
  if (fichaBar) {
    if (name === 'bienvenida') {
      fichaBar.style.display = 'none';
    } else {
      fichaBar.style.display = 'flex';
    }
  }

  if (name === 'explorador') {
    loadExplorerFilters();
    loadExplorer();
  }
}

function goToDashboard() {
  const btnInicio = document.getElementById('btn-inicio');
  if (btnInicio) btnInicio.style.display = 'none';
  document.querySelectorAll('.sys-btn').forEach(b => b.style.display = 'inline-block');
  const btn = [...document.querySelectorAll('nav button')].find(b => b.textContent.includes('Dashboard'));
  setPage('dashboard', btn);
}

function goToExplorer() {
  const btn = [...document.querySelectorAll('nav button')].find(b => b.textContent.includes('Explorador'));
  setPage('explorador', btn);
}

async function loadFichas() {
  const fichas = await api('fichas');
  const sel = $('g-ficha');
  const current = sel.value;
  sel.innerHTML = '<option value="">Selecciona una ficha...</option>';

  fichas.forEach(f => {
    const o = document.createElement('option');
    o.value = f.id_ficha;
    o.text = `${f.codigo_ficha} - ${f.programa}`;
    o.dataset.info = JSON.stringify(f);
    sel.appendChild(o);
  });

  if (current && [...sel.options].some(o => o.value === current)) {
    sel.value = current;
  } else if (fichas.length === 1) {
    sel.value = fichas[0].id_ficha;
  }

  onFichaChange();
}

function onFichaChange() {
  const sel = $('g-ficha');
  const opt = sel.options[sel.selectedIndex];
  state.fichaId = sel.value ? parseInt(sel.value, 10) : null;
  state.fichaInfo = opt?.dataset?.info ? JSON.parse(opt.dataset.info) : {};

  if (!state.fichaId) {
    $('g-ficha-info').textContent = 'Todas las fichas';
    const de = $('analytics-empty');
    if (de) de.style.display = 'block';
    const dc = $('analytics-content');
    if (dc) dc.style.display = 'none';
    const dcharts = $('dashboard-charts');
    if (dcharts) dcharts.style.display = 'none';
    const grid = $('kpi-grid');
    if (grid) grid.classList.add('didactic-mode');
  } else {
    $('g-ficha-info').textContent = [
      state.fichaInfo.estado_ficha,
      state.fichaInfo.modalidad,
      state.fichaInfo.regional,
    ].filter(Boolean).join(' | ');
    const de = $('analytics-empty');
    if (de) de.style.display = 'none';
    const dc = $('analytics-content');
    if (dc) dc.style.display = 'block';
    const dcharts = $('dashboard-charts');
    if (dcharts) dcharts.style.display = 'grid';
    const grid = $('kpi-grid');
    if (grid) grid.classList.remove('didactic-mode');
  }

  loadDashboard();
  loadExplorerFilters();
  loadExplorer();
}

async function loadDashboard() {
  const [kpis, comps, analitica] = await Promise.all([
    api('dashboard', { id_ficha: state.fichaId }),
    api('avance_competencias', { id_ficha: state.fichaId }),
    api('analitica', { id_ficha: state.fichaId }),
  ]);

  $('k-total').textContent = kpis.total_aprendices ?? '-';
  $('k-formacion').textContent = kpis.en_formacion ?? '-';
  $('k-retirados').textContent = kpis.retirados ?? '-';
  $('k-traslados').textContent = kpis.trasladados ?? '-';
  $('k-aprobados').textContent = kpis.aprobados ?? '-';
  $('k-pendientes').textContent = kpis.pendientes ?? '-';
  $('k-pct').textContent = `${kpis.pct_global ?? 0}%`;
  state.dashboardComps = comps;

  drawDoughnut('chart-estados', 'estados', chart => chartEstados = chart, chartEstados, {
    labels: ['En formacion', 'Retirados', 'Trasladados'],
    values: [kpis.en_formacion, kpis.retirados, kpis.trasladados],
    colors: ['#2563eb', '#dc2626', '#d97706'],
  });

  drawDoughnut('chart-juicios', 'juicios', chart => chartJuicios = chart, chartJuicios, {
    labels: ['Aprobados', 'Por evaluar'],
    values: [kpis.aprobados, kpis.pendientes],
    colors: ['#059669', '#d97706'],
  });

  renderAnalitica(analitica);
}

function drawDoughnut(canvasId, label, setChart, currentChart, data) {
  const ctx = $(canvasId).getContext('2d');
  if (currentChart) currentChart.destroy();
  setChart(new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: data.labels,
      datasets: [{
        label,
        data: data.values.map(v => Number(v ?? 0)),
        backgroundColor: data.colors,
        borderColor: '#fff',
        borderWidth: 3,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '68%',
      plugins: { legend: { position: 'bottom' } },
    },
  }));
}

function renderAnalitica(data) {
  const lowComps = data.competencias_menor_aprobacion || [];
  const pendingResults = data.resultados_mas_pendientes || [];
  const riskStudents = data.aprendices_riesgo || [];
  const fichaRows = data.comparacion_fichas || [];
  const estadoRows = data.estado_vs_avance || [];

  renderInsightStrip(lowComps, pendingResults, riskStudents, estadoRows);
  renderLowCompetences(lowComps);
  renderPendingResults(pendingResults);
  renderRiskStudents(riskStudents);
  drawEstadoAvance(estadoRows);
}

function renderInsightStrip(lowComps, pendingResults, riskStudents, estadoRows) {
  const comp = lowComps[0];
  const result = pendingResults[0];
  const estado = estadoRows[0];

  $('analytics-insights').innerHTML = `
    <div>
      <strong>${comp ? `${Number(comp.pct_aprobacion ?? 0)}%` : '-'}</strong>
      <span>${comp ? shortText(comp.competencia, 42) : 'Competencia critica'}</span>
    </div>
    <div>
      <strong>${result ? Number(result.pendientes ?? 0) : '-'}</strong>
      <span>${result ? shortText(result.resultado, 42) : 'Resultado mas pendiente'}</span>
    </div>
    <div>
      <strong>${riskStudents.length}</strong>
      <span>Atrasados frente al grupo</span>
    </div>
    <div>
      <strong>${estado ? `${Number(estado.avance_promedio ?? 0)}%` : '-'}</strong>
      <span>${estado ? estado.estado : 'Menor avance por estado'}</span>
    </div>
  `;
}

function renderLowCompetences(rows) {
  const box = $('analytics-low-comps');
  if (!rows.length) {
    box.innerHTML = emptyAnalytics('Sin competencias con juicios registrados.');
    return;
  }

  box.innerHTML = rows.map((row, i) => `
    <article class="rank-item">
      <span class="rank-num">${i + 1}</span>
      <div class="rank-main">
        <strong>${esc(row.competencia)}</strong>
        <small>${row.aprobados ?? 0}/${row.total ?? 0} aprobados - ${row.pendientes ?? 0} pendientes</small>
        ${miniBar(row.pct_aprobacion, true)}
      </div>
      <div class="rank-side">
        <span class="rank-value ${Number(row.pct_aprobacion ?? 0) < 40 ? 'risk' : 'warn'}">${row.pct_aprobacion ?? 0}%</span>
        <button class="btn btn-outline compact-btn" onclick="goToCompetencia(${row.id_competencia})">Ver</button>
      </div>
    </article>
  `).join('');
}

function renderPendingResults(rows) {
  const box = $('analytics-pending-results');
  if (!rows.length) {
    box.innerHTML = emptyAnalytics('No hay resultados pendientes.');
    return;
  }

  box.innerHTML = rows.map((row, i) => `
    <article class="rank-item">
      <span class="rank-num">${i + 1}</span>
      <div class="rank-main">
        <strong>${esc(row.resultado)}</strong>
        <small>${esc(row.competencia)}</small>
        ${miniBar(row.pct_pendiente)}
      </div>
      <span class="rank-value warn">${row.pendientes ?? 0}</span>
    </article>
  `).join('');
}

function renderRiskStudents(rows) {
  const box = $('analytics-risk-students');
  if (!rows.length) {
    box.innerHTML = emptyAnalytics('No hay aprendices con pendientes que el grupo ya tenga mayoritariamente aprobados.');
    return;
  }

  box.innerHTML = rows.map((row, i) => `
    <article class="rank-item">
      <span class="rank-num">${i + 1}</span>
      <div class="rank-main">
        <strong>${esc(row.aprendiz)}</strong>
        <small>${esc(row.codigo_ficha)} - ${esc(row.documento)} - ${esc(row.estado)}</small>
        <p class="risk-reason">${esc(buildRiskReason(row))}</p>
        ${renderPeerPendingList(row.pendientes_clave)}
        ${miniBar(row.avance_pct, true)}
      </div>
      <div class="rank-side">
        <span class="rank-value risk">${row.pendientes_prioritarios ?? 0}</span>
        <button class="btn btn-primary compact-btn" onclick="openModal(${row.id_aprendiz}, ${esc(JSON.stringify(row.aprendiz))})">Estudio</button>
      </div>
    </article>
  `).join('');
}

function buildRiskReason(row) {
  const priority = Number(row.pendientes_prioritarios ?? 0);
  const groupPct = Number(row.pct_grupo_promedio ?? 0);
  const totalPending = Number(row.pendientes ?? 0);
  return `${priority} pendiente${priority === 1 ? '' : 's'} donde al menos el 70% del grupo ya aprobo. Promedio del grupo: ${groupPct}%. Pendientes totales: ${totalPending}.`;
}

function renderPeerPendingList(raw) {
  const items = parsePeerPending(raw).slice(0, 2);
  if (!items.length) return '';
  return `<div class="peer-pending-list">
    ${items.map(item => `
      <div>
        <strong>${esc(item.resultado)}</strong>
        <span>${esc(item.competencia)} - ${esc(item.pct)}% aprobado en el grupo (${esc(item.ratio)})</span>
      </div>
    `).join('')}
  </div>`;
}

function parsePeerPending(raw) {
  if (!raw) return [];
  return String(raw).split('##').map(part => {
    const [resultado, competencia, pct, ratio] = part.split('||');
    return { resultado, competencia, pct, ratio };
  });
}

function renderFichaComparison(rows) {
  const box = $('analytics-fichas');
  if (state.fichaId) {
    box.innerHTML = emptyAnalytics('Selecciona "Todas las fichas" para comparar fichas entre si.');
    return;
  }
  if (!rows.length) {
    box.innerHTML = emptyAnalytics('No hay fichas con datos para comparar.');
    return;
  }

  box.innerHTML = rows.map((row, i) => `
    <article class="rank-item">
      <span class="rank-num">${i + 1}</span>
      <div class="rank-main">
        <strong>${esc(row.codigo_ficha)} - ${esc(row.programa)}</strong>
        <small>${row.pendientes ?? 0} pendientes - ${row.retirados ?? 0} retirados - ${row.total_aprendices ?? 0} aprendices</small>
        ${miniBar(row.avance_pct, true)}
      </div>
      <span class="rank-value">${row.avance_pct ?? 0}%</span>
    </article>
  `).join('');
}

function drawEstadoAvance(rows) {
  const ctx = $('chart-estado-avance').getContext('2d');
  if (chartEstadoAvance) chartEstadoAvance.destroy();

  chartEstadoAvance = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: rows.map(row => row.estado),
      datasets: [{
        label: 'Avance promedio',
        data: rows.map(row => Number(row.avance_promedio ?? 0)),
        backgroundColor: rows.map(row => colorByPct(row.avance_promedio)),
        borderRadius: 6,
        barThickness: 28,
      }],
    },
    options: {
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => {
              const row = rows[ctx.dataIndex];
              return `${row.avance_promedio}% promedio - ${row.aprendices} aprendices`;
            },
          },
        },
      },
      scales: {
        y: { beginAtZero: true, max: 100, ticks: { callback: v => `${v}%` } },
        x: { grid: { display: false }, ticks: { font: { size: 11 } } },
      },
    },
  });
}

function miniBar(value, approvalMode = false) {
  const pct = Math.max(0, Math.min(Number(value ?? 0), 100));
  const cls = approvalMode ? (pct >= 70 ? 'ok' : pct >= 40 ? 'mid' : 'low') : (pct >= 60 ? 'low' : pct >= 30 ? 'mid' : 'ok');
  return `<div class="mini-bar"><div class="${cls}" style="width:${pct}%"></div></div>`;
}

function emptyAnalytics(text) {
  return `<div class="analytics-empty">${esc(text)}</div>`;
}

async function goToCompetencia(idCompetencia) {
  const btn = [...document.querySelectorAll('nav button')].find(b => b.textContent.includes('Explorador'));
  setPage('explorador', btn);
  await loadExplorerFilters();
  $('ex-competencia').value = String(idCompetencia);
  $('ex-estado-juicio').value = '';
  $('ex-estado-ap').value = '';
  $('ex-rango').value = '';
  $('ex-busqueda').value = '';
  loadExplorer();
}

function colorByPct(pct) {
  const p = Number(pct ?? 0);
  if (p >= 70) return '#059669';
  if (p >= 40) return '#d97706';
  return '#dc2626';
}

function shortText(text, max) {
  const value = String(text ?? '');
  return value.length > max ? `${value.slice(0, max - 1)}...` : value;
}

async function loadExplorerFilters() {
  const [comps, insts] = await Promise.all([
    api('competencias', { id_ficha: state.fichaId }),
    api('instructores', { id_ficha: state.fichaId }),
  ]);

  fillSelect($('ex-competencia'), 'Todas las competencias', comps, 'id_competencia', 'nombre');
  fillSelect($('ex-instructor'), 'Todos los instructores', insts, 'id_instructor', 'nombre_completo');
}

function fillSelect(sel, firstLabel, rows, valueKey, textKey) {
  const current = sel.value;
  sel.innerHTML = `<option value="">${firstLabel}</option>`;
  rows.forEach(row => {
    const o = document.createElement('option');
    o.value = row[valueKey];
    o.text = shortText(row[textKey], 72);
    sel.appendChild(o);
  });
  if ([...sel.options].some(o => o.value === current)) sel.value = current;
}

function explorerParams() {
  const rango = $('ex-rango').value;
  const params = {
    id_ficha: state.fichaId,
    busqueda: $('ex-busqueda').value,
    estado: $('ex-estado-ap').value,
    estado_juicio: $('ex-estado-juicio').value,
    id_competencia: $('ex-competencia').value,
    id_instructor: $('ex-instructor').value,
  };

  if (rango === 'critico') Object.assign(params, { min_pct: 0, max_pct: 30 });
  if (rango === 'medio') Object.assign(params, { min_pct: 30, max_pct: 70 });
  if (rango === 'avanzado') Object.assign(params, { min_pct: 70, max_pct: 99.9 });
  if (rango === 'completado') Object.assign(params, { min_pct: 100, max_pct: 100 });
  return params;
}

async function loadExplorer() {
  const tbody = $('ex-tbody');
  tbody.innerHTML = '<tr><td colspan="8" class="loading">Cargando...</td></tr>';

  const rows = await api('avance_aprendices', explorerParams());
  state.explorerRows = rows;
  renderExplorer(rows);
}

function renderExplorer(rows) {
  const tbody = $('ex-tbody');
  $('ex-count').textContent = `${rows.length} aprendices`;

  const totalAprobados = rows.reduce((acc, row) => acc + Number(row.aprobados ?? 0), 0);
  const totalPendientes = rows.reduce((acc, row) => acc + Number(row.pendientes ?? 0), 0);
  const avg = rows.length
    ? Math.round(rows.reduce((acc, row) => acc + Number(row.avance_pct ?? 0), 0) / rows.length)
    : 0;
  $('ex-summary').innerHTML = `
    <div><strong>${rows.length}</strong><span>Aprendices</span></div>
    <div><strong>${avg}%</strong><span>Promedio avance</span></div>
    <div><strong>${totalAprobados}</strong><span>Aprobados</span></div>
    <div><strong>${totalPendientes}</strong><span>Pendientes</span></div>
  `;

  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="8" class="loading">Sin resultados para estos filtros</td></tr>';
    return;
  }

  tbody.innerHTML = rows.map(a => {
    const nombre = `${a.nombre} ${a.apellidos}`;
    return `
      <tr class="group-row" id="row-${a.id_aprendiz}" onclick="toggleGroup(${a.id_aprendiz}, this)">
        <td><span class="toggle-icon">›</span></td>
        <td>
          <strong>${esc(nombre)}</strong>
          <small>${esc(a.tipo_documento)} ${esc(a.documento)}</small>
        </td>
        <td>${badgeEstado(a.estado)}</td>
        <td>${a.total_resultados ?? 0}</td>
        <td class="ok">${a.aprobados ?? 0}</td>
        <td class="warn">${a.pendientes ?? 0}</td>
        <td>${barra(a.avance_pct)}</td>
        <td>
          <button class="btn btn-primary compact-btn" onclick="event.stopPropagation(); openModal(${a.id_aprendiz}, ${esc(JSON.stringify(nombre))})">Estudio</button>
        </td>
      </tr>
      <tr class="detail-row detail-${a.id_aprendiz}">
        <td colspan="8"><div id="content-${a.id_aprendiz}" class="detail-panel">Cargando juicios...</div></td>
      </tr>
    `;
  }).join('');
}

async function toggleGroup(id, el) {
  const isOpening = !el.classList.contains('open');
  el.classList.toggle('open', isOpening);
  const detail = document.querySelector(`.detail-${id}`);
  detail.classList.toggle('open', isOpening);

  if (!isOpening) return;
  const content = $(`content-${id}`);
  content.innerHTML = '<div class="loading small">Cargando juicios...</div>';

  const juicios = await api('juicios_aprendiz', {
    id_aprendiz: id,
    id_competencia: $('ex-competencia').value,
    id_instructor: $('ex-instructor').value,
    estado_juicio: $('ex-estado-juicio').value,
  });

  content.innerHTML = renderJuiciosMini(juicios);
}

function renderJuiciosMini(juicios) {
  if (!juicios.length) return '<div class="loading small">Sin juicios registrados</div>';
  const grouped = groupByCompetencia(juicios);
  return grouped.map(group => `
    <section class="competence-block">
      <div class="competence-head">
        <strong>${esc(group.competencia)}</strong>
        <span>${group.aprobados}/${group.rows.length} aprobados</span>
      </div>
      <div class="result-list">
        ${group.rows.map(row => `
          <article class="result-item">
            <div>${esc(row.resultado)}</div>
            <div>${badgeJuicio(row.juicio)}</div>
            <small>${fmtFecha(row.fecha)} | ${esc(row.instructor)}</small>
          </article>
        `).join('')}
      </div>
    </section>
  `).join('');
}

function groupByCompetencia(juicios) {
  const map = new Map();
  juicios.forEach(j => {
    if (!map.has(j.competencia)) {
      map.set(j.competencia, { competencia: j.competencia, rows: [], aprobados: 0, pendientes: 0 });
    }
    const group = map.get(j.competencia);
    group.rows.push(j);
    if (j.juicio === 'APROBADO') group.aprobados += 1;
    if (j.juicio === 'POR EVALUAR') group.pendientes += 1;
  });
  return [...map.values()];
}

function clearExplorerFilters() {
  ['ex-busqueda', 'ex-estado-ap', 'ex-estado-juicio', 'ex-competencia', 'ex-instructor', 'ex-rango']
    .forEach(id => $(id).value = '');
  loadExplorer();
}

async function openModal(idAprendiz, nombre) {
  currentModalId = idAprendiz;
  $('modal-title').textContent = nombre;
  $('modal-overlay').classList.add('open');
  $('modal-tbody').innerHTML = '<tr><td colspan="5" class="loading">Cargando...</td></tr>';

  const [stats, juicios, comps] = await Promise.all([
    api('stats_aprendiz', { id_aprendiz: idAprendiz }),
    api('juicios_aprendiz', { id_aprendiz: idAprendiz }),
    api('competencias', { id_ficha: state.fichaId }),
  ]);

  currentModalJuicios = juicios;
  fillSelect($('modal-filter-comp'), 'Todas las competencias', comps, 'id_competencia', 'nombre');
  $('modal-filter-status').value = '';

  renderStudentStudy(stats, juicios);
  renderModalTable(juicios);
}

function renderStudentStudy(stats, juicios) {
  const res = stats.resumen || {};
  const pct = Number(res.pct ?? 0);
  $('modal-kpis-mini').innerHTML = `
    <div><strong>${pct}%</strong><span>Avance</span></div>
    <div><strong>${res.aprobados ?? 0}</strong><span>Aprobados</span></div>
    <div><strong>${res.pendientes ?? 0}</strong><span>Pendientes</span></div>
    <div><strong>${res.total ?? 0}</strong><span>Resultados</span></div>
  `;

  const pending = Number(res.pendientes ?? 0);
  let status = 'En seguimiento';
  let tone = 'mid';
  if (pct >= 90 && pending <= 2) { status = 'Cerca de cierre'; tone = 'ok'; }
  if (pct < 40 || pending >= 10) { status = 'Requiere prioridad'; tone = 'risk'; }
  $('modal-status').className = `status-box ${tone}`;
  $('modal-status').innerHTML = `<strong>${status}</strong><span>${buildStatusText(pct, pending)}</span>`;

  drawDoughnut('modal-chart', 'aprendiz', chart => chartModal = chart, chartModal, {
    labels: ['Aprobados', 'Por evaluar'],
    values: [res.aprobados, res.pendientes],
    colors: ['#059669', '#d97706'],
  });
  drawStudentCompetenceChart(juicios);
  renderAlerts(stats.alertas || []);
}

function buildStatusText(pct, pending) {
  if (pct >= 90 && pending <= 2) return 'Tiene pocos resultados pendientes frente al total.';
  if (pct < 40 || pending >= 10) return 'Conviene revisar los pendientes antes de nuevas evidencias.';
  return 'El avance es intermedio y necesita seguimiento por competencia.';
}

function drawStudentCompetenceChart(juicios) {
  const groups = groupByCompetencia(juicios);
  const ctx = $('modal-chart-comp').getContext('2d');
  if (chartModalComp) chartModalComp.destroy();
  $('modal-chart-comp').parentElement.style.height = `${Math.max(260, groups.length * 38)}px`;

  chartModalComp = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: groups.map(g => shortText(g.competencia, 52)),
      datasets: [{
        data: groups.map(g => Math.round(g.aprobados * 100 / Math.max(g.rows.length, 1))),
        backgroundColor: groups.map(g => colorByPct(g.aprobados * 100 / Math.max(g.rows.length, 1))),
        borderRadius: 6,
        barThickness: 16,
      }],
    },
    options: {
      indexAxis: 'y',
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { beginAtZero: true, max: 100, ticks: { callback: v => `${v}%` } },
        y: { grid: { display: false }, ticks: { font: { size: 10 } } },
      },
    },
  });
}

function renderAlerts(alertas) {
  const box = $('modal-alerts');
  const list = $('alerts-list');
  if (!alertas.length) {
    list.innerHTML = '<p class="muted">No hay pendientes que destaquen frente al grupo.</p>';
    return;
  }
  list.innerHTML = alertas.slice(0, 6).map(a => {
    const pct = Math.round(Number(a.otros_aprobados) * 100 / Math.max(Number(a.total_pares), 1));
    return `
      <article class="alert-item">
        <strong>${esc(shortText(a.resultado, 92))}</strong>
        <span>${pct}% del grupo ya lo aprobo</span>
      </article>
    `;
  }).join('');
  box.style.display = 'block';
}

function filterModalJuicios() {
  const compId = $('modal-filter-comp').value;
  const status = $('modal-filter-status').value;
  let rows = [...currentModalJuicios];
  if (compId) rows = rows.filter(row => String(row.id_competencia) === String(compId) || String(row.codigo_comp) === String(compId));
  if (status) rows = rows.filter(row => row.juicio === status);

  if (compId) {
    loadFilteredModalJuicios(compId, status);
  } else {
    renderModalTable(rows);
  }
}

async function loadFilteredModalJuicios(compId, status) {
  $('modal-tbody').innerHTML = '<tr><td colspan="5" class="loading">Filtrando...</td></tr>';
  const rows = await api('juicios_aprendiz', {
    id_aprendiz: currentModalId,
    id_competencia: compId,
    estado_juicio: status,
  });
  renderModalTable(rows);
}

function renderModalTable(data) {
  const tbody = $('modal-tbody');
  $('modal-count').textContent = `${data.length} resultados`;

  if (!data.length) {
    tbody.innerHTML = '<tr><td colspan="5" class="loading">Sin resultados</td></tr>';
    return;
  }

  tbody.innerHTML = data.map(row => `
    <tr>
      <td>${esc(shortText(row.competencia, 70))}</td>
      <td>${esc(row.resultado)}</td>
      <td>${badgeJuicio(row.juicio)}</td>
      <td>${fmtFecha(row.fecha)}</td>
      <td>${esc(row.instructor)}</td>
    </tr>
  `).join('');
}

function closeModal(e) {
  if (e.target.id === 'modal-overlay') closeModalDirect();
}

function closeModalDirect() {
  $('modal-overlay').classList.remove('open');
}

const dz = $('drop-zone');
dz.addEventListener('dragover', e => {
  e.preventDefault();
  dz.classList.add('over');
});
dz.addEventListener('dragleave', () => dz.classList.remove('over'));
dz.addEventListener('drop', e => {
  e.preventDefault();
  dz.classList.remove('over');
  if (e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]);
});

function onFileSelected(input) {
  if (input.files[0]) setFile(input.files[0]);
}

function setFile(file) {
  selectedFile = file;
  $('drop-filename').textContent = file.name;
  $('btn-upload').disabled = false;
  $('import-result').style.display = 'none';
}

async function uploadFile() {
  if (!selectedFile) return;
  const prog = $('prog-bar');
  const res = $('import-result');
  prog.style.display = 'block';
  res.style.display = 'none';
  $('btn-upload').disabled = true;

  const fd = new FormData();
  fd.append('archivo', selectedFile);

  try {
    const r = await fetch('api_import.php', { method: 'POST', body: fd });
    const d = await r.json();
    prog.style.display = 'none';
    $('btn-upload').disabled = false;

    if (d.ok) {
      res.className = 'result-box';
      res.innerHTML = `<strong>Importacion exitosa</strong><br>
        Ficha: <strong>${esc(d.ficha)}</strong><br>
        Programa: ${esc(d.programa)}<br>
        Registros procesados: <strong>${esc(d.insertados)}</strong>
        ${d.errores?.length ? `<ul>${d.errores.map(e => `<li>${esc(e)}</li>`).join('')}</ul>` : ''}`;
      loadFichas();
    } else {
      res.className = 'result-box error';
      res.innerHTML = `<strong>Error:</strong> ${esc(d.error)}`;
    }
    res.style.display = 'block';
  } catch (e) {
    prog.style.display = 'none';
    $('btn-upload').disabled = false;
    res.className = 'result-box error';
    res.textContent = 'Error de conexion con el servidor.';
    res.style.display = 'block';
  }
}

async function exportExplorerCSV() {
  const rows = await api('avance_aprendices', explorerParams());
  const head = 'Documento,Nombre,Estado,Total,Aprobados,Pendientes,Avance';
  const body = rows.map(r => [
    r.documento,
    `${r.nombre} ${r.apellidos}`,
    r.estado,
    r.total_resultados,
    r.aprobados,
    r.pendientes,
    `${r.avance_pct}%`,
  ].map(csvCell).join(','));
  downloadCSV([head, ...body].join('\n'), 'explorador_aprendices.csv');
}

function csvCell(value) {
  return `"${String(value ?? '').replace(/"/g, '""')}"`;
}

function downloadCSV(content, filename) {
  const a = document.createElement('a');
  a.href = `data:text/csv;charset=utf-8,\uFEFF${encodeURIComponent(content)}`;
  a.download = filename;
  a.click();
}

loadFichas();

// --- Funcionalidad de IA ---
async function callIA(action, datos, resultBoxId) {
  const box = $(resultBoxId);
  box.style.display = 'block';
  box.className = 'ai-box loading';
  box.innerHTML = 'Generando análisis con IA...';

  try {
    const res = await fetch('api_ai.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, datos })
    });
    
    const data = await res.json();
    
    if (!res.ok) {
      throw new Error(data.error || 'Error desconocido al consultar la IA');
    }

    box.className = 'ai-box';
    box.innerHTML = `
      <button class="ai-copy-btn" onclick="copyToClipboard(this)">Copiar</button>
      <h3>Análisis de IA</h3>
      <pre>${esc(data.resultado)}</pre>
    `;
  } catch (error) {
    box.className = 'ai-box error';
    box.innerHTML = `<strong>Error:</strong> ${esc(error.message)}`;
  }
}

function copyToClipboard(btn) {
  const pre = btn.parentElement.querySelector('pre');
  if (!pre) return;
  navigator.clipboard.writeText(pre.textContent).then(() => {
    const originalText = btn.textContent;
    btn.textContent = '¡Copiado!';
    setTimeout(() => btn.textContent = originalText, 2000);
  });
}

function generarResumenIA() {
  const datos = {
    total_aprendices: $('k-total').textContent,
    en_formacion: $('k-formacion').textContent,
    retirados: $('k-retirados').textContent,
    trasladados: $('k-traslados').textContent,
    aprobados: $('k-aprobados').textContent,
    pendientes: $('k-pendientes').textContent,
    avance_global: $('k-pct').textContent,
    competencias: []
  };

  datos.competencias = state.dashboardComps.map(row => ({
    competencia: row.competencia,
    avance: `${row.pct}%`,
  }));

  callIA('resumen_ficha', datos, 'dash-ai-result');
}

function generarAnalisisIA() {
  if (!currentModalId) return;

  const res = chartModal ? chartModal.data.datasets[0].data : [0, 0];
  const pct = $('modal-kpis-mini').querySelector('div:first-child strong').textContent;
  const nombre = $('modal-title').textContent;
  const estado = $('modal-status').querySelector('strong').textContent;
  const alertas = [];
  
  document.querySelectorAll('.alert-item').forEach(el => {
    alertas.push(el.querySelector('strong').textContent);
  });

  const datos = {
    nombre: nombre,
    estado_academico: estado,
    avance: pct,
    aprobados: res[0],
    pendientes: res[1],
    total_resultados: res[0] + res[1],
    resultados_pendientes_prioritarios: alertas
  };

  callIA('analizar_aprendiz', datos, 'modal-ai-result');
}

// --- UX mejorada para la respuesta IA del dashboard ---
async function callIA(action, datos, resultBoxId) {
  const box = $(resultBoxId);
  const isDashboard = resultBoxId === 'dash-ai-result';
  const btn = isDashboard ? $('dash-ai-btn') : null;

  box.style.display = 'block';
  box.className = isDashboard ? 'ai-box ai-box-dashboard loading' : 'ai-box loading';
  box.innerHTML = isDashboard ? renderDashboardAILoading() : 'Generando analisis con IA...';

  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Generando...';
  }

  try {
    const res = await fetch('api_ai.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, datos })
    });

    const data = await res.json();

    if (!res.ok) {
      throw new Error(data.error || 'Error desconocido al consultar la IA');
    }

    if (isDashboard) {
      box.className = 'ai-box ai-box-dashboard ready';
      box.innerHTML = renderDashboardAIResult(data.resultado, datos);
    } else {
      box.className = 'ai-box';
      box.innerHTML = `
        <button class="ai-copy-btn" onclick="copyToClipboard(this)">Copiar</button>
        <h3>Analisis de IA</h3>
        <pre>${esc(data.resultado)}</pre>
      `;
    }
  } catch (error) {
    box.className = isDashboard ? 'ai-box ai-box-dashboard error' : 'ai-box error';
    box.innerHTML = isDashboard ? renderDashboardAIError(error.message) : `<strong>Error:</strong> ${esc(error.message)}`;
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.textContent = 'Generar resumen IA';
    }
  }
}

function renderDashboardAILoading() {
  return `
    <div class="ai-dashboard-shell">
      <div class="ai-dashboard-head">
        <div>
          <span class="ai-kicker">Analisis ejecutivo</span>
          <h2>Generando lectura academica de la ficha</h2>
        </div>
        <span class="ai-status-pill">Procesando</span>
      </div>
      <div class="ai-loading-grid">
        <div class="ai-skeleton wide"></div>
        <div class="ai-skeleton"></div>
        <div class="ai-skeleton"></div>
        <div class="ai-skeleton tall"></div>
      </div>
      <p class="ai-helper">Cruzando KPIs, avance por competencia y pendientes para devolver recomendaciones concretas.</p>
    </div>
  `;
}

function renderDashboardAIResult(text, datos) {
  return `
    <div class="ai-dashboard-shell">
      <div class="ai-dashboard-head">
        <div>
          <span class="ai-kicker">Resumen IA de la ficha</span>
          <h2>Lectura ejecutiva para coordinacion</h2>
          <p>Generado con los indicadores actuales del dashboard.</p>
        </div>
        <div class="ai-actions">
          <button class="btn btn-outline compact-btn" onclick="copyAIText(this)">Copiar</button>
          <button class="btn btn-outline compact-btn" onclick="downloadAIText(this, 'resumen_ia_ficha.txt')">Descargar</button>
          <button class="btn btn-outline compact-btn" onclick="clearAIBox('dash-ai-result')">Limpiar</button>
        </div>
      </div>
      <div class="ai-metric-strip">
        <div><strong>${esc(datos.avance_global)}</strong><span>Avance global</span></div>
        <div><strong>${esc(datos.total_aprendices)}</strong><span>Aprendices</span></div>
        <div><strong>${esc(datos.pendientes)}</strong><span>Pendientes</span></div>
        <div><strong>${esc(datos.competencias.length)}</strong><span>Competencias</span></div>
      </div>
      <div class="ai-readable" data-ai-text="${esc(text)}">
        ${formatAIText(text)}
      </div>
    </div>
  `;
}

function renderDashboardAIError(message) {
  return `
    <div class="ai-dashboard-shell">
      <div class="ai-dashboard-head">
        <div>
          <span class="ai-kicker">No se pudo generar</span>
          <h2>La respuesta de IA fallo</h2>
          <p>${esc(message)}</p>
        </div>
        <div class="ai-actions">
          <button class="btn btn-outline compact-btn" onclick="generarResumenIA()">Reintentar</button>
          <button class="btn btn-outline compact-btn" onclick="clearAIBox('dash-ai-result')">Cerrar</button>
        </div>
      </div>
    </div>
  `;
}

function formatAIText(text) {
  const sections = [];
  let current = { title: 'Resumen', lines: [] };

  String(text ?? '').split(/\r?\n/).forEach(rawLine => {
    const line = rawLine.trim();
    if (!line) return;

    const clean = line.replace(/^[-*]\s*/, '').replace(/\*\*/g, '');
    const match = clean.match(/^(Diagnostico|Diagnóstico|Riesgo|Hallazgos principales|Hallazgos|Recomendaciones|Proximos pasos|Próximos pasos)\s*:?\s*(.*)$/i);

    if (match) {
      if (current.lines.length) sections.push(current);
      current = { title: normalizeAITitle(match[1]), lines: [] };
      if (match[2]) current.lines.push(match[2]);
    } else {
      current.lines.push(clean);
    }
  });

  if (current.lines.length) sections.push(current);

  return sections.map(section => `
    <article class="ai-section">
      <h3>${esc(section.title)}</h3>
      ${section.lines.map(line => `<p>${esc(line)}</p>`).join('')}
    </article>
  `).join('');
}

function normalizeAITitle(title) {
  const t = title.toLowerCase();
  if (t.includes('diagn')) return 'Diagnostico';
  if (t.includes('riesgo')) return 'Riesgo';
  if (t.includes('hallazgo')) return 'Hallazgos principales';
  if (t.includes('recomend')) return 'Recomendaciones';
  if (t.includes('pasos')) return 'Proximos pasos';
  return title;
}

function copyAIText(btn) {
  const text = btn.closest('.ai-dashboard-shell')?.querySelector('.ai-readable')?.dataset.aiText;
  if (!text) return;
  navigator.clipboard.writeText(text).then(() => {
    const original = btn.textContent;
    btn.textContent = 'Copiado';
    setTimeout(() => btn.textContent = original, 1600);
  });
}

function downloadAIText(btn, filename) {
  const text = btn.closest('.ai-dashboard-shell')?.querySelector('.ai-readable')?.dataset.aiText;
  if (!text) return;
  const a = document.createElement('a');
  a.href = `data:text/plain;charset=utf-8,\uFEFF${encodeURIComponent(text)}`;
  a.download = filename;
  a.click();
}

function clearAIBox(id) {
  const box = $(id);
  box.style.display = 'none';
  box.innerHTML = '';
}

function generarResumenIA() {
  if (!state.fichaId) {
    const box = $('dash-ai-result');
    box.style.display = 'block';
    box.className = 'ai-box ai-box-dashboard error';
    box.innerHTML = renderDashboardAIError('Selecciona una ficha antes de generar el resumen.');
    return;
  }

  const datos = {
    total_aprendices: $('k-total').textContent,
    en_formacion: $('k-formacion').textContent,
    retirados: $('k-retirados').textContent,
    trasladados: $('k-traslados').textContent,
    aprobados: $('k-aprobados').textContent,
    pendientes: $('k-pendientes').textContent,
    avance_global: $('k-pct').textContent,
    competencias: []
  };

  datos.competencias = state.dashboardComps.map(row => ({
    competencia: row.competencia,
    avance: `${row.pct}%`,
  }));

  callIA('resumen_ficha', datos, 'dash-ai-result');
}

// --- Funciones de Exportación a PDF (Reportes) ---

/**
 * Exporta el Dashboard actual a un PDF profesional
 */
async function exportarDashboardPDF() {
  if (!state.fichaId) {
    alert('Por favor selecciona una ficha primero.');
    return;
  }

  const element = document.createElement('div');
  element.className = 'pdf-report';
  
  const fichaNombre = $('g-ficha').options[$('g-ficha').selectedIndex].text;
  const fecha = new Date().toLocaleDateString();

  element.innerHTML = `
    <div class="pdf-header">
      <div class="pdf-brand">SISTEMA DE JUICIOS EVALUATIVOS</div>
      <div class="pdf-title">Reporte Ejecutivo de Ficha</div>
    </div>
    
    <div class="pdf-info-grid">
      <div><strong>Ficha:</strong> ${esc(fichaNombre)}</div>
      <div><strong>Fecha de reporte:</strong> ${fecha}</div>
      <div><strong>Estado:</strong> ${esc(state.fichaInfo.estado_ficha || 'N/A')}</div>
      <div><strong>Modalidad:</strong> ${esc(state.fichaInfo.modalidad || 'N/A')}</div>
    </div>

    <div class="pdf-section-title">Resumen de Indicadores (KPIs)</div>
    <div class="pdf-kpi-container">
      ${$('kpi-grid').innerHTML}
    </div>

    <div class="pdf-section-title">Distribución y Avance</div>
    <div class="pdf-charts-row">
       <div class="pdf-chart-item">
         <p>Estados de Aprendices</p>
         <img src="${$('chart-estados').toDataURL('image/png')}" style="width:100%">
       </div>
       <div class="pdf-chart-item">
         <p>Juicios Evaluativos</p>
         <img src="${$('chart-juicios').toDataURL('image/png')}" style="width:100%">
       </div>
    </div>

    <div class="pdf-section-title">Análisis de Competencias</div>
    <div class="pdf-rank-list">
      ${$('analytics-low-comps').innerHTML}
    </div>

    <div class="pdf-footer">
      Documento generado automáticamente por el Sistema de Juicios Evaluativos.
    </div>
  `;

  const opt = {
    margin: [10, 10],
    filename: `Reporte_Ficha_${state.fichaInfo.codigo_ficha || 'General'}.pdf`,
    image: { type: 'jpeg', quality: 0.98 },
    html2canvas: { scale: 2, useCORS: true },
    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
  };

  try {
    await html2pdf().set(opt).from(element).save();
  } catch (err) {
    console.error('Error generando PDF:', err);
    alert('No se pudo generar el PDF. Verifica la consola.');
  }
}

/**
 * Exporta el estudio del aprendiz actual a una Ficha de Seguimiento PDF
 */
async function exportarAprendizPDF() {
  if (!currentModalId) return;

  const element = document.createElement('div');
  element.className = 'pdf-report pdf-student';
  
  const nombre = $('modal-title').textContent;
  const fichaNombre = $('g-ficha').options[$('g-ficha').selectedIndex].text;
  const fecha = new Date().toLocaleDateString();

  // Capturamos los gráficos actuales antes de clonar
  const chartImg = $('modal-chart').toDataURL('image/png');
  const chartCompImg = $('modal-chart-comp').toDataURL('image/png');

  element.innerHTML = `
    <div class="pdf-header">
      <div class="pdf-brand">SISTEMA DE JUICIOS EVALUATIVOS</div>
      <div class="pdf-title">Ficha de Seguimiento Individual</div>
    </div>
    
    <div class="pdf-info-grid">
      <div><strong>Aprendiz:</strong> ${esc(nombre)}</div>
      <div><strong>Ficha:</strong> ${esc(fichaNombre)}</div>
      <div><strong>Fecha:</strong> ${fecha}</div>
      <div><strong>Estado Sistema:</strong> ${$('modal-status').querySelector('strong').textContent}</div>
    </div>

    <div class="pdf-two-cols">
      <div class="pdf-col-side">
        <div class="pdf-section-title">Avance Global</div>
        <img src="${chartImg}" style="width:100%; max-width:200px; margin: 0 auto; display:block;">
        <div class="pdf-mini-stats">
          ${$('modal-kpis-mini').innerHTML}
        </div>
      </div>
      <div class="pdf-col-main">
        <div class="pdf-section-title">Avance por Competencia</div>
        <img src="${chartCompImg}" style="width:100%">
      </div>
    </div>

    <div class="pdf-section-title">Alertas y Pendientes Prioritarios</div>
    <div class="pdf-alerts">
      ${$('alerts-list').innerHTML}
    </div>

    <div class="pdf-section-title">Detalle de Juicios Evaluativos</div>
    <div class="pdf-table-wrap">
      <table>
        <thead>
          <tr>
            <th>Competencia</th>
            <th>Resultado</th>
            <th>Juicio</th>
            <th>Fecha</th>
          </tr>
        </thead>
        <tbody>
          ${Array.from($('modal-tbody').rows).map(row => `
            <tr>
              <td>${row.cells[0].textContent}</td>
              <td>${row.cells[1].textContent}</td>
              <td>${row.cells[2].textContent}</td>
              <td>${row.cells[3].textContent}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>

    <div class="pdf-footer">
      Este reporte sirve como soporte de seguimiento académico para el aprendiz.
    </div>
  `;

  const opt = {
    margin: [10, 10],
    filename: `Seguimiento_${nombre.replace(/\s+/g, '_')}.pdf`,
    image: { type: 'jpeg', quality: 0.98 },
    html2canvas: { scale: 2, useCORS: true },
    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
  };

  try {
    await html2pdf().set(opt).from(element).save();
  } catch (err) {
    console.error('Error generando PDF:', err);
    alert('No se pudo generar el PDF.');
  }
}
// ═══════════════════════════════════════════════════════════════════════════
//  MÓDULO FASES — completo
// ═══════════════════════════════════════════════════════════════════════════
 
let fasesData        = [];   // fases de la ficha activa
let faseSeleccionada = null; // objeto fase actualmente abierta
let resultadosFicha  = [];   // competencias+resultados para asignar
 
// ── Cargar fases cuando cambia la ficha ────────────────────────────────────
// Agregar esta línea dentro de la función onFichaChange() existente en app.js:
//   if (document.getElementById('page-fases').classList.contains('active')) loadFases();
//
// O bien, en setPage() cuando page === 'fases', llama loadFases().
// La forma más simple es agregar al final de onFichaChange():
 
const _origOnFichaChange = typeof onFichaChange === 'function' ? onFichaChange : null;
 
function loadFasesIfActive() {
  const pg = document.getElementById('page-fases');
  if (pg && pg.classList.contains('active')) loadFases();
}
 
// Hook: cuando cambie la ficha y estemos en la pestaña fases, recargar
document.addEventListener('DOMContentLoaded', function() {
  const sel = document.getElementById('g-ficha');
  if (sel) sel.addEventListener('change', loadFasesIfActive);
 
  // También al entrar a la pestaña
  const btn = document.querySelector('[onclick*="fases"]');
  if (btn) btn.addEventListener('click', () => setTimeout(loadFases, 50));
});
 
// ── Cargar todas las fases de la ficha ────────────────────────────────────
async function loadFases() {
  const idFicha = document.getElementById('g-ficha')?.value;
  const container = document.getElementById('fases-dashboard');
  const detalle = document.getElementById('fases-detalle');
 
  if (!idFicha) {
    container.innerHTML = '<p class="fases-placeholder">Selecciona una ficha para ver las fases.</p>';
    detalle.style.display = 'none';
    return;
  }
 
  container.innerHTML = '<p class="fases-placeholder">Cargando fases...</p>';
 
  try {
    const res = await fetch(`api_fases.php?action=fases_ficha&id_ficha=${idFicha}`);
    fasesData = await res.json();
 
    if (!fasesData.length) {
      container.innerHTML = `
        <div class="fases-placeholder">
          <p style="margin-bottom: 20px; font-size: 16px; color: var(--text);">Esta ficha aún no tiene fases definidas en su proyecto formativo.</p>
          <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
            <button class="btn btn-primary" onclick="generarFasesEstandarSENA()">
              <span style="margin-right:8px;">⚡</span> Autogenerar 4 Fases SENA (Análisis, Planeación, Ejecución, Evaluación)
            </button>
            <button class="btn btn-outline" onclick="abrirModalCrearFase()">+ Crear fase manual</button>
          </div>
        </div>
      `;
      detalle.style.display = 'none';
      return;
    }
 
    renderFasesOverview();
 
    // Si había una fase seleccionada, la volvemos a abrir
    if (faseSeleccionada) {
      const f = fasesData.find(x => x.id_fase == faseSeleccionada.id_fase);
      if (f) abrirDetalleFase(f);
    }
  } catch(e) {
    container.innerHTML = '<p class="fases-placeholder" style="color:#dc3545">Error al cargar fases.</p>';
  }
}

// ── Autogenerar fases estándar SENA ──────────────────────────────────────
async function generarFasesEstandarSENA() {
  const idFicha = document.getElementById('g-ficha')?.value;
  if (!idFicha) return;
  
  try {
    const res = await fetch('api_fases.php?action=generar_fases_sena', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'generar_fases_sena', id_ficha: idFicha })
    });
    const data = await res.json();
    if (data.error) {
      alert(data.error);
      return;
    }
    await loadFases();
  } catch (e) {
    alert('Error al generar las fases estándar.');
  }
}
 
// ── Renderizar las tarjetas de fases ─────────────────────────────────────
function renderFasesOverview() {
  const container = document.getElementById('fases-dashboard');
  container.innerHTML = '';
 
  fasesData.forEach(fase => {
    const pct = parseFloat(fase.pct_cumplimiento) || 0;
    const color = pct >= 80 ? '#16a34a' : pct >= 50 ? '#f59e0b' : '#dc3545';
    const isActive = faseSeleccionada && faseSeleccionada.id_fase == fase.id_fase;
 
    const card = document.createElement('div');
    card.className = 'fase-card' + (isActive ? ' active' : '');
    card.innerHTML = `
      <div class="fase-card-header">
        <h3>${fase.orden}. ${escHtml(fase.nombre)}</h3>
        <span class="fase-pct" style="color:${color}">${pct}%</span>
      </div>
      <div class="fase-prog-bar">
        <div class="fase-prog-fill" style="width:${pct}%;background:${color}"></div>
      </div>
      <div class="fase-meta">
        <span>📚 ${fase.total_resultados} resultado(s)</span>
        <span>✅ ${fase.aprendices_aprobados} aprendices completaron</span>
        <span>👥 ${fase.total_aprendices} total</span>
        ${fase.fecha_inicio ? `<span>📅 ${fase.fecha_inicio} → ${fase.fecha_fin || '?'}</span>` : ''}
        ${fase.descripcion ? `<span>💬 ${escHtml(fase.descripcion)}</span>` : ''}
      </div>
    `;
    card.addEventListener('click', () => abrirDetalleFase(fase));
    container.appendChild(card);
  });
}
 
// ── Abrir detalle de una fase ─────────────────────────────────────────────
async function abrirDetalleFase(fase) {
  faseSeleccionada = fase;
  renderFasesOverview(); // actualiza el resaltado
 
  const idFicha = document.getElementById('g-ficha')?.value;
  document.getElementById('fases-detalle').style.display = 'block';
  document.getElementById('fase-detalle-titulo').textContent = `${fase.orden}. ${fase.nombre}`;
  document.getElementById('fase-aprendices-tbody').innerHTML = '<tr><td colspan="5" class="loading">Cargando...</td></tr>';
  document.getElementById('fase-resultados-list').innerHTML = '<div class="loading">Cargando...</div>';
 
  try {
    const res = await fetch(`api_fases.php?action=detalle_fase&id_fase=${fase.id_fase}&id_ficha=${idFicha}`);
    const data = await res.json();
 
    // Resultados asignados
    const rList = document.getElementById('fase-resultados-list');
    if (!data.resultados.length) {
      rList.innerHTML = '<p style="color:#9ca3af;padding:12px;font-size:13px;">No hay resultados asignados a esta fase. Usa "Asignar resultados".</p>';
    } else {
      rList.innerHTML = data.resultados.map(r => `
        <div class="rank-item" style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;">
          <div>
            <div style="font-size:11px;color:#6b7280;">${escHtml(r.competencia)}</div>
            <div style="font-size:13px;font-weight:500;">${escHtml(r.resultado)}</div>
          </div>
          <button class="btn btn-outline btn-sm btn-danger" style="flex-shrink:0;"
            onclick="quitarResultadoFase(${fase.id_fase}, ${r.id_resultado})">✕</button>
        </div>
      `).join('');
    }
 
    // Aprendices
    const tbody = document.getElementById('fase-aprendices-tbody');
    if (!data.aprendices.length) {
      tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#9ca3af;padding:20px;">Sin datos (asigna resultados primero)</td></tr>';
    } else {
      tbody.innerHTML = data.aprendices.map(a => {
        const pct = parseFloat(a.pct_fase) || 0;
        const color = pct >= 80 ? '#16a34a' : pct >= 50 ? '#f59e0b' : '#dc3545';
        return `
          <tr>
            <td>${escHtml(a.aprendiz)}</td>
            <td><span class="badge ${badgeClass(a.estado)}">${a.estado}</span></td>
            <td style="text-align:center;">${a.aprobados}</td>
            <td style="text-align:center;">${a.pendientes}</td>
            <td>
              <div style="display:flex;align-items:center;gap:8px;">
                <div style="flex:1;height:6px;background:#e5e7eb;border-radius:99px;overflow:hidden;">
                  <div style="height:100%;width:${pct}%;background:${color};border-radius:99px;"></div>
                </div>
                <span style="font-size:12px;font-weight:700;color:${color};min-width:36px;text-align:right;">${pct}%</span>
              </div>
            </td>
          </tr>`;
      }).join('');
    }
 
    document.getElementById('fases-detalle').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
 
  } catch(e) {
    document.getElementById('fase-aprendices-tbody').innerHTML = '<tr><td colspan="5" style="color:#dc3545">Error al cargar detalle.</td></tr>';
  }
}
 
// ── Badge helper (reutiliza el que ya existe en app.js si lo tiene) ────────
function badgeClass(estado) {
  if (estado === 'EN FORMACION')     return 'badge-green';
  if (estado === 'RETIRO VOLUNTARIO') return 'badge-red';
  if (estado === 'TRASLADADO')       return 'badge-amber';
  return 'badge-gray';
}
function escHtml(str) {
  return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
 
// ── Modal crear fase ──────────────────────────────────────────────────────
function abrirModalCrearFase() {
  const idFicha = state.fichaId || document.getElementById('g-ficha')?.value;
  if (!idFicha) {
    alert('Por favor, selecciona una ficha antes de crear una fase.');
    return;
  }
  document.getElementById('mf-id-fase').value = '';
  document.getElementById('mf-nombre').value = '';
  document.getElementById('mf-descripcion').value = '';
  document.getElementById('mf-fecha-inicio').value = '';
  document.getElementById('mf-fecha-fin').value = '';
  document.getElementById('mf-error').style.display = 'none';
  document.getElementById('modal-fase-titulo').textContent = 'Nueva fase';
  document.getElementById('modal-fase-overlay').style.display = 'flex';
}
 
function abrirModalEditarFase() {
  if (!faseSeleccionada) return;
  document.getElementById('mf-id-fase').value = faseSeleccionada.id_fase;
  document.getElementById('mf-nombre').value = faseSeleccionada.nombre;
  document.getElementById('mf-descripcion').value = faseSeleccionada.descripcion || '';
  document.getElementById('mf-fecha-inicio').value = faseSeleccionada.fecha_inicio || '';
  document.getElementById('mf-fecha-fin').value = faseSeleccionada.fecha_fin || '';
  document.getElementById('mf-error').style.display = 'none';
  document.getElementById('modal-fase-titulo').textContent = 'Editar fase';
  document.getElementById('modal-fase-overlay').style.display = 'flex';
}
 
function cerrarModalFase(e) {
  if (e.target.id === 'modal-fase-overlay') document.getElementById('modal-fase-overlay').style.display = 'none';
}
 
async function guardarFase() {
  const idFase = document.getElementById('mf-id-fase').value;
  const nombre = document.getElementById('mf-nombre').value.trim();
  const errEl  = document.getElementById('mf-error');
  if (!nombre) { errEl.textContent = 'El nombre es obligatorio.'; errEl.style.display='block'; return; }
 
  const idFicha = state.fichaId || document.getElementById('g-ficha')?.value;
  if (!idFicha) { errEl.textContent = 'Falta seleccionar la ficha.'; errEl.style.display='block'; return; }
  const action  = idFase ? 'editar_fase' : 'crear_fase';
  const body    = {
    action,
    id_ficha:      idFicha,
    id_fase:       idFase,
    nombre,
    descripcion:   document.getElementById('mf-descripcion').value,
    fecha_inicio:  document.getElementById('mf-fecha-inicio').value,
    fecha_fin:     document.getElementById('mf-fecha-fin').value,
  };
 
  try {
    const res  = await fetch('api_fases.php?action=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
    const data = await res.json();
    if (data.error) { errEl.textContent = data.error; errEl.style.display='block'; return; }
    document.getElementById('modal-fase-overlay').style.display = 'none';
    faseSeleccionada = null;
    await loadFases();
  } catch(e) {
    errEl.textContent = 'Error de red. Intenta de nuevo.';
    errEl.style.display = 'block';
  }
}
 
// ── Eliminar fase ─────────────────────────────────────────────────────────
async function confirmarEliminarFase() {
  if (!faseSeleccionada) return;
  if (!confirm(`¿Eliminar la fase "${faseSeleccionada.nombre}"?\nSe quitarán también los resultados asignados. Los juicios no se eliminan.`)) return;
 
  await fetch('api_fases.php?action=eliminar_fase', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'eliminar_fase', id_fase: faseSeleccionada.id_fase })
  });
  faseSeleccionada = null;
  document.getElementById('fases-detalle').style.display = 'none';
  await loadFases();
}
 
// ── Modal asignar resultados ──────────────────────────────────────────────
async function abrirModalAsignar() {
  if (!faseSeleccionada) return;
  const idFicha = document.getElementById('g-ficha')?.value;
 
  document.getElementById('asignar-busqueda').value = '';
  document.getElementById('asignar-lista').innerHTML = '<p style="color:#9ca3af;padding:12px;">Cargando...</p>';
  document.getElementById('modal-asignar-overlay').style.display = 'flex';
 
  const res = await fetch(`api_fases.php?action=resultados_ficha&id_ficha=${idFicha}`);
  resultadosFicha = await res.json();
  renderAsignarLista();
}
 
function cerrarModalAsignar(e) {
  if (e.target.id === 'modal-asignar-overlay') {
    cerrarModalAsignarBtn();
  }
}

async function cerrarModalAsignarBtn() {
  document.getElementById('modal-asignar-overlay').style.display = 'none';
  if (faseSeleccionada) {
    await abrirDetalleFase(faseSeleccionada);
    await loadFases();
  }
}
 
function filtrarAsignar() {
  renderAsignarLista(document.getElementById('asignar-busqueda').value.toLowerCase());
}
 
function renderAsignarLista(filtro = '') {
  const lista = document.getElementById('asignar-lista');
  let html = '';
 
  resultadosFicha.forEach(comp => {
    const resultsFiltrados = comp.resultados.filter(r =>
      !filtro ||
      r.resultado.toLowerCase().includes(filtro) ||
      comp.competencia.toLowerCase().includes(filtro)
    );
    if (!resultsFiltrados.length) return;
 
    html += `<div class="asignar-comp-header">📂 ${escHtml(comp.competencia)}</div>`;
    resultsFiltrados.forEach(r => {
      const enEstaFase  = r.id_fase_asignada == faseSeleccionada.id_fase;
      const enOtraFase  = r.id_fase_asignada && r.id_fase_asignada != faseSeleccionada.id_fase;
      const faseNombre  = enOtraFase
        ? (fasesData.find(f => f.id_fase == r.id_fase_asignada)?.nombre || 'otra fase')
        : '';
 
      html += `
        <label class="asignar-item${enOtraFase ? ' otro-fase' : ''}" title="${enOtraFase ? 'Ya asignado a: '+faseNombre : ''}">
          <input type="checkbox"
            ${enEstaFase ? 'checked' : ''}
            ${enOtraFase ? 'disabled' : ''}
            data-id-resultado="${r.id_resultado}"
            data-id-comp="${comp.id_competencia}"
            onchange="toggleResultadoFase(this)">
          <div>
            <span class="asignar-resultado">${escHtml(r.resultado)}</span>
            ${enOtraFase ? `<span class="asignar-comp">⚠ Asignado a: ${escHtml(faseNombre)}</span>` : ''}
          </div>
        </label>`;
    });
  });
 
  lista.innerHTML = html || '<p style="color:#9ca3af;padding:12px;">No hay resultados disponibles.</p>';
}
 
async function toggleResultadoFase(checkbox) {
  const idResultado = parseInt(checkbox.dataset.idResultado);
  const idComp      = parseInt(checkbox.dataset.idComp);
  const action      = checkbox.checked ? 'asignar_resultado' : 'quitar_resultado';
 
  checkbox.disabled = true;
  try {
    const res  = await fetch(`api_fases.php?action=${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action,
        id_fase:       faseSeleccionada.id_fase,
        id_resultado:  idResultado,
        id_competencia: idComp
      })
    });
    const data = await res.json();
    if (data.error) {
      alert(data.error);
      checkbox.checked = !checkbox.checked;
    } else {
      // Actualizar resultadosFicha local
      resultadosFicha.forEach(c => {
        c.resultados.forEach(r => {
          if (r.id_resultado === idResultado) {
            r.id_fase_asignada = checkbox.checked ? faseSeleccionada.id_fase : null;
          }
        });
      });
    }
  } catch(e) {
    alert('Error de red.');
    checkbox.checked = !checkbox.checked;
  }
  checkbox.disabled = false;
}
 
async function quitarResultadoFase(idFase, idResultado) {
  if (!confirm('¿Quitar este resultado de la fase?')) return;
  await fetch('api_fases.php?action=quitar_resultado', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'quitar_resultado', id_fase: idFase, id_resultado: idResultado })
  });
  await abrirDetalleFase(faseSeleccionada);
  await loadFases();
}
