<?php

declare(strict_types=1);

/**
 * xdb 自动更新脚本
 *
 * 用法:
 *   php scripts/xdb_auto_update.php
 *   php scripts/xdb_auto_update.php --force
 *
 * 建议 crontab（每小时跑一次，脚本内部会门控到每月 2 号 00:00）:
 *   0 * * * * /usr/bin/php /Users/gentpan/projects/ip.bluecdn.com/scripts/xdb_auto_update.php >> /Users/gentpan/projects/ip.bluecdn.com/data/xdb_update.log 2>&1
 */

const ROOT_DIR = __DIR__ . '/..';
const DB_V4_DEFAULT = ROOT_DIR . '/data/high_full.xdb';
const DB_V6_DEFAULT = ROOT_DIR . '/data/high_full_v6.xdb';
const DEFAULT_STATE_FILE = ROOT_DIR . '/data/xdb_update_state.json';
const DEFAULT_LOCK_FILE = ROOT_DIR . '/data/xdb_update.lock';
const DEFAULT_MAX_DAILY_DOWNLOADS = 12;
const DEFAULT_TIMEOUT_SECONDS = 1800;

function loadEnvFile(string $envPath): void
{
    if (!is_file($envPath) || !is_readable($envPath)) return;
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
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

function getTz(): DateTimeZone
{
    $tzName = envValue('XDB_META_TIMEZONE', envValue('APP_TIMEZONE', 'Asia/Tashkent') ?? 'Asia/Tashkent') ?? 'Asia/Tashkent';
    try {
        return new DateTimeZone($tzName);
    } catch (\Exception) {
        return new DateTimeZone('Asia/Tashkent');
    }
}

function getScheduleConfig(): array
{
    $day = (int)(envValue('XDB_META_UPDATE_DAY', envValue('XDB_AUTO_UPDATE_DAY', '2')) ?? '2');
    $hour = (int)(envValue('XDB_META_UPDATE_HOUR', envValue('XDB_AUTO_UPDATE_HOUR', '0')) ?? '0');
    $minute = (int)(envValue('XDB_META_UPDATE_MINUTE', envValue('XDB_AUTO_UPDATE_MINUTE', '0')) ?? '0');

    return [
        'day' => max(1, min(28, $day)),
        'hour' => max(0, min(23, $hour)),
        'minute' => max(0, min(59, $minute)),
    ];
}

function currentScheduleWindow(DateTimeZone $tz, array $cfg): DateTimeImmutable
{
    $now = new DateTimeImmutable('now', $tz);
    $scheduled = $now
        ->setDate((int)$now->format('Y'), (int)$now->format('m'), $cfg['day'])
        ->setTime($cfg['hour'], $cfg['minute'], 0);
    return $now >= $scheduled ? $scheduled : $scheduled->modify('-1 month');
}

function nextScheduleWindow(DateTimeZone $tz, array $cfg): DateTimeImmutable
{
    $now = new DateTimeImmutable('now', $tz);
    $scheduled = $now
        ->setDate((int)$now->format('Y'), (int)$now->format('m'), $cfg['day'])
        ->setTime($cfg['hour'], $cfg['minute'], 0);
    return $now < $scheduled ? $scheduled : $scheduled->modify('+1 month');
}

function readState(string $file): array
{
    if (!is_file($file)) return [];
    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') return [];
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function writeState(string $file, array $state): void
{
    $tmp = $file . '.tmp';
    $payload = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($payload === false) return;
    file_put_contents($tmp, $payload);
    rename($tmp, $file);
}

function countTodayAttempts(array $state, string $today): int
{
    $attempts = $state['daily_attempts'] ?? [];
    if (!is_array($attempts)) return 0;
    return (int)($attempts[$today] ?? 0);
}

function increaseTodayAttempts(array &$state, string $today): void
{
    if (!isset($state['daily_attempts']) || !is_array($state['daily_attempts'])) {
        $state['daily_attempts'] = [];
    }
    $state['daily_attempts'][$today] = ((int)($state['daily_attempts'][$today] ?? 0)) + 1;
}

function compactAttempts(array &$state, string $today): void
{
    $attempts = $state['daily_attempts'] ?? [];
    if (!is_array($attempts)) {
        $state['daily_attempts'] = [];
        return;
    }

    $keep = [$today];
    $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    $keep[] = $yesterday;

    $state['daily_attempts'] = array_intersect_key($attempts, array_flip($keep));
}

function downloadToTemp(string $url, string $targetTmp, int $timeout): array
{
    $ch = curl_init();
    if ($ch === false) {
        return [false, 'curl_init failed'];
    }

    $fp = fopen($targetTmp, 'wb');
    if ($fp === false) {
        curl_close($ch);
        return [false, 'open tmp file failed: ' . $targetTmp];
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'ip.bluecdn.com/xdb-updater');
    curl_setopt($ch, CURLOPT_FAILONERROR, true);

    if (str_starts_with($url, 'https://')) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }

    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (PHP_VERSION_ID < 80000) {
        curl_close($ch);
    }
    fclose($fp);

    if ($ok === false) {
        @unlink($targetTmp);
        return [false, 'download failed: ' . $err];
    }

    if ($code >= 400) {
        @unlink($targetTmp);
        return [false, 'http status: ' . $code];
    }

    $size = @filesize($targetTmp);
    if (!is_int($size) || $size < 1024) {
        @unlink($targetTmp);
        return [false, 'downloaded file too small'];
    }

    return [true, null];
}

function updateOne(string $url, string $dstFile, int $timeout): array
{
    if (trim($url) === '') {
        return [false, 'empty download url: ' . $dstFile];
    }
    $tmp = $dstFile . '.tmp.' . getmypid();
    [$ok, $err] = downloadToTemp($url, $tmp, $timeout);
    if (!$ok) return [false, $err];

    if (!@rename($tmp, $dstFile)) {
        @unlink($tmp);
        return [false, 'rename failed for: ' . $dstFile];
    }

    return [true, null];
}

function nowStr(DateTimeZone $tz): string
{
    return (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
}

loadEnvFile(ROOT_DIR . '/.env');

$tz = getTz();
$cfg = getScheduleConfig();
$force = in_array('--force', $argv ?? [], true);

$stateFile = envValue('XDB_UPDATE_STATE_FILE', DEFAULT_STATE_FILE) ?? DEFAULT_STATE_FILE;
$lockFile = envValue('XDB_UPDATE_LOCK_FILE', DEFAULT_LOCK_FILE) ?? DEFAULT_LOCK_FILE;
$maxDaily = max(1, min(12, (int)(envValue('XDB_AUTO_MAX_DAILY_DOWNLOADS', (string)DEFAULT_MAX_DAILY_DOWNLOADS) ?? (string)DEFAULT_MAX_DAILY_DOWNLOADS)));
$timeout = max(60, (int)(envValue('XDB_DOWNLOAD_TIMEOUT_SECONDS', (string)DEFAULT_TIMEOUT_SECONDS) ?? (string)DEFAULT_TIMEOUT_SECONDS));
$v4Url = envValue('XDB_V4_DOWNLOAD_URL', '');
$v6Url = envValue('XDB_V6_DOWNLOAD_URL', '');
$v4File = envValue('XDB_V4_LOCAL_FILE', DB_V4_DEFAULT) ?? DB_V4_DEFAULT;
$v6File = envValue('XDB_V6_LOCAL_FILE', DB_V6_DEFAULT) ?? DB_V6_DEFAULT;

$lockFp = fopen($lockFile, 'c');
if ($lockFp === false) {
    fwrite(STDERR, "lock open failed\n");
    exit(2);
}
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "another updater is running\n");
    fclose($lockFp);
    exit(3);
}

