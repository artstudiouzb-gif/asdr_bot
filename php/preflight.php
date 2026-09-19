<?php
/**
 * Preflight — проверка shared hosting перед разработкой Telegram-репостера.
 *
 * Отвечает на три вопроса:
 *   1. Пойдёт ли здесь MadelineProto (MTProto — чтение любых каналов)?
 *   2. Работает ли план Б — чтение публичных каналов со страниц t.me/s/?
 *   3. Доступен ли Bot API и MySQL, и чем запускать cron?
 *
 * Запуск по SSH:      php preflight.php
 * Запуск в браузере:  https://ваш-домен/preflight.php?key=КЛЮЧ_НИЖЕ
 * С проверкой MySQL:  php preflight.php --db-host=localhost --db-name=… --db-user=… --db-pass=…
 *
 * ВАЖНО: после проверки удалите файл с хостинга — он показывает параметры сервера.
 */

declare(strict_types=1);

const PREFLIGHT_KEY = 'g1LNVlGjcXzFDnK3ugzVDFB4';

const OK = 'ok';       // требование выполнено
const WARN = 'warn';   // работать будет, но с оговоркой
const FAIL = 'fail';   // блокирует сценарий

const REQUIRED_EXTENSIONS = ['mbstring', 'json', 'xml', 'dom', 'fileinfo', 'iconv', 'zlib', 'openssl', 'curl'];
const MTPROTO_DC = [['149.154.167.51', 443, 'DC2 Amsterdam'], ['149.154.175.50', 443, 'DC1 Miami']];

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $key = (string)($_GET['key'] ?? '');
    if (!hash_equals(PREFLIGHT_KEY, $key)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("403. Откройте адрес с ?key=… — ключ задан в начале файла preflight.php\n");
    }
}

$options = collectOptions($isCli);
$report = [];
$section = static function (string $name) use (&$report): void { $report[] = ['section', $name, '', '', '']; };
$row = static function (string $name, string $value, string $status, string $note = '') use (&$report): void {
    $report[] = ['row', $name, $value, $status, $note];
};

// ─────────────────────────────── PHP ────────────────────────────────────────
$section('PHP');
$phpOk = version_compare(PHP_VERSION, '8.2', '>=');
$row('Версия PHP', PHP_VERSION, $phpOk ? OK : FAIL, $phpOk ? '' : 'MadelineProto требует 8.2 и новее');
$row('SAPI', PHP_SAPI, OK);
$bits = PHP_INT_SIZE * 8;
$row('Разрядность', $bits . '-bit', $bits === 64 ? OK : FAIL, $bits === 64 ? '' : 'MTProto требует 64-битный PHP');
$row('Часовой пояс', (string)(ini_get('date.timezone') ?: 'не задан'), OK, 'в проекте используется Asia/Tashkent');

// ────────────────────────────── Расширения ──────────────────────────────────
$section('Расширения');
foreach (REQUIRED_EXTENSIONS as $extension) {
    $has = extension_loaded($extension);
    $row('ext-' . $extension, $has ? 'есть' : 'нет', $has ? OK : FAIL, $has ? '' : 'обязательно для MTProto');
}
$hasGmp = extension_loaded('gmp');
$hasBcmath = extension_loaded('bcmath');
$row('ext-gmp', $hasGmp ? 'есть' : 'нет', $hasGmp ? OK : ($hasBcmath ? WARN : FAIL),
    $hasGmp ? '' : ($hasBcmath ? 'есть bcmath — медленнее, см. замер ниже' : 'без gmp и bcmath MTProto не запустится'));
$row('ext-bcmath', $hasBcmath ? 'есть' : 'нет', $hasBcmath ? OK : WARN);
$hasPdo = extension_loaded('pdo_mysql');
$row('ext-pdo_mysql', $hasPdo ? 'есть' : 'нет', $hasPdo ? OK : FAIL, $hasPdo ? '' : 'нужен для MySQL');
foreach (['ffi' => 'ускоряет MadelineProto', 'sodium' => 'ускоряет шифрование', 'zip' => 'опционально'] as $extension => $why) {
    $row('ext-' . $extension, extension_loaded($extension) ? 'есть' : 'нет', extension_loaded($extension) ? OK : WARN, $why);
}

