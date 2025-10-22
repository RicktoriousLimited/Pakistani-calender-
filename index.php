<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use SHUTDOWN\Util\Store;

$store = new Store(__DIR__ . DIRECTORY_SEPARATOR . 'storage');
$meta = $store->meta();
$schedule = $store->readSchedule();
$totalItems = count($schedule['items'] ?? []);
$lastUpdated = $schedule['updatedAt'] ?? $meta['mtime'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Shutdown Lookup</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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
        <li class="nav-item"><a class="nav-link active" href="index.php">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="sources.php">Sources</a></li>
        <li class="nav-item"><a class="nav-link" href="admin.php">Admin</a></li>
      </ul>
    </div>
  </div>
</nav>
<header class="hero">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-7">
        <span class="hero-eyebrow"><i class="bi bi-lightning-charge-fill"></i> Lahore Electric Supply Company</span>
        <h1 class="display-4 hero-title">Plan around upcoming power shutdowns with confidence</h1>
        <p class="hero-lead">Search, export and visualise planned outages across all LESCO divisions. Quickly filter by area, feeder, division or date and share polished intelligence packs with stakeholders.</p>
        <div class="hero-actions">
          <a class="btn btn-brand" href="#schedulePanel"><i class="bi bi-search me-2"></i>Explore schedule</a>
          <a class="btn btn-outline-light" href="sources.php"><i class="bi bi-diagram-3 me-2"></i>Review sources</a>
        </div>
        <ul class="hero-meta">
          <li><i class="bi bi-clock-history"></i><span>Updated: <?php echo $lastUpdated ? htmlspecialchars(date('d M Y \a\t H:i', strtotime($lastUpdated))) : '—'; ?></span></li>
          <li><i class="bi bi-card-list"></i><span>Entries: <?php echo number_format($totalItems); ?></span></li>
          <li><i class="bi bi-hdd-network"></i><span>Storage: <?php echo number_format((int)($meta['size'] ?? 0)); ?> bytes</span></li>
        </ul>
      </div>
      <div class="col-lg-5">
        <div class="quick-export-card">
          <h2 class="h4 mb-3">Rapid exports</h2>
          <p class="mb-4">Need the latest shutdown view for leadership or field teams? Generate CSV, ICS or PDF snapshots straight from the live filters and keep everyone aligned.</p>
          <div class="d-flex flex-wrap gap-2">
            <button id="btnCsvHero" class="btn btn-light btn-sm"><i class="bi bi-download me-1"></i>Download CSV</button>
            <button id="btnIcsHero" class="btn btn-outline-light btn-sm"><i class="bi bi-calendar-event me-1"></i>Download ICS</button>
            <button id="btnPdfHero" class="btn btn-outline-light btn-sm"><i class="bi bi-file-earmark-text me-1"></i>Download PDF</button>
          </div>
          <div class="export-note">Exports mirror the filters you apply in the schedule workspace.</div>
        </div>
      </div>
    </div>
  </div>
</header>
<section class="feature-band">
  <div class="container">
    <div class="row g-3">
      <div class="col-md-4">
        <div class="feature-card h-100">
          <i class="bi bi-map"></i>
          <h3>Geospatial awareness</h3>
          <p class="text-muted mb-0">Interactive Leaflet mapping with feeder polygons and live outage categorisation keeps operations teams situationally aware.</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="feature-card h-100">
          <i class="bi bi-graph-up-arrow"></i>
          <h3>Forward-looking insights</h3>
          <p class="text-muted mb-0">Automated forecasts surface upcoming work, longest jobs and the divisions most impacted over the next seven days.</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="feature-card h-100">
          <i class="bi bi-shield-check"></i>
          <h3>Operational reliability</h3>
          <p class="text-muted mb-0">Backed by curated source ingestion, manual overrides and audit history, ensuring transparent updates for every stakeholder.</p>
        </div>
      </div>
    </div>
  </div>
</section>
<main class="page-main flex-grow-1" id="schedulePanel">
  <div class="container">
    <div class="card data-card border-0 shadow-sm">
      <div class="card-body p-4 p-lg-5">
        <div class="data-card-header">
          <div>
            <h2 class="section-title h3 mb-0">Live shutdown schedule</h2>
            <p class="text-muted mb-0">Refine filters, group by date and export professional-grade artefacts in seconds.</p>
          </div>
          <div class="text-muted small d-flex align-items-center gap-2">
            <span class="badge badge-pill"><i class="bi bi-broadcast-pin me-1"></i>Real-time data service</span>
          </div>
        </div>
        <ul class="nav nav-tabs" role="tablist">
          <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#search" type="button">Search</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#maptab" type="button">Map</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#insights" type="button">Insights</button></li>
        </ul>
        <div class="tab-content">
          <div class="tab-pane fade show active" id="search">
            <div class="row g-3 align-items-end">
              <div class="col-md-3">
                <label class="form-label">Search</label>
                <input id="q" class="form-control" placeholder="Area / Feeder / Reason">
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
            <div class="d-flex flex-wrap gap-2 mt-4">
              <button id="btnCsv" class="btn btn-outline-secondary btn-sm"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
              <button id="btnIcs" class="btn btn-outline-secondary btn-sm"><i class="bi bi-calendar3 me-1"></i>ICS</button>
              <button id="btnPdf" class="btn btn-outline-dark btn-sm"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
            </div>
            <div id="meta" class="text-muted small mt-3"></div>
            <div id="grouped" class="mt-3"></div>
            <nav><ul class="pagination mt-3" id="pager"></ul></nav>
          </div>
          <div class="tab-pane fade" id="maptab">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
              <div class="text-muted small" id="mapMeta"></div>
              <div><button class="btn btn-sm btn-outline-secondary" id="refreshMap"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button></div>
            </div>
            <div id="map" style="height:520px"></div>
            <div class="small text-muted mt-3">Polygons show area boundaries (approx). Colors: <span class="badge bg-info">Scheduled</span> <span class="badge bg-warning text-dark">Maintenance</span> <span class="badge bg-danger">Forced</span>.</div>
          </div>
          <div class="tab-pane fade" id="insights">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
              <div id="insightsMeta" class="text-muted small">Forecast upcoming work for the next seven days.</div>
              <button class="btn btn-sm btn-outline-secondary" id="refreshInsights"><i class="bi bi-stars me-1"></i>Refresh forecast</button>
            </div>
            <div class="row g-3" id="insightSummary">
              <div class="col-sm-6 col-lg-3">
                <div class="insight-stat">
                  <div class="insight-stat-label">Upcoming outages</div>
                  <div class="insight-stat-value" id="insightCount">—</div>
                  <div class="insight-stat-foot">within 7 days</div>
                </div>
              </div>
              <div class="col-sm-6 col-lg-3">
                <div class="insight-stat">
                  <div class="insight-stat-label">Total hours</div>
                  <div class="insight-stat-value" id="insightHours">—</div>
                  <div class="insight-stat-foot">sum of planned work</div>
                </div>
              </div>
              <div class="col-sm-6 col-lg-3">
                <div class="insight-stat">
                  <div class="insight-stat-label">Within 24 hours</div>
                  <div class="insight-stat-value" id="insightSoon">—</div>
                  <div class="insight-stat-foot">starting by tomorrow</div>
                </div>
              </div>
              <div class="col-sm-6 col-lg-3">
                <div class="insight-stat">
                  <div class="insight-stat-label">Distinct areas</div>
                  <div class="insight-stat-value" id="insightAreas">—</div>
                  <div class="insight-stat-foot">affected in window</div>
                </div>
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-lg-7">
                <div class="insight-panel h-100">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="insight-panel-title">Daily outlook</h6>
                    <span class="badge bg-light text-muted" id="insightWindow">—</span>
                  </div>
                  <canvas id="insightChart" height="160"></canvas>
                  <div class="table-responsive mt-3">
                    <table class="table table-sm mb-0">
                      <thead class="table-light"><tr><th>Day</th><th>Count</th><th>Hours</th><th>Areas</th></tr></thead>
                      <tbody id="insightDaily"></tbody>
                    </table>
                  </div>
                </div>
              </div>
              <div class="col-lg-5">
                <div class="insight-panel mb-3">
                  <div class="insight-panel-title">Next up (soonest five)</div>
                  <div id="insightUpcoming" class="small"></div>
                </div>
                <div class="insight-panel mb-3">
                  <div class="insight-panel-title">Divisions most impacted</div>
                  <ol id="insightDivisions" class="list-group list-group-numbered list-group-flush small mb-0"></ol>
                </div>
                <div class="insight-panel">
                  <div class="insight-panel-title">Outage mix by type</div>
                  <div id="insightTypes" class="small"></div>
                </div>
              </div>
            </div>
            <div class="insight-panel mt-3">
              <div class="insight-panel-title">Longest planned work</div>
              <div id="insightLongest" class="small"></div>
            </div>
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
        <p class="mb-1">Shutdown Lookup surfaces official and curated outage schedules for Lahore Electric Supply Company.</p>
        <p class="mb-0">Data refreshed via automated scrapers and manual overrides. Always verify critical operations with control rooms.</p>
      </div>
      <div class="col-lg-4">
        <nav class="nav justify-content-lg-end">
          <a class="nav-link px-0" href="sources.php">Sources</a>
          <a class="nav-link px-0" href="admin.php">Admin</a>
          <a class="nav-link px-0" href="storage/schedule.json" target="_blank" rel="noopener">Latest JSON</a>
        </nav>
      </div>
    </div>
  </div>
</footer>
<script>const API_BASE='api.php';</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="assets/app.js"></script>
<script src="assets/export.js"></script>
<script src="assets/map.js"></script>
<script src="assets/insights.js"></script>
<script>
  document.getElementById('btnCsvHero').addEventListener('click', () => document.getElementById('btnCsv').click());
  document.getElementById('btnIcsHero').addEventListener('click', () => document.getElementById('btnIcs').click());
  document.getElementById('btnPdfHero').addEventListener('click', () => document.getElementById('btnPdf').click());
</script>
</body>
</html>
