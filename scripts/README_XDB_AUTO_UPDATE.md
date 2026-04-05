# XDB 自动更新说明

## 1) 脚本位置

- `/Users/gentpan/projects/ip.bluecdn.com/scripts/xdb_auto_update.php`

## 2) 功能

- 同步下载并更新 v4 + v6 xdb 文件
- 下载到临时文件后 `rename` 原子替换
- 跟踪 HTTP 重定向（符合 ip2region 外用下载 API 要求）
- 每日最多下载 12 次（可配置）
- 计划门控：每月 2 号 00:00 后执行一次（可配置）
- 更新状态写入 `data/xdb_update_state.json`，页面“上次更新日期/下次更新日期”自动同步显示

## 3) 运行

```bash
php /Users/gentpan/projects/ip.bluecdn.com/scripts/xdb_auto_update.php
```

强制更新（忽略时间窗口）：

```bash
php /Users/gentpan/projects/ip.bluecdn.com/scripts/xdb_auto_update.php --force
```

## 4) crontab（推荐每小时跑一次，由脚本内部门控）

```cron
0 * * * * /usr/bin/php /Users/gentpan/projects/ip.bluecdn.com/scripts/xdb_auto_update.php >> /Users/gentpan/projects/ip.bluecdn.com/data/xdb_update.log 2>&1
```

## 5) 关键环境变量（见 `.env`）

- `XDB_V4_DOWNLOAD_URL`
- `XDB_V6_DOWNLOAD_URL`
- `XDB_AUTO_MAX_DAILY_DOWNLOADS=12`
- `XDB_META_UPDATE_DAY=2`
- `XDB_META_UPDATE_HOUR=0`
- `XDB_META_UPDATE_MINUTE=0`
- `XDB_UPDATE_STATE_FILE`
- `XDB_UPDATE_LOCK_FILE`

