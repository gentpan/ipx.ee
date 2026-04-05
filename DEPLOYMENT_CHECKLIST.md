# ip.bluecdn.com 完整部署与验收文档（PHP 前端 + Go API）

更新时间：2026-02-28

## 1. 目标架构

- `ip.bluecdn.com`
  - 前端页面：PHP（首页、API 文档、about、contact）
  - 可选：API 路由也可转发到 Go
- `ip-api.bluecdn.com`
  - 纯 API：Go
- `ipv4.bluecdn.com`
  - 纯文本 IP（仅 IPv4）
- `ipv6.bluecdn.com`
  - 纯文本 IP（仅 IPv6）

当前约定的 4 个核心 API：

1. `GET /ip`：纯文本返回访问者公网 IP
2. `GET /jsonip`：JSON/JSONP 返回访问者 IP（默认仅 `{"ip":"..."}`）
3. `GET /geoip`：JSON/JSONP 返回访问者 IP 地理信息
4. `GET /geoip/{ip}`：JSON/JSONP 返回指定 IP 地理信息（支持 IPv4/IPv6）

---

## 2. DNS 配置（必须）

推荐：

- `ip.bluecdn.com`：A + AAAA
- `ip-api.bluecdn.com`：A + AAAA
- `ipv4.bluecdn.com`：仅 A
- `ipv6.bluecdn.com`：仅 AAAA

检查命令：

```bash
dig +short A ip.bluecdn.com
dig +short AAAA ip.bluecdn.com

dig +short A ip-api.bluecdn.com
dig +short AAAA ip-api.bluecdn.com

dig +short A ipv4.bluecdn.com
dig +short AAAA ipv4.bluecdn.com

dig +short A ipv6.bluecdn.com
dig +short AAAA ipv6.bluecdn.com
```

验收标准：

- `ipv4.bluecdn.com` 有 A、无 AAAA
- `ipv6.bluecdn.com` 有 AAAA、无 A
- `ip-api.bluecdn.com` 同时有 A + AAAA

---

## 3. 代码目录确认

项目根目录：`/Users/gentpan/projects/ip.bluecdn.com`

关键文件：

- PHP 站点：`/Users/gentpan/projects/ip.bluecdn.com/index.php`
- Go API：`/Users/gentpan/projects/ip.bluecdn.com/go-backend/main.go`
- XDB 数据：
  - `/Users/gentpan/projects/ip.bluecdn.com/data/high_full.xdb`
  - `/Users/gentpan/projects/ip.bluecdn.com/data/high_full_v6.xdb`

---

## 4. Go API 构建与运行

### 4.1 构建

```bash
cd /Users/gentpan/projects/ip.bluecdn.com/go-backend
GOCACHE=/Users/gentpan/projects/ip.bluecdn.com/.gocache go build -o /Users/gentpan/projects/ip.bluecdn.com/go-backend/ip-api
```

### 4.2 手工运行（调试）

```bash
cd /Users/gentpan/projects/ip.bluecdn.com/go-backend
PORT=18080 \
./ip-api
```

### 4.3 systemd 运行（生产）

示例文件已提供：

- `/Users/gentpan/projects/ip.bluecdn.com/go-backend/ip-bluecdn-go.service.example`

部署：

```bash
sudo cp /Users/gentpan/projects/ip.bluecdn.com/go-backend/ip-bluecdn-go.service.example /etc/systemd/system/ip-bluecdn-go.service
sudo systemctl daemon-reload
sudo systemctl enable --now ip-bluecdn-go.service
sudo systemctl status ip-bluecdn-go.service
```

---

## 5. 宝塔/Nginx 配置（关键）

## 5.1 `ip.bluecdn.com`（PHP 主站）

- 保持 PHP 站点能力
- 如果你希望 API 也走 Go，则添加反代 location（放在 `location /` 前）

```nginx
location = /ip {
    proxy_pass http://127.0.0.1:18080/ip;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}

location = /jsonip {
    proxy_pass http://127.0.0.1:18080/jsonip$is_args$args;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}

location = /geoip {
    proxy_pass http://127.0.0.1:18080/geoip$is_args$args;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}

location ^~ /geoip/ {
    proxy_pass http://127.0.0.1:18080;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}
```

## 5.2 `ip-api.bluecdn.com`（建议独立 API 域名）

