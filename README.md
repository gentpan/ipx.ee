<div align="center">

# IPX.EE

**快速、现代的 IP 地址与 GeoIP 查询服务**

<p>
  <img src="https://img.shields.io/badge/TanStack%20Start-1.121-ff4154?style=for-the-badge" alt="TanStack Start">
  <img src="https://img.shields.io/badge/React-19-61dafb?style=for-the-badge&logo=react&logoColor=111827" alt="React 19">
  <img src="https://img.shields.io/badge/Vite-8-646cff?style=for-the-badge&logo=vite&logoColor=white" alt="Vite 8">
  <img src="https://img.shields.io/badge/Go-1.25-00ADD8?style=for-the-badge&logo=go&logoColor=white" alt="Go 1.25">
  <img src="https://img.shields.io/badge/license-MIT-brightgreen?style=for-the-badge" alt="License">
</p>

<p>
  <a href="https://ipx.ee">网站</a>
  ·
  <a href="https://api.ipx.ee">API</a>
</p>

</div>

---

## 概述

IPX.EE 提供公网 IPv4 / IPv6 探测、IP 或域名查询、ASN / ISP / GeoIP 信息、网络类型识别以及多语言 JSON API。

当前项目由 TanStack Start 服务端渲染应用和 Go API 服务组成，不依赖 PHP。

---

## 技术栈

- 应用框架：TanStack Start 1.121、TanStack Router、React 19
- 开发与构建：TypeScript 7、Vite 8、Node.js
- UI：Liquid Glass OSS WebGL 材质、Base UI、Lucide、自定义 CSS
- 地图：Leaflet + Google 地图瓦片
- API：Go 1.25 标准库 `net/http`
- GeoIP：ip-api Pro、磁盘 JSON 缓存、可配置 TTL
- 生产环境：Caddy、systemd、Node SSR 服务、Go API 服务

## 架构

- `ipx.ee`：浏览器访问 React SSR 页面；命令行访问返回当前公网 IP。
- `api.ipx.ee`：公开 GeoIP JSON API。
- `v4.ipx.ee`：仅通过 IPv4 提供纯文本地址。
- `v6.ipx.ee`：仅通过 IPv6 提供纯文本地址。
- 前端 `/api/*`：由 TanStack Start 中间件代理到本机 Go 服务。

---

## 本地开发

```bash
npm install

# 终端 1：Go API
cd go-backend
PORT=18080 PROJECT_ROOT=$(pwd)/.. go run main.go

# 终端 2：前端
npm run dev
```

TanStack Start 默认把 `/api/*` 代理到 `http://127.0.0.1:18080`。可通过 `API_PROXY_TARGET` 覆盖目标地址。

复制 `.env.example` 并按环境设置：

- `IP_API_KEY`：ip-api Pro 密钥，必须通过环境变量提供。
- `GEOIP_CACHE_DIR`、`GEOIP_CACHE_TTL`：缓存目录和有效期。
- `VITE_PUBLIC_API_ORIGIN`：API 构建器展示的公开地址。
- `SITE_HOST`、`API_HOST`、`V4_HOST`、`V6_HOST`：服务域名。
- `PORT`、`PROJECT_ROOT`：Go 服务监听端口和项目目录。

---

## API

- `GET /api/health`：健康检查。
- `GET /api/ip`：当前访问者的 GeoIP JSON。
- `GET /api/lookup/{ip-or-domain}`：查询指定 IP 或域名。
- `GET /api/raw/{ip-or-domain}`：标准化结果与原始上游 JSON。
- `GET /api/fields`：可用字段、默认字段和语言列表。
- `GET https://api.ipx.ee/ip`：通过公开 API 查询当前访问者。
- `GET https://api.ipx.ee/{ip-or-domain}`：通过公开 API 查询目标。

支持的查询参数：

- `fields`：字段 bitmask 或字段列表，例如 `8908315`
- `lang`：返回语言，默认 `en`，支持 `en`、`de`、`es`、`pt-BR`、`fr`、`ja`、`zh-CN`、`ru`
- `raw=1`：在响应中包含原始上游 JSON

---

## 构建与验证

```bash
npm run typecheck
npm run build
cd go-backend && go test ./...
```

生产前端由 `server.mjs` 启动，默认监听 `127.0.0.1:3012`：

```bash
npm run build
npm start
```

---

## License

MIT