// ─────────────────────────────── Лимиты ─────────────────────────────────────
$section('Лимиты');
$memory = (string)ini_get('memory_limit');
$memoryBytes = toBytes($memory);
$row('memory_limit', $memory, ($memoryBytes === -1 || $memoryBytes >= 256 * 1024 * 1024) ? OK : WARN,
    'для MTProto желательно 256M и больше');
$maxTime = (string)ini_get('max_execution_time');
$row('max_execution_time', $maxTime, ($isCli || $maxTime === '0' || (int)$maxTime >= 60) ? OK : WARN,
    'вход в Telegram из панели занимает до 60 с');
$row('upload_max_filesize', (string)ini_get('upload_max_filesize'), OK);
$row('open_basedir', (string)(ini_get('open_basedir') ?: 'не задан'), ini_get('open_basedir') ? WARN : OK,
    ini_get('open_basedir') ? 'проверьте, что каталог проекта и /tmp внутри' : '');
$row('allow_url_fopen', ini_get('allow_url_fopen') ? 'включён' : 'выключен', OK, 'сеть используется через cURL');

$section('Запрещённые функции');
$disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
$row('disable_functions', $disabled ? implode(', ', $disabled) : 'нет', $disabled ? WARN : OK);
foreach (['proc_open' => 'MadelineProto запустим и без неё, в однопроцессном режиме',
          'shell_exec' => 'не требуется',
          'pcntl_fork' => 'не требуется',
          'set_time_limit' => 'желательна для воркера'] as $function => $why) {
    $available = function_exists($function) && !in_array($function, $disabled, true);
    $row($function . '()', $available ? 'доступна' : 'запрещена', $available ? OK : WARN, $why);
}

// ───────────────────────────── Файловая система ─────────────────────────────
$section('Файловая система');
$dir = __DIR__;
$probe = $dir . '/.preflight-' . bin2hex(random_bytes(4));
$writable = @file_put_contents($probe, 'test') !== false;
if ($writable) {
    @unlink($probe);
}
$row('Запись в каталог скрипта', $writable ? 'да' : 'нет', $writable ? OK : FAIL,
    $writable ? $dir : 'здесь будет лежать сессия Telegram и временные файлы');
$tmp = sys_get_temp_dir();
$row('Временный каталог', $tmp, is_writable($tmp) ? OK : WARN);
$free = @disk_free_space($dir);
$row('Свободно на диске', $free ? formatBytes((int)$free) : 'неизвестно',
    ($free && $free > 200 * 1024 * 1024) ? OK : WARN, 'медиа при публикации ботом занимают место временно');

// ──────────────────────────────── Сеть ──────────────────────────────────────
$section('Сеть');
[$botStatus, $botNote, $botTime] = httpProbe('https://api.telegram.org/bot0:0/getMe');
$row('api.telegram.org (Bot API)', $botStatus === FAIL ? 'недоступен' : 'доступен (' . $botTime . ' мс)', $botStatus, $botNote);

$dcReachable = false;
foreach (MTPROTO_DC as [$ip, $port, $label]) {
    $started = microtime(true);
    $socket = @fsockopen($ip, $port, $errNo, $errStr, 5);
    $elapsed = (int)round((microtime(true) - $started) * 1000);
    if ($socket) {
        fclose($socket);
        $dcReachable = true;
        $row('MTProto ' . $label, 'доступен (' . $elapsed . ' мс)', OK, $ip . ':' . $port);
    } else {
        $row('MTProto ' . $label, 'недоступен', FAIL, trim($errStr) !== '' ? $errStr : 'соединение не установлено');
    }
}

[$pageStatus, $pageNote, $pageTime, $pageBody] = httpProbe('https://t.me/s/telegram', true);
$postsFound = $pageBody !== '' ? substr_count($pageBody, 'tgme_widget_message_wrap') : 0;
$planB = $pageStatus !== FAIL && $postsFound > 0;
$row('Страница t.me/s/ (план Б)', $planB ? "читается, постов на странице: {$postsFound}" : 'не читается',
    $planB ? OK : FAIL, $planB ? '' : $pageNote);

