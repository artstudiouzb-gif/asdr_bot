<?php

/**
 * Установка и первичная настройка через браузер — для хостинга без SSH.
 *
 * Сам создаёт .env (с проверкой подключения к базе), применяет миграции
 * и заводит первого администратора. Пока администратора нет, страница открыта;
 * после установки требует ключ INSTALL_KEY из .env.
 */

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Db;
use App\Core\Diagnostics;
use App\Core\Env;
use App\Core\Migrator;
use App\Core\Url;

require dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

$envPath = APP_ROOT . '/.env';
$envExists = is_file($envPath);
$messages = [];
$errors = [];
$envDraft = null;

// ── состояние установки ─────────────────────────────────────────────────────
$dbReady = false;
$pending = [];
$adminExists = false;

$state = static function () use (&$dbReady, &$pending, &$adminExists): void {
    $dbReady = false;
    $pending = ['неизвестно'];
    $adminExists = false;
    try {
        Db::pdo();
        $dbReady = true;
        $pending = (new Migrator(APP_ROOT . '/db/migrations'))->pending();
        if ($pending === []) {
            $adminExists = (int)Db::value('SELECT COUNT(*) FROM admins') > 0;
        }
    } catch (Throwable) {
        // база ещё не настроена — это нормальное состояние до установки
    }
};

if ($envExists) {
    $state();
}

// Как только .env создан, установщик открывается только с ключом из него.
// Иначе в окне между шагами кто угодно мог бы перезаписать .env или первым
// создать администратора.
$givenKey = (string)($_GET['key'] ?? $_POST['key'] ?? '');
if ($envExists) {
    $expected = (string)(Env::get('INSTALL_KEY', '') ?? '');
    if ($expected === '' || !hash_equals($expected, $givenKey)) {
        http_response_code(403);
        $hint = $expected === ''
            ? 'В файле .env нет значения INSTALL_KEY — добавьте туда строку <code>INSTALL_KEY=</code> с любой длинной случайной строкой.'
            : 'Откройте страницу с параметром <code>?key=</code> и значением INSTALL_KEY из файла .env '
              . '(файловый менеджер → каталог сайта → .env).';
        exit('<!doctype html><meta charset="utf-8"><div style="font:15px/1.6 sans-serif;max-width:620px;margin:40px auto;padding:0 16px">'
            . '<h2>Установка защищена ключом</h2><p>' . $hint . '</p>'
            . ($adminExists ? '<p><a href="' . htmlspecialchars(Url::to('/'), ENT_QUOTES) . '">Перейти в панель</a></p>' : '')
            . '</div>');
    }
}

// ── действия ────────────────────────────────────────────────────────────────
$action = (string)($_POST['action'] ?? '');

if ($action === 'env' && !$envExists) {
    $values = [
        'DB_HOST'     => trim((string)($_POST['db_host'] ?? 'localhost')),
        'DB_PORT'     => trim((string)($_POST['db_port'] ?? '3306')),
        'DB_NAME'     => trim((string)($_POST['db_name'] ?? '')),
        'DB_USER'     => trim((string)($_POST['db_user'] ?? '')),
        'DB_PASS'     => (string)($_POST['db_pass'] ?? ''),
        'TG_API_ID'   => trim((string)($_POST['tg_api_id'] ?? '')),
        'TG_API_HASH' => trim((string)($_POST['tg_api_hash'] ?? '')),
        'TIMEZONE'    => trim((string)($_POST['timezone'] ?? 'Asia/Tashkent')),
        'APP_URL'     => rtrim((string)($_POST['app_url'] ?? ''), '/'),
    ];

    if ($values['DB_NAME'] === '' || $values['DB_USER'] === '') {
        $errors[] = 'Укажите имя базы и пользователя.';
    } else {
        try {
            new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $values['DB_HOST'], (int)$values['DB_PORT'], $values['DB_NAME']),
                $values['DB_USER'], $values['DB_PASS'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
        } catch (Throwable $e) {
            $errors[] = 'Не удалось подключиться к базе: ' . $e->getMessage();
        }
    }

    if ($errors === []) {
        $content = buildEnv($values);
        if (@file_put_contents($envPath, $content) !== false) {
            @chmod($envPath, 0600);
            Env::reload($envPath);
            $key = (string)(Env::get('INSTALL_KEY', '') ?? '');
            header('Location: ' . Url::to('/install.php') . '?key=' . rawurlencode($key));
            exit;
        } else {
            $envDraft = $content;
            $errors[] = 'Каталог недоступен для записи — создайте файл .env вручную, текст ниже.';
        }
    }
}

