# ipx.ee Go Backend

这个后端现在只保留 4 个域名行为：

- `ipx.ee`
  - `curl ipx.ee` 返回当前连接的公网 IP
  - 浏览器访问时展示一个极简页面，并自动探测 `v4.ipx.ee` / `v6.ipx.ee`
- `v4.ipx.ee`
  - 只返回 IPv4 文本 IP
- `v6.ipx.ee`
  - 只返回 IPv6 文本 IP
- `api.ipx.ee`
  - `GET /ip` 返回当前访问者的 GeoIP JSON
  - `GET /{ip-or-domain}` 返回指定 IP 或域名的 GeoIP JSON

## 内置默认值

代码里已经直接写入以下默认值，部署时不配环境变量也能跑：

- `SITE_HOST=ipx.ee`
- `API_HOST=api.ipx.ee`
- `V4_HOST=v4.ipx.ee`
- `V6_HOST=v6.ipx.ee`
- `IP_API_ENDPOINT=https://pro.ip-api.com/json/`
- `IP_API_FIELDS=21180127`
- `IP_API_LANG=zh-CN`

仍然支持通过环境变量覆盖。

## 构建

```bash
cd /Users/gentpan/projects/ipx.ee/go-backend
go build -o backend .
```

## 运行

```bash
cd /Users/gentpan/projects/ipx.ee/go-backend
PORT=18080 \
PROJECT_ROOT=/Users/gentpan/projects/ipx.ee \
./backend
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
