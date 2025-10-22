<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use SHUTDOWN\Util\Store;

$store = new Store(__DIR__ . DIRECTORY_SEPARATOR . 'storage');
$cfg = $store->readConfig();
$sources = $cfg['sources'] ?? [];
$sourceCount = count($sources);
$timezone = $cfg['timezone'] ?? 'Asia/Karachi';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sources — Shutdown</title>
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
        <li class="nav-item"><a class="nav-link active" href="sources.php">Sources</a></li>
        <li class="nav-item"><a class="nav-link" href="admin.php">Admin</a></li>
      </ul>
    </div>
  </div>
</nav>
<header class="page-hero">
  <div class="container">
    <div class="page-hero-layout">
      <div class="page-hero-content">
        <span class="page-hero-eyebrow"><i class="bi bi-diagram-3"></i> Data supply chain</span>
        <h1 class="page-hero-title display-5">Source catalogue</h1>
        <p class="page-hero-lead">Transparency into every feed used to assemble the Shutdown Lookup dataset. Disabled sources remain listed for operational awareness.</p>
        <div class="page-hero-actions">
          <a class="btn btn-brand" href="admin.php"><i class="bi bi-sliders me-2"></i>Manage configuration</a>
          <a class="btn btn-outline-dark" href="index.php"><i class="bi bi-speedometer2 me-2"></i>Return to dashboard</a>
        </div>
        <div class="page-hero-metrics">
          <div class="page-hero-metric">
            <span class="pill-label">Total sources</span>
            <span class="pill-value"><?php echo number_format($sourceCount); ?></span>
            <span class="pill-foot">managed connections</span>
          </div>
          <div class="page-hero-metric">
            <span class="pill-label">Timezone</span>
            <span class="pill-value"><?php echo htmlspecialchars($timezone); ?></span>
            <span class="pill-foot">default parsing zone</span>
          </div>
          <div class="page-hero-metric">
            <span class="pill-label">Admin console</span>
            <span class="pill-value">JSON &amp; CSV</span>
            <span class="pill-foot">configuration surfaces</span>
          </div>
        </div>
      </div>
      <div class="page-hero-side">
        <div class="page-hero-card">
          <h2 class="h5 mb-2">Why it matters</h2>
          <p>Every source is versioned and auditable. Keep contractors, planners and communications aligned on the same upstream truth.</p>
          <ul>
            <li><i class="bi bi-database-fill-check"></i>Track which feeds are live or paused at a glance.</li>
            <li><i class="bi bi-shield-lock"></i>Ensure manual overrides honour the shared timezone.</li>
            <li><i class="bi bi-arrow-repeat"></i>Back up schedules before making structural edits.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</header>
<main class="page-main flex-grow-1">
  <div class="container">
    <div class="card data-card border-0 shadow-sm">
      <div class="card-body p-4 p-lg-5">
        <div class="d-flex justify-content-between flex-wrap gap-3 mb-3">
          <div>
            <h2 class="section-title h4 mb-1">Configured sources</h2>
            <p class="text-muted small mb-0">Manage definitions and toggles in the admin console. Even disabled endpoints remain catalogued for documentation.</p>
          </div>
          <span class="badge badge-pill align-self-start"><i class="bi bi-clock-history me-1"></i>Last refreshed from storage</span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr><th scope="col">Source</th><th scope="col">Status</th><th scope="col">Endpoint</th><th scope="col" class="text-end">Notes</th></tr>
            </thead>
            <tbody>
            <?php foreach ($sources as $name => $info): $enabled = !empty($info['enabled']); ?>
              <tr>
                <th scope="row" class="text-capitalize"><?php echo htmlspecialchars($name); ?></th>
                <td><?php echo $enabled ? '<span class="badge bg-success">Enabled</span>' : '<span class="badge bg-secondary">Disabled</span>'; ?></td>
                <td><?php echo !empty($info['url']) ? '<a href="' . htmlspecialchars($info['url']) . '" target="_blank" rel="noopener">' . htmlspecialchars($info['url']) . '</a>' : '<span class="text-muted">—</span>'; ?></td>
                <td class="text-end text-muted small"><?php echo htmlspecialchars($info['note'] ?? ''); ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$sourceCount): ?>
              <tr><td colspan="4" class="text-center text-muted py-4">No sources configured yet.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="alert alert-info small mt-4 mb-0"><i class="bi bi-info-circle me-2"></i>Timezone for parsing and exports: <strong><?php echo htmlspecialchars($timezone); ?></strong></div>
      </div>
    </div>
    <div class="card data-card border-0 shadow-sm mt-4">
      <div class="card-body p-4 p-lg-5">
        <h2 class="h5 mb-3">Primary reference sources</h2>
        <ul class="small text-muted mb-0">
          <li class="mb-2"><a href="https://www.lesco.gov.pk/shutdownschedule" target="_blank" rel="noopener">LESCO planned shutdown listings</a> — official HTML/PDF schedule (also available via the <a href="https://www.lesco.gov.pk/TBR" target="_blank" rel="noopener">TBR mirror</a>).</li>
          <li class="mb-2"><a href="https://ccms.pitc.com.pk/FeederDetails" target="_blank" rel="noopener">PITC CCMS feeder details</a> — JSON/HTML feed powering national dashboards.</li>
          <li class="mb-2"><a href="https://www.facebook.com/PRLESCO/" target="_blank" rel="noopener">PR LESCO announcements</a> — urgent notices and manual updates.</li>
          <li><a href="https://www.openstreetmap.org" target="_blank" rel="noopener">OpenStreetMap contributors</a> — base cartography served via Leaflet.</li>
        </ul>
      </div>
    </div>
  </div>
</main>
<footer class="site-footer">
  <div class="container small text-muted">
    <div class="row gy-3 align-items-center">
      <div class="col-lg-8">
        <p class="mb-1">These references underpin the automated schedules and manual enrichments delivered by Shutdown Lookup.</p>
        <p class="mb-0">For change requests please coordinate with the operations team through the admin console.</p>
      </div>
      <div class="col-lg-4">
        <nav class="nav justify-content-lg-end">
          <a class="nav-link px-0" href="index.php">Dashboard</a>
          <a class="nav-link px-0" href="admin.php">Admin</a>
          <a class="nav-link px-0" href="storage/schedule.json" target="_blank" rel="noopener">Latest JSON</a>
        </nav>
      </div>
    </div>
  </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
