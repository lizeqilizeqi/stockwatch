<?php
$basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
if ($basePath === '/' || $basePath === '\\') $basePath = '';
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>股票看盘 Pro</title>
  <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
  <style>
    :root{
      --bg:#0a1225;
      --card:#111d36;
      --line:#213559;
      --text:#d6e2ff;
      --muted:#8ea4cc;
      --accent:#48a8ff;
      --up:#ff4d5f;
      --down:#2bc98a;
    }
    *{box-sizing:border-box}
    body{margin:0;background:radial-gradient(circle at 15% 10%, #172d4f 0%, var(--bg) 45%);color:var(--text);font-family:Arial,"Microsoft YaHei",sans-serif}
    .wrap{max-width:1200px;margin:0 auto;padding:18px}
    .card{background:linear-gradient(180deg, rgba(20,34,61,.95), rgba(14,24,44,.95));border:1px solid var(--line);border-radius:14px;padding:14px 16px;margin-bottom:14px;box-shadow:0 10px 24px rgba(0,0,0,.25)}
    .top{display:flex;gap:10px;align-items:end;flex-wrap:wrap}
    label{font-size:12px;color:var(--muted);display:block;margin-bottom:6px}
    input{background:#0b172e;border:1px solid #27406e;color:#dce8ff;padding:10px 12px;border-radius:10px;min-width:230px}
    button{background:#2a6ef6;color:#fff;border:0;border-radius:10px;padding:10px 14px;cursor:pointer}
    button.sec{background:#344c78}
    .title{font-size:20px;font-weight:700}
    .muted{color:var(--muted);font-size:12px}
    .metrics{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}
    .m{background:#0f1c35;border:1px solid #223758;border-radius:10px;padding:10px}
    .m .k{font-size:12px;color:var(--muted)}
    .m .v{margin-top:6px;font-size:18px;font-weight:700}
    .charts{display:grid;grid-template-columns:1fr;gap:14px}
    .chartBox{height:440px;border:1px solid #223758;border-radius:12px;background:#0c1830}
    .tabs{display:flex;gap:8px}
    .tab{padding:6px 10px;border-radius:8px;background:#152746;border:1px solid #27406e;color:#cfe0ff;font-size:12px;cursor:pointer}
    .tab.active{background:#215ac5}
    .hint{margin-top:8px;font-size:12px;color:var(--muted)}
    @media (max-width:1000px){.metrics{grid-template-columns:repeat(3,minmax(0,1fr))}}
    @media (max-width:680px){.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.chartBox{height:360px}}
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="top">
      <div>
        <label>股票代码（如 `sz002261` 或 `600519`）</label>
        <input id="symbolInput" placeholder="例：300033">
      </div>
      <button id="btnLoad">加载看盘</button>
      <button id="btnRefresh" class="sec">手动刷新</button>
      <div style="margin-left:auto">
        <div class="title" id="titleLine">未加载</div>
        <div class="muted" id="timeLine">—</div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="metrics">
      <div class="m"><div class="k">最新价</div><div class="v" id="lastPrice">—</div></div>
      <div class="m"><div class="k">涨跌额</div><div class="v" id="changeAmount">—</div></div>
      <div class="m"><div class="k">涨跌幅</div><div class="v" id="changePct">—</div></div>
      <div class="m"><div class="k">成交量（手）</div><div class="v" id="volume">—</div></div>
      <div class="m"><div class="k">成交额（万元）</div><div class="v" id="amount">—</div></div>
      <div class="m"><div class="k">今开盘</div><div class="v" id="openPrice">—</div></div>
    </div>
    <div id="errLine" class="hint"></div>
  </div>

  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
      <div class="tabs">
        <button class="tab active" data-mode="minute">分时图</button>
        <button class="tab" data-mode="day">日K图</button>
      </div>
      <div class="muted">数据源：新浪接口（优先实时，失败回退K线）</div>
    </div>
    <div style="height:10px;"></div>
    <div id="chartMain" class="chartBox"></div>
  </div>
</div>

<script>
const basePath = <?php echo json_encode($basePath); ?>;
function urlFor(rel){ return basePath ? basePath.replace(/\/+$/,'') + '/' + rel.replace(/^\/+/,'') : rel; }
function $(id){ return document.getElementById(id); }
function setText(id,val){ $(id).textContent = (val===undefined||val===null||val==='')?'—':String(val); }

function normalizeSymbol(sym){
  const s=(sym||'').trim().toLowerCase();
  if(!s) return '';
  if(/^(sh|sz)\d{6}$/.test(s)) return s;
  if(/^\d{6}$/.test(s)) return (s[0]==='6'?'sh':'sz')+s;
  return s;
}

function colorByNumber(v){
  const n=parseFloat(String(v).replace('%',''));
  if (Number.isNaN(n)) return '#d6e2ff';
  if (n>0) return '#ff4d5f';
  if (n<0) return '#2bc98a';
  return '#d6e2ff';
}

let currentSymbol = '';
let currentMode = 'minute';
const chart = echarts.init($('chartMain'));

async function fetchJson(url){
  const r = await fetch(url, {cache:'no-store'});
  const t = await r.text();
  if(!r.ok) throw new Error('HTTP '+r.status+' '+t.slice(0,120));
  let d = null;
  try { d = JSON.parse(t); } catch(e){ throw new Error('非JSON返回：'+t.slice(0,120)); }
  if (d && d.error) throw new Error(d.error);
  return d;
}

async function loadInfo(symbol){
  const info = await fetchJson(urlFor('api/stock_info.php?symbol='+encodeURIComponent(symbol)));
  setText('titleLine', (info.name||symbol.toUpperCase()) + '  ' + symbol.toUpperCase());
  setText('timeLine', info.time || '');
  setText('lastPrice', info.lastPrice);
  setText('changeAmount', info.changeAmount);
  setText('changePct', info.changePct ? (String(info.changePct).replace('%','') + '%') : '');
  setText('volume', info.volume);
  setText('amount', info.amount);
  setText('openPrice', info.openPrice);
  $('changeAmount').style.color = colorByNumber(info.changeAmount);
  $('changePct').style.color = colorByNumber(info.changePct);
  $('lastPrice').style.color = colorByNumber(info.changeAmount);
}

async function loadChart(symbol, mode){
  const data = await fetchJson(urlFor('api/stock_timeseries.php?symbol='+encodeURIComponent(symbol)+'&mode='+mode));
  if (!Array.isArray(data.labels) || data.labels.length < 2) throw new Error('图表数据不足');

  if (mode === 'minute') {
    chart.setOption({
      backgroundColor:'#0c1830',
      animation:false,
      grid:[{left:56,right:18,top:24,height:'58%'},{left:56,right:18,top:'74%',height:'16%'}],
      tooltip:{trigger:'axis',axisPointer:{type:'cross'}},
      xAxis:[
        {type:'category',data:data.labels,axisLabel:{color:'#8ea4cc',formatter:(v)=>String(v).slice(11,16)},axisLine:{lineStyle:{color:'#355486'}},axisTick:{show:false}},
        {type:'category',gridIndex:1,data:data.labels,axisLabel:{show:false},axisLine:{lineStyle:{color:'#355486'}},axisTick:{show:false}}
      ],
      yAxis:[
        {type:'value',scale:true,axisLabel:{color:'#8ea4cc'},splitLine:{lineStyle:{color:'#20385c'}},axisLine:{lineStyle:{color:'#355486'}}},
        {type:'value',gridIndex:1,axisLabel:{color:'#8ea4cc'},splitLine:{show:false},axisLine:{lineStyle:{color:'#355486'}}}
      ],
      dataZoom:[{type:'inside',xAxisIndex:[0,1],start:55,end:100},{type:'slider',xAxisIndex:[0,1],bottom:8,height:14,borderColor:'#24406d'}],
      series:[
        {name:'分时',type:'line',data:data.linePrices,smooth:true,showSymbol:false,lineStyle:{width:2,color:'#48a8ff'},areaStyle:{color:'rgba(72,168,255,0.15)'}},
        {name:'成交量',type:'bar',xAxisIndex:1,yAxisIndex:1,data:data.volumes,itemStyle:{color:'#6aa8ff'}}
      ]
    }, true);
  } else {
    chart.setOption({
      backgroundColor:'#0c1830',
      animation:false,
      grid:[{left:56,right:18,top:24,bottom:42}],
      tooltip:{trigger:'axis',axisPointer:{type:'cross'}},
      xAxis:{type:'category',data:data.labels,axisLabel:{color:'#8ea4cc',formatter:(v)=>String(v).slice(5,10)},axisLine:{lineStyle:{color:'#355486'}},axisTick:{show:false}},
      yAxis:{type:'value',scale:true,axisLabel:{color:'#8ea4cc'},splitLine:{lineStyle:{color:'#20385c'}},axisLine:{lineStyle:{color:'#355486'}}},
      dataZoom:[{type:'inside',start:60,end:100},{type:'slider',bottom:8,height:14,borderColor:'#24406d'}],
      series:[{name:'日K',type:'candlestick',data:data.ohlc,itemStyle:{color:'#ff4d5f',color0:'#2bc98a',borderColor:'#ff4d5f',borderColor0:'#2bc98a'}}]
    }, true);
  }
}

async function updateAll(raw){
  const symbol = normalizeSymbol(raw);
  currentSymbol = symbol;
  $('errLine').textContent = '';
  if(!/^(sh|sz)\d{6}$/.test(symbol)){
    $('errLine').textContent = '股票代码格式错误，请输入 sh/sz 开头或6位数字。';
    return;
  }
  try{
    await Promise.all([loadInfo(symbol), loadChart(symbol, currentMode)]);
  }catch(e){
    $('errLine').textContent = '加载失败：' + (e.message || e);
  }
}

$('btnLoad').onclick = function(){ updateAll($('symbolInput').value); };
$('btnRefresh').onclick = function(){ if(currentSymbol) updateAll(currentSymbol); };
$('symbolInput').addEventListener('keydown', function(e){ if(e.key === 'Enter'){ e.preventDefault(); updateAll(this.value); }});

document.querySelectorAll('.tab').forEach(function(btn){
  btn.addEventListener('click', function(){
    document.querySelectorAll('.tab').forEach(function(t){ t.classList.remove('active'); });
    btn.classList.add('active');
    currentMode = btn.getAttribute('data-mode');
    if(currentSymbol) loadChart(currentSymbol, currentMode).catch(function(e){ $('errLine').textContent='图表失败：'+(e.message||e); });
  });
});

window.addEventListener('resize', function(){ chart.resize(); });

const params = new URLSearchParams(window.location.search);
const initSymbol = params.get('symbol') || '300033';
$('symbolInput').value = initSymbol;
updateAll(initSymbol);
setInterval(function(){ if(currentSymbol) updateAll(currentSymbol); }, 3600 * 1000);
</script>
</body>
</html>