// ──────────────────────────── Скорость криптографии ─────────────────────────
$section('Скорость криптографии (рукопожатие MTProto)');
[$cryptoValue, $cryptoStatus, $cryptoNote] = benchmarkModPow($hasGmp, $hasBcmath);
$row('2048-битное возведение в степень', $cryptoValue, $cryptoStatus, $cryptoNote);

// ─────────────────────────────── MySQL ──────────────────────────────────────
$section('MySQL');
if ($options['db_name'] !== '') {
    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $options['db_host'], $options['db_name']);
        $pdo = new PDO($dsn, $options['db_user'], $options['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $row('Подключение', 'успешно', OK, 'сервер: ' . $version);
        $lock = $pdo->query("SELECT GET_LOCK('reposter.preflight', 0)")->fetchColumn();
        $pdo->query("SELECT RELEASE_LOCK('reposter.preflight')");
        $row('GET_LOCK()', $lock === 1 || $lock === '1' ? 'работает' : 'недоступна',
            ($lock === 1 || $lock === '1') ? OK : WARN, 'используется как защита от параллельного cron');
        $engines = $pdo->query("SHOW ENGINES")->fetchAll(PDO::FETCH_ASSOC);
        $innodb = false;
        foreach ($engines as $engine) {
            if (strtoupper((string)$engine['Engine']) === 'INNODB' && in_array(strtoupper((string)$engine['Support']), ['YES', 'DEFAULT'], true)) {
                $innodb = true;
            }
        }
        $row('InnoDB', $innodb ? 'доступен' : 'недоступен', $innodb ? OK : FAIL, 'нужен для внешних ключей');
    } catch (Throwable $e) {
        $row('Подключение', 'ошибка', FAIL, $e->getMessage());
    }
} else {
    $row('Проверка', 'пропущена', WARN, 'передайте --db-host --db-name --db-user --db-pass (или ?db_name=…)');
}

// ──────────────────────────────── Cron ──────────────────────────────────────
$section('Cron');
if ($isCli) {
    $row('Бинарник PHP для cron', PHP_BINARY, OK, 'строка cron: * * * * * ' . PHP_BINARY . ' ' . $dir . '/bin/cron.php');
} else {
    $candidates = ['/usr/local/bin/php', '/usr/bin/php', '/usr/local/bin/php82', '/usr/local/bin/php83',
        '/opt/cpanel/ea-php82/root/usr/bin/php', '/opt/cpanel/ea-php83/root/usr/bin/php', '/opt/alt/php82/usr/bin/php'];
    $found = array_values(array_filter($candidates, static fn(string $path): bool => @is_executable($path)));
    $row('Бинарник PHP для cron', $found ? implode(', ', $found) : 'не найден автоматически',
        $found ? OK : WARN, $found ? '' : 'спросите у хостера путь к PHP CLI');
}

// ─────────────────────────────── Вердикт ────────────────────────────────────
$fails = [];
$warns = [];
foreach ($report as [$type, $name, $value, $status, $note]) {
    if ($type !== 'row') {
        continue;
    }
    if ($status === FAIL) {
        $fails[] = $name;
    } elseif ($status === WARN) {
        $warns[] = $name;
    }
}

$extensionsOk = true;
foreach (REQUIRED_EXTENSIONS as $extension) {
    $extensionsOk = $extensionsOk && extension_loaded($extension);
}
$mtprotoReady = $phpOk && $bits === 64 && $extensionsOk && ($hasGmp || $hasBcmath) && $dcReachable && $writable
    && ($memoryBytes === -1 || $memoryBytes >= 128 * 1024 * 1024);

$verdict = [];
$verdict[] = [$mtprotoReady ? OK : FAIL, 'MadelineProto (MTProto, чтение любых каналов): '
    . ($mtprotoReady ? 'хостинг подходит' . ($hasGmp ? '' : ' — но без gmp, проверьте замер скорости') : 'не подходит')];
$verdict[] = [$planB ? OK : FAIL, 'План Б (парсинг t.me/s/, только публичные каналы, текст и фото): '
    . ($planB ? 'работает' : 'не работает — сеть закрыта')];
$verdict[] = [$botStatus === FAIL ? FAIL : OK, 'Bot API (публикация ботом): '
    . ($botStatus === FAIL ? 'недоступен' : 'доступен')];