if ($action === 'migrate' && $envExists) {
    try {
        $applied = (new Migrator(APP_ROOT . '/db/migrations'))->run();
        $messages[] = $applied === []
            ? 'Новых миграций нет — схема актуальна.'
            : 'Применены миграции: ' . implode(', ', $applied);
        $state();
    } catch (Throwable $e) {
        $errors[] = 'Ошибка миграций: ' . $e->getMessage();
    }
}

if ($action === 'admin' && $envExists && $pending === []) {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || strlen($password) < 10) {
        $errors[] = 'Логин обязателен, пароль — не короче 10 символов.';
    } elseif ((int)Db::value('SELECT COUNT(*) FROM admins') > 0) {
        $errors[] = 'Администратор уже создан.';
    } else {
        Db::insert('admins', [
            'username' => $username, 'password_hash' => Auth::hash($password),
            'role' => 'owner', 'is_active' => 1, 'created_at' => Db::now(),
        ]);
        $messages[] = 'Администратор создан.';
        $adminExists = true;
    }
}

// ── страница ────────────────────────────────────────────────────────────────
$step = match (true) {
    !$envExists            => 1,
    $pending !== []        => 2,
    !$adminExists          => 3,
    default                => 4,
};

function buildEnv(array $values): string
{
    $random = static fn(): string => bin2hex(random_bytes(32));
    $lines = [
        '# Создан установщиком ' . gmdate('Y-m-d H:i') . ' UTC',
        'APP_ENV=production',
        'APP_URL=' . $values['APP_URL'],
        'APP_KEY=' . $random(),
        'TIMEZONE=' . ($values['TIMEZONE'] !== '' ? $values['TIMEZONE'] : 'Asia/Tashkent'),
        '',
        'DB_HOST=' . $values['DB_HOST'],
        'DB_PORT=' . ((int)$values['DB_PORT'] ?: 3306),
        'DB_NAME=' . $values['DB_NAME'],
        'DB_USER=' . $values['DB_USER'],
        'DB_PASS=' . $values['DB_PASS'],
        '',
        'TG_API_ID=' . $values['TG_API_ID'],
        'TG_API_HASH=' . $values['TG_API_HASH'],
        '',
        'CRON_KEY=' . $random(),
        'INSTALL_KEY=' . $random(),
        '',
        'LOG_LEVEL=info',
        'LOG_RETENTION_DAYS=30',
    ];
    return implode("\n", $lines) . "\n";
}

$checks = [
    'PHP 8.2+'          => version_compare(PHP_VERSION, '8.2', '>=') ? PHP_VERSION : 'нужна 8.2+, сейчас ' . PHP_VERSION,
    'Расширение gmp'    => extension_loaded('gmp') ? 'есть' : 'нет — Telegram работать не будет',
    'Расширение pdo_mysql' => extension_loaded('pdo_mysql') ? 'есть' : 'нет',
    'Запись в storage/' => is_writable(APP_ROOT . '/storage') ? 'да' : 'нет — поставьте права 755',
    'Запись рядом с app/' => is_writable(APP_ROOT) ? 'да' : 'нет — .env придётся создать вручную',
];
$cronLine = '/usr/bin/php ' . APP_ROOT . '/bin/cron.php';
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Установка репостера</title>
<link rel="stylesheet" href="<?= e(Url::to('/assets/app.css')) ?>">
<style>.steps{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap}
.steps span{padding:4px 12px;border-radius:999px;background:var(--muted-bg);color:var(--muted);font-size:13px}
.steps span.now{background:var(--accent);color:var(--accent-text);font-weight:600}
.steps span.done{background:var(--ok-bg);color:var(--ok)}</style>
</head><body><div class="wrap" style="max-width:720px;margin:0 auto;padding:28px 16px 60px">
<h1 style="font-size:21px">Установка репостера</h1>

<div class="steps">
  <?php foreach ([1 => 'Подключение', 2 => 'Таблицы', 3 => 'Администратор', 4 => 'Готово'] as $number => $label): ?>
    <span class="<?= $step === $number ? 'now' : ($step > $number ? 'done' : '') ?>"><?= $number ?>. <?= e($label) ?></span>
  <?php endforeach; ?>
</div>

<?php foreach ($messages as $message): ?><div class="flash ok"><?= e($message) ?></div><?php endforeach; ?>
<?php foreach ($errors as $error): ?><div class="flash err"><?= e($error) ?></div><?php endforeach; ?>

<div class="card">
  <h2>Окружение</h2>
  <table>
    <?php foreach ($checks as $name => $value): ?>
      <tr><td><?= e($name) ?></td><td class="muted"><?= e($value) ?></td></tr>
    <?php endforeach; ?>
    <tr><td>Каталог проекта</td><td class="muted"><?= e(APP_ROOT) ?></td></tr>
  </table>
