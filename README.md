<div align="center">

# IPX.EE

**IP 地理信息查询 - TanStack Start + Go**

<p>
  <img src="https://img.shields.io/badge/TanStack%20Start-RC-ff4154?style=for-the-badge" alt="TanStack Start">
  <img src="https://img.shields.io/badge/Base%20UI-1.6-111111?style=for-the-badge" alt="Base UI">
  <img src="https://img.shields.io/badge/Go-1.26-00ADD8?style=for-the-badge&logo=go&logoColor=white" alt="Go">
  <img src="https://img.shields.io/badge/license-MIT-brightgreen?style=for-the-badge" alt="License">
</p>

<p>
  <a href="https://ipx.ee">ipx.ee</a>
</p>

</div>

---

## 概述

IPX.EE 是一个 IP 地址地理信息查询服务。当前主线采用 TanStack Start 构建首页与交互界面，Go 提供 IP / GeoIP JSON API。

旧 PHP 实现仍保留在仓库中用于参考和回滚，但新首页已经迁移到 React / TanStack Start。

---

## 技术栈

- 前端：TanStack Start、TanStack Router、React、TypeScript、Vite
- UI：Base UI 无样式组件 + 自定义 CSS
- 图标：lucide-react
- 后端：Go `net/http`
- GeoIP：ip-api.com Pro / ip-api.com，服务端缓存
- 部署入口：Nginx 反向代理到前端服务与 Go API 服务

---

## 本地开发

```bash
# 安装前端依赖
npm install

# 启动 Go API
cd go-backend
PORT=18080 PROJECT_ROOT=$(pwd)/.. go run main.go

# 另开终端启动 TanStack Start
npm run dev
```

开发环境中，TanStack Start 会通过 `src/start.ts` 中间件把 `/api/*` 代理到 Go 后端（默认 `http://127.0.0.1:18080`）。**Go 后端必须先启动**，否则 API 会返回 503。

---

## API

| 路由 | 说明 |
| --- | --- |
| `/api/health` | 健康检查 |
| `/api/ip` | 返回当前访问者 GeoIP JSON |
| `/api/lookup/{ip-or-domain}` | 返回指定 IP 或域名 GeoIP JSON |
| `/api/raw/{ip-or-domain}` | 返回标准化结果并包含 ip-api 原始 JSON |
| `/api/fields` | 返回可用字段、默认字段和语言列表 |
| `https://api.ipx.ee/ip` | 线上 API 域名查询当前访问者 |
| `https://api.ipx.ee/{ip-or-domain}` | 线上 API 域名查询指定目标 |

支持的查询参数：

- `fields`：ip-api 字段列表，例如 `status,message,query,country,city,isp,as`
- `lang`：返回语言，默认 `en`，支持 `en`、`de`、`es`、`pt-BR`、`fr`、`ja`、`zh-CN`、`ru`
- `raw=1`：在响应中包含 ip-api 原始 JSON

首页和前端 `/api` 页面都提供国旗语言入口。用户选择语言后，前端会把对应 `lang` 参数传给后端，GeoIP 输出会按该语言返回。

---

## 验证

```bash
npm run typecheck
npm run build
cd go-backend && go test ./...
```

---

## License

MIT