render($isCli, $report, $verdict, $fails, $warns);

// ─────────────────────────────── Функции ────────────────────────────────────

function collectOptions(bool $isCli): array
{
    $defaults = ['db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'db_pass' => ''];
    if ($isCli) {
        $parsed = getopt('', ['db-host:', 'db-name:', 'db-user:', 'db-pass:']);
        foreach ($defaults as $key => $value) {
            $cliKey = str_replace('_', '-', $key);
            $defaults[$key] = isset($parsed[$cliKey]) ? (string)$parsed[$cliKey] : $value;
        }
        return $defaults;
    }
    foreach ($defaults as $key => $value) {
        $defaults[$key] = isset($_GET[$key]) ? (string)$_GET[$key] : $value;
    }
    return $defaults;
}

function toBytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return -1;
    }
    $unit = strtolower(substr($value, -1));
    $number = (int)$value;
    return match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
}

function formatBytes(int $bytes): string
{
    $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    $index = 0;
    $size = (float)$bytes;
    while ($size >= 1024 && $index < count($units) - 1) {
        $size /= 1024;
        $index++;
    }
    return sprintf('%.1f %s', $size, $units[$index]);
}

/** @return array{0:string,1:string,2:int,3:string} статус, примечание, миллисекунды, тело */
function httpProbe(string $url, bool $needBody = false): array
{
    if (!function_exists('curl_init')) {
        return [FAIL, 'нет расширения curl', 0, ''];
    }
    $started = microtime(true);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 7,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; reposter-preflight/1.0)',
    ]);
    $body = curl_exec($curl);
    $error = curl_error($curl);
    $code = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $elapsed = (int)round((microtime(true) - $started) * 1000);

    if ($body === false) {
        return [FAIL, $error !== '' ? $error : 'нет ответа', $elapsed, ''];
    }
    // 401/404 от api.telegram.org означают, что сервер отвечает — этого достаточно
    $reachable = $code > 0;
    return [$reachable ? OK : FAIL, 'HTTP ' . $code, $elapsed, $needBody ? (string)$body : ''];
}

/** @return array{0:string,1:string,2:string} значение, статус, примечание */
function benchmarkModPow(bool $hasGmp, bool $hasBcmath): array
{
    $modulusHex = str_repeat('F', 512); // 2048 бит
    if ($hasGmp) {
        $modulus = gmp_init($modulusHex, 16);
        $base = gmp_init(2);
        $exponent = gmp_init(str_repeat('A', 512), 16);
        $started = microtime(true);
        gmp_powm($base, $exponent, $modulus);
        $ms = (microtime(true) - $started) * 1000;
        $status = $ms < 100 ? OK : ($ms < 1000 ? WARN : FAIL);
        return [sprintf('%.1f мс (gmp)', $ms), $status, 'на рукопожатие уходит несколько таких операций'];
    }
    if ($hasBcmath) {
        $modulus = bcHexToDec($modulusHex);
        $started = microtime(true);
        bcpowmod('2', '65537', $modulus);       // короткая степень, дальше экстраполяция
        $ms = (microtime(true) - $started) * 1000;
        $estimate = $ms * (2048 / 17);
        $status = $estimate < 1000 ? WARN : FAIL;
        return [sprintf('~%.0f мс (bcmath, оценка)', $estimate), $status,
            'без gmp рукопожатие может занимать секунды на каждый запуск cron'];
    }
    return ['нечем считать', FAIL, 'нет ни gmp, ни bcmath'];
}

function bcHexToDec(string $hex): string
{
    $decimal = '0';
    $length = strlen($hex);
    for ($i = 0; $i < $length; $i++) {
        $decimal = bcadd(bcmul($decimal, '16'), (string)hexdec($hex[$i]));
    }
    return $decimal;
}

function pad(string $text, int $width): string
{
    $length = mb_strlen($text, 'UTF-8');
    return $text . ($length >= $width ? ' ' : str_repeat(' ', $width - $length));
}

