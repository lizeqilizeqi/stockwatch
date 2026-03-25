<?php
// Sina quote client wrapper (PHP 5.4 compatible)

function sw_normalize_symbol($input) {
  $s = strtolower(trim((string)$input));
  if ($s === '') return null;

  // Accept already normalized code: sh000001 / sz000001
  if (preg_match('/^(sh|sz)\d{6}$/', $s)) return $s;

  // Accept plain 6-digit code: 6xxxxx => sh, else sz
  if (preg_match('/^\d{6}$/', $s)) {
    $prefix = ($s[0] === '6') ? 'sh' : 'sz';
    return $prefix . $s;
  }

  return null;
}

function sw_http_get($url, $timeoutSeconds = 8) {
  $context = stream_context_create([
    'http' => [
      'method' => 'GET',
      'timeout' => $timeoutSeconds,
      'header' => "User-Agent: stockwatch/1.0\r\n",
    ]
  ]);

  $data = @file_get_contents($url, false, $context);
  if ($data === false) return null;
  return $data;
}

function sw_to_utf8($text) {
  if ($text === null) return '';
  $s = (string)$text;
  if ($s === '') return '';

  if (function_exists('mb_convert_encoding')) {
    $converted = @mb_convert_encoding($s, 'UTF-8', 'GB18030,GBK,GB2312,UTF-8');
    if ($converted !== false && $converted !== null) return $converted;
  }
  if (function_exists('iconv')) {
    $converted = @iconv('GBK', 'UTF-8//IGNORE', $s);
    if ($converted !== false && $converted !== null) return $converted;
    $converted = @iconv('GB18030', 'UTF-8//IGNORE', $s);
    if ($converted !== false && $converted !== null) return $converted;
  }
  return $s;
}

function sw_fetch_stock_info($symbol) {
  $symbol = sw_normalize_symbol($symbol);
  if (!$symbol) return null;

  // Try hq.sinajs first (richer fields), then fall back to K-line endpoint.
  $hqInfo = sw_fetch_stock_info_from_hq($symbol);
  if ($hqInfo) return $hqInfo;
  return sw_fetch_stock_info_from_kline($symbol);
}

function sw_fetch_stock_info_from_hq($symbol) {
  // Sina real-time quote: var hq_str_sh601006="name,open,prevClose,last,high,low,bid,ask,volume,amount,...,date,time";
  $url = 'http://hq.sinajs.cn/list=' . rawurlencode($symbol);
  $raw = sw_http_get($url);
  if (!$raw) return null;

  $raw = sw_to_utf8($raw);
  if (!preg_match('/\"(.*)\"/sU', $raw, $m)) return null;
  $payload = trim($m[1]);
  if ($payload === '') return null;

  $arr = explode(',', $payload);
  if (count($arr) < 32) return null;

  $name = trim($arr[0]);
  $open = trim($arr[1]);
  $prevClose = trim($arr[2]);
  $last = trim($arr[3]);
  $high = trim($arr[4]);
  $low = trim($arr[5]);
  $bidPrice = trim($arr[6]);
  $askPrice = trim($arr[7]);
  $volumeShares = trim($arr[8]);    // volume in shares
  $amountYuan = trim($arr[9]);      // amount in yuan
  $date = trim($arr[30]);
  $time = trim($arr[31]);

  $lastF = is_numeric($last) ? (float)$last : null;
  $prevF = is_numeric($prevClose) ? (float)$prevClose : null;
  $changeAmount = null;
  $changePct = null;
  if ($lastF !== null && $prevF !== null) {
    $changeAmount = $lastF - $prevF;
    if ((float)$prevF != 0.0) {
      $changePct = ($changeAmount / $prevF) * 100.0;
    }
  }

  $volumeHands = '';
  if (is_numeric($volumeShares)) {
    $volumeHands = (string)round(((float)$volumeShares) / 100.0);
  }
  $amountWan = '';
  if (is_numeric($amountYuan)) {
    $amountWan = number_format(((float)$amountYuan) / 10000.0, 2, '.', '');
  }

  $bids = [];
  $asks = [];
  // buy1 volume/price to buy5 volume/price => indexes 10..19
  for ($i = 10; $i <= 18; $i += 2) {
    $bids[] = trim($arr[$i + 1]) . '/' . trim($arr[$i]);
  }
  // sell1 volume/price to sell5 volume/price => indexes 20..29
  for ($i = 20; $i <= 28; $i += 2) {
    $asks[] = trim($arr[$i + 1]) . '/' . trim($arr[$i]);
  }

  return [
    'code' => $symbol,
    'name' => $name,
    'time' => trim($date . ' ' . $time),
    'lastPrice' => $last,
    'prevClose' => $prevClose,
    'openPrice' => $open,
    'changeAmount' => ($changeAmount === null) ? '' : number_format($changeAmount, 2, '.', ''),
    'low' => $low,
    'high' => $high,
    'changePct' => ($changePct === null) ? '' : number_format($changePct, 2, '.', ''),
    'volume' => $volumeHands, // label is "手"
    'amount' => $amountWan,   // label is "万元"
    'bidPrice' => $bidPrice,
    'askPrice' => $askPrice,
    'bidStrength' => '',
    'bids' => $bids,
    'asks' => $asks,
    'source' => 'sina',
  ];
}

