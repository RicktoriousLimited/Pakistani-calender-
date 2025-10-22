<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use SHUTDOWN\Util\Store;

$store = new Store(__DIR__ . DIRECTORY_SEPARATOR . 'storage');
$meta = $store->meta();
$cfg = $store->readConfig();
$sourceCount = count($cfg['sources'] ?? []);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin — Shutdown</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="d-flex flex-column min-vh-100">
<nav class="navbar navbar-expand-lg navbar-dark site-navbar">
  <div class="container">
    <a class="navbar-brand" href="index.php">
      <span class="brand-mark">Shutdown</span>
      <span class="brand-subtitle d-none d-md-inline">LESCO outage intelligence</span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#primaryNav" aria-controls="primaryNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="primaryNav">
      <ul class="navbar-nav ms-auto align-items-lg-center">
        <li class="nav-item"><a class="nav-link" href="index.php">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="sources.php">Sources</a></li>
        <li class="nav-item"><a class="nav-link active" href="admin.php">Admin</a></li>
      </ul>
    </div>
  </div>
</nav>
<header class="page-hero">
  <div class="container">
    <div class="page-hero-layout">
      <div class="page-hero-content">
        <span class="page-hero-eyebrow"><i class="bi bi-speedometer2"></i> Operations control</span>
        <h1 class="page-hero-title display-5">Administration console</h1>
        <p class="page-hero-lead">Run ingestion, manage config and capture manual overrides for the LESCO shutdown dataset. Every action is logged for traceability.</p>
        <div class="page-hero-actions">
          <a class="btn btn-brand" href="api.php?route=backup"><i class="bi bi-cloud-arrow-down me-2"></i>Backup storage</a>
          <a class="btn btn-outline-dark" href="sources.php"><i class="bi bi-diagram-3 me-2"></i>Review sources</a>
        </div>
        <div class="page-hero-metrics">
          <div class="page-hero-metric">
            <span class="pill-label">Last update</span>
            <span class="pill-value"><?php echo htmlspecialchars($meta['mtime'] ?? '—'); ?></span>
            <span class="pill-foot">schedule timestamp</span>
          </div>
          <div class="page-hero-metric">
            <span class="pill-label">Storage footprint</span>
            <span class="pill-value"><?php echo number_format((int)($meta['size'] ?? 0)); ?></span>
            <span class="pill-foot">bytes on disk</span>
          </div>
          <div class="page-hero-metric">
            <span class="pill-label">Sources</span>
            <span class="pill-value"><?php echo number_format($sourceCount); ?></span>
            <span class="pill-foot">configured inputs</span>
          </div>
        </div>
      </div>
      <div class="page-hero-side">
        <div class="page-hero-card">
          <h2 class="h5 mb-2">Operations checklist</h2>
          <p class="mb-0">Keep this rhythm when preparing for the week ahead.</p>
          <ul>
            <li><i class="bi bi-arrow-repeat"></i>Run <strong>Fetch latest</strong> after source updates land.</li>
            <li><i class="bi bi-journal-text"></i>Review the change log before sharing exports.</li>
            <li><i class="bi bi-cloud-arrow-down"></i>Take a backup before major config edits.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</header>