function render(bool $isCli, array $report, array $verdict, array $fails, array $warns): void
{
    $marks = [OK => '[ OK ]', WARN => '[ !  ]', FAIL => '[FAIL]'];
    if ($isCli) {
        echo "\n  Preflight — проверка хостинга для Telegram-репостера\n";
        echo '  ' . date('Y-m-d H:i:s') . ' · ' . php_uname('n') . "\n";
        foreach ($report as [$type, $name, $value, $status, $note]) {
            if ($type === 'section') {
                echo "\n── " . $name . " " . str_repeat('─', max(0, 60 - mb_strlen($name))) . "\n";
                continue;
            }
                echo '  ' . $marks[$status] . ' ' . pad($name, 34) . $value
                . ($note !== '' ? '  — ' . $note : '') . "\n";
        }
        echo "\n── Вердикт " . str_repeat('─', 52) . "\n";
        foreach ($verdict as [$status, $text]) {
            echo '  ' . $marks[$status] . ' ' . $text . "\n";
        }
        echo "\n  Критичных проблем: " . count($fails) . ', предупреждений: ' . count($warns) . "\n";
        if ($fails) {
            echo '  Смотрите: ' . implode(', ', $fails) . "\n";
        }
        echo "\n  Пришлите этот вывод целиком — по нему выбирается архитектура.\n";
        echo "  После проверки удалите preflight.php с хостинга.\n\n";
        return;
    }

    $cssMap = [OK => '#15803d', WARN => '#b45309', FAIL => '#b91c1c'];
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">';
    echo '<title>Preflight</title><style>';
    echo 'body{font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;background:#f6f7fb;color:#111;margin:0;padding:24px}';
    echo '.wrap{max-width:900px;margin:0 auto}h1{font-size:20px}h2{font-size:15px;margin:22px 0 6px;color:#374151}';
    echo 'table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden}';
    echo 'td{padding:7px 10px;border-top:1px solid #f1f2f6;vertical-align:top}tr:first-child td{border-top:0}';
    echo 'td.s{width:70px;font-weight:700}td.n{width:35%}td.note{color:#6b7280;font-size:13px}';
    echo '.verdict{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin-top:18px}';
    echo '.verdict div{margin:6px 0;font-weight:600}pre{background:#111827;color:#e5e7eb;padding:12px;border-radius:10px;overflow:auto}';
    echo '</style></head><body><div class="wrap"><h1>Preflight — проверка хостинга</h1>';
    echo '<p>' . htmlspecialchars(date('Y-m-d H:i:s') . ' · ' . php_uname('n'), ENT_QUOTES) . '</p>';

    $open = false;
    foreach ($report as [$type, $name, $value, $status, $note]) {
        if ($type === 'section') {
            if ($open) {
                echo '</table>';
            }
            echo '<h2>' . htmlspecialchars($name, ENT_QUOTES) . '</h2><table>';
            $open = true;
            continue;
        }
        printf('<tr><td class="s" style="color:%s">%s</td><td class="n">%s</td><td>%s</td><td class="note">%s</td></tr>',
            $cssMap[$status], $marks[$status], htmlspecialchars($name, ENT_QUOTES),
            htmlspecialchars($value, ENT_QUOTES), htmlspecialchars($note, ENT_QUOTES));
    }
    if ($open) {
        echo '</table>';
    }

    echo '<div class="verdict"><div>Вердикт</div>';
    foreach ($verdict as [$status, $text]) {
        printf('<div style="color:%s">%s %s</div>', $cssMap[$status], $marks[$status], htmlspecialchars($text, ENT_QUOTES));
    }
    echo '</div><p>Скопируйте текст ниже и пришлите разработчику:</p><pre>';
    foreach ($report as [$type, $name, $value, $status, $note]) {
        if ($type === 'section') {
            echo "\n== " . htmlspecialchars($name, ENT_QUOTES) . " ==\n";
            continue;
        }
        echo htmlspecialchars($marks[$status] . ' ' . pad($name, 34) . $value
            . ($note !== '' ? '  — ' . $note : ''), ENT_QUOTES) . "\n";
    }
    foreach ($verdict as [$status, $text]) {
        echo htmlspecialchars($marks[$status] . ' ' . $text, ENT_QUOTES) . "\n";
    }
    echo '</pre><p><b>После проверки удалите preflight.php с хостинга.</b></p></div></body></html>';
}