function sw_fetch_kline_data($symbol, $scale, $datalen) {
  $symbol = sw_normalize_symbol($symbol);
  if (!$symbol) return array();

  $scale = (int)$scale;
  $datalen = (int)$datalen;
  if ($scale <= 0) $scale = 5;
  if ($datalen <= 0) $datalen = 120;
  if ($datalen > 1023) $datalen = 1023;

  $url = 'http://money.finance.sina.com.cn/quotes_service/api/json_v2.php/CN_MarketData.getKLineData?symbol=' .
    rawurlencode($symbol) . '&scale=' . $scale . '&ma=no&datalen=' . $datalen;

  $raw = sw_http_get($url);
  if (!$raw) return array();

  $raw = trim(sw_to_utf8($raw));
  if ($raw === '' || strtolower($raw) === 'null') return array();

  $arr = json_decode($raw, true);
  if (!is_array($arr)) return array();

  $rows = array();
  foreach ($arr as $item) {
    if (!is_array($item)) continue;
    $rows[] = array(
      'day' => isset($item['day']) ? (string)$item['day'] : '',
      'open' => isset($item['open']) ? (float)$item['open'] : 0.0,
      'high' => isset($item['high']) ? (float)$item['high'] : 0.0,
      'low' => isset($item['low']) ? (float)$item['low'] : 0.0,
      'close' => isset($item['close']) ? (float)$item['close'] : 0.0,
      'volume' => isset($item['volume']) ? (float)$item['volume'] : 0.0,
    );
  }
  return $rows;
}

function sw_fetch_stock_info_from_kline($symbol) {
  $symbol = sw_normalize_symbol($symbol);
  if (!$symbol) return null;

  $dayRows = sw_fetch_kline_data($symbol, 240, 3);
  if (!is_array($dayRows) || count($dayRows) < 1) return null;

  $lastRow = $dayRows[count($dayRows) - 1];
  $prevRow = (count($dayRows) >= 2) ? $dayRows[count($dayRows) - 2] : null;

  $last = $lastRow['close'];
  $prevClose = $prevRow ? $prevRow['close'] : $last;
  $open = $lastRow['open'];
  $high = $lastRow['high'];
  $low = $lastRow['low'];
  $volumeHands = (string)round($lastRow['volume'] / 100.0);
  $amountWan = ''; // K-line endpoint does not provide turnover amount.
  $changeAmount = $last - $prevClose;
  $changePct = ((float)$prevClose == 0.0) ? 0.0 : ($changeAmount / $prevClose * 100.0);

  $minuteRows = sw_fetch_kline_data($symbol, 5, 1);
  $time = $lastRow['day'];
  if (is_array($minuteRows) && count($minuteRows) > 0) {
    $time = $minuteRows[count($minuteRows) - 1]['day'];
  }

  return array(
    'code' => $symbol,
    'name' => $symbol, // no stock name in this endpoint
    'time' => $time,
    'lastPrice' => number_format($last, 3, '.', ''),
    'prevClose' => number_format($prevClose, 3, '.', ''),
    'openPrice' => number_format($open, 3, '.', ''),
    'changeAmount' => number_format($changeAmount, 3, '.', ''),
    'low' => number_format($low, 3, '.', ''),
    'high' => number_format($high, 3, '.', ''),
    'changePct' => number_format($changePct, 2, '.', ''),
    'volume' => $volumeHands,
    'amount' => $amountWan,
    'bidPrice' => '',
    'askPrice' => '',
    'bidStrength' => '',
    'bids' => array('', '', '', '', ''),
    'asks' => array('', '', '', '', ''),
    'source' => 'sina-kline',
  );
}

