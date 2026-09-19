<?php

/**
 * Находит каталог с кодом репостера, где бы он ни лежал относительно этого файла.
 *
 * Поддерживаются обе раскладки:
 *   public_html/panel/index.php + domains/ДОМЕН/reposter/…   (код вне public_html)
 *   public_html/bot/index.php   + public_html/bot/app/…      (всё в одной папке)
 */

declare(strict_types=1);

function reposter_locate(): ?string
{
    $override = __DIR__ . '/reposter-path.php';
    if (is_file($override)) {
        $path = require $override;                       // вернуть строку с путём
        if (is_string($path) && is_file(rtrim($path, '/') . '/app/bootstrap.php')) {
            return rtrim($path, '/');
        }
    }

    $directory = __DIR__;
    for ($level = 0; $level <= 6; $level++) {
        foreach (['', '/reposter', '/bot', '/repost', '/app-reposter'] as $suffix) {
            $candidate = $directory . $suffix;
            if (is_file($candidate . '/app/bootstrap.php') && is_file($candidate . '/public_html/index.php')) {
                return $candidate;
            }
        }
        $parent = dirname($directory);
        if ($parent === $directory) {
            break;
        }
        $directory = $parent;
    }
    return null;
}

function reposter_fail(string $entry): never
{
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $checked = [];
    $directory = __DIR__;
    for ($level = 0; $level <= 6; $level++) {
        $checked[] = $directory . '/reposter';
        $checked[] = $directory;
        $parent = dirname($directory);
        if ($parent === $directory) {
            break;
        }
        $directory = $parent;
    }
    echo '<h2>Не найден код репостера</h2>';
    echo '<p>Этот файл лежит в: <code>' . htmlspecialchars(__DIR__, ENT_QUOTES) . '</code></p>';
    echo '<p>Искал каталог, в котором есть <code>app/bootstrap.php</code> и <code>public_html/'
        . htmlspecialchars($entry, ENT_QUOTES) . '</code>, здесь:</p><ul>';
    foreach (array_unique($checked) as $path) {
        echo '<li><code>' . htmlspecialchars($path, ENT_QUOTES) . '</code></li>';
    }
    echo '</ul><p>Если код лежит в другом месте, создайте рядом с этим файлом '
        . '<code>reposter-path.php</code> с содержимым:<br>'
        . '<code>&lt;?php return \'/home/ПОЛЬЗОВАТЕЛЬ/domains/ДОМЕН/reposter\';</code></p>';
    exit;
}

function reposter_run(string $entry): void
{
    $base = reposter_locate();
    if ($base === null) {
        reposter_fail($entry);
    }
    require $base . '/public_html/' . $entry;
}
