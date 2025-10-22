<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use SHUTDOWN\Util\Store;

$store = new Store(__DIR__ . DIRECTORY_SEPARATOR . 'storage');
$cfg = $store->readConfig();
$sources = $cfg['sources'] ?? [];
$sourceCount = count($sources);
$timezone = $cfg['timezone'] ?? 'Asia/Karachi';

$enabledCount = 0;
$disabledCount = 0;
$discoveryEndpoints = 0;
foreach ($sources as $info) {
    $enabled = !empty($info['enabled']);
    if ($enabled) {
        $enabledCount++;
    } else {
        $disabledCount++;
    }
    if (!empty($info['discover']) && is_array($info['discover'])) {
        foreach ($info['discover'] as $url) {
            if (is_string($url) && trim($url) !== '') {
                $discoveryEndpoints++;
            }
        }
    }
}
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
            <span class="pill-label">Active connections</span>
            <span class="pill-value"><?php echo number_format($enabledCount); ?></span>
            <span class="pill-foot">delivering data now</span>
          </div>
          <div class="page-hero-metric">
            <span class="pill-label">Discovery endpoints</span>
            <span class="pill-value"><?php echo number_format($discoveryEndpoints); ?></span>
            <span class="pill-foot">monitored entry points</span>
          </div>
        </div>
      </div>
      <div class="page-hero-side">
        <div class="page-hero-card">
          <h2 class="h5 mb-2">Why it matters</h2>
          <p>Every source is versioned and auditable. Keep contractors, planners and communications aligned on the same upstream truth.</p>
          <ul>
            <li><i class="bi bi-database-fill-check"></i>Track which feeds are live or paused at a glance.</li>
            <li><i class="bi bi-clock-history"></i>Default parsing zone: <strong><?php echo htmlspecialchars($timezone); ?></strong>.</li>
            <li><i class="bi bi-robot"></i>Discovery monitors cover <?php echo number_format($discoveryEndpoints); ?> endpoints.</li>
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
        <div class="source-toolbar">
          <div class="input-group input-group-sm source-search">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" id="sourceSearch" class="form-control" placeholder="Filter by name, endpoint or note">
          </div>
          <div class="btn-group btn-group-sm source-filter-group" role="group" aria-label="Filter sources">
            <button type="button" class="btn btn-outline-secondary active" data-filter="all">All</button>
            <button type="button" class="btn btn-outline-secondary" data-filter="enabled">Enabled (<?php echo number_format($enabledCount); ?>)</button>
            <button type="button" class="btn btn-outline-secondary" data-filter="disabled">Disabled (<?php echo number_format($disabledCount); ?>)</button>
          </div>
        </div>
        <div class="source-stats">
          <div class="source-stat">
            <span class="source-stat-label">Active feeds</span>
            <span class="source-stat-value"><?php echo number_format($enabledCount); ?></span>
            <span class="source-stat-foot">Currently enabled connectors</span>
          </div>
          <div class="source-stat">
            <span class="source-stat-label">Paused feeds</span>
            <span class="source-stat-value"><?php echo number_format($disabledCount); ?></span>
            <span class="source-stat-foot">Temporarily disabled inputs</span>
          </div>
          <div class="source-stat">
            <span class="source-stat-label">Discovery watchpoints</span>
            <span class="source-stat-value"><?php echo number_format($discoveryEndpoints); ?></span>
            <span class="source-stat-foot">URLs monitored for schedule drops</span>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr><th scope="col">Source</th><th scope="col">Status</th><th scope="col">Endpoint</th><th scope="col" class="text-center">Discovery / config</th><th scope="col" class="text-end">Notes</th></tr>
            </thead>
            <tbody id="sourcesBody">
            <?php foreach ($sources as $name => $info):
                $enabled = !empty($info['enabled']);
                $discoverRaw = !empty($info['discover']) && is_array($info['discover']) ? $info['discover'] : [];
                $discoverList = [];
                foreach ($discoverRaw as $url) {
                    if (is_string($url) && trim($url) !== '') {
                        $discoverList[] = trim($url);
                    }
                }
                $discoverCount = count($discoverList);
                $note = trim((string)($info['note'] ?? ''));
                $sourceId = 'source-' . preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$name));
                $keywords = strtolower(
                    (string)$name . ' ' .
                    ($info['url'] ?? '') . ' ' .
                    $note . ' ' .
                    implode(' ', $discoverList)
                );
            ?>
              <tr class="source-row" data-source="<?php echo htmlspecialchars($sourceId); ?>" data-enabled="<?php echo $enabled ? '1' : '0'; ?>" data-keywords="<?php echo htmlspecialchars($keywords, ENT_QUOTES); ?>">
                <th scope="row" class="text-capitalize"><?php echo htmlspecialchars($name); ?></th>
                <td><?php echo $enabled ? '<span class="badge bg-success">Enabled</span>' : '<span class="badge bg-secondary">Disabled</span>'; ?></td>
                <td><?php echo !empty($info['url']) ? '<a href="' . htmlspecialchars((string)$info['url']) . '" target="_blank" rel="noopener">' . htmlspecialchars((string)$info['url']) . '</a>' : '<span class="text-muted">—</span>'; ?></td>
                <td class="text-center">
                  <button type="button" class="btn btn-sm btn-outline-primary source-detail-toggle" data-source="<?php echo htmlspecialchars($sourceId); ?>">
                    <i class="bi bi-folder2-open me-1"></i>Details<?php echo $discoverCount ? ' (' . number_format($discoverCount) . ')' : ''; ?>
                  </button>
                </td>
                <td class="text-end text-muted small"><?php echo htmlspecialchars($note); ?></td>
              </tr>
              <tr class="source-detail d-none" data-source="<?php echo htmlspecialchars($sourceId); ?>">
                <td colspan="5">
                  <div class="source-detail-card">
                    <div class="source-detail-heading">
                      <h3 class="h6 mb-0 text-capitalize"><?php echo htmlspecialchars($name); ?></h3>
                      <span class="badge <?php echo $enabled ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $enabled ? 'Enabled' : 'Disabled'; ?></span>
                    </div>
                    <?php if ($note !== ''): ?>
                      <p class="small text-muted mb-3"><?php echo htmlspecialchars($note); ?></p>
                    <?php endif; ?>
                    <?php if ($discoverCount): ?>
                      <div class="source-detail-section">
                        <div class="source-detail-label">Discovery endpoints</div>
                        <div class="source-discover-list">
                          <?php foreach ($discoverList as $endpoint): ?>
                            <a class="source-discover-chip" href="<?php echo htmlspecialchars($endpoint); ?>" target="_blank" rel="noopener">
                              <i class="bi bi-link-45deg me-1"></i><?php echo htmlspecialchars($endpoint); ?>
                            </a>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    <?php endif; ?>
                    <div class="source-detail-section">
                      <div class="source-detail-label">Configuration snapshot</div>
                      <pre class="small mb-0"><?php echo htmlspecialchars(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?></pre>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
              <tr id="sourcesEmpty" class="<?php echo $sourceCount ? 'd-none' : ''; ?>">
                <td colspan="5" class="text-center text-muted py-4">No sources match the current filters.</td>
              </tr>
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
        <div class="source-guidance">
          <div class="source-guidance-card">
            <div class="source-guidance-icon"><i class="bi bi-broadcast"></i></div>
            <h3 class="h6 mb-1">Maintain coverage</h3>
            <p class="small">Keep at least one official bulletin and one CCMS feed enabled to preserve redundancy across the workflow.</p>
          </div>
          <div class="source-guidance-card">
            <div class="source-guidance-icon"><i class="bi bi-shield-lock"></i></div>
            <h3 class="h6 mb-1">Validate before enabling</h3>
            <p class="small">Run a probe from the admin console before reactivating paused sources to prevent stale or malformed payloads.</p>
          </div>
          <div class="source-guidance-card">
            <div class="source-guidance-icon"><i class="bi bi-journal-text"></i></div>
            <h3 class="h6 mb-1">Document overrides</h3>
            <p class="small">Record manual edits and add operator notes so downstream teams can trace provenance during incidents.</p>
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
<script src="assets/sources.js"></script>
</body>
</html>
