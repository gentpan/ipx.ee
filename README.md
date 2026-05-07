<div align="center">

# IPX.EE

**IP 地理信息查询 — PHP + Go 混合架构**

<p>
  <img src="https://img.shields.io/badge/PHP-8.5-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/Go-1.25-00ADD8?style=for-the-badge&logo=go&logoColor=white" alt="Go">
  <img src="https://img.shields.io/badge/license-MIT-brightgreen?style=for-the-badge" alt="License">
</p>

<p>
  🌐 <a href="https://ipx.ee">ipx.ee</a>
</p>

</div>

---

## 📖 概述

IPX.EE 是一个 IP 地址地理信息查询服务，使用 ip2region xdb 数据库进行高性能本地查询。采用 PHP + Go 混合架构。

---

## ✨ 特性

- 🔍 **IP 地理位置查询** — 国家、省份、城市、区县
- 📡 **运营商识别** — ISP 信息
- 🌍 **双栈支持** — IPv4 和 IPv6
- 📊 **17 个字段** — 大洲、国家、省份、城市、区县、ISP、经纬度、时区、货币、ASN 等
- ⚡ **高性能** — 本地 xdb 数据库查询
- 🎯 **自动检测** — 首页自动识别当前 IP

---

## 📡 API

| 路由 | 说明 |
|------|------|
| `/` | 首页（自动识别客户端 IP） |
| `/ip` | 返回客户端 IP（纯文本） |
| `/jsonip` | 返回客户端 IP（JSON） |
| `/geoip` | 返回自身 IP 地理信息（JSON） |
| `/geoip/{ip}` | 返回指定 IP 地理信息（JSON） |

---

## 🚀 部署

```bash
# PHP 版本
php -S 0.0.0.0:8080

# Go 后端
cd go-backend && go mod tidy && go run main.go
```

---

## 📄 License

MIT
