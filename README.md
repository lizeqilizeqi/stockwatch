# Stockwatch

一个基于 `PHP 5.4 + WAMP` 的轻量级股票看盘项目，支持：

- 现代化深色看盘界面（ECharts 交互图）
- 实时行情卡片（最新价、涨跌幅、成交量等）
- 分时图 / 日K 图切换
- 服务端 1 小时缓存，降低第三方接口压力
- 多级回退策略，提升可用性

## 项目结构

```text
stockwatch/
├─ index.php                 # 前端页面（ECharts）
├─ api/
│  ├─ stock_info.php         # 实时行情接口（JSON）
│  ├─ stock_timeseries.php   # 分时/日K序列接口（JSON）
│  └─ stock_image.php        # 图片图表接口（GIF优先，SVG回退）
├─ lib/
│  └─ webxml_client.php      # 数据拉取/解析/回退逻辑
└─ cache/                    # 缓存目录（运行时自动生成）
```

## 数据源说明

当前实现使用新浪公开接口：

- 行情：`hq.sinajs.cn`
- K线数据：`money.finance.sina.com.cn`
- 图像：`image.sinajs.cn`

回退逻辑：

1. 优先实时接口（字段更全）
2. 若实时接口不可达，回退到 K 线接口推导核心字段
3. 图像接口失败时，后端本地生成 SVG 图返回

## 运行环境

- Windows Server（已在 2008 环境验证）
- Apache + PHP 5.4.16（WAMP）
- `allow_url_fopen = On`

## 部署步骤

1. 将项目放到站点目录，例如：`D:/wamp/www/stockwatch`
2. 确认 Apache vhost 或默认站点可访问该目录
3. 确认 Windows 防火墙入站放行 `80`（以及 `443` 如需）
4. 首次访问：`http://你的域名/`

## API 示例

- 实时信息：

```text
/api/stock_info.php?symbol=sz300033
```

- 分时序列：

```text
/api/stock_timeseries.php?symbol=sz300033&mode=minute&datalen=240
```

- 日K序列：

```text
/api/stock_timeseries.php?symbol=sz300033&mode=day&datalen=180
```

- 图像（GIF优先）：

```text
/api/stock_image.php?symbol=sz300033&img=minute
/api/stock_image.php?symbol=sz300033&img=k_day
```

## 缓存策略

- 默认缓存时间：`3600` 秒
- 缓存位置：`/cache`
- 清缓存：删除 `cache` 目录中的 `sina_*` 文件

## 常见问题

### 1) 页面可打开但数据为空

- 检查第三方接口是否可达
- 访问 `/api/stock_info.php?...` 看是否返回 `error`
- 清理缓存后重试

### 2) 外网 503，本机正常

- 常见是 Windows 防火墙或云侧安全策略拦截
- 需放行入站 `TCP 80`（和 `443`）

### 3) 代码输入格式

支持两种输入：

- `sz300033` / `sh600519`
- `300033` / `600519`（会自动推断交易所）

## License

仅用于学习与技术验证，不构成投资建议。

