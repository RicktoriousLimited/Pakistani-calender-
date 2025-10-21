<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use SHUTDOWN\Util\Store;

$store = new Store(__DIR__ . DIRECTORY_SEPARATOR . 'storage');
$cfg = $store->readConfig();
$sources = $cfg['sources'] ?? [];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sources — Shutdown</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
  <div class="container">
    <a class="navbar-brand fw-bold" href="index.php">Shutdown</a>
    <span class="navbar-text">Sources</span>
  </div>
</nav>
<main class="container py-4">
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <h5 class="card-title">Configured Sources</h5>
      <p class="text-muted small">Manage these via the Admin console. Disabled sources remain listed for reference.</p>
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
          </tbody>
        </table>
      </div>
      <pre class="small text-muted mt-3 bg-light border rounded p-3">Timezone: <?php echo htmlspecialchars($cfg['timezone'] ?? 'Asia/Karachi'); ?></pre>
    </div>
  </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
