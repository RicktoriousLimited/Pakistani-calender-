(function () {
  let chart = null;
  let loaded = false;
  let loading = false;

  function formatDate(iso, options = {}) {
    if (!iso) {
      return '—';
    }
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
      return '—';
    }
    const opts = Object.assign({ dateStyle: 'medium', timeStyle: 'short' }, options);
    return date.toLocaleString(undefined, opts);
  }

  function formatDay(iso) {
    if (!iso) {
      return '—';
    }
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
      return '—';
    }
    return date.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
  }

  function formatHours(val) {
    if (typeof val !== 'number' || !Number.isFinite(val)) {
      return '0 h';
    }
    const rounded = Math.round(val * 10) / 10;
    return `${rounded.toLocaleString(undefined, { maximumFractionDigits: 1, minimumFractionDigits: 0 })} h`;
  }

  function badgeClass(type) {
    const fallback = (window.shutdownHelpers && window.shutdownHelpers.badgeClass) || function (t) {
      const tt = (t || 'scheduled').toLowerCase();
      if (tt.includes('force')) return 'danger';
      if (tt.includes('maint')) return 'warning';
      return 'info';
    };
    return fallback(type);
  }

  function renderSummary(totals, windowInfo) {
    document.getElementById('insightCount').textContent = (totals.count ?? 0).toLocaleString();
    document.getElementById('insightHours').textContent = formatHours(totals.totalHours ?? 0);
    document.getElementById('insightSoon').textContent = (totals.within24h ?? 0).toLocaleString();
    document.getElementById('insightAreas').textContent = (totals.areas ?? 0).toLocaleString();
    const range = `${formatDay(windowInfo.start)} → ${formatDay(windowInfo.end)}`;
    document.getElementById('insightWindow').textContent = range;
  }

  function renderMeta(data) {
    const meta = document.getElementById('insightsMeta');
    const updated = data.scheduleUpdatedAt ? formatDate(data.scheduleUpdatedAt) : '—';
    meta.textContent = `Window: ${formatDay(data.window.start)} → ${formatDay(data.window.end)} • Schedule updated: ${updated}`;
  }

  function renderDaily(daily) {
    const body = document.getElementById('insightDaily');
    if (!body) return;
    if (!daily.length) {
      body.innerHTML = '<tr><td colspan="4" class="text-muted text-center">No outages in the next seven days.</td></tr>';
      return;
    }
    body.innerHTML = daily.map(row => `
      <tr>
        <td>${row.label}</td>
        <td>${row.count}</td>
        <td>${formatHours(row.totalHours)}</td>
        <td>${row.distinctAreas}</td>
      </tr>
    `).join('');
  }

  function renderUpcoming(list) {
    const container = document.getElementById('insightUpcoming');
    if (!container) return;
    if (!list.length) {
      container.innerHTML = '<p class="text-muted mb-0">No future outages within the selected window.</p>';
      return;
    }
    container.innerHTML = list.map(item => {
      const badge = badgeClass(item.type);
      const start = formatDate(item.start);
      const end = item.end ? formatDate(item.end) : '—';
      const division = item.division ? `<span class="text-muted">${item.division}</span>` : '';
      const feeder = item.feeder ? `<div class="text-muted">Feeder: ${item.feeder}</div>` : '';
      return `
        <div class="insight-upcoming">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="fw-semibold">${item.area}</div>
              ${division}
            </div>
            <span class="badge bg-${badge}">${(item.type || 'scheduled').toUpperCase()}</span>
          </div>
          <div class="small text-muted">${start} → ${end}</div>
          <div class="small">Duration: ${formatHours(item.hours ?? 0)}</div>
          ${feeder}
          ${item.reason ? `<div class="text-muted fst-italic">${item.reason}</div>` : ''}
        </div>
      `;
    }).join('');
  }

  function renderDivisions(divisions) {
    const container = document.getElementById('insightDivisions');
    if (!container) return;
    if (!divisions.length) {
      container.innerHTML = '<li class="list-group-item text-muted">No division level insights available.</li>';
      return;
    }
    container.innerHTML = divisions.map(div => `
      <li class="list-group-item d-flex justify-content-between align-items-center">
        <span>${div.division}</span>
        <span class="badge bg-primary rounded-pill">${div.count}</span>
      </li>
    `).join('');
  }

  function renderTypes(types) {
    const container = document.getElementById('insightTypes');
    if (!container) return;
    if (!types.length) {
      container.innerHTML = '<p class="text-muted mb-0">No outage types detected.</p>';
      return;
    }
    container.innerHTML = types.map(type => {
      const share = Math.min(100, Math.max(0, type.share ?? 0));
      return `
        <div class="mb-2">
          <div class="d-flex justify-content-between"><span>${type.type}</span><span>${type.count}</span></div>
          <div class="progress insight-progress">
            <div class="progress-bar" role="progressbar" style="width:${share}%" aria-valuenow="${share}" aria-valuemin="0" aria-valuemax="100">${share}%</div>
          </div>
        </div>
      `;
    }).join('');
  }

  function renderLongest(entries) {
    const container = document.getElementById('insightLongest');
    if (!container) return;
    if (!entries.length) {
      container.innerHTML = '<p class="text-muted mb-0">No extended outages scheduled.</p>';
      return;
    }
    container.innerHTML = entries.map(item => {
      const badge = badgeClass(item.type);
      return `
        <div class="insight-upcoming">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="fw-semibold">${item.area}</div>
              ${item.division ? `<span class="text-muted">${item.division}</span>` : ''}
            </div>
            <span class="badge bg-${badge}">${(item.type || 'scheduled').toUpperCase()}</span>
          </div>
          <div class="small text-muted">${formatDate(item.start)} → ${formatDate(item.end)}</div>
          <div class="small">Duration: ${formatHours(item.hours ?? 0)}</div>
          ${item.reason ? `<div class="text-muted fst-italic">${item.reason}</div>` : ''}
        </div>
      `;
    }).join('');
  }

  function renderChart(daily) {
    const canvas = document.getElementById('insightChart');
    if (!canvas) return;
    if (chart) {
      chart.destroy();
      chart = null;
    }
    const ctx = canvas.getContext('2d');
    if (!daily.length) {
      if (ctx) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.save();
        ctx.fillStyle = '#6c757d';
        ctx.font = '14px sans-serif';
        ctx.fillText('No data available for this window.', 10, 24);
        ctx.restore();
      }
      return;
    }
    const labels = daily.map(row => row.label);
    const counts = daily.map(row => row.count);
    const hours = daily.map(row => row.totalHours);
    chart = new Chart(ctx, {
      data: {
        labels,
        datasets: [
          {
            type: 'bar',
            label: 'Outages',
            data: counts,
            backgroundColor: 'rgba(13,110,253,0.65)',
            borderColor: 'rgba(13,110,253,1)',
            borderRadius: 8,
            maxBarThickness: 32,
            yAxisID: 'y',
          },
          {
            type: 'line',
            label: 'Total hours',
            data: hours,
            borderColor: '#f59f00',
            backgroundColor: 'rgba(245,159,0,0.3)',
            tension: 0.35,
            fill: false,
            yAxisID: 'y1',
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: true,
            ticks: { precision: 0 }
          },
          y1: {
            position: 'right',
            beginAtZero: true,
            grid: { drawOnChartArea: false },
            title: { display: true, text: 'Hours' }
          }
        },
        plugins: {
          legend: { display: true },
          tooltip: {
            callbacks: {
              label: function (context) {
                if (context.dataset.label === 'Total hours') {
                  return `${context.dataset.label}: ${formatHours(context.parsed.y)}`;
                }
                return `${context.dataset.label}: ${context.parsed.y}`;
              }
            }
          }
        }
      }
    });
  }

  function renderAll(data) {
    renderMeta(data);
    renderSummary(data.totals || {}, data.window || {});
    renderDaily(data.daily || []);
    renderUpcoming(data.upcoming || []);
    renderDivisions(data.divisions || []);
    renderTypes(data.types || []);
    renderLongest(data.longest || []);
    renderChart(data.daily || []);
  }

  async function load(force = false) {
    if (loading) {
      return;
    }
    if (!force && loaded) {
      return;
    }
    loading = true;
    const meta = document.getElementById('insightsMeta');
    meta.textContent = 'Loading forecast…';
    try {
      const res = await fetch('api.php?route=forecast');
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }
      const data = await res.json();
      if (!data.ok) {
        throw new Error(data.error || 'Unknown error');
      }
      renderAll(data);
      loaded = true;
      meta.textContent = `Forecast generated: ${formatDate(data.generatedAt)}`;
    } catch (err) {
      meta.textContent = `Unable to load forecast: ${err.message}`;
    } finally {
      loading = false;
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    const tabBtn = document.querySelector('button[data-bs-target="#insights"]');
    if (tabBtn) {
      tabBtn.addEventListener('shown.bs.tab', function () {
        load();
      });
    }
    const tabPane = document.getElementById('insights');
    if (tabPane && tabPane.classList.contains('show')) {
      load();
    }
    const refresh = document.getElementById('refreshInsights');
    if (refresh) {
      refresh.addEventListener('click', function () {
        load(true);
      });
    }
  });
})();
