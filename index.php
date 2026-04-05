<?php

/**
 * ip.bluecdn.com — 主 IP 查询 API
 *
 * xdb 字段格式 (17 个，管道分隔):
 *   [0]  continent    大洲
 *   [1]  country      国家
 *   [2]  province     省份
 *   [3]  city         城市
 *   [4]  district     区县
 *   [5]  isp          运营商
 *   [6]  longitude    经度
 *   [7]  latitude     纬度
 *   [8]  area_code    行政区划代码
 *   [9]  phone_code   电话区号
 *   [10] zip_code     邮政编码
 *   [11] timezone     时区
 *   [12] currency     货币
 *   [13] asn          ASN
 *   [14] accuracy     精度半径
 *   [15] weather_code 气象站代码
 *   [16] country_code 国家代码
 *
 * 路由:
 *   /              → 首页（自动识别客户端 IP 并展示字段）
 *   /ip            → 返回客户端 IP（纯文本）
 *   /jsonip        → 返回客户端 IP（JSON）
 *   /geoip         → 返回客户端自身 IP 地理信息（JSON）
 *   /geoip/{ip}    → 返回指定 IP 地理信息（JSON）
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/Searcher.class.php';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/footer.php';

use ip2region\xdb\Searcher;
use ip2region\xdb\Util;
use ip2region\xdb\IPv4;
use ip2region\xdb\IPv6;

// ─── 全局 CORS（默认开启）───────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── 数据库路径 ───────────────────────────────────────────

const DB_V4 = __DIR__ . '/data/high_full.xdb';
const DB_V6 = __DIR__ . '/data/high_full_v6.xdb';
const DEFAULT_XDB_META_URL = 'https://ip2region.net/api/public/data/offline/ver_latest?t=646a0d6627.3e0.69944e81.OS002@GJB';
const XDB_META_CACHE_SCHEMA = 2;

// ─── 环境变量 ────────────────────────────────────────────

function loadEnvFile(string $envPath): void
{
    if (!is_file($envPath) || !is_readable($envPath)) return;

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if ($key === '') continue;

        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) !== false) continue;
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
}

function envValue(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value !== false) return $value;
    if (isset($_ENV[$key])) return (string)$_ENV[$key];
    return $default;
}

loadEnvFile(__DIR__ . '/.env');

function renderHeadAssetLinks(): string
{
    return <<<HTML
  <link rel="icon" type="image/x-icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <meta name="theme-color" content="#008235">
HTML;
}

// ─── XDB 元信息（版本/更新时间） ─────────────────────────

function detectUpdateScheduleLabel(): string
{
    $day = max(1, min(28, (int)(envValue('XDB_META_UPDATE_DAY', '2') ?? '2')));
    $hour = max(0, min(23, (int)(envValue('XDB_META_UPDATE_HOUR', '0') ?? '0')));
    $minute = max(0, min(59, (int)(envValue('XDB_META_UPDATE_MINUTE', '0') ?? '0')));
    return "每月 {$day} 号 " . sprintf('%02d:%02d', $hour, $minute);
}

function getMetaTimezone(): DateTimeZone
{
    $tzName = envValue('XDB_META_TIMEZONE', envValue('APP_TIMEZONE', 'Asia/Tashkent') ?? 'Asia/Tashkent') ?? 'Asia/Tashkent';
    try {
        return new DateTimeZone($tzName);
    } catch (\Exception) {
        return new DateTimeZone('Asia/Tashkent');
    }
}

function getLastScheduleAt(DateTimeZone $tz): DateTimeImmutable
{
    $now = new DateTimeImmutable('now', $tz);

    $day = max(1, min(28, (int)(envValue('XDB_META_UPDATE_DAY', '2') ?? '2')));
    $hour = max(0, min(23, (int)(envValue('XDB_META_UPDATE_HOUR', '0') ?? '0')));
    $minute = max(0, min(59, (int)(envValue('XDB_META_UPDATE_MINUTE', '0') ?? '0')));

    $currentMonthScheduled = $now
        ->setDate((int)$now->format('Y'), (int)$now->format('m'), $day)
        ->setTime($hour, $minute, 0);

    if ($now >= $currentMonthScheduled) {
        return $currentMonthScheduled;
    }

    return $currentMonthScheduled->modify('-1 month');
}

function getNextScheduleAt(DateTimeZone $tz): DateTimeImmutable
{
    $now = new DateTimeImmutable('now', $tz);

    $day = max(1, min(28, (int)(envValue('XDB_META_UPDATE_DAY', '2') ?? '2')));
    $hour = max(0, min(23, (int)(envValue('XDB_META_UPDATE_HOUR', '0') ?? '0')));
    $minute = max(0, min(59, (int)(envValue('XDB_META_UPDATE_MINUTE', '0') ?? '0')));

    $currentMonthScheduled = $now
        ->setDate((int)$now->format('Y'), (int)$now->format('m'), $day)
        ->setTime($hour, $minute, 0);

    if ($now < $currentMonthScheduled) {
        return $currentMonthScheduled;
    }

    return $currentMonthScheduled->modify('+1 month');
}

function normalizeMetaPayload(array $json): array
{
    $versionKeys = ['version', 'ver', 'xdb_version', 'data_version', 'name', 'tag'];
    $timeKeys = ['released_dt', 'released_at', 'update_time', 'updated_at', 'last_update', 'release_time', 'mtime', 'time', 'timestamp', 'date'];

    $version = '';
    foreach ($versionKeys as $key) {
        if (!empty($json[$key])) {
            $version = (string)$json[$key];
            break;
        }
    }
    if ($version === '' && isset($json['data']) && is_array($json['data'])) {
        foreach ($versionKeys as $key) {
            if (!empty($json['data'][$key])) {
                $version = (string)$json['data'][$key];
                break;
            }
        }
    }

    $updated = '';
    foreach ($timeKeys as $key) {
        if (!empty($json[$key])) {
            $updated = (string)$json[$key];
            break;
        }
    }
    if ($updated === '' && isset($json['data']) && is_array($json['data'])) {
        foreach ($timeKeys as $key) {
            if (!empty($json['data'][$key])) {
                $updated = (string)$json['data'][$key];
                break;
            }
        }
    }

    // 兼容新结构：data.model + data.srv_id
    if ($version === '' && isset($json['data']) && is_array($json['data'])) {
        $model = trim((string)($json['data']['model'] ?? ''));
        $srvId = trim((string)($json['data']['srv_id'] ?? ''));
        if ($model !== '' && $srvId !== '') {
            $version = $model . ' / ' . $srvId;
        } elseif ($model !== '') {
            $version = $model;
        } elseif ($srvId !== '') {
            $version = $srvId;
        }
    }

    return ['version' => $version, 'updated_at' => $updated];
}

function formatMetaTime(string $timeRaw, DateTimeZone $tz): string
{
    $timeRaw = trim($timeRaw);
    if ($timeRaw === '') return '—';

    // 对于 released_dt 这类无时区字符串，按源值直接显示，避免二次时区换算偏移。
    if ((bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timeRaw)) {
        return $timeRaw;
    }

    if (ctype_digit($timeRaw)) {
        $timestamp = (int)$timeRaw;
        if ($timestamp > 0) {
            return (new DateTimeImmutable('@' . $timestamp))
                ->setTimezone($tz)
                ->format('Y-m-d H:i:s');
        }
    }

    try {
        return (new DateTimeImmutable($timeRaw))
            ->setTimezone($tz)
            ->format('Y-m-d H:i:s');
    } catch (\Exception) {
        return $timeRaw;
    }
}

function getLocalDbUpdatedAt(DateTimeZone $tz): string
{
    $mtime = 0;
    if (is_file(DB_V4)) $mtime = max($mtime, (int)filemtime(DB_V4));
    if (is_file(DB_V6)) $mtime = max($mtime, (int)filemtime(DB_V6));
    if ($mtime <= 0) return '—';
    return (new DateTimeImmutable('@' . $mtime))->setTimezone($tz)->format('Y-m-d H:i:s');
}

function getXdbMetaInfo(): array
{
    $tz = getMetaTimezone();
    $stateFile = envValue('XDB_UPDATE_STATE_FILE', __DIR__ . '/data/xdb_update_state.json') ?? (__DIR__ . '/data/xdb_update_state.json');
    $cacheFile = envValue('XDB_META_CACHE_FILE', __DIR__ . '/data/xdb_meta_cache.json') ?? (__DIR__ . '/data/xdb_meta_cache.json');
    $metaUrl = envValue('XDB_META_URL', DEFAULT_XDB_META_URL) ?? DEFAULT_XDB_META_URL;
    $timeout = max(2, min(15, (int)(envValue('XDB_META_HTTP_TIMEOUT', '6') ?? '6')));
    $localUpdated = getLocalDbUpdatedAt($tz);

    $cached = null;
    if (is_file($cacheFile)) {
        $raw = file_get_contents($cacheFile);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $cached = $decoded;
        }
    }

    $lastScheduleAt = getLastScheduleAt($tz);
    $needRefresh = true;
    if (is_array($cached) && !empty($cached['fetched_at'])) {
        try {
            $fetchedAt = new DateTimeImmutable((string)$cached['fetched_at'], $tz);
            $needRefresh = $fetchedAt < $lastScheduleAt;
        } catch (\Exception) {
            $needRefresh = true;
        }
    }
    if (!is_array($cached) || ((int)($cached['schema'] ?? 0) !== XDB_META_CACHE_SCHEMA)) {
        $needRefresh = true;
    }

    $meta = is_array($cached) ? $cached : [];
    if ($needRefresh) {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => $timeout,
                'header'  => "Accept: application/json\r\nUser-Agent: ip.bluecdn.com/meta-fetch\r\n",
            ],
        ]);

        $raw = @file_get_contents($metaUrl, false, $ctx);
        if ($raw !== false && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $parsed = normalizeMetaPayload($decoded);
                $meta = [
                    'schema'     => XDB_META_CACHE_SCHEMA,
                    'version'    => $parsed['version'] !== '' ? $parsed['version'] : (string)($meta['version'] ?? ''),
                    'updated_at' => $parsed['updated_at'] !== '' ? $parsed['updated_at'] : (string)($meta['updated_at'] ?? ''),
                    'fetched_at' => (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s'),
                    'source'     => 'remote',
                ];
                @file_put_contents($cacheFile, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
    }

    $version = trim((string)($meta['version'] ?? ''));
    if ($version === '') $version = 'unknown';

    $updatedDisplay = formatMetaTime((string)($meta['updated_at'] ?? ''), $tz);
    if ($updatedDisplay === '—') $updatedDisplay = $localUpdated;
    $nextUpdateAt = getNextScheduleAt($tz)->format('Y-m-d H:i:s');

    // 如果自动更新脚本有状态，页面优先显示实际更新状态
    if (is_file($stateFile)) {
        $raw = file_get_contents($stateFile);
        if ($raw !== false && trim($raw) !== '') {
            $st = json_decode($raw, true);
            if (is_array($st)) {
                if (!empty($st['last_success_at'])) {
                    $updatedDisplay = formatMetaTime((string)$st['last_success_at'], $tz);
                }
                if (!empty($st['next_update_at'])) {
                    $nextUpdateAt = formatMetaTime((string)$st['next_update_at'], $tz);
                }
            }
        }
    }

    return [
        'version'      => $version,
        'updated_at'   => $updatedDisplay,
        'next_update_at' => $nextUpdateAt,
        'localUpdated' => $localUpdated,
    ];
}

// ─── 工具函数 ─────────────────────────────────────────────

function getClientIp(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (str_starts_with($ip, '::ffff:')) {
        $ip = substr($ip, 7);
    }
    return $ip;
}

function getIpVersion(string $ip): int
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return 4;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return 6;
    return 0;
}

function isCliLikeRequest(): bool
{
    $ua = strtolower(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
    if ($ua === '') return false;
    foreach (['curl/', 'wget/', 'httpie/', 'python-requests/', 'powershell/', 'go-http-client/'] as $sig) {
        if (str_contains($ua, $sig)) return true;
    }
    return false;
}

function buildRootCliIpSummary(string $clientIp): string
{
    return trim($clientIp);
}

function queryRegion(string $ip): ?string
{
    $version = getIpVersion($ip);
    $dbFile  = match ($version) {
        4 => DB_V4,
        6 => DB_V6,
        default => null,
    };
    if ($dbFile === null) return null;

    $ipVersion = $version === 4 ? IPv4::default() : IPv6::default();

    try {
        $vIndex   = Util::loadVectorIndexFromFile($dbFile);
        $searcher = Searcher::newWithVectorIndex($ipVersion, $dbFile, $vIndex);
        $region   = $searcher->search($ip);
        $searcher->close();
        return $region !== '' ? $region : null;
    } catch (\Exception) {
        return null;
    }
}

function parseRegion(string $region): array
{
    $parts = array_pad(explode('|', $region), 17, '');
    $clean = static fn(string $v): string => ($v === '0' || $v === '') ? '' : $v;

    $lon = $parts[6] !== '' ? (float)$parts[6] : null;
    $lat = $parts[7] !== '' ? (float)$parts[7] : null;

    return [
        'continent'    => $clean($parts[0]),
        'country'      => $clean($parts[1]),
        'province'     => $clean($parts[2]),
        'city'         => $clean($parts[3]),
        'district'     => $clean($parts[4]),
        'isp'          => $clean($parts[5]),
        'longitude'    => $lon,
        'latitude'     => $lat,
        'area_code'    => $clean($parts[8]),
        'phone_code'   => $clean($parts[9]),
        'zip_code'     => $clean($parts[10]),
        'timezone'     => $clean($parts[11]),
        'currency'     => $clean($parts[12]),
        'asn'          => $clean($parts[13]),
        'country_code' => $clean($parts[16]),
    ];
}

function queryAll(string $ip): array
{
    $region = queryRegion($ip);
    if ($region === null) return ['ip' => $ip];
    return array_merge(['ip' => $ip], parseRegion($region));
}

function getGeoipQueryableFields(): array
{
    return [
        'ip',
        'continent',
        'country',
        'country_code',
        'province',
        'city',
        'district',
        'isp',
        'asn',
        'longitude',
        'latitude',
        'timezone',
        'currency',
        'area_code',
        'phone_code',
        'zip_code',
    ];
}

function filterGeoipByField(array $data, string $field): array
{
    $field = trim($field);
    if ($field === '') return $data;

    $allowed = getGeoipQueryableFields();
    $allowedMap = array_flip($allowed);
    $requested = array_values(array_unique(array_filter(array_map('trim', explode(',', $field)), static function ($v) {
        return $v !== '';
    })));

    if ($requested === []) {
        return $data;
    }

    foreach ($requested as $f) {
        if (!isset($allowedMap[$f])) {
            jsonResponse([
                'error' => 'Invalid field',
                'field' => $f,
                'requested' => $requested,
                'available_fields' => $allowed,
            ], 400);
        }
    }

    if (count($requested) === 1 && $requested[0] === 'ip') {
        return ['ip' => (string)($data['ip'] ?? '')];
    }

    $result = ['ip' => (string)($data['ip'] ?? '')];
    foreach ($requested as $f) {
        if ($f === 'ip') continue;
        $result[$f] = $data[$f] ?? null;
    }
    return $result;
}

// ─── 响应函数 ─────────────────────────────────────────────

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function textResponse(string $text, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo $text . "\n";
    exit;
}

function htmlResponse(string $html, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

function homePageHtml(?string $queryIp = null, string $context = 'home'): string
{
    $year = date('Y');
    $xdbMeta = getXdbMetaInfo();
    $headerHtml = renderSiteHeader($context);
    $footerHtml = renderSiteFooter(['/', '/ip', '/jsonip', '/geoip', '/geoip/{ip}'], $xdbMeta, (int)$year);
    $fieldConfig = [
        ['key' => 'ip',           'label' => 'IP 地址'],
        ['key' => 'asn',          'label' => 'ASN'],
        ['key' => 'isp',          'label' => 'ISP'],
        ['key' => 'continent',    'label' => '大洲'],
        ['key' => 'country',      'label' => '国家/代码'],
        ['key' => 'province',     'label' => '省'],
        ['key' => 'city',         'label' => '市'],
        ['key' => 'district',     'label' => '区'],
        ['key' => 'longitude',    'label' => '经度'],
        ['key' => 'latitude',     'label' => '纬度'],
        ['key' => 'timezone',     'label' => '时区'],
        ['key' => 'currency',     'label' => '货币'],
        ['key' => 'area_code',    'label' => '行政区划代码'],
        ['key' => 'phone_code',   'label' => '电话区号'],
        ['key' => 'zip_code',     'label' => '邮政编码'],
    ];
    $fieldsJson = json_encode($fieldConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $initialData = ($queryIp !== null && getIpVersion($queryIp) !== 0) ? queryAll($queryIp) : queryAll(getClientIp());
    $initialQueryIp = ($queryIp !== null && getIpVersion($queryIp) !== 0) ? $queryIp : null;
    $initialDataJson = json_encode($initialData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $initialQueryIpJson = json_encode($initialQueryIp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headAssetLinks = renderHeadAssetLinks();

    return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>IP Address Lookup / IPv4 / IPv6  - ip.Bluecdn.com</title>
  <meta name="description" content="IPv4 Address Lookup, IPv6 Address Lookup" />
  {$headAssetLinks}
  <link rel="stylesheet" href="https://icons.bluecdn.com/fontawesome-pro/css/all.min.css">
  <link rel="stylesheet" href="https://flagcdn.io/css/flag-icons.min.css" />
  <link rel="preconnect" href="https://fonts.bluecdn.com">
  <link href="https://fonts.bluecdn.com/css2?family=Cairo+Play:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://static.bluecdn.com/npm/tailwindcss@4.2.1/index.min.css">
  <link rel="stylesheet" href="/assets/main.css">
</head>
<body class="min-h-screen bg-background text-foreground overflow-x-hidden">
  {$headerHtml}
  <main class="wrap">
    <section class="hero-landing">
      <h1 class="hero-landing-title"><i class="fa-solid fa-globe title-icon" aria-hidden="true"></i><span>IP Address Lookup</span></h1>
      <p class="hero-landing-sub">快速识别访问者 IP，支持 <span class="font-cairo-play">IPv4</span> / <span class="font-cairo-play">IPv6</span> 查询与 GeoIP 结果展示。</p>
      <div class="hero-landing-actions">
        <a class="hero-action-btn hero-action-btn-primary" href="/api">API</a>
        <button id="hero-focus-query" class="hero-action-btn hero-action-btn-ghost" type="button">查询</button>
      </div>
    </section>

    <section id="query-panel" class="hero">
      <div class="hero-title-row">
        <h1 class="title"><i class="fa-solid fa-location-dot title-icon" aria-hidden="true"></i><span>IP 地理查询</span></h1>
        <div id="status" class="status status-query">正在识别本机 IPv4 / IPv6 ...</div>
      </div>
      <p class="sub">首页会自动识别当前访问者 IP；输入 <span class="font-cairo-play">IPv4/IPv6</span> 后，将通过 <code class="font-cairo-play">/geoip/{ip}</code> 返回对应地理信息。</p>
      <form id="ip-query-form" class="ip-query" autocomplete="off">
        <input id="ip-query-input" class="ip-query-input" type="text" inputmode="text" placeholder="输入 IPv4 / IPv6，例如 8.8.8.8 或 2001:4860:4860::8888" aria-label="IP address">
        <button class="ip-query-btn" type="submit">查询 IP</button>
      </form>
    </section>

    <section class="panel">
      <div class="result-head">
        <h2 class="panel-title">实时识别结果</h2>
        <div id="query-ip-summary" class="query-ip-summary" hidden></div>
      </div>
      <div id="result-groups" class="result-groups">
        <section id="group-dual" class="result-group" hidden>
          <h3 class="result-title">本机 IPv4 + IPv6</h3>
          <section id="grid-dual" class="grid"></section>
        </section>

        <section id="group-v4" class="result-group" hidden>
          <h3 class="result-title">IPv4（来自 ipv4.bluecdn.com）</h3>
          <section id="grid-v4" class="grid"></section>
        </section>

        <section id="group-v6" class="result-group" hidden>
          <h3 class="result-title">IPv6（来自 ipv6.bluecdn.com）</h3>
          <section id="grid-v6" class="grid"></section>
        </section>

        <section id="group-query" class="result-group" hidden>
          <h3 class="result-title">查询结果</h3>
          <section id="grid-query" class="grid"></section>
        </section>
      </div>
    </section>

    {$footerHtml}
  </main>
  <script id="field-config" type="application/json">{$fieldsJson}</script>
  <script id="initial-geoip" type="application/json">{$initialDataJson}</script>
  <script id="initial-query-ip" type="application/json">{$initialQueryIpJson}</script>
  <script src="/assets/main.js"></script>
</body>
</html>
HTML;
}

function apiPageHtml(): string
{
    $year = date('Y');
    $xdbMeta = getXdbMetaInfo();
    $headerHtml = renderSiteHeader('api');
    $footerHtml = renderSiteFooter(['/ip', '/jsonip', '/geoip', '/geoip/{ip}'], $xdbMeta, (int)$year);
    $headAssetLinks = renderHeadAssetLinks();
    return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>API Documentation - ip.Bluecdn.com</title>
  <meta name="description" content="ip-api.bluecdn.com API 文档：IP、JSON IP、GeoIP 调用示例与错误说明" />
  {$headAssetLinks}
  <link rel="stylesheet" href="https://icons.bluecdn.com/fontawesome-pro/css/all.min.css">
  <link rel="stylesheet" href="https://flagcdn.io/css/flag-icons.min.css" />
  <link rel="preconnect" href="https://fonts.bluecdn.com">
  <link href="https://fonts.bluecdn.com/css2?family=Cairo+Play:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://static.bluecdn.com/npm/tailwindcss@4.2.1/index.min.css">
  <link rel="stylesheet" href="/assets/main.css">
</head>
<body class="api-page min-h-screen bg-background text-foreground overflow-x-hidden">
  {$headerHtml}

  <main class="wrap">
    <section class="hero">
      <h1 class="title"><i class="fa-solid fa-code title-icon" aria-hidden="true"></i><span>API Documentation</span></h1>
      <p class="sub">提供 IP、JSON IP、GeoIP 接口。支持双栈查询、指定 IP 定位、脚本调用与浏览器跨域调用。</p>
      <div class="status status-base-cards">
        <span class="base-card">
          <span class="base-card-label">Base URL</span>
          <code>https://ip-api.bluecdn.com</code>
        </span>
        <span class="base-card">
          <span class="base-card-label">兼容入口</span>
          <code>https://ip.bluecdn.com</code>
        </span>
      </div>
    </section>

    <section class="panel">
      <h2 class="panel-title">接口总览</h2>
      <div class="grid">
        <div class="result-row"><span class="k">GET /ip</span><span class="v">纯文本返回访问者公网 IP</span></div>
        <div class="result-row"><span class="k">GET /jsonip</span><span class="v">JSON 返回访问者 IP</span></div>
        <div class="result-row"><span class="k">GET /geoip</span><span class="v">JSON 返回访问者 IP 地理信息</span></div>
        <div class="result-row"><span class="k">GET /geoip/{ip}</span><span class="v">JSON 返回指定 IP 地理信息（支持 IPv4 / IPv6）</span></div>
      </div>
    </section>

    <section class="panel">
      <h2 class="panel-title">IP（纯文本）</h2>
      <p class="doc-lead">返回访问者公网 IP（IPv4 或 IPv6）纯文本，适合 Shell 脚本、监控探活、快速获取出口地址。</p>
      <div class="api-lang-grid">
        <article class="api-lang-card">
          <h3 class="api-lang-title">中文说明</h3>
          <div class="api-table-wrap">
            <table class="api-table">
              <thead>
                <tr>
                  <th>模式</th>
                  <th>接口地址</th>
                  <th>命令</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>双栈（IPv4 + IPv6）</td>
                  <td><a class="api-link" href="https://ip.bluecdn.com/ip" target="_blank" rel="noopener noreferrer">https://ip.bluecdn.com/ip</a></td>
                  <td>
                    <span class="cmd-chip">
                      <code class="inline-code">curl ip.bluecdn.com</code>
                      <button class="copy-icon-btn" type="button" data-copy-text="curl ip.bluecdn.com" aria-label="复制命令"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
                    </span>
                  </td>
                </tr>
                <tr>
                  <td>仅 IPv4</td>
                  <td><a class="api-link" href="https://ipv4.bluecdn.com" target="_blank" rel="noopener noreferrer">https://ipv4.bluecdn.com</a></td>
                  <td>
                    <span class="cmd-chip">
                      <code class="inline-code">curl -4 ip.bluecdn.com</code>
                      <button class="copy-icon-btn" type="button" data-copy-text="curl -4 ip.bluecdn.com" aria-label="复制命令"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
                    </span>
                    <span class="cmd-sep"> / </span>
                    <span class="cmd-chip">
                      <code class="inline-code">curl ipv4.bluecdn.com</code>
                      <button class="copy-icon-btn" type="button" data-copy-text="curl ipv4.bluecdn.com" aria-label="复制命令"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
                    </span>
                  </td>
                </tr>
                <tr>
                  <td>仅 IPv6</td>
                  <td><a class="api-link" href="https://ipv6.bluecdn.com" target="_blank" rel="noopener noreferrer">https://ipv6.bluecdn.com</a></td>
                  <td>
                    <span class="cmd-chip">
                      <code class="inline-code">curl -6 ip.bluecdn.com</code>
                      <button class="copy-icon-btn" type="button" data-copy-text="curl -6 ip.bluecdn.com" aria-label="复制命令"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
                    </span>
                    <span class="cmd-sep"> / </span>
                    <span class="cmd-chip">
                      <code class="inline-code">curl ipv6.bluecdn.com</code>
                      <button class="copy-icon-btn" type="button" data-copy-text="curl ipv6.bluecdn.com" aria-label="复制命令"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </article>

        <article class="api-lang-card api-lang-card-en">
          <h3 class="api-lang-title">English</h3>
          <div class="api-table-wrap">
            <table class="api-table">
              <thead>
                <tr>
                  <th>Mode</th>
                  <th>Endpoint URL</th>
                  <th>Command</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>Dual Stack (IPv4 + IPv6)</td>
                  <td><a class="api-link" href="https://ip.bluecdn.com/ip" target="_blank" rel="noopener noreferrer">https://ip.bluecdn.com/ip</a></td>
                  <td>
                    <span class="cmd-chip">
                      <code class="inline-code">curl ip.bluecdn.com</code>
                      <button class="copy-icon-btn" type="button" data-copy-text="curl ip.bluecdn.com" aria-label="Copy command"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
                    </span>
                  </td>
                </tr>
                <tr>
                  <td>IPv4 Only</td>
                  <td><a class="api-link" href="https://ipv4.bluecdn.com" target="_blank" rel="noopener noreferrer">https://ipv4.bluecdn.com</a></td>
                  <td>
                    <span class="cmd-chip">
                      <code class="inline-code">curl -4 ip.bluecdn.com</code>
                      <button class="copy-icon-btn" type="button" data-copy-text="curl -4 ip.bluecdn.com" aria-label="Copy command"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
                    </span>
                    <span class="cmd-sep"> / </span>
                    <span class="cmd-chip">
                      <code class="inline-code">curl ipv4.bluecdn.com</code>
                      <button class="copy-icon-btn" type="button" data-copy-text="curl ipv4.bluecdn.com" aria-label="Copy command"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
                    </span>
                  </td>
                </tr>
                <tr>
                  <td>IPv6 Only</td>
                  <td><a class="api-link" href="https://ipv6.bluecdn.com" target="_blank" rel="noopener noreferrer">https://ipv6.bluecdn.com</a></td>
                  <td>
                    <span class="cmd-chip">
                      <code class="inline-code">curl -6 ip.bluecdn.com</code>
                      <button class="copy-icon-btn" type="button" data-copy-text="curl -6 ip.bluecdn.com" aria-label="Copy command"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
                    </span>
                    <span class="cmd-sep"> / </span>
                    <span class="cmd-chip">
                      <code class="inline-code">curl ipv6.bluecdn.com</code>
                      <button class="copy-icon-btn" type="button" data-copy-text="curl ipv6.bluecdn.com" aria-label="Copy command"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </article>
      </div>
    </section>

    <section class="panel">
      <h2 class="panel-title">JSON IP（JSON）</h2>
      <div class="cmd-grid">
        <article class="terminal">
          <div class="terminal-head">JSON</div>
          <pre><code>$ curl ip-api.bluecdn.com/jsonip
{
  "ip": "203.0.113.10"
}</code></pre>
        </article>
      </div>
    </section>

    <section class="panel">
      <h2 class="panel-title">GeoIP（JSON）</h2>
      <div class="cmd-grid">
        <article class="terminal">
          <div class="terminal-head">当前访问者 IP</div>
          <pre><code>$ curl ip-api.bluecdn.com/geoip</code></pre>
        </article>
        <article class="terminal">
          <div class="terminal-head">指定 IP（测试 IP）</div>
          <pre><code>$ curl ip-api.bluecdn.com/geoip/8.8.8.8
{
  "ip": "8.8.8.8",
  "country": "美国",
  "city": "Mountain View",
  "isp": "谷歌",
  "asn": "AS15169"
}</code></pre>
        </article>
        <article class="terminal">
          <div class="terminal-head">IPv6 示例</div>
          <pre><code>$ curl "ip-api.bluecdn.com/geoip/2a09::"</code></pre>
        </article>
      </div>
    </section>

    <section class="panel">
      <h2 class="panel-title">GeoIP 字段筛选（单字段 / 多字段）</h2>
      <div class="grid">
        <div class="result-row result-row-flow"><span class="k">Query 参数</span><span class="v"><code>field=字段名</code> 或 <code>field=字段1,字段2</code>（返回指定字段，固定附带 <code>ip</code>）</span></div>
        <div class="result-row result-row-flow result-row-fields">
          <span class="k">支持字段</span>
          <span class="v">
            <span class="field-chip-list">
              <code class="field-chip">ip</code>
              <code class="field-chip">continent</code>
              <code class="field-chip">country</code>
              <code class="field-chip">country_code</code>
              <code class="field-chip">province</code>
              <code class="field-chip">city</code>
              <code class="field-chip">district</code>
              <code class="field-chip">isp</code>
              <code class="field-chip">asn</code>
              <code class="field-chip">longitude</code>
              <code class="field-chip">latitude</code>
              <code class="field-chip">timezone</code>
              <code class="field-chip">currency</code>
              <code class="field-chip">area_code</code>
              <code class="field-chip">phone_code</code>
              <code class="field-chip">zip_code</code>
            </span>
          </span>
        </div>
      </div>
      <div class="cmd-grid" style="margin-top:10px;">
        <article class="terminal">
          <div class="terminal-head">当前访问者（单字段）</div>
          <pre><code>$ curl "ip-api.bluecdn.com/geoip?field=country"
{
  "ip": "203.0.113.10",
  "country": "美国"
}</code></pre>
        </article>
        <article class="terminal">
          <div class="terminal-head">指定 IP（单字段）</div>
          <pre><code>$ curl "ip-api.bluecdn.com/geoip/8.8.8.8?field=asn"
{
  "ip": "8.8.8.8",
  "asn": "AS15169"
}</code></pre>
        </article>
        <article class="terminal">
          <div class="terminal-head">当前访问者（多字段）</div>
          <pre><code>$ curl "ip-api.bluecdn.com/geoip?field=country,city,asn"
{
  "ip": "203.0.113.10",
  "country": "美国",
  "city": "Mountain View",
  "asn": "AS15169"
}</code></pre>
        </article>
        <article class="terminal">
          <div class="terminal-head">指定 IP（多字段）</div>
          <pre><code>$ curl "ip-api.bluecdn.com/geoip/8.8.8.8?field=country_code,isp,timezone"
{
  "ip": "8.8.8.8",
  "country_code": "US",
  "isp": "谷歌",
  "timezone": "America/Los_Angeles"
}</code></pre>
        </article>
      </div>
    </section>

    <section class="panel">
      <h2 class="panel-title panel-title-green">Query 参数与状态码</h2>
      <div class="grid">
        <div class="result-row"><span class="k">200</span><span class="v">请求成功，返回 JSON / 纯文本。</span></div>
        <div class="result-row"><span class="k">400</span><span class="v">参数错误（如 IP 非法），返回错误 JSON。</span></div>
      </div>
      <article class="terminal" style="margin-top:10px;">
        <div class="terminal-head">错误示例</div>
        <pre><code>$ curl ip-api.bluecdn.com/geoip/invalid-ip
{
  "error": "Invalid IP address",
  "input": "invalid-ip"
}</code></pre>
      </article>
    </section>

    <section class="panel">
      <h2 class="panel-title">多语言调用示例</h2>
      <div class="lang-tabs" role="tablist" aria-label="Language tabs">
        <button class="lang-tab is-active" type="button" role="tab" aria-selected="true" data-lang-target="lang-node">Node.js</button>
        <button class="lang-tab" type="button" role="tab" aria-selected="false" data-lang-target="lang-php">PHP</button>
        <button class="lang-tab" type="button" role="tab" aria-selected="false" data-lang-target="lang-python">Python</button>
        <button class="lang-tab" type="button" role="tab" aria-selected="false" data-lang-target="lang-go">Go</button>
        <button class="lang-tab" type="button" role="tab" aria-selected="false" data-lang-target="lang-rust">Rust</button>
        <button class="lang-tab" type="button" role="tab" aria-selected="false" data-lang-target="lang-java">Java</button>
        <button class="lang-tab" type="button" role="tab" aria-selected="false" data-lang-target="lang-csharp">C#</button>
        <button class="lang-tab" type="button" role="tab" aria-selected="false" data-lang-target="lang-ruby">Ruby</button>
        <button class="lang-tab" type="button" role="tab" aria-selected="false" data-lang-target="lang-kotlin">Kotlin</button>
        <button class="lang-tab" type="button" role="tab" aria-selected="false" data-lang-target="lang-swift">Swift</button>
        <button class="lang-tab" type="button" role="tab" aria-selected="false" data-lang-target="lang-powershell">PowerShell</button>
        <button class="lang-tab" type="button" role="tab" aria-selected="false" data-lang-target="lang-bash">Bash</button>
        <button class="lang-tab" type="button" role="tab" aria-selected="false" data-lang-target="lang-curl">cURL</button>
      </div>

      <div class="lang-panels">
        <article id="lang-node" class="terminal lang-panel is-active" role="tabpanel">
          <div class="terminal-head">Node.js (fetch)</div>
          <button class="lang-code-copy" type="button" aria-label="Copy code"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
          <pre><code>const ip = await fetch('https://ip-api.bluecdn.com/ip').then(r =&gt; r.text());
const geo = await fetch('https://ip-api.bluecdn.com/geoip/8.8.8.8').then(r =&gt; r.json());
console.log(ip.trim(), geo.country, geo.city, geo.asn);</code></pre>
        </article>
        <article id="lang-php" class="terminal lang-panel" role="tabpanel" hidden>
          <div class="terminal-head">PHP</div>
          <button class="lang-code-copy" type="button" aria-label="Copy code"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
          <pre><code>&lt;?php
\$ip = trim(file_get_contents('https://ip-api.bluecdn.com/ip'));
\$geo = json_decode(file_get_contents('https://ip-api.bluecdn.com/geoip/8.8.8.8'), true);
echo "IP: {\$ip}\n";
echo "Country: " . (\$geo['country'] ?? '-') . "\n";
echo "ASN: " . (\$geo['asn'] ?? '-') . "\n";</code></pre>
        </article>
        <article id="lang-python" class="terminal lang-panel" role="tabpanel" hidden>
          <div class="terminal-head">Python</div>
          <button class="lang-code-copy" type="button" aria-label="Copy code"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
          <pre><code>import requests
ip = requests.get("https://ip-api.bluecdn.com/ip", timeout=10).text.strip()
geo = requests.get("https://ip-api.bluecdn.com/geoip/8.8.8.8", timeout=10).json()
print(ip, geo.get("country"), geo.get("asn"))</code></pre>
        </article>
        <article id="lang-go" class="terminal lang-panel" role="tabpanel" hidden>
          <div class="terminal-head">Go</div>
          <button class="lang-code-copy" type="button" aria-label="Copy code"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
          <pre><code>resp, _ := http.Get("https://ip-api.bluecdn.com/ip")
ipBytes, _ := io.ReadAll(resp.Body)
geoResp, _ := http.Get("https://ip-api.bluecdn.com/geoip/8.8.8.8")
geoBytes, _ := io.ReadAll(geoResp.Body)
fmt.Println(strings.TrimSpace(string(ipBytes)), string(geoBytes))</code></pre>
        </article>
        <article id="lang-rust" class="terminal lang-panel" role="tabpanel" hidden>
          <div class="terminal-head">Rust (reqwest)</div>
          <button class="lang-code-copy" type="button" aria-label="Copy code"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
          <pre><code>let ip = reqwest::get("https://ip-api.bluecdn.com/ip").await?.text().await?;
let geo = reqwest::get("https://ip-api.bluecdn.com/geoip/8.8.8.8").await?.text().await?;
println!("{} {}", ip.trim(), geo);</code></pre>
        </article>
        <article id="lang-java" class="terminal lang-panel" role="tabpanel" hidden>
          <div class="terminal-head">Java (HttpClient)</div>
          <button class="lang-code-copy" type="button" aria-label="Copy code"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
          <pre><code>HttpClient c = HttpClient.newHttpClient();
String ip = c.send(HttpRequest.newBuilder(URI.create("https://ip-api.bluecdn.com/ip")).build(),
    HttpResponse.BodyHandlers.ofString()).body().trim();
String geo = c.send(HttpRequest.newBuilder(URI.create("https://ip-api.bluecdn.com/geoip/8.8.8.8")).build(),
    HttpResponse.BodyHandlers.ofString()).body();
System.out.println(ip + " " + geo);</code></pre>
        </article>
        <article id="lang-csharp" class="terminal lang-panel" role="tabpanel" hidden>
          <div class="terminal-head">C# (.NET)</div>
          <button class="lang-code-copy" type="button" aria-label="Copy code"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
          <pre><code>using var http = new HttpClient();
var ip = (await http.GetStringAsync("https://ip-api.bluecdn.com/ip")).Trim();
var geo = await http.GetStringAsync("https://ip-api.bluecdn.com/geoip/8.8.8.8");
Console.WriteLine($"{ip} {geo}");</code></pre>
        </article>
        <article id="lang-ruby" class="terminal lang-panel" role="tabpanel" hidden>
          <div class="terminal-head">Ruby</div>
          <button class="lang-code-copy" type="button" aria-label="Copy code"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
          <pre><code>require "net/http"
ip = Net::HTTP.get(URI("https://ip-api.bluecdn.com/ip")).strip
geo = Net::HTTP.get(URI("https://ip-api.bluecdn.com/geoip/8.8.8.8"))
puts "#{ip} #{geo}"</code></pre>
        </article>
        <article id="lang-kotlin" class="terminal lang-panel" role="tabpanel" hidden>
          <div class="terminal-head">Kotlin (OkHttp)</div>
          <button class="lang-code-copy" type="button" aria-label="Copy code"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
          <pre><code>val client = OkHttpClient()
val ip = client.newCall(Request.Builder().url("https://ip-api.bluecdn.com/ip").build())
  .execute().body!!.string().trim()
val geo = client.newCall(Request.Builder().url("https://ip-api.bluecdn.com/geoip/8.8.8.8").build())
  .execute().body!!.string()
println("\$ip \$geo")</code></pre>
        </article>
        <article id="lang-swift" class="terminal lang-panel" role="tabpanel" hidden>
          <div class="terminal-head">Swift</div>
          <button class="lang-code-copy" type="button" aria-label="Copy code"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
          <pre><code>let ipURL = URL(string: "https://ip-api.bluecdn.com/ip")!
let geoURL = URL(string: "https://ip-api.bluecdn.com/geoip/8.8.8.8")!
let ip = String(data: try await URLSession.shared.data(from: ipURL).0, encoding: .utf8)!.trimmingCharacters(in: .whitespacesAndNewlines)
let geo = String(data: try await URLSession.shared.data(from: geoURL).0, encoding: .utf8)!
print(ip, geo)</code></pre>
        </article>
        <article id="lang-powershell" class="terminal lang-panel" role="tabpanel" hidden>
          <div class="terminal-head">PowerShell</div>
          <button class="lang-code-copy" type="button" aria-label="Copy code"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
          <pre><code>\$ip = (Invoke-WebRequest -Uri "https://ip-api.bluecdn.com/ip").Content.Trim()
\$geo = Invoke-RestMethod -Uri "https://ip-api.bluecdn.com/geoip/8.8.8.8"
Write-Output "\$ip \$(\$geo.country) \$(\$geo.asn)"</code></pre>
        </article>
        <article id="lang-bash" class="terminal lang-panel" role="tabpanel" hidden>
          <div class="terminal-head">Bash</div>
          <button class="lang-code-copy" type="button" aria-label="Copy code"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
          <pre><code>#!/usr/bin/env bash
ip=\$(curl -s https://ip-api.bluecdn.com/ip)
geo=\$(curl -s https://ip-api.bluecdn.com/geoip/8.8.8.8)
echo "\$ip"
echo "\$geo"</code></pre>
        </article>
        <article id="lang-curl" class="terminal lang-panel" role="tabpanel" hidden>
          <div class="terminal-head">cURL</div>
          <button class="lang-code-copy" type="button" aria-label="Copy code"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
          <pre><code>curl ip-api.bluecdn.com
curl https://ip-api.bluecdn.com/ip
curl https://ip-api.bluecdn.com/geoip/8.8.8.8
curl https://ip-api.bluecdn.com/geoip/8.8.8.8</code></pre>
        </article>
      </div>
    </section>

    {$footerHtml}
  </main>
  <script>
    (function () {
      const buttons = document.querySelectorAll('.copy-icon-btn');

      async function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(text);
          return;
        }
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        document.execCommand('copy');
        ta.remove();
      }

      buttons.forEach((btn) => {
        btn.addEventListener('click', async () => {
          const icon = btn.querySelector('i');
          const text = btn.getAttribute('data-copy-text') || '';
          if (!text || !icon) return;
          try {
            await copyText(text);
            icon.classList.remove('fa-copy', 'fa-regular');
            icon.classList.add('fa-check', 'fa-solid');
            btn.classList.add('copied');
            setTimeout(() => {
              icon.classList.remove('fa-check', 'fa-solid');
              icon.classList.add('fa-copy', 'fa-regular');
              btn.classList.remove('copied');
            }, 1200);
          } catch (e) {
            // noop
          }
        });
      });

      const langCopyButtons = document.querySelectorAll('.lang-code-copy');
      langCopyButtons.forEach((btn) => {
        btn.addEventListener('click', async () => {
          const icon = btn.querySelector('i');
          const panel = btn.closest('.lang-panel');
          const codeEl = panel ? panel.querySelector('pre code') : null;
          const text = codeEl ? codeEl.textContent || '' : '';
          if (!text || !icon) return;
          try {
            await copyText(text);
            icon.classList.remove('fa-copy', 'fa-regular');
            icon.classList.add('fa-check', 'fa-solid');
            btn.classList.add('copied');
            setTimeout(() => {
              icon.classList.remove('fa-check', 'fa-solid');
              icon.classList.add('fa-copy', 'fa-regular');
              btn.classList.remove('copied');
            }, 1200);
          } catch (e) {
            // noop
          }
        });
      });

      const langTabs = document.querySelectorAll('.lang-tab');
      const langPanels = document.querySelectorAll('.lang-panel');
      if (!langTabs.length || !langPanels.length) return;

      langTabs.forEach((tab) => {
        tab.addEventListener('click', () => {
          const target = tab.getAttribute('data-lang-target');
          if (!target) return;

          langTabs.forEach((t) => {
            t.classList.remove('is-active');
            t.setAttribute('aria-selected', 'false');
          });

          langPanels.forEach((panel) => {
            panel.classList.remove('is-active');
            panel.hidden = true;
          });

          const activePanel = document.getElementById(target);
          if (!activePanel) return;

          tab.classList.add('is-active');
          tab.setAttribute('aria-selected', 'true');
          activePanel.hidden = false;
          activePanel.classList.add('is-active');
        });
      });
    })();
  </script>
</body>
</html>
HTML;
}

function aboutPageHtml(): string
{
    $year = date('Y');
    $xdbMeta = getXdbMetaInfo();
    $headerHtml = renderSiteHeader('about');
    $footerHtml = renderSiteFooter(['/ip', '/jsonip', '/geoip', '/geoip/{ip}'], $xdbMeta, (int)$year);
    $headAssetLinks = renderHeadAssetLinks();

    return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>关于 - ip.Bluecdn.com</title>
  <meta name="description" content="关于 ip.bluecdn.com：IPv4/IPv6 查询、GeoIP 能力、更新机制与服务定位。" />
  {$headAssetLinks}
  <link rel="stylesheet" href="https://icons.bluecdn.com/fontawesome-pro/css/all.min.css">
  <link rel="stylesheet" href="https://flagcdn.io/css/flag-icons.min.css" />
  <link rel="preconnect" href="https://fonts.bluecdn.com">
  <link href="https://fonts.bluecdn.com/css2?family=Cairo+Play:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://static.bluecdn.com/npm/tailwindcss@4.2.1/index.min.css">
  <link rel="stylesheet" href="/assets/main.css">
</head>
<body class="min-h-screen bg-background text-foreground overflow-x-hidden">
  {$headerHtml}
  <main class="wrap">
    <section class="hero">
      <h1 class="title"><i class="fa-solid fa-circle-info title-icon" aria-hidden="true"></i><span>关于我们</span></h1>
      <p class="sub">ip.bluecdn.com 提供稳定的公网 IP 与地理信息查询服务，面向开发者、运维团队与企业系统集成场景。</p>
    </section>

    <section class="panel content-section">
      <h2 class="panel-title">服务概览</h2>
      <div class="content-grid">
        <article class="content-card">
          <h3><i class="fa-solid fa-compass-drafting" aria-hidden="true"></i><span>服务定位</span></h3>
          <p>轻量、可脚本化、可反代部署的 IP/GeoIP 查询能力，适合在业务系统中直接调用。</p>
        </article>
        <article class="content-card">
          <h3><i class="fa-solid fa-plug-circle-bolt" aria-hidden="true"></i><span>接口能力</span></h3>
          <p>提供 <code>/ip</code>、<code>/jsonip</code>、<code>/geoip</code>、<code>/geoip/{ip}</code> 四个核心端点，覆盖纯文本和 JSON 场景。</p>
        </article>
        <article class="content-card">
          <h3><i class="fa-solid fa-table-cells-large" aria-hidden="true"></i><span>字段覆盖</span></h3>
          <p>支持国家/代码、省市区、运营商、ASN、经纬度、时区、货币等结构化字段输出。</p>
        </article>
      </div>
    </section>

    <section class="panel content-section">
      <h2 class="panel-title">使用场景</h2>
      <div class="content-grid">
        <article class="content-card">
          <h3><i class="fa-solid fa-shield-check" aria-hidden="true"></i><span>安全与风控</span></h3>
          <ul class="content-list">
            <li>登录风控、异常来源识别</li>
            <li>按国家/地区访问策略</li>
          </ul>
        </article>
        <article class="content-card">
          <h3><i class="fa-solid fa-chart-line" aria-hidden="true"></i><span>运营与分析</span></h3>
          <ul class="content-list">
            <li>地域来源统计与报表</li>
            <li>运营商维度网络质量分析</li>
          </ul>
        </article>
        <article class="content-card">
          <h3><i class="fa-solid fa-terminal" aria-hidden="true"></i><span>工程与自动化</span></h3>
          <ul class="content-list">
            <li>Shell / CI 自动化巡检</li>
            <li>反向代理 + API 网关接入</li>
          </ul>
        </article>
      </div>
    </section>

    <section class="panel content-section">
      <h2 class="panel-title">数据与更新</h2>
      <div class="content-grid content-grid-2">
        <article class="content-card">
          <h3><i class="fa-solid fa-database" aria-hidden="true"></i><span>数据库来源</span></h3>
          <p>使用 ip2region 付费版数据库，覆盖 IPv4/IPv6 双栈查询能力。</p>
        </article>
        <article class="content-card">
          <h3><i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i><span>更新机制</span></h3>
          <p>按计划进行版本同步，页面底部展示上次与下次更新日期，便于运维核对。</p>
        </article>
      </div>
    </section>

    {$footerHtml}
  </main>
</body>
</html>
HTML;
}

function contactPageHtml(): string
{
    $year = date('Y');
    $xdbMeta = getXdbMetaInfo();
    $headerHtml = renderSiteHeader('contact');
    $footerHtml = renderSiteFooter(['/ip', '/jsonip', '/geoip', '/geoip/{ip}'], $xdbMeta, (int)$year);
    $headAssetLinks = renderHeadAssetLinks();

    return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>联系我们 - ip.Bluecdn.com</title>
  <meta name="description" content="联系 ip.bluecdn.com / BlueCDN：技术支持、接入协助、问题反馈与商务合作。" />
  {$headAssetLinks}
  <link rel="stylesheet" href="https://icons.bluecdn.com/fontawesome-pro/css/all.min.css">
  <link rel="stylesheet" href="https://flagcdn.io/css/flag-icons.min.css" />
  <link rel="preconnect" href="https://fonts.bluecdn.com">
  <link href="https://fonts.bluecdn.com/css2?family=Cairo+Play:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://static.bluecdn.com/npm/tailwindcss@4.2.1/index.min.css">
  <link rel="stylesheet" href="/assets/main.css">
</head>
<body class="min-h-screen bg-background text-foreground overflow-x-hidden">
  {$headerHtml}
  <main class="wrap">
    <section class="hero">
      <h1 class="title"><i class="fa-solid fa-envelope title-icon" aria-hidden="true"></i><span>联系支持</span></h1>
      <p class="sub">如需接入协助、部署排障、字段解释或商务咨询，请按下方渠道联系并附上必要上下文。</p>
    </section>

    <section class="panel content-section">
      <h2 class="panel-title">联系方式</h2>
      <div class="content-grid content-grid-2">
        <article class="content-card channel-card">
          <h3><i class="fa-solid fa-envelope" aria-hidden="true"></i><span>支持邮箱</span></h3>
          <p>接口问题、返回异常、字段解释、反代配置建议。</p>
          <a class="channel-link" href="mailto:support@bluecdn.com">support@bluecdn.com</a>
        </article>
        <article class="content-card channel-card">
          <h3><i class="fa-brands fa-github" aria-hidden="true"></i><span>GitHub</span></h3>
          <p>关注项目更新、提交 Issue 或查看公开仓库信息。</p>
          <a class="channel-link" href="https://github.com/bluecdn" target="_blank" rel="noreferrer">github.com/bluecdn</a>
        </article>
      </div>
    </section>

    <section class="panel content-section">
      <h2 class="panel-title">联系表单</h2>
      <form class="contact-form" action="mailto:support@bluecdn.com" method="post" enctype="text/plain">
        <div class="contact-form-grid">
          <label class="contact-label">
            <span>姓名</span>
            <input class="contact-input" type="text" name="name" placeholder="请输入姓名">
          </label>
          <label class="contact-label">
            <span>邮箱</span>
            <input class="contact-input" type="email" name="email" placeholder="请输入邮箱">
          </label>
          <label class="contact-label">
            <span>主题</span>
            <input class="contact-input" type="text" name="subject" placeholder="例如：API 接入咨询">
          </label>
          <label class="contact-label">
            <span>联系电话（可选）</span>
            <input class="contact-input" type="text" name="phone" placeholder="请输入电话或 Telegram">
          </label>
        </div>
        <label class="contact-label">
          <span>内容</span>
          <textarea class="contact-input contact-textarea" name="message" placeholder="请描述你的问题、需求与复现信息"></textarea>
        </label>
        <div class="contact-actions">
          <button class="channel-link contact-submit" type="submit">提交</button>
        </div>
      </form>
    </section>

    {$footerHtml}
  </main>
</body>
</html>
HTML;
}

// ─── 路由 ────────────────────────────────────────────────

$path     = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$clientIp = getClientIp();

// /geoip/{ip}
if (preg_match('#^/geoip/([^/]+)$#', $path, $m)) {
    $queryIp = urldecode($m[1]);
    if (getIpVersion($queryIp) === 0) {
        jsonResponse(['error' => 'Invalid IP address', 'input' => $queryIp], 400);
    }
    $data = queryAll($queryIp);
    $field = trim((string)($_GET['field'] ?? ''));
    if ($field !== '') {
        jsonResponse(filterGeoipByField($data, $field));
    }
    jsonResponse($data);
}

// /geoip
if ($path === '/geoip') {
    $data = queryAll($clientIp);
    $field = trim((string)($_GET['field'] ?? ''));
    if ($field !== '') {
        jsonResponse(filterGeoipByField($data, $field));
    }
    jsonResponse($data);
}

// /jsonip
if ($path === '/jsonip') {
    jsonResponse(['ip' => $clientIp]);
}

// /ip
if ($path === '/ip') {
    textResponse($clientIp);
}

// /ip/{ip} -> home page with query state
if (preg_match('#^/ip/([^/]+)$#', $path, $m)) {
    $queryIp = urldecode($m[1]);
    if (getIpVersion($queryIp) === 0) {
        htmlResponse(homePageHtml(null, 'ip'));
    }
    htmlResponse(homePageHtml($queryIp, 'ip'));
}

// /api
if ($path === '/api') {
    htmlResponse(apiPageHtml());
}

// /about
if ($path === '/about') {
    htmlResponse(aboutPageHtml());
}

// /contact
if ($path === '/contact') {
    htmlResponse(contactPageHtml());
}

// /
$path = $path === '' ? '/' : $path;
if ($path === '/') {
    if (isCliLikeRequest()) {
        textResponse(buildRootCliIpSummary($clientIp));
    }
    htmlResponse(homePageHtml(null, 'home'));
}

// fallback to plain IP
textResponse($clientIp);