$state = readState($stateFile);
$today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
compactAttempts($state, $today);

$currentWindow = currentScheduleWindow($tz, $cfg);
$nextWindow = nextScheduleWindow($tz, $cfg);
$state['next_update_at'] = $nextWindow->format('Y-m-d H:i:s');

$lastSuccess = null;
if (!empty($state['last_success_at'])) {
    try {
        $lastSuccess = new DateTimeImmutable((string)$state['last_success_at'], $tz);
    } catch (\Exception) {
        $lastSuccess = null;
    }
}

if (!$force) {
    if ((new DateTimeImmutable('now', $tz)) < $currentWindow) {
        $state['last_check_at'] = nowStr($tz);
        $state['last_result'] = 'skip_before_window';
        writeState($stateFile, $state);
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        echo "skip: before update window\n";
        exit(0);
    }

    if ($lastSuccess !== null && $lastSuccess >= $currentWindow) {
        $state['last_check_at'] = nowStr($tz);
        $state['last_result'] = 'skip_already_updated';
        writeState($stateFile, $state);
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        echo "skip: already updated in current window\n";
        exit(0);
    }
}

$todayAttempts = countTodayAttempts($state, $today);
if ($todayAttempts >= $maxDaily) {
    $state['last_check_at'] = nowStr($tz);
    $state['last_result'] = 'skip_daily_limit';
    writeState($stateFile, $state);
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    fwrite(STDERR, "daily download limit reached: {$todayAttempts}/{$maxDaily}\n");
    exit(4);
}

$errors = [];

increaseTodayAttempts($state, $today);
[$ok4, $err4] = updateOne((string)$v4Url, (string)$v4File, $timeout);
if (!$ok4) $errors[] = 'v4: ' . $err4;

increaseTodayAttempts($state, $today);
[$ok6, $err6] = updateOne((string)$v6Url, (string)$v6File, $timeout);
if (!$ok6) $errors[] = 'v6: ' . $err6;

$state['last_check_at'] = nowStr($tz);
$state['next_update_at'] = $nextWindow->format('Y-m-d H:i:s');

if (count($errors) > 0) {
    $state['last_result'] = 'failed';
    $state['last_error'] = implode(' | ', $errors);
    writeState($stateFile, $state);
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    fwrite(STDERR, "update failed: " . $state['last_error'] . "\n");
    exit(5);
}

$state['last_result'] = 'success';
$state['last_error'] = '';
$state['last_success_at'] = nowStr($tz);
$state['target_v4_file'] = (string)$v4File;
$state['target_v6_file'] = (string)$v6File;
writeState($stateFile, $state);

flock($lockFp, LOCK_UN);
fclose($lockFp);

echo "update success\n";
exit(0);
