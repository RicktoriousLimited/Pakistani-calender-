<?php
header('Content-Type: text/plain; charset=utf-8');
echo "OK\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "curl: " . (extension_loaded('curl') ? 'yes' : 'no') . "\n";
echo "dom: " . (extension_loaded('dom') ? 'yes' : 'no') . "\n";
echo "xml: " . (extension_loaded('xml') ? 'yes' : 'no') . "\n";
echo "json: " . (extension_loaded('json') ? 'yes' : 'no') . "\n";
echo "zip: " . (class_exists('ZipArchive') ? 'yes' : 'no') . "\n";
$w = is_writable(__DIR__ . '/storage') ? 'yes' : 'no';
echo "storage writable: $w\n";
?>