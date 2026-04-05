# ip.bluecdn.com Go Full-Stack Deployment

当前 Go 服务已同时提供：

- 页面路由：`/`、`/ip/{ip}`、`/api`、`/about`、`/contact`
- API 路由：`/ip`、`/jsonip`、`/geoip`、`/geoip/{ip}`、`/health`
- 静态资源：`/assets/*`、favicon、`site.webmanifest`

并默认开启：

- `Access-Control-Allow-Origin: *`
- `Access-Control-Allow-Methods: GET, OPTIONS`

## 环境变量

- `PORT` 默认 `18080`
- `PROJECT_ROOT` 默认 `..`（用于定位 `assets/`、`data/`）
- `IP2R_V4_DB` 默认 `${PROJECT_ROOT}/data/high_full.xdb`
- `IP2R_V6_DB` 默认 `${PROJECT_ROOT}/data/high_full_v6.xdb`
- `IP2R_CACHE_POLICY` 默认 `vectorIndex`（`file` / `vectorIndex` / `content`）
- `IP2R_SEARCHERS` 默认 `20`
- `IP2R_VERIFY_DB` 默认 `true`
- `APP_TIMEZONE` 默认 `Asia/Tashkent`

## 构建

```bash
cd /Users/gentpan/projects/ip.bluecdn.com/go-backend
GOCACHE=/Users/gentpan/projects/ip.bluecdn.com/.gocache go build -o backend .
```

## 本地运行

```bash
cd /Users/gentpan/projects/ip.bluecdn.com/go-backend
PORT=18080 \
PROJECT_ROOT=/Users/gentpan/projects/ip.bluecdn.com \
IP2R_V4_DB=/Users/gentpan/projects/ip.bluecdn.com/data/high_full.xdb \
IP2R_V6_DB=/Users/gentpan/projects/ip.bluecdn.com/data/high_full_v6.xdb \
IP2R_CACHE_POLICY=vectorIndex \
IP2R_SEARCHERS=20 \
IP2R_VERIFY_DB=true \
APP_TIMEZONE=Asia/Tashkent \
./backend
```

## systemd

拷贝 `ip-bluecdn-go.service.example` 到 `/etc/systemd/system/ip-bluecdn-go.service`，然后：

```bash
sudo systemctl daemon-reload
sudo systemctl enable ip-bluecdn-go
sudo systemctl restart ip-bluecdn-go
sudo systemctl status ip-bluecdn-go
```

## 宝塔 / Nginx 反代（整站走 Go）

站点配置中保留证书与域名监听，把 `location /` 改为反代到 Go：

```nginx
location / {
  proxy_pass http://127.0.0.1:18080;
  proxy_http_version 1.1;
  proxy_set_header Host $host;
  proxy_set_header X-Real-IP $remote_addr;
  proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
  proxy_set_header X-Forwarded-Proto $scheme;
}
```

## 验证

```bash
curl -i https://ip.bluecdn.com/health
curl -i https://ip.bluecdn.com/ip
curl -i https://ip.bluecdn.com/jsonip
curl -i https://ip.bluecdn.com/geoip/8.8.8.8
curl -i https://ip.bluecdn.com/api
```

## 说明

- 现在页面和 API 都由 Go 直接提供，不再依赖 PHP 页面渲染。
- `scripts/xdb_auto_update.php` 仍可继续用于 xdb 文件自动更新（不影响 Go 服务读取）。