function sw_fetch_stock_image_binary($symbol, $imgMode) {
  $symbol = sw_normalize_symbol($symbol);
  if (!$symbol) return null;

  // minute => min chart; k_day/k_week/k_month => daily/weekly/monthly chart.
  $imgMode = strtolower(trim((string)$imgMode));
  $path = null;
  if ($imgMode === 'minute') {
    $path = 'min';
  } else {
    if ($imgMode === 'k_day') $path = 'daily';
    if ($imgMode === 'k_week') $path = 'weekly';
    if ($imgMode === 'k_month') $path = 'monthly';
    if (!$path) return null;
  }

  $url = 'http://image.sinajs.cn/newchart/' . $path . '/n/' . rawurlencode($symbol) . '.gif';
  $bin = sw_http_get($url);
  if (!$bin || strlen($bin) < 10) return null;
  return $bin;
}

function sw_fetch_stock_chart_svg($symbol, $imgMode) {
  $symbol = sw_normalize_symbol($symbol);
  if (!$symbol) return null;

  $imgMode = strtolower(trim((string)$imgMode));
  if ($imgMode === 'minute') {
    $rows = sw_fetch_kline_data($symbol, 5, 120);
    if (count($rows) < 2) return null;
    return sw_render_minute_svg($symbol, $rows);
  }

  // k_day / k_week / k_month fallback
  $rows = sw_fetch_kline_data($symbol, 240, 120);
  if (count($rows) < 2) return null;
  return sw_render_k_svg($symbol, $rows);
}

