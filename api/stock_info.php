<?php
require_once __DIR__ . '/../lib/webxml_client.php';

header('Content-Type: application/json; charset=utf-8');

$symbolRaw = isset($_GET['symbol']) ? $_GET['symbol'] : '';
$symbol = sw_normalize_symbol($symbolRaw);
if (!$symbol) {
  http_response_code(400);
  echo json_encode(['error' => 'invalid symbol']);
  exit;
}

$ttlSeconds = 3600;
$cacheDir = __DIR__ . '/../cache';
if (!is_dir($cacheDir)) {
  @mkdir($cacheDir, 0777, true);
}
$cacheFile = $cacheDir . '/sina_info_' . $symbol . '.json';

if (is_file($cacheFile) && (time() - filemtime($cacheFile) <= $ttlSeconds)) {
  $json = @file_get_contents($cacheFile);
  if ($json !== false) {
    echo $json;
    exit;
  }
}

$info = sw_fetch_stock_info($symbol);
if (!$info) {
  http_response_code(502);
  echo json_encode(['error' => 'sina fetch failed']);
  exit;
}

// Add server timestamp for debugging/diagnostics.
$info['_cachedAt'] = date('c');
$json = json_encode($info, JSON_UNESCAPED_UNICODE);
if ($json === false) {
  http_response_code(500);
  echo json_encode(['error' => 'json encode failed']);
  exit;
}

// Atomic-ish write
$tmp = $cacheFile . '.tmp';
@file_put_contents($tmp, $json);
@rename($tmp, $cacheFile);

echo $json;