</div>

<?php if ($step === 1): ?>
  <div class="card">
    <h2>1. Подключение к базе и Telegram</h2>
    <p class="hint muted">Базу создайте в панели хостинга (Databases → MySQL). Ключи APP_KEY, CRON_KEY
       и INSTALL_KEY установщик сгенерирует сам.</p>
    <form method="post">
      <input type="hidden" name="action" value="env">
      <div class="row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 16px">
        <div><label for="db_host">Хост базы</label><input id="db_host" name="db_host" value="localhost"></div>
        <div><label for="db_port">Порт</label><input id="db_port" name="db_port" value="3306"></div>
        <div><label for="db_name">Имя базы</label><input id="db_name" name="db_name" required></div>
        <div><label for="db_user">Пользователь</label><input id="db_user" name="db_user" required></div>
        <div><label for="db_pass">Пароль базы</label><input id="db_pass" name="db_pass" type="password"></div>
        <div><label for="timezone">Часовой пояс</label><input id="timezone" name="timezone" value="Asia/Tashkent"></div>
        <div><label for="tg_api_id">TG_API_ID</label><input id="tg_api_id" name="tg_api_id" placeholder="с my.telegram.org"></div>
        <div><label for="tg_api_hash">TG_API_HASH</label><input id="tg_api_hash" name="tg_api_hash"></div>
      </div>
      <label for="app_url">Адрес панели</label>
      <input id="app_url" name="app_url" value="<?= e((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . Url::base()) ?>">
      <small>Ключи Telegram можно оставить пустыми и вписать позже в .env — но без них аккаунт не подключить.</small>
      <div class="actions"><button type="submit">Проверить и сохранить</button></div>
    </form>
    <?php if ($envDraft !== null): ?>
      <p style="margin-top:16px"><b>Создайте файл <code>.env</code> в <?= e(APP_ROOT) ?> с таким содержимым:</b></p>
      <pre><?= e($envDraft) ?></pre>
      <?php preg_match('/^INSTALL_KEY=(.+)$/m', $envDraft, $draftKey); ?>
      <p>После этого продолжите установку по ссылке:
        <a href="<?= e(Url::to('/install.php') . '?key=' . rawurlencode($draftKey[1] ?? '')) ?>">продолжить →</a></p>
    <?php endif; ?>
  </div>
<?php elseif ($step === 2): ?>
  <div class="card">
    <h2>2. Таблицы базы данных</h2>
    <p class="muted">Не применено миграций: <?= count($pending) ?> (<?= e(implode(', ', $pending)) ?>).</p>
    <form method="post">
      <input type="hidden" name="action" value="migrate">
      <input type="hidden" name="key" value="<?= e($givenKey) ?>">
      <div class="actions"><button type="submit">Создать таблицы</button></div>
    </form>
  </div>
<?php elseif ($step === 3): ?>
  <div class="card">
    <h2>3. Администратор панели</h2>
    <form method="post">
      <input type="hidden" name="action" value="admin">
      <input type="hidden" name="key" value="<?= e($givenKey) ?>">
      <label for="username">Логин</label>
      <input id="username" name="username" required autocomplete="username">
      <label for="password">Пароль (минимум 10 символов)</label>
      <input id="password" name="password" type="password" minlength="10" required autocomplete="new-password">
      <div class="actions"><button type="submit">Создать</button></div>
    </form>
  </div>
<?php else: ?>
  <div class="card">
    <h2>4. Установка завершена</h2>
    <p><a href="<?= e(Url::to('/')) ?>">Войти в панель →</a></p>
    <p style="margin-top:16px"><b>Добавьте задание cron</b> (hPanel → Advanced → Cron Jobs, каждую минуту):</p>
    <pre><?= e($cronLine) ?></pre>
    <p class="muted">Если интервал в минуту недоступен, поставьте 5 минут. Если cron умеет только открывать
       адреса, используйте <code><?= e((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . Url::base()) ?>/cron.php?key=ЗНАЧЕНИЕ_CRON_KEY</code>.</p>
    <p style="margin-top:16px"><b>Закройте установку:</b> удалите <code>install.php</code> или сотрите значение
       <code>INSTALL_KEY</code> в файле .env. Пока ключ есть, установщик открывается только по нему.</p>
  </div>
<?php endif; ?>

<p class="muted" style="text-align:center">Дальше — «Аккаунты» в панели: подключение Telegram по номеру и коду.</p>
</div></body></html>
