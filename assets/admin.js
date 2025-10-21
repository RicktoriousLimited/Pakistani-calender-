// assets/admin.js
function pretty(data){ return JSON.stringify(data, null, 2); }

async function call(route, opts = {}) {
  const res = await fetch(`api.php?route=${route}`, opts);
  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch {
    throw new Error(`HTTP ${res.status} ${res.statusText}\n\n${text}`);
  }
}

function renderReport(out, data){
  if (data.report){
    const sources = Object.entries(data.report.sources || {}).map(([name, info]) => {
      if (!info) return `${name}: n/a`;
      if (info.ok === false) return `${name}: error — ${info.error}`;
      return `${name}: ${info.count} item(s)`;
    }).join('\n');
    out.textContent = `Total: ${data.count ?? data.report.total ?? '?'}\nGenerated: ${data.updatedAt || data.report.generatedAt}\n${sources}`;
  } else {
    out.textContent = pretty(data);
  }
}

document.getElementById('btnIngest').addEventListener('click', async () => {
  const out = document.getElementById('ingestOut');
  out.textContent = 'Fetching…';
  try {
    const data = await call('ingest');
    renderReport(out, data);
  } catch (e) {
    out.textContent = String(e);
  }
});

document.getElementById('btnProbe').addEventListener('click', async () => {
  const out = document.getElementById('ingestOut');
  out.textContent = 'Probing…';
  try {
    const data = await call('probe');
    renderReport(out, data);
  } catch (e) {
    out.textContent = String(e);
  }
});

document.getElementById('cfgForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const out = document.getElementById('cfgOut');
  const textarea = document.getElementById('cfg');
  const jsonStr = textarea.value.trim();

  try {
    JSON.parse(jsonStr);
  } catch (err) {
    out.textContent = 'Invalid JSON: ' + err.message;
    return;
  }

  try {
    const res = await fetch('api.php?route=config', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: jsonStr
    });
    const text = await res.text();
    try {
      const j = JSON.parse(text);
      out.textContent = pretty(j);
    } catch {
      out.textContent = `Server returned non-JSON (HTTP ${res.status}). Body:\n${text}`;
    }
  } catch (err) {
    out.textContent = 'Request failed: ' + err.message;
  }
});

document.getElementById('addForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const out = document.getElementById('addOut');
  const payload = Object.fromEntries(new FormData(e.target).entries());
  try {
    const res = await fetch('api.php?route=addManual', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const text = await res.text();
    try {
      out.textContent = pretty(JSON.parse(text));
    } catch {
      out.textContent = `Server returned non-JSON (HTTP ${res.status}). Body:\n${text}`;
    }
    e.target.reset();
  } catch (err) {
    out.textContent = 'Request failed: ' + err.message;
  }
});

document.getElementById('btnHistory').addEventListener('click', async () => {
  const day = document.getElementById('histDay').value || new Date().toISOString().slice(0,10);
  const out = document.getElementById('histOut');
  out.textContent = 'Loading…';
  try {
    const data = await call(`history&day=${encodeURIComponent(day)}`);
    out.textContent = data.items?.length ? pretty(data.items) : 'No entries for this day.';
  } catch (err) {
    out.textContent = String(err);
  }
});

document.getElementById('btnChanges').addEventListener('click', async () => {
  const out = document.getElementById('changesOut');
  out.textContent = 'Loading…';
  try {
    const data = await call('changelog');
    out.textContent = data.entries?.length ? pretty(data.entries) : 'Change log is empty.';
  } catch (err) {
    out.textContent = String(err);
  }
});
