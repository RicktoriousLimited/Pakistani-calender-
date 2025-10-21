<?php
$storageDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
$manualPath = $storageDir . DIRECTORY_SEPARATOR . 'manual.csv';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['csv']['tmp_name'])) {
  if (!is_dir($storageDir)) mkdir($storageDir, 0775, true);
  if (@move_uploaded_file($_FILES['csv']['tmp_name'], $manualPath)) {
    header('Location: admin.php?ok=1'); exit;
  }
}
header('Location: admin.php?err=1');
