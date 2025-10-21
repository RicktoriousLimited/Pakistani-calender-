<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use SHUTDOWN\Util\Store;

$store = new Store(__DIR__ . DIRECTORY_SEPARATOR . 'storage');
$meta = $store->meta();
$cfg = $store->readConfig();
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin — Shutdown</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
  <div class="container">
    <a class="navbar-brand fw-bold" href="index.php">Shutdown</a>
    <span class="navbar-text">Admin Console</span>
  </div>
</nav>
<main class="container py-4">
  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
              <h5 class="card-title mb-1">Data Controls</h5>
              <p class="text-muted small mb-0">Last schedule update: <strong><?php echo htmlspecialchars($meta['mtime'] ?? '—'); ?></strong></p>
              <p class="text-muted small">Current file size: <?php echo number_format((int)($meta['size'] ?? 0)); ?> bytes</p>
            </div>
            <div class="text-end small">
              <span class="badge bg-success"><?php echo count($cfg['sources'] ?? []); ?> sources configured</span>
            </div>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <button id="btnIngest" class="btn btn-primary"><i class="bi bi-arrow-repeat me-1"></i>Fetch Latest</button>
            <button id="btnProbe" class="btn btn-outline-secondary">Probe Sources</button>
            <a class="btn btn-outline-dark" href="api.php?route=backup">Backup Storage (ZIP)</a>
            <a class="btn btn-outline-dark" href="storage/schedule.json" target="_blank">Download JSON</a>
          </div>
          <pre id="ingestOut" class="mt-3 small bg-light border rounded p-3" style="min-height:120px;">Ready.</pre>
        </div>
      </div>

      <div class="card shadow-sm border-0 mt-3">
        <div class="card-body">
          <h5 class="card-title">Config (Sources)</h5>
          <p class="small text-muted">Edit source definitions and toggles. Invalid JSON is rejected with an error.</p>
          <form id="cfgForm" class="row g-2">
            <div class="col-12">
              <label class="form-label">Config JSON</label>
              <textarea id="cfg" class="form-control" rows="10"><?php echo htmlspecialchars(json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea>
            </div>
            <div class="col-12"><button class="btn btn-success">Save Config</button></div>
          </form>
          <pre id="cfgOut" class="small mt-2 bg-light border rounded p-2"></pre>
        </div>
      </div>

      <div class="card shadow-sm border-0 mt-3">
        <div class="card-body">
          <h5 class="card-title">Add Single Entry</h5>
          <p class="small text-muted">Perfect for ad-hoc shutdowns. Entries land in <code>manual.csv</code> and merge on the next fetch.</p>
          <form id="addForm" class="row g-2">
            <div class="col-md-4"><label class="form-label small">Area</label><input class="form-control" name="area" placeholder="Area" required></div>
            <div class="col-md-4"><label class="form-label small">Feeder</label><input class="form-control" name="feeder" placeholder="Feeder" required></div>
            <div class="col-md-4"><label class="form-label small">Type</label><input class="form-control" name="type" placeholder="Type" value="scheduled"></div>
            <div class="col-md-6"><label class="form-label small">Start</label><input class="form-control" name="start" placeholder="YYYY-MM-DDTHH:MM:SS+05:00" required></div>
            <div class="col-md-6"><label class="form-label small">End</label><input class="form-control" name="end" placeholder="YYYY-MM-DDTHH:MM:SS+05:00"></div>
            <div class="col-12"><label class="form-label small">Reason</label><input class="form-control" name="reason" placeholder="Reason"></div>
            <div class="col-12"><button class="btn btn-success">Append to Manual</button></div>
          </form>
          <pre id="addOut" class="small mt-2 bg-light border rounded p-2"></pre>
        </div>
      </div>

      <div class="card shadow-sm border-0 mt-3">
        <div class="card-body">
          <h5 class="card-title">Manual CSV Upload</h5>
          <p class="small text-muted mb-2">Upload a CSV to replace <code>manual.csv</code>. Expected headers: <code>utility,area,feeder,start,end,type,reason,source,url,confidence</code>.</p>
          <form method="post" action="admin_upload.php" enctype="multipart/form-data" class="d-flex flex-wrap gap-2">
            <input type="file" name="csv" accept=".csv" class="form-control flex-grow-1">
            <button class="btn btn-dark">Upload</button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <h5 class="card-title">History &amp; Changes</h5>
          <div class="input-group input-group-sm mb-2">
            <span class="input-group-text">Day</span>
            <input id="histDay" type="date" class="form-control">
            <button id="btnHistory" class="btn btn-outline-secondary">View</button>
          </div>
          <pre id="histOut" class="small bg-light border rounded p-2" style="max-height:220px;overflow:auto"></pre>
          <div class="d-flex justify-content-between align-items-center mt-2">
            <button id="btnChanges" class="btn btn-sm btn-outline-secondary">Show Change Log</button>
            <span class="small text-muted">Latest 50 entries</span>
          </div>
          <pre id="changesOut" class="small bg-light border rounded p-2 mt-2" style="max-height:220px;overflow:auto"></pre>
        </div>
      </div>

      <div class="card shadow-sm border-0 mt-3">
        <div class="card-body">
          <h5 class="card-title">Quick Stats</h5>
          <ul class="list-unstyled small mb-0">
            <li><span class="fw-semibold">Timezone:</span> <?php echo htmlspecialchars($cfg['timezone'] ?? 'Asia/Karachi'); ?></li>
            <?php foreach (($cfg['sources'] ?? []) as $name => $info): ?>
              <li><span class="fw-semibold text-capitalize"><?php echo htmlspecialchars($name); ?>:</span> <?php echo !empty($info['enabled']) ? '<span class="text-success">enabled</span>' : '<span class="text-muted">disabled</span>'; ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
  </div>
</main>
<script>const API_BASE='api.php';</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/admin.js"></script>
</body>
</html>
