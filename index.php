<?php

/**
 * ip.bluecdn.com — 主 IP 查询 API
 *
 * GeoIP 字段格式:
 *   continent    大洲
 *   country      国家
 *   province     省份/州
 *   city         城市
 *   district     区县
 *   isp          运营商
 *   longitude    经度
 *   latitude     纬度
 *   area_code    行政区划代码（当前上游不提供，默认空）
 *   phone_code   电话区号（当前上游不提供，默认空）
 *   zip_code     邮政编码
 *   timezone     时区
 *   currency     货币
 *   asn          ASN
 *   country_code 国家代码
 *
 * 路由:
 *   /              → 首页（自动识别客户端 IP 并展示字段）
 *   /ip            → 返回客户端 IP（纯文本）
 *   /jsonip        → 返回客户端 IP（JSON）
 *   /geoip         → 返回客户端自身 IP 地理信息（JSON）
 *   /geoip/{ip}    → 返回指定 IP 地理信息（JSON）
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/footer.php';

// ─── 全局 CORS（默认开启）───────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── GeoIP Provider 配置 ─────────────────────────────────

const GEOIP_CACHE_SCHEMA = 1;
const GEOIP_RATE_LIMIT_SCHEMA = 1;
const DEFAULT_IP_API_ENDPOINT = 'http://ip-api.com/json/';
const DEFAULT_IP_API_PRO_ENDPOINT = 'https://pro.ip-api.com/json/';
const DEFAULT_IP_API_FIELDS = 'status,message,continent,country,countryCode,regionName,city,district,zip,lat,lon,timezone,currency,isp,as,query';

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

// ─── GeoIP Provider 元信息 ───────────────────────────────

function geoipHttpTimeout(): int
{
    return max(2, min(15, (int)(envValue('GEOIP_HTTP_TIMEOUT', '5') ?? '5')));
}

function geoipCacheTtl(): int
{
    return max(60, (int)(envValue('GEOIP_CACHE_TTL', '86400') ?? '86400'));
}

function geoipIpApiLang(): string
{
    $lang = trim((string)(envValue('IP_API_LANG', 'zh-CN') ?? 'zh-CN'));
    return $lang !== '' ? $lang : 'zh-CN';
}

function geoipIpApiKey(): string
{
    return trim((string)(envValue('IP_API_KEY', '') ?? ''));
}

function geoipIpApiEndpoint(): string
{
    $endpoint = trim((string)(envValue('IP_API_ENDPOINT', '') ?? ''));
    if ($endpoint === '') {
        $endpoint = geoipIpApiKey() !== '' ? DEFAULT_IP_API_PRO_ENDPOINT : DEFAULT_IP_API_ENDPOINT;
    }
    return rtrim($endpoint, '/') . '/';
}

function geoipCacheDir(): string
{
    return envValue('GEOIP_CACHE_DIR', __DIR__ . '/data/geoip-cache') ?? (__DIR__ . '/data/geoip-cache');
}

function geoipRateLimitFile(): string
{
    return envValue('IP_API_RATE_LIMIT_FILE', __DIR__ . '/data/ip_api_rate_limit.json') ?? (__DIR__ . '/data/ip_api_rate_limit.json');
}

function ensureDir(string $dir): void
{
    if ($dir === '' || is_dir($dir)) return;
    @mkdir($dir, 0775, true);
}

function readJsonFile(string $path): ?array
{
    if (!is_file($path) || !is_readable($path)) return null;
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function writeJsonFile(string $path, array $payload): void
{
    ensureDir(dirname($path));
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function formatDurationLabel(int $seconds): string
{
    if ($seconds % 86400 === 0) return (string)($seconds / 86400) . ' 天';
    if ($seconds % 3600 === 0) return (string)($seconds / 3600) . ' 小时';
    if ($seconds % 60 === 0) return (string)($seconds / 60) . ' 分钟';
    return $seconds . ' 秒';
}

function getGeoSourceInfo(): array
{
    $ttl = geoipCacheTtl();
    $provider = geoipIpApiKey() !== '' ? 'ip-api.com Pro' : 'ip-api.com';
    $note = '服务端代理查询并缓存结果，默认缓存 TTL：' . formatDurationLabel($ttl);
    if (geoipIpApiKey() !== '') {
        $note .= '，当前已启用 Pro key';
    }
    return [
        'provider' => $provider,
        'note' => $note,
    ];
}

function geoipCachePath(string $ip): string
{
    return rtrim(geoipCacheDir(), '/\\') . '/' . sha1(strtolower(trim($ip))) . '.json';
}

function readGeoipCacheEntry(string $ip): ?array
{
    $entry = readJsonFile(geoipCachePath($ip));
    if (!is_array($entry)) return null;
    if ((int)($entry['schema'] ?? 0) !== GEOIP_CACHE_SCHEMA) return null;
    if (!isset($entry['data']) || !is_array($entry['data'])) return null;
    return $entry;
}

function writeGeoipCacheEntry(string $ip, array $data): void
{
    $now = time();
    writeJsonFile(geoipCachePath($ip), [
        'schema' => GEOIP_CACHE_SCHEMA,
        'provider' => 'ip-api',
        'fetched_at' => $now,
        'expires_at' => $now + geoipCacheTtl(),
        'data' => $data,
    ]);
}

function readIpApiRateLimitState(): ?array
{
    $state = readJsonFile(geoipRateLimitFile());
    if (!is_array($state)) return null;
    if ((int)($state['schema'] ?? 0) !== GEOIP_RATE_LIMIT_SCHEMA) return null;
    return $state;
}

function isIpApiRateLimited(): bool
{
    $state = readIpApiRateLimitState();
    if (!is_array($state)) return false;
    return (int)($state['blocked_until'] ?? 0) > time();
}

function writeIpApiRateLimitState(int $ttl, int $remaining = 0): void
{
    $now = time();
    writeJsonFile(geoipRateLimitFile(), [
        'schema' => GEOIP_RATE_LIMIT_SCHEMA,
        'provider' => 'ip-api',
        'updated_at' => $now,
        'remaining' => max(0, $remaining),
        'ttl' => max(0, $ttl),
        'blocked_until' => $now + max(0, $ttl),
    ]);
}

function clearIpApiRateLimitState(): void
{
    $path = geoipRateLimitFile();
    if (is_file($path)) {
        @unlink($path);
    }
}

function buildIpApiUrl(string $ip): string
{
    $fields = trim((string)(envValue('IP_API_FIELDS', DEFAULT_IP_API_FIELDS) ?? DEFAULT_IP_API_FIELDS));
    if ($fields === '') $fields = DEFAULT_IP_API_FIELDS;

    $params = [
        'fields' => $fields,
        'lang' => geoipIpApiLang(),
    ];
    $key = geoipIpApiKey();
    if ($key !== '') {
        $params['key'] = $key;
    }

    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    return geoipIpApiEndpoint() . rawurlencode($ip) . '?' . $query;
}

function httpJsonRequest(string $url, int $timeout): array
{
    $headers = [];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'headers' => [], 'body' => null, 'error' => 'Failed to initialize cURL'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ip.bluecdn.com/geoip-proxy',
            ],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $trimmed = trim($line);
                if ($trimmed === '' || !str_contains($trimmed, ':')) return strlen($line);
                [$name, $value] = explode(':', $trimmed, 2);
                $headers[strtolower(trim($name))] = trim($value);
                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => is_string($body) ? $body : null,
            'error' => $error !== '' ? $error : null,
        ];
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: ip.bluecdn.com/geoip-proxy\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    $error = $body === false ? 'HTTP request failed' : null;

    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int)$m[1];
            continue;
        }
        if (!str_contains($line, ':')) continue;
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }

    return [
        'status' => $status,
        'headers' => $headers,
        'body' => is_string($body) ? $body : null,
        'error' => $error,
    ];
}

function applyIpApiRateLimitHeaders(array $headers): void
{
    $remaining = isset($headers['x-rl']) ? (int)$headers['x-rl'] : null;
    $ttl = isset($headers['x-ttl']) ? (int)$headers['x-ttl'] : null;

    if ($remaining !== null && $ttl !== null && $remaining <= 0 && $ttl > 0) {
        writeIpApiRateLimitState($ttl, $remaining);
        return;
    }

    if ($remaining !== null && $remaining > 0) {
        clearIpApiRateLimitState();
    }
}

function normalizeIpApiAsn(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    if (preg_match('/\bAS\d+\b/i', $value, $m)) {
        return strtoupper($m[0]);
    }
    return $value;
}

function geoipStatusFromMessage(string $message): int
{
    $message = strtolower(trim($message));
    return match ($message) {
        'private range', 'reserved range', 'invalid query' => 400,
        default => 503,
    };
}

function mapIpApiPayload(array $payload, string $ip): array
{
    $queryIp = trim((string)($payload['query'] ?? $ip));
    $countryCode = strtoupper(trim((string)($payload['countryCode'] ?? '')));
    $longitude = isset($payload['lon']) && is_numeric($payload['lon']) ? (float)$payload['lon'] : null;
    $latitude = isset($payload['lat']) && is_numeric($payload['lat']) ? (float)$payload['lat'] : null;

    return [
        'ip' => $queryIp !== '' ? $queryIp : $ip,
        'continent' => trim((string)($payload['continent'] ?? '')),
        'country' => trim((string)($payload['country'] ?? '')),
        'province' => trim((string)($payload['regionName'] ?? '')),
        'city' => trim((string)($payload['city'] ?? '')),
        'district' => trim((string)($payload['district'] ?? '')),
        'isp' => trim((string)($payload['isp'] ?? '')),
        'longitude' => $longitude,
        'latitude' => $latitude,
        'area_code' => '',
        'phone_code' => '',
        'zip_code' => trim((string)($payload['zip'] ?? '')),
        'timezone' => trim((string)($payload['timezone'] ?? '')),
        'currency' => trim((string)($payload['currency'] ?? '')),
        'asn' => normalizeIpApiAsn((string)($payload['as'] ?? '')),
        'country_code' => $countryCode,
    ];
}

function lookupGeoip(string $ip): array
{
    $cached = readGeoipCacheEntry($ip);
    $staleData = is_array($cached['data'] ?? null) ? $cached['data'] : null;
    $expiresAt = (int)($cached['expires_at'] ?? 0);
    if ($staleData !== null && $expiresAt >= time()) {
        return ['status' => 200, 'data' => $staleData];
    }

    if (isIpApiRateLimited()) {
        if ($staleData !== null) {
            return ['status' => 200, 'data' => $staleData];
        }
        return ['status' => 503, 'error' => 'GeoIP upstream rate limited'];
    }

    $response = httpJsonRequest(buildIpApiUrl($ip), geoipHttpTimeout());
    applyIpApiRateLimitHeaders($response['headers'] ?? []);

    if (($response['status'] ?? 0) === 429) {
        $ttl = (int)(($response['headers']['x-ttl'] ?? 60));
        writeIpApiRateLimitState(max(1, $ttl));
        if ($staleData !== null) {
            return ['status' => 200, 'data' => $staleData];
        }
        return ['status' => 503, 'error' => 'GeoIP upstream rate limited'];
    }

    $body = is_string($response['body'] ?? null) ? trim((string)$response['body']) : '';
    $payload = null;
    if ($body !== '') {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    if (is_array($payload) && (($payload['status'] ?? '') !== 'success')) {
        $message = trim((string)($payload['message'] ?? 'GeoIP lookup failed'));
        return ['status' => geoipStatusFromMessage($message), 'error' => $message];
    }

    if (($response['error'] ?? null) !== null || ($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300 || $body === '') {
        if ($staleData !== null) {
            return ['status' => 200, 'data' => $staleData];
        }
        return ['status' => 503, 'error' => 'GeoIP lookup failed'];
    }

    if (!is_array($payload)) {
        if ($staleData !== null) {
            return ['status' => 200, 'data' => $staleData];
        }
        return ['status' => 503, 'error' => 'GeoIP lookup returned invalid JSON'];
    }

    $data = mapIpApiPayload($payload, $ip);
    writeGeoipCacheEntry($ip, $data);
    return ['status' => 200, 'data' => $data];
}

function queryAll(string $ip): array
{
    $lookup = lookupGeoip($ip);
    if ((int)($lookup['status'] ?? 0) !== 200 || !isset($lookup['data']) || !is_array($lookup['data'])) {
        return ['ip' => $ip];
    }
    return $lookup['data'];
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
    $geoSource = getGeoSourceInfo();
    $headerHtml = renderSiteHeader($context);
    $footerHtml = renderSiteFooter(['/', '/ip', '/jsonip', '/geoip', '/geoip/{ip}'], $geoSource, (int)$year);
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
    $geoSource = getGeoSourceInfo();
    $headerHtml = renderSiteHeader('api');
    $footerHtml = renderSiteFooter(['/ip', '/jsonip', '/geoip', '/geoip/{ip}'], $geoSource, (int)$year);
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
    $geoSource = getGeoSourceInfo();
    $headerHtml = renderSiteHeader('about');
    $footerHtml = renderSiteFooter(['/ip', '/jsonip', '/geoip', '/geoip/{ip}'], $geoSource, (int)$year);
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
          <p>服务端通过 ip-api.com 获取 GeoIP 数据，并使用本地缓存降低上游请求频率。</p>
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
    $geoSource = getGeoSourceInfo();
    $headerHtml = renderSiteHeader('contact');
    $footerHtml = renderSiteFooter(['/ip', '/jsonip', '/geoip', '/geoip/{ip}'], $geoSource, (int)$year);
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
    $lookup = lookupGeoip($queryIp);
    if ((int)($lookup['status'] ?? 0) !== 200 || !isset($lookup['data']) || !is_array($lookup['data'])) {
        jsonResponse([
            'error' => (string)($lookup['error'] ?? 'GeoIP lookup failed'),
            'input' => $queryIp,
        ], (int)($lookup['status'] ?? 503));
    }
    $data = $lookup['data'];
    $field = trim((string)($_GET['field'] ?? ''));
    if ($field !== '') {
        jsonResponse(filterGeoipByField($data, $field));
    }
    jsonResponse($data);
}

// /geoip
if ($path === '/geoip') {
    $lookup = lookupGeoip($clientIp);
    if ((int)($lookup['status'] ?? 0) !== 200 || !isset($lookup['data']) || !is_array($lookup['data'])) {
        jsonResponse([
            'error' => (string)($lookup['error'] ?? 'GeoIP lookup failed'),
            'input' => $clientIp,
        ], (int)($lookup['status'] ?? 503));
    }
    $data = $lookup['data'];
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
