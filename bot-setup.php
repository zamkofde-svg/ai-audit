<?php
/**
 * Одноразовая настройка бота для заявок. Запускается только из консоли хостинга:
 *   php ~/www/audit.colver.pro/bot-setup.php
 * Спрашивает токен, находит чат, откуда боту написали /start,
 * и сохраняет настройки в файл вне папки сайта.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function api(string $token, string $method, array $params = []): ?array
{
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
        ]);
        $res = curl_exec($ch);
        $data = $res === false ? null : json_decode((string) $res, true);
        return is_array($data) ? $data : null;
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($params),
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    $data = $res === false ? null : json_decode($res, true);
    return is_array($data) ? $data : null;
}

fwrite(STDOUT, "Вставьте токен бота и нажмите Enter: ");
$token = trim((string) fgets(STDIN));
if (!preg_match('/^\d+:[\w-]{30,}$/', $token)) {
    fwrite(STDOUT, "Это не похоже на токен бота. Скопируйте его целиком из @BotFather.\n");
    exit(1);
}

$me = api($token, 'getMe');
if (!$me) {
    fwrite(STDOUT, "Не удалось связаться с Telegram с этого хостинга. Напишите об этом Claude.\n");
    exit(1);
}
if (empty($me['ok'])) {
    fwrite(STDOUT, "Telegram не принял токен. Проверьте, что скопировали его целиком.\n");
    exit(1);
}
$bot = '@' . $me['result']['username'];

$upd = api($token, 'getUpdates', ['limit' => 100, 'allowed_updates' => json_encode(['message'])]);
$chat = null;
foreach (array_reverse($upd['result'] ?? []) as $u) {
    $c = $u['message']['chat'] ?? null;
    if ($c && ($c['type'] ?? '') === 'private') {
        $chat = $c;
        break;
    }
}
if (!$chat) {
    fwrite(STDOUT, "Бот $bot ещё не знает, кому писать.\n"
        . "Откройте $bot в Telegram, нажмите «Запустить» (или отправьте /start) и запустите эту команду ещё раз.\n");
    exit(1);
}

$who = trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''));
$confFile = dirname(__DIR__, 2) . '/audit-lead.conf.php';
$conf = "<?php\nreturn " . var_export(['token' => $token, 'chat_id' => $chat['id']], true) . ";\n";
if (file_put_contents($confFile, $conf, LOCK_EX) === false) {
    fwrite(STDOUT, "Не удалось записать файл настроек: $confFile\n");
    exit(1);
}
chmod($confFile, 0600);

$test = api($token, 'sendMessage', [
    'chat_id' => $chat['id'],
    'text' => "Форма сайта audit.colver.pro подключена. Заявки будут приходить в этот чат.",
]);
if (empty($test['ok'])) {
    fwrite(STDOUT, "Настройки сохранены, но тестовое сообщение не отправилось. Напишите об этом Claude.\n");
    exit(1);
}

fwrite(STDOUT, "Готово. Заявки с сайта будут приходить от $bot в чат: $who.\n"
    . "Проверьте Telegram: там должно быть тестовое сообщение.\n");
