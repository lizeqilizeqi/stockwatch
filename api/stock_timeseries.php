<?php
require_once __DIR__ . '/../lib/webxml_client.php';

header('Content-Type: application/json; charset=utf-8');

$symbolRaw = isset($_GET['symbol']) ? $_GET['symbol'] : '';
$symbol = sw_normalize_symbol($symbolRaw);
if (!$symbol) {
  http_response_code(400);
  echo json_encode(array('error' => 'invalid symbol'));
  exit;
}

$mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : 'minute';
if (!in_array($mode, array('minute', 'day'), true)) {
  http_response_code(400);
  echo json_encode(array('error' => 'invalid mode'));
  exit;
}

$datalen = isset($_GET['datalen']) ? (int)$_GET['datalen'] : (($mode === 'minute') ? 240 : 180);
if ($datalen <= 0) $datalen = (($mode === 'minute') ? 240 : 180);
if ($datalen > 1023) $datalen = 1023;

$ttlSeconds = 3600;
$cacheDir = __DIR__ . '/../cache';
if (!is_dir($cacheDir)) {
  @mkdir($cacheDir, 0777, true);
}
$cacheFile = $cacheDir . '/sina_ts_' . $mode . '_' . $symbol . '_' . $datalen . '.json';

if (is_file($cacheFile) && (time() - filemtime($cacheFile) <= $ttlSeconds)) {
  $json = @file_get_contents($cacheFile);
  if ($json !== false) {
    echo $json;
    exit;
  }
}

$scale = ($mode === 'minute') ? 5 : 240;
$rows = sw_fetch_kline_data($symbol, $scale, $datalen);
if (!is_array($rows) || count($rows) < 2) {
  http_response_code(502);
  echo json_encode(array('error' => 'sina timeseries fetch failed'));
  exit;
}

$labels = array();
$linePrices = array();
$volumes = array();
$ohlc = array();
foreach ($rows as $r) {
  $labels[] = isset($r['day']) ? $r['day'] : '';
  $linePrices[] = isset($r['close']) ? (float)$r['close'] : 0.0;
  $volumes[] = isset($r['volume']) ? (float)$r['volume'] : 0.0;
  $ohlc[] = array(
    isset($r['open']) ? (float)$r['open'] : 0.0,
    isset($r['close']) ? (float)$r['close'] : 0.0,
    isset($r['low']) ? (float)$r['low'] : 0.0,
    isset($r['high']) ? (float)$r['high'] : 0.0,
  );
}

$payload = array(
  'symbol' => $symbol,
  'mode' => $mode,
  'scale' => $scale,
  'labels' => $labels,
  'linePrices' => $linePrices,
  'volumes' => $volumes,
  'ohlc' => $ohlc,
  '_cachedAt' => date('c')
);

$json = json_encode($payload, JSON_UNESCAPED_UNICODE);
if ($json === false) {
  http_response_code(500);
  echo json_encode(array('error' => 'json encode failed'));
  exit;
}

$tmp = $cacheFile . '.tmp';
@file_put_contents($tmp, $json);
@rename($tmp, $cacheFile);

echo $json;

