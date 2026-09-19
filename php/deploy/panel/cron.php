<?php

/**
 * Загрузчик панели. Лежит в public_html/panel, сам код — рядом с public_html,
 * в каталоге reposter (он недоступен из интернета).
 */

declare(strict_types=1);

$base = dirname(__DIR__, 2) . '/reposter';   // …/domains/ВАШ_ДОМЕН/reposter
if (!is_file($base . '/public_html/cron.php')) {
    http_response_code(500);
    exit('Не найден каталог reposter рядом с public_html. Проверьте, куда распакован архив.');
}
require $base . '/public_html/cron.php';
