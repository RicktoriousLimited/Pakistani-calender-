<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use SHUTDOWN\Util\Store;

$store = new Store(__DIR__ . DIRECTORY_SEPARATOR . 'storage');
$meta = $store->meta();
$schedule = $store->readSchedule();
$totalItems = count($schedule['items'] ?? []);
$lastUpdated = $schedule['updatedAt'] ?? $meta['mtime'] ?? null;
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Shutdown Lookup</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
  <div class="container">
    <a class="navbar-brand fw-bold" href="index.php">Shutdown</a>
    <div class="navbar-nav ms-auto">
      <a class="nav-link active" href="index.php">Home</a>
      <a class="nav-link" href="sources.php">Sources</a>
      <a class="nav-link" href="admin.php">Admin</a>
    </div>
  </div>
</nav>
<header class="bg-white border-bottom py-5">
  <div class="container">
    <div class="row align-items-center g-4">
      <div class="col-lg-7">
        <h1 class="display-5 fw-bold text-primary">Plan around upcoming power shutdowns</h1>
        <p class="lead text-muted">Search, export and visualise planned outages across Lahore Electric Supply Company divisions. Filter by area, feeder, division or date and download shareable reports.</p>
        <div class="d-flex flex-wrap gap-3 align-items-center text-muted small">
          <span><i class="bi bi-clock-history me-1"></i>Updated: <?php echo $lastUpdated ? htmlspecialchars(date('d M Y H:i', strtotime($lastUpdated))) : '—'; ?></span>
          <span><i class="bi bi-card-list me-1"></i>Entries: <?php echo number_format($totalItems); ?></span>
          <span><i class="bi bi-cloud-download me-1"></i>Storage: <?php echo number_format((int)($meta['size'] ?? 0)); ?> bytes</span>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="stats-card shadow-sm bg-primary text-white rounded-4 p-4">
          <h2 class="h4">Quick Export</h2>
          <p class="mb-3 small text-white-50">Need the latest schedule for stakeholders? Generate CSV, ICS or PDF snapshots from the search results.</p>
          <div class="d-flex gap-2 flex-wrap">
            <button id="btnCsvHero" class="btn btn-light btn-sm text-primary">Download CSV</button>
            <button id="btnIcsHero" class="btn btn-outline-light btn-sm">Download ICS</button>
            <button id="btnPdfHero" class="btn btn-outline-light btn-sm">Download PDF</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</header>
<main class="container py-4">
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#search" type="button">Search</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#maptab" type="button">Map</button></li>
      </ul>
      <div class="tab-content p-3 border border-top-0 rounded-bottom bg-white">
        <div class="tab-pane fade show active" id="search">
          <div class="row g-2 align-items-end">
            <div class="col-md-3">
              <label class="form-label">Search</label>
              <input id="q" class="form-control" placeholder="Area/Feeder/Reason">
            </div>
            <div class="col-md-2">
              <label class="form-label">Area</label>
              <input id="area" class="form-control" placeholder="Area">
            </div>
            <div class="col-md-2">
              <label class="form-label">Feeder</label>
              <input id="feeder" class="form-control" placeholder="Feeder">
            </div>
            <div class="col-md-2">
              <label class="form-label">Date</label>
              <input id="date" type="date" class="form-control">
            </div>
            <div class="col-md-2">
              <label class="form-label">Division</label>
              <select id="division" class="form-select"><option value="">All</option></select>
            </div>
            <div class="col-md-1 d-grid"><button id="searchBtn" class="btn btn-primary">Go</button></div>
          </div>
          <div class="d-flex flex-wrap gap-2 mt-3">
            <button id="btnCsv" class="btn btn-outline-secondary btn-sm">CSV</button>
            <button id="btnIcs" class="btn btn-outline-secondary btn-sm">ICS</button>
            <button id="btnPdf" class="btn btn-outline-dark btn-sm">PDF</button>
          </div>
          <div id="meta" class="text-muted small mt-3"></div>
          <div id="grouped" class="mt-3"></div>
          <nav><ul class="pagination mt-3" id="pager"></ul></nav>
        </div>
        <div class="tab-pane fade" id="maptab">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="text-muted small" id="mapMeta"></div>
            <div><button class="btn btn-sm btn-outline-secondary" id="refreshMap">Refresh</button></div>
          </div>
          <div id="map" style="height:520px;border-radius:12px;overflow:hidden"></div>
          <div class="small text-muted mt-2">Polygons show area boundaries (approx). Colors: <span class="badge bg-info">Scheduled</span> <span class="badge bg-warning text-dark">Maintenance</span> <span class="badge bg-danger">Forced</span>.</div>
        </div>
      </div>
    </div>
  </div>
</main>
<script>const API_BASE='api.php';</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="assets/app.js"></script>
<script src="assets/export.js"></script>
<script src="assets/map.js"></script>
<script>
  document.getElementById('btnCsvHero').addEventListener('click', () => document.getElementById('btnCsv').click());
  document.getElementById('btnIcsHero').addEventListener('click', () => document.getElementById('btnIcs').click());
  document.getElementById('btnPdfHero').addEventListener('click', () => document.getElementById('btnPdf').click());
</script>
</body>
</html>