- 新建一个 Nginx 站点，全部反代到 Go：

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name ip-api.bluecdn.com;

    location / {
        proxy_pass http://127.0.0.1:18080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_http_version 1.1;
    }
}
```

## 5.3 `ipv4.bluecdn.com` 与 `ipv6.bluecdn.com`

分别使用各自目录：

- `ipv4` 根目录：`/Users/gentpan/projects/ip.bluecdn.com/ipv4`
- `ipv6` 根目录：`/Users/gentpan/projects/ip.bluecdn.com/ipv6`

验收命令：

```bash
curl ipv4.bluecdn.com
curl ipv6.bluecdn.com
curl -4 ip.bluecdn.com
curl -6 ip.bluecdn.com
curl ip.bluecdn.com
```

---

## 6. CORS 与 JSONP 规则

默认要求（已实现）：

- `Access-Control-Allow-Origin: *`
- `Access-Control-Allow-Methods: GET, OPTIONS`
- `Access-Control-Allow-Headers: Content-Type, X-Requested-With`

JSONP：

- `/jsonip?callback=fn`
- `/geoip?callback=fn`
- `/geoip/{ip}?callback=fn`

`/jsonip` 默认输出保持最简：

```json
{"ip":"2a05:..."}
```

---

## 7. 全量接口验收（逐条）

## 7.1 基础可用性

```bash
curl -i https://ip.bluecdn.com/ip
curl -i https://ip.bluecdn.com/jsonip
curl -i https://ip.bluecdn.com/geoip
curl -i https://ip.bluecdn.com/geoip/8.8.8.8
```

检查：

- 状态码 200
- `Access-Control-Allow-Origin: *` 存在

## 7.2 JSONP

```bash
curl 'https://ip.bluecdn.com/jsonip?callback=getip'
curl 'https://ip.bluecdn.com/geoip?callback=getgeo'
```

检查：

- 返回形如：`getip({...});`

## 7.3 协议出口验证

```bash
curl ip.bluecdn.com
curl -4 ip.bluecdn.com
curl -6 ip.bluecdn.com
curl ipv4.bluecdn.com
curl ipv6.bluecdn.com
```

检查：

- `curl ipv4.bluecdn.com` 返回 IPv4
- `curl ipv6.bluecdn.com` 返回 IPv6
- `curl -4/-6 ip.bluecdn.com` 分别走对应协议

---

## 8. 常见问题排查

## 8.1 `/ip` 返回 404

优先检查站点 root 是否正确。

错误示例（会 404）：

- `root /path/to/ip.bluecdn.com/ip;`

正确示例：

- `root /path/to/ip.bluecdn.com;`

## 8.2 `curl ipv6.bluecdn.com` 返回 301

通常是站点没有命中 ipv6 专属 server，落到默认站点或 https 强制跳转。

检查：

- `server_name ipv6.bluecdn.com;`
- `listen [::]:80 ipv6only=on;`
- DNS AAAA 是否正确

## 8.3 Go 服务正常但 API 不通

检查：

```bash
sudo systemctl status ip-bluecdn-go.service
curl -i http://127.0.0.1:18080/health
nginx -t
sudo systemctl reload nginx
```

---

## 9. 回滚方案

如果 Go API 出现故障，可临时回滚到 PHP：

1. 注释 `ip.bluecdn.com` 里 API 的 proxy location
2. `nginx -t && systemctl reload nginx`
3. 保持 Go 服务不影响页面（或停掉）

---

## 10. 最终建议（当前项目）

- 前端页面继续 PHP（你现在已完成）
- API 主路径走 Go（性能与扩展更好）
- 新增 `ip-api.bluecdn.com` 作为纯 API 域名（便于后续限流、监控、独立发布）
- `jsonip` 默认保持最简输出，不引入专业模式字段


---

## 11. 自建 xdb 生成流程（可选）

你当前线上使用的是付费下载 xdb（自动更新）。
如果后续要自己生成/编辑 xdb，可按 ip2region 官方 `xdb_maker` 流程：

### 11.1 编译 xdb_maker

```bash
# 切换到 ip2region 的 golang maker 目录
make
```

产物：`xdb_maker`

### 11.2 生成 xdb 文件

```bash
# IPv4
./xdb_maker gen --src=../../data/ipv4_source.txt --dst=./ip2region_v4.xdb --version=ipv4

# IPv6
./xdb_maker gen --src=../../data/ipv6_source.txt --dst=./ip2region_v6.xdb --version=ipv6
```

可选：`--field-list` 自定义字段。

### 11.3 查询测试

```bash
# IPv4
./xdb_maker search --db=../../data/ip2region_v4.xdb

# IPv6
./xdb_maker search --db=../../data/ip2region_v6.xdb
```

### 11.4 数据编辑（可选）

```bash
# IPv4 源数据编辑
./xdb_maker edit --src=../../data/ipv4_source.txt --version=ipv4

# IPv6 源数据编辑
./xdb_maker edit --src=../../data/ipv6_source.txt --version=ipv6
```

常用命令：`put` / `put_file` / `save`。

### 11.5 bench 校验（强烈建议）

```bash
# IPv4
./xdb_maker bench --db=../../data/ip2region_v4.xdb --src=../../data/ipv4_source.txt --version=ipv4

# IPv6
./xdb_maker bench --db=../../data/ip2region_v6.xdb --src=../../data/ipv6_source.txt --version=ipv6
```

注意：`bench` 使用的 `src` 必须与生成该 xdb 的源文件一致。

### 11.6 接入到当前项目

生成完成后，将文件替换为当前线上文件名：

- `high_full.xdb`（IPv4）
- `high_full_v6.xdb`（IPv6）

路径：`/Users/gentpan/projects/ip.bluecdn.com/data/`

然后重启 Go API：

```bash
sudo systemctl restart ip-bluecdn-go.service
```

### 11.7 与自动下载方案的关系

- 自动下载方案：适合生产稳定更新，运维成本低。
- 自建生成方案：适合你需要自定义数据字段、手工修订、定制数据源。

建议：

- 生产默认用自动下载。
- 自建生成用于测试/定制，再灰度替换线上 xdb。
