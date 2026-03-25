<?php
require_once __DIR__ . '/../lib/webxml_client.php';

$symbolRaw = isset($_GET['symbol']) ? $_GET['symbol'] : '';
$imgMode = isset($_GET['img']) ? $_GET['img'] : 'minute';

$symbol = sw_normalize_symbol($symbolRaw);
if (!$symbol) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'invalid symbol';
  exit;
}

$imgMode = strtolower(trim((string)$imgMode));
if (!in_array($imgMode, ['minute', 'k_day', 'k_week', 'k_month'], true)) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'invalid img mode';
  exit;
}

$ttlSeconds = 3600;
$cacheDir = __DIR__ . '/../cache';
if (!is_dir($cacheDir)) {
  @mkdir($cacheDir, 0777, true);
}
$cacheBodyFile = $cacheDir . '/sina_img_' . $imgMode . '_' . $symbol . '.bin';
$cacheTypeFile = $cacheDir . '/sina_img_' . $imgMode . '_' . $symbol . '.type';

if (is_file($cacheBodyFile) && (time() - filemtime($cacheBodyFile) <= $ttlSeconds)) {
  $type = 'image/gif';
  if (is_file($cacheTypeFile)) {
    $t = trim((string)@file_get_contents($cacheTypeFile));
    if ($t !== '') $type = $t;
  }
  header('Content-Type: ' . $type);
  header('Cache-Control: public, max-age=' . $ttlSeconds);
  $data = @file_get_contents($cacheBodyFile);
  if ($data === false) {
    http_response_code(500);
    echo '';
  } else {
    echo $data;
  }
  exit;
}

$body = null;
$type = null;

// Prefer Sina GIF chart endpoint.
$bin = sw_fetch_stock_image_binary($symbol, $imgMode);
if ($bin) {
  $body = $bin;
  $type = 'image/gif';
} else {
  // Fallback: render chart from Sina K-line JSON.
  $svg = sw_fetch_stock_chart_svg($symbol, $imgMode);
  if ($svg) {
    $body = $svg;
    $type = 'image/svg+xml; charset=utf-8';
  }
}

if ($body === null || $type === null) {
  http_response_code(502);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'sina fetch failed (gif and fallback svg both unavailable)';
  exit;
}

$tmpBody = $cacheBodyFile . '.tmp';
$tmpType = $cacheTypeFile . '.tmp';
@file_put_contents($tmpBody, $body);
@file_put_contents($tmpType, $type);
@rename($tmpBody, $cacheBodyFile);
@rename($tmpType, $cacheTypeFile);

header('Content-Type: ' . $type);
header('Cache-Control: public, max-age=' . $ttlSeconds);
echo $body;

