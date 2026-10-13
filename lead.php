<?php
/**
 * Принимает заявку с формы сайта и пересылает её в Telegram.
 * Токен бота и chat_id лежат в файле вне папки сайта (его создаёт bot-setup.php),
 * в репозитории и в коде страницы их нет.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function cut(string $s, int $max): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max * 2);
}

function field(array $in, string $key, int $max): string
{
    $v = isset($in[$key]) && is_string($in[$key]) ? trim($in[$key]) : '';
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v) ?? '';
    return cut($v, $max);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(405, ['ok' => false, 'error' => 'method']);
}

/* заявки принимаем только со своей страницы */
$origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
if ($origin !== '') {
    $host = parse_url($origin, PHP_URL_HOST);
    $self = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
    if (!is_string($host) || strcasecmp($host, $self) !== 0) {
        out(403, ['ok' => false, 'error' => 'origin']);
    }
}

$base = dirname(__DIR__, 2);                  // домашняя папка хостинга, вне сайта
$confFile = $base . '/audit-lead.conf.php';
if (!is_file($confFile)) {
    out(503, ['ok' => false, 'error' => 'not_configured']);
}
$cfg = require $confFile;
if (!is_array($cfg) || empty($cfg['token']) || empty($cfg['chat_id'])) {
    out(503, ['ok' => false, 'error' => 'not_configured']);
}

$raw = file_get_contents('php://input', false, null, 0, 20000);
$in = json_decode((string) $raw, true);
if (!is_array($in)) {
    $in = $_POST;
}

/* поле-ловушка: человек его не видит, боты заполняют */
if (field($in, 'website', 200) !== '') {
    out(200, ['ok' => true]);
}

$name    = field($in, 'name', 120);
$company = field($in, 'company', 160);
$role    = field($in, 'role', 160);
$contact = field($in, 'contact', 160);
$comment = field($in, 'comment', 2000);

if ($name === '' || $contact === '') {
    out(422, ['ok' => false, 'error' => 'required']);
}

/* ограничение частоты: 5 заявок с одного адреса за 10 минут, 60 в час всего */
$tmp = is_dir($base . '/tmp') && is_writable($base . '/tmp') ? $base . '/tmp' : sys_get_temp_dir();
$rateFile = $tmp . '/audit-lead-rate.json';
$now = time();
$ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|audit');
$fh = @fopen($rateFile, 'c+');
if ($fh) {
    flock($fh, LOCK_EX);
    $hits = json_decode((string) stream_get_contents($fh), true);
    $hits = is_array($hits) ? array_values(array_filter($hits, fn($h) => is_array($h) && ($h[0] ?? 0) > $now - 3600)) : [];
    $mine = count(array_filter($hits, fn($h) => $h[1] === $ip && $h[0] > $now - 600));
    $limited = $mine >= 5 || count($hits) >= 60;
    if (!$limited) {
        $hits[] = [$now, $ip];
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($hits));
    flock($fh, LOCK_UN);
    fclose($fh);
    if ($limited) {
        out(429, ['ok' => false, 'error' => 'rate']);
    }
}

$text = "Заявка с audit.colver.pro\n"
    . "\nИмя: " . $name
    . "\nКомпания: " . ($company !== '' ? $company : '—')
    . "\nДолжность: " . ($role !== '' ? $role : '—')
    . "\nКонтакт: " . $contact
    . ($comment !== '' ? "\nЗадача: " . $comment : '');

/* копия на хостинге: если Telegram недоступен, заявка не потеряется */
$logFile = $base . '/audit-leads.log';
$isNew = !is_file($logFile);
@file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "]\n" . $text . "\n\n", FILE_APPEND | LOCK_EX);
if ($isNew) {
    @chmod($logFile, 0600);
}

$url = 'https://api.telegram.org/bot' . $cfg['token'] . '/sendMessage';
$post = ['chat_id' => $cfg['chat_id'], 'text' => $text, 'disable_web_page_preview' => 'true'];
$sent = false;

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    $res = curl_exec($ch);
    $sent = $res !== false && (json_decode((string) $res, true)['ok'] ?? false) === true;
} else {
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($post),
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    $sent = $res !== false && (json_decode((string) $res, true)['ok'] ?? false) === true;
}

if (!$sent) {
    error_log('audit lead: telegram send failed');
    out(502, ['ok' => false, 'error' => 'telegram']);
}

out(200, ['ok' => true]);
