let map, markersLayer, polygonsLayer;
function badgeForType(t){ const tt=(t||'scheduled').toLowerCase(); if(tt.includes('force')) return 'danger'; if(tt.includes('maint')) return 'warning'; return 'info'; }
function colorForType(t){ const tt=(t||'scheduled').toLowerCase(); if(tt.includes('force')) return '#dc3545'; if(tt.includes('maint')) return '#ffc107'; return '#0dcaf0'; }
function hoursBetween(a,b){ const A=a?new Date(a).getTime():0; const B=b?new Date(b).getTime():0; if(!A||!B) return 1; return Math.max(1,(B-A)/3600000); }
async function loadJSON(path){ const r=await fetch(path); if(!r.ok) return null; return r.json(); }
async function draw(){
  const geo = await loadJSON('../storage/geo.json') || {};
  const poly = await loadJSON('../storage/polygons.geojson');
  const data = await (await fetch('api.php?route=schedule')).json();
  const {items, updatedAt} = data;
  document.getElementById('mapMeta').textContent = `Updated: ${updatedAt ? new Date(updatedAt).toLocaleString() : '—'} • ${items.length} item(s)`;
  if (!map){
    map = L.map('map').setView([31.5204,74.3587], 11);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'&copy; OpenStreetMap'}).addTo(map);
    polygonsLayer=L.layerGroup().addTo(map); markersLayer=L.layerGroup().addTo(map);
  }
  polygonsLayer.clearLayers(); markersLayer.clearLayers();
  const bounds=[];
  if (poly){
    L.geoJSON(poly,{style:()=>({color:'#0d6efd',weight:1,fillOpacity:0.08}),onEachFeature:(f,l)=>{ if(f.properties&&f.properties.name) l.bindPopup(`<b>${f.properties.name}</b>`);} }).addTo(polygonsLayer);
  }
  items.forEach(it=>{
    const key=(it.area||'').toLowerCase().trim();
    const g=geo[key]||{lat:31.5204,lng:74.3587};
    const radius=Math.min(hoursBetween(it.start,it.end)*200,1200);
    const color=colorForType(it.type);
    const circle=L.circle([g.lat,g.lng],{radius,color,fillColor:color,fillOpacity:0.35,weight:1}).bindPopup(`
      <div><b>${it.area||'—'}</b> — <span class="badge bg-${badgeForType(it.type)}">${it.type||'scheduled'}</span></div>
      <div class="small text-muted">${it.feeder||''}</div>
      <div class="small"><b>Start:</b> ${it.start?new Date(it.start).toLocaleString():'—'}</div>
      <div class="small"><b>End:</b> ${it.end?new Date(it.end).toLocaleString():'—'}</div>
      <div class="small">${it.reason||''}</div>`);
    circle.addTo(markersLayer); bounds.push([g.lat,g.lng]);
  });
  if (bounds.length) map.fitBounds(bounds,{padding:[20,20]});
}
document.getElementById('refreshMap')?.addEventListener('click', draw);
document.addEventListener('DOMContentLoaded', draw);