<main class="page-main flex-grow-1">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-8">
        <div class="card data-card border-0 shadow-sm">
          <div class="card-body p-4 p-lg-5">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
              <div>
                <h2 class="h4 mb-1">Data controls</h2>
                <p class="text-muted small mb-0">Use ingestion tools to refresh the working dataset or test upstream availability.</p>
              </div>
              <span class="badge bg-success-subtle text-success fw-semibold"><?php echo $sourceCount; ?> source<?php echo $sourceCount === 1 ? '' : 's'; ?> active</span>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <button id="btnIngest" class="btn btn-primary"><i class="bi bi-arrow-repeat me-1"></i>Fetch latest</button>
              <button id="btnProbe" class="btn btn-outline-secondary"><i class="bi bi-activity me-1"></i>Probe sources</button>
              <a class="btn btn-outline-dark" href="storage/schedule.json" target="_blank" rel="noopener"><i class="bi bi-filetype-json me-1"></i>Download JSON</a>
            </div>
            <pre id="ingestOut" class="mt-4 small">Ready.</pre>
          </div>
        </div>

        <div class="card data-card border-0 shadow-sm mt-4">
          <div class="card-body p-4 p-lg-5">
            <h2 class="h5 mb-2">Config (sources)</h2>
            <p class="small text-muted">Edit JSON configuration for automated sources. Invalid payloads are rejected with detailed error messaging.</p>
            <form id="cfgForm" class="row g-3">
              <div class="col-12">
                <label class="form-label">Config JSON</label>
                <textarea id="cfg" class="form-control" rows="10"><?php echo htmlspecialchars(json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea>
              </div>
              <div class="col-12"><button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Save config</button></div>
            </form>
            <pre id="cfgOut" class="small mt-3"></pre>
          </div>
        </div>

        <div class="card data-card border-0 shadow-sm mt-4">
          <div class="card-body p-4 p-lg-5">
            <h2 class="h5 mb-2">Add single entry</h2>
            <p class="small text-muted">Append a one-off shutdown. Entries are stored in <code>manual.csv</code> and merged on the next fetch cycle.</p>
            <form id="addForm" class="row g-3">
              <div class="col-md-4"><label class="form-label small">Area</label><input class="form-control" name="area" placeholder="Area" required></div>
              <div class="col-md-4"><label class="form-label small">Feeder</label><input class="form-control" name="feeder" placeholder="Feeder" required></div>
              <div class="col-md-4"><label class="form-label small">Type</label><input class="form-control" name="type" placeholder="Type" value="scheduled"></div>
              <div class="col-md-6"><label class="form-label small">Start</label><input class="form-control" name="start" placeholder="YYYY-MM-DDTHH:MM:SS+05:00" required></div>
              <div class="col-md-6"><label class="form-label small">End</label><input class="form-control" name="end" placeholder="YYYY-MM-DDTHH:MM:SS+05:00"></div>
              <div class="col-12"><label class="form-label small">Reason</label><input class="form-control" name="reason" placeholder="Reason"></div>
              <div class="col-12"><button class="btn btn-success"><i class="bi bi-plus-circle me-1"></i>Append to manual</button></div>
            </form>
            <pre id="addOut" class="small mt-3"></pre>
          </div>
        </div>

        <div class="card data-card border-0 shadow-sm mt-4">
          <div class="card-body p-4 p-lg-5">
            <h2 class="h5 mb-2">Manual CSV upload</h2>
            <p class="small text-muted mb-3">Upload a CSV to replace <code>manual.csv</code>. Expected headers: <code>utility,area,feeder,start,end,type,reason,source,url,confidence</code>.</p>
            <form method="post" action="admin_upload.php" enctype="multipart/form-data" class="d-flex flex-wrap gap-2">
              <input type="file" name="csv" accept=".csv" class="form-control flex-grow-1">
              <button class="btn btn-dark"><i class="bi bi-upload me-1"></i>Upload</button>
            </form>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card data-card border-0 shadow-sm">
          <div class="card-body p-4 p-lg-5">
            <h2 class="h5 mb-3">History &amp; change log</h2>
            <div class="input-group input-group-sm mb-3">
              <span class="input-group-text">Day</span>
              <input id="histDay" type="date" class="form-control">
              <button id="btnHistory" class="btn btn-outline-secondary">View</button>
            </div>
            <pre id="histOut" class="small" style="max-height:220px;overflow:auto"></pre>
            <div class="d-flex justify-content-between align-items-center mt-2">
              <button id="btnChanges" class="btn btn-sm btn-outline-secondary"><i class="bi bi-journal-text me-1"></i>Show change log</button>
              <span class="small text-muted">Latest 50 entries</span>
            </div>
            <pre id="changesOut" class="small mt-3" style="max-height:220px;overflow:auto"></pre>
          </div>
        </div>

        <div class="card data-card border-0 shadow-sm mt-4">
          <div class="card-body p-4 p-lg-5">
            <h2 class="h5 mb-3">Quick stats</h2>
            <ul class="list-unstyled small mb-0">
              <li class="mb-2"><span class="fw-semibold">Timezone:</span> <?php echo htmlspecialchars($cfg['timezone'] ?? 'Asia/Karachi'); ?></li>
              <?php foreach (($cfg['sources'] ?? []) as $name => $info): ?>
                <li class="mb-1"><span class="fw-semibold text-capitalize"><?php echo htmlspecialchars($name); ?>:</span> <?php echo !empty($info['enabled']) ? '<span class="text-success">enabled</span>' : '<span class="text-muted">disabled</span>'; ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>
<footer class="site-footer">
  <div class="container small text-muted">
    <div class="row gy-3 align-items-center">
      <div class="col-lg-8">
        <p class="mb-1">Admin tools power the data seen on Shutdown Lookup. Use responsibly and capture operator notes for auditing.</p>
        <p class="mb-0">Manual edits merge with automated runs to maintain a single source of truth.</p>
      </div>
      <div class="col-lg-4">
        <nav class="nav justify-content-lg-end">
          <a class="nav-link px-0" href="index.php">Dashboard</a>
          <a class="nav-link px-0" href="sources.php">Sources</a>
          <a class="nav-link px-0" href="api.php?route=backup">Backup ZIP</a>
        </nav>
      </div>
    </div>
  </div>
</footer>
<script>const API_BASE='api.php';</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/admin.js"></script>
</body>
</html>