function sw_render_minute_svg($symbol, $rows) {
  $w = 860;
  $h = 470;
  $padL = 56;
  $padR = 24;
  $padT = 24;
  $padB = 28;

  $priceTop = $padT;
  $priceH = 280;
  $volTop = $priceTop + $priceH + 26;
  $volH = 110;
  $plotW = $w - $padL - $padR;

  $minP = 999999999.0;
  $maxP = -999999999.0;
  $maxV = 0.0;
  foreach ($rows as $r) {
    if ($r['close'] < $minP) $minP = $r['close'];
    if ($r['close'] > $maxP) $maxP = $r['close'];
    if ($r['volume'] > $maxV) $maxV = $r['volume'];
  }
  if ($maxP <= $minP) {
    $maxP = $minP + 1.0;
  }
  if ($maxV <= 0.0) $maxV = 1.0;

  $n = count($rows);
  $step = ($n > 1) ? ($plotW / ($n - 1)) : $plotW;
  $linePts = array();
  $bars = array();
  for ($i = 0; $i < $n; $i++) {
    $x = $padL + $step * $i;
    $p = $rows[$i]['close'];
    $v = $rows[$i]['volume'];
    $y = $priceTop + ($maxP - $p) / ($maxP - $minP) * $priceH;
    $linePts[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');

    $bh = ($v / $maxV) * $volH;
    $by = $volTop + $volH - $bh;
    $bw = max(1.0, $step * 0.65);
    $bars[] = '<rect x="' . number_format($x - $bw / 2.0, 2, '.', '') . '" y="' . number_format($by, 2, '.', '') .
      '" width="' . number_format($bw, 2, '.', '') . '" height="' . number_format($bh, 2, '.', '') .
      '" fill="#9ec5ff" />';
  }

  $bg = '<rect x="0" y="0" width="' . $w . '" height="' . $h . '" fill="#ffffff" />';
  $grid = '';
  for ($i = 0; $i <= 4; $i++) {
    $y = $priceTop + $priceH * ($i / 4.0);
    $grid .= '<line x1="' . $padL . '" y1="' . number_format($y, 2, '.', '') . '" x2="' . ($w - $padR) .
      '" y2="' . number_format($y, 2, '.', '') . '" stroke="#e8eef7" stroke-width="1" />';
  }
  $grid .= '<line x1="' . $padL . '" y1="' . ($volTop + $volH) . '" x2="' . ($w - $padR) . '" y2="' . ($volTop + $volH) .
    '" stroke="#e8eef7" stroke-width="1" />';

  $poly = '<polyline fill="none" stroke="#1f78ff" stroke-width="2" points="' . implode(' ', $linePts) . '" />';
  $text = '<text x="' . $padL . '" y="16" fill="#333" font-size="14">SINA ' . htmlspecialchars(strtoupper($symbol), ENT_QUOTES, 'UTF-8') .
    ' 5分钟线(含量)</text>';
  $minTxt = '<text x="' . $padL . '" y="' . ($priceTop + $priceH + 16) . '" fill="#666" font-size="12">价格区间: ' .
    number_format($minP, 3, '.', '') . ' - ' . number_format($maxP, 3, '.', '') . '</text>';

  $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">' .
    $bg . $grid . implode('', $bars) . $poly . $text . $minTxt . '</svg>';
  return $svg;
}

function sw_render_k_svg($symbol, $rows) {
  $w = 860;
  $h = 430;
  $padL = 56;
  $padR = 24;
  $padT = 24;
  $padB = 32;
  $plotW = $w - $padL - $padR;
  $plotH = $h - $padT - $padB;

  $minP = 999999999.0;
  $maxP = -999999999.0;
  foreach ($rows as $r) {
    if ($r['low'] < $minP) $minP = $r['low'];
    if ($r['high'] > $maxP) $maxP = $r['high'];
  }
  if ($maxP <= $minP) $maxP = $minP + 1.0;

  $n = count($rows);
  $step = ($n > 0) ? ($plotW / $n) : $plotW;
  $barW = max(2.0, $step * 0.6);
  $shapes = array();

  for ($i = 0; $i < $n; $i++) {
    $r = $rows[$i];
    $x = $padL + $step * $i + $step / 2.0;
    $yHigh = $padT + ($maxP - $r['high']) / ($maxP - $minP) * $plotH;
    $yLow = $padT + ($maxP - $r['low']) / ($maxP - $minP) * $plotH;
    $yOpen = $padT + ($maxP - $r['open']) / ($maxP - $minP) * $plotH;
    $yClose = $padT + ($maxP - $r['close']) / ($maxP - $minP) * $plotH;
    $up = ($r['close'] >= $r['open']);
    $color = $up ? '#ef5350' : '#2ca02c';
    $yTop = min($yOpen, $yClose);
    $bh = abs($yClose - $yOpen);
    if ($bh < 1.0) $bh = 1.0;

    $shapes[] = '<line x1="' . number_format($x, 2, '.', '') . '" y1="' . number_format($yHigh, 2, '.', '') .
      '" x2="' . number_format($x, 2, '.', '') . '" y2="' . number_format($yLow, 2, '.', '') .
      '" stroke="' . $color . '" stroke-width="1" />';
    $shapes[] = '<rect x="' . number_format($x - $barW / 2.0, 2, '.', '') . '" y="' . number_format($yTop, 2, '.', '') .
      '" width="' . number_format($barW, 2, '.', '') . '" height="' . number_format($bh, 2, '.', '') .
      '" fill="' . $color . '" />';
  }

  $bg = '<rect x="0" y="0" width="' . $w . '" height="' . $h . '" fill="#ffffff" />';
  $grid = '';
  for ($i = 0; $i <= 4; $i++) {
    $y = $padT + $plotH * ($i / 4.0);
    $grid .= '<line x1="' . $padL . '" y1="' . number_format($y, 2, '.', '') . '" x2="' . ($w - $padR) .
      '" y2="' . number_format($y, 2, '.', '') . '" stroke="#e8eef7" stroke-width="1" />';
  }
  $text = '<text x="' . $padL . '" y="16" fill="#333" font-size="14">SINA ' . htmlspecialchars(strtoupper($symbol), ENT_QUOTES, 'UTF-8') .
    ' 日K(回退绘制)</text>';

  $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">' .
    $bg . $grid . implode('', $shapes) . $text . '</svg>';
  return $svg;
}

