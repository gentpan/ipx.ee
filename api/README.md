# ipx.ee API

Go 服务负责 IP 文本响应与 GeoIP JSON API。浏览器前端由仓库根目录的 TanStack Start 应用提供。

- `ipx.ee`
  - `curl ipx.ee` 返回当前连接的公网 IP
  - 浏览器请求由 Caddy 转发到 Node SSR；Go 内置页面仅作为直接访问后端时的降级响应
- `v4.ipx.ee`
  - 只返回 IPv4 文本 IP
- `v6.ipx.ee`
  - 只返回 IPv6 文本 IP
- `api.ipx.ee`
  - `GET /ip` 返回当前访问者的 GeoIP JSON
  - `GET /{ip-or-domain}` 返回指定 IP 或域名的 GeoIP JSON

## 配置

以下配置带有内置默认值：

- `SITE_HOST=ipx.ee`
- `API_HOST=api.ipx.ee`
- `V4_HOST=v4.ipx.ee`
- `V6_HOST=v6.ipx.ee`
- `IP_API_ENDPOINT=https://pro.ip-api.com/json/`
- `IP_API_FIELDS=21229083`
- `IP_API_LANG=en`

`IP_API_KEY` 不提供默认值，必须通过环境变量注入，禁止提交到仓库。

## 构建

```bash
cd api
go build -o api .
```

## 运行

```bash
cd api
PORT=18080 \
PROJECT_ROOT="$(pwd)/.." \
IP_API_KEY="replace-with-your-ip-api-key" \
./api
```

## DNS

- `ipx.ee` 配 `A + AAAA`
- `api.ipx.ee` 配 `A + AAAA`
- `v4.ipx.ee` 只配 `A`
- `v6.ipx.ee` 只配 `AAAA`

## API 示例

```bash
curl ipx.ee
curl v4.ipx.ee
curl v6.ipx.ee
curl https://api.ipx.ee/ip
curl https://api.ipx.ee/24.48.0.1
curl https://api.ipx.ee/example.com
curl https://api.ipx.ee/24.48.0.1
```
