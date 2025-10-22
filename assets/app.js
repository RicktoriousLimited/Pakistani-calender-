async function fetchJSON(url){
  const r = await fetch(url);
  if (!r.ok) {
    const text = await r.text();
    throw new Error(`HTTP ${r.status}: ${text || r.statusText}`);
  }
  return r.json();
}
async function fetchSchedule(params={}){
  const qs = new URLSearchParams(params);
  return fetchJSON(`${API_BASE}?route=schedule&${qs.toString()}`);
}
async function fetchDivisions(){
  try {
    const j=await fetchJSON(`${API_BASE}?route=divisions`);
    return j.divisions||[];
  } catch (err) {
    console.error('Failed to load divisions', err);
    return [];
  }
}
function fmt(iso){ if(!iso) return '—'; const d=new Date(iso); if(Number.isNaN(d.getTime())) return '—'; return d.toLocaleString(undefined,{dateStyle:'medium',timeStyle:'short'}); }
function textOrDash(value){ if(value===undefined||value===null) return '—'; const str=String(value).trim(); return str!==''?str:'—'; }
function deriveDurationHours(it){
  if (typeof it.durationHours === 'number' && Number.isFinite(it.durationHours) && it.durationHours > 0) {
    return Math.round(it.durationHours * 10) / 10;
  }
  if (!it.start || !it.end) {
    return null;
  }
  const start = new Date(it.start);
  const end = new Date(it.end);
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
    return null;
  }
  const diff = (end.getTime() - start.getTime()) / 3600000;
  if (!Number.isFinite(diff) || diff <= 0) {
    return null;
  }
  return Math.round(diff * 10) / 10;
}
function formatDuration(it){
  const hours = deriveDurationHours(it);
  if (hours === null) {
    return '—';
  }
  const opts = { maximumFractionDigits: hours >= 10 ? 0 : 1, minimumFractionDigits: 0 };
  return `${hours.toLocaleString(undefined, opts)} h`;
}
function groupBy(items, keyFn){
  const m = new Map();
  for (const it of items){ const k=keyFn(it); if(!m.has(k)) m.set(k, []); m.get(k).push(it); }
  return m;
}
function renderTable(items){
  return `<table class="table table-sm align-middle mb-0">
    <thead><tr><th>Area</th><th>Division</th><th>Feeder</th><th>Start</th><th>End</th><th>Duration</th><th>Type</th><th>Reason</th></tr></thead>
    <tbody>
      ${items.map(it=>`
        <tr>
          <td>${textOrDash(it.area)}</td>
          <td class="text-muted">${textOrDash(it.division)}</td>
          <td class="text-muted">${textOrDash(it.feeder)}</td>
          <td>${fmt(it.start)}</td>
          <td>${fmt(it.end)}</td>
          <td>${formatDuration(it)}</td>
          <td><span class="badge bg-${badge(it.type)}">${(it.type||'scheduled').toUpperCase()}</span></td>
          <td class="text-muted">${textOrDash(it.reason)}</td>
        </tr>`).join('')}
    </tbody></table>`;
}
function badge(t){ const tt=(t||'scheduled').toLowerCase(); if(tt.includes('force')) return 'danger'; if(tt.includes('maint')) return 'warning'; return 'info'; }
function paginate(arr, page=1, per=30){ const start=(page-1)*per; return arr.slice(start,start+per); }
function pager(total, page, per){
  const pages=Math.max(1, Math.ceil(total/per));
  let html=''; for(let i=1;i<=pages;i++){ html+=`<li class="page-item ${i===page?'active':''}"><a class="page-link" data-page="${i}">${i}</a></li>`; }
  return html;
}
let currentItems=[], currentPage=1, perPage=30, totalItems=0;
function render(items, updatedAt, total){
  currentItems = items;
  currentPage = 1;
  totalItems = total ?? items.length;
  renderPage();
  document.getElementById('meta').textContent = `Updated: ${updatedAt ? new Date(updatedAt).toLocaleString() : '—'} • Showing ${items.length} of ${totalItems} result(s)`;
}
function renderPage(){
  const pageItems = paginate(currentItems, currentPage, perPage);
  const container = document.getElementById('grouped');
  container.innerHTML='';
  if (!pageItems.length){
    const empty = document.createElement('div');
    empty.className='alert alert-info';
    empty.textContent='No shutdowns found for the selected filters.';
    container.appendChild(empty);
  } else {
    const byDate = groupBy(pageItems, it => (it.start||'').slice(0,10));
    for (const [date, arr] of byDate.entries()){
      const block = document.createElement('div'); block.className='grp';
      const h = document.createElement('div'); h.className='thead'; h.textContent = date || 'No date';
      block.appendChild(h);
      block.insertAdjacentHTML('beforeend', renderTable(arr));
      container.appendChild(block);
    }
  }
  document.getElementById('pager').innerHTML = pager(currentItems.length, currentPage, perPage);
  document.querySelectorAll('#pager .page-link').forEach(a=>a.addEventListener('click', e=>{
    currentPage = parseInt(e.target.getAttribute('data-page'),10); renderPage();
  }));
}
async function go(){
  const q=document.getElementById('q').value;
  const area=document.getElementById('area').value;
  const feeder=document.getElementById('feeder').value;
  const date=document.getElementById('date').value;
  const division=document.getElementById('division').value;
  const meta=document.getElementById('meta');
  meta.textContent='Loading…';
  try {
    const {items=[], updatedAt=null, total=0} = await fetchSchedule({q, area, feeder, date, division});
    render(items, updatedAt, total);
  } catch (err) {
    console.error(err);
    meta.textContent = `Error loading schedule: ${err.message}`;
    document.getElementById('grouped').innerHTML='';
    document.getElementById('pager').innerHTML='';
  }
}
async function initDivisions(){
  const divs = await fetchDivisions();
  const sel=document.getElementById('division');
  divs.forEach(d=>{ const opt=document.createElement('option'); opt.value=d; opt.textContent=d; sel.appendChild(opt); });
}
document.getElementById('searchBtn').addEventListener('click', go);
['q','area','feeder'].forEach(id=>{
  document.getElementById(id).addEventListener('keydown',e=>{ if(e.key==='Enter'){ e.preventDefault(); go(); }});
});
document.getElementById('date').addEventListener('change', go);
document.getElementById('division').addEventListener('change', go);
window.shutdownHelpers = Object.assign({}, window.shutdownHelpers || {}, {
  formatDate: fmt,
  badgeClass: badge,
});
function boot(){ initDivisions().then(go); }
if (document.readyState === 'loading') {
  window.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}
