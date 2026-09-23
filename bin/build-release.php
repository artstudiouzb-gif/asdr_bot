<?php

/**
 * Сборка релиза: архив для загрузки через файловый менеджер и готовое к деплою
 * дерево (для ветки deploy / git-деплоя на хостинге).
 *
 *   php bin/build-release.php            → deploy/dist и deploy/reposter-release.zip
 *
 * Перед запуском нужен установленный vendor:
 *   composer install --no-dev --prefer-dist
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$dist = $root . '/deploy/dist';
$zipPath = $root . '/deploy/reposter-release.zip';

$vendor = productionVendor($root);

echo "Готовлю дерево релиза…\n";
removeDirectory($dist);
mkdir($dist . '/reposter', 0755, true);
mkdir($dist . '/panel', 0755, true);

foreach (['app', 'bin', 'db', 'public_html'] as $directory) {
    copyTree($root . '/' . $directory, $dist . '/reposter/' . $directory);
}
copyTree($vendor, $dist . '/reposter/vendor');
foreach (['logs', 'tmp', 'telegram'] as $directory) {
    mkdir($dist . '/reposter/storage/' . $directory, 0700, true);
    file_put_contents($dist . '/reposter/storage/' . $directory . '/.gitkeep', '');
}
copy($root . '/.env.example', $dist . '/reposter/.env.example');
copy($root . '/composer.json', $dist . '/reposter/composer.json');
copy($root . '/composer.lock', $dist . '/reposter/composer.lock');
copy($root . '/../docs/DEPLOYMENT.md', $dist . '/DEPLOYMENT.md');

// Каталоги с кодом не должны отдаваться по HTTP, если всё лежит одной папкой в public_html
foreach (['app', 'bin', 'db', 'vendor', 'storage'] as $directory) {
    file_put_contents($dist . '/reposter/' . $directory . '/.htaccess', "Require all denied\n");
}

// Загрузчики: и для раскладки «код вне public_html», и для «всё в одной папке»
$loaders = ['bootstrap-loader.php', 'index.php', 'cron.php', 'install.php'];
foreach ($loaders as $file) {
    copy($root . '/deploy/panel/' . $file, $dist . '/panel/' . $file);
    copy($root . '/deploy/panel/' . $file, $dist . '/reposter/' . $file);
}
copy($root . '/deploy/panel/.htaccess', $dist . '/panel/.htaccess');
copy($root . '/deploy/panel/.htaccess', $dist . '/reposter/.htaccess');

// Те же загрузчики в корне архива: если распаковать его целиком в папку сайта,
// адрес этой папки тоже открывает панель.
foreach ($loaders as $file) {
    copy($root . '/deploy/panel/' . $file, $dist . '/' . $file);
}
copy($root . '/deploy/panel/.htaccess', $dist . '/.htaccess');
mkdir($dist . '/assets', 0755, true);
copy($root . '/public_html/assets/app.css', $dist . '/assets/app.css');
mkdir($dist . '/panel/assets', 0755, true);
mkdir($dist . '/reposter/assets', 0755, true);
copy($root . '/public_html/assets/app.css', $dist . '/panel/assets/app.css');
copy($root . '/public_html/assets/app.css', $dist . '/reposter/assets/app.css');

echo "Собираю архив…\n";
@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Не удалось создать архив\n");
    exit(1);
}
addToZip($zip, $dist, '');
$zip->close();

printf("Готово: %s (%.1f МБ), дерево: %s\n", $zipPath, filesize($zipPath) / 1048576, $dist);

// ── функции ────────────────────────────────────────────────────────────────

/**
 * vendor без dev-пакетов собирается во временном каталоге, чтобы рабочий
 * vendor разработчика (с PHPUnit) оставался нетронутым.
 */
function productionVendor(string $root): string
{
    $composer = trim((string)shell_exec('command -v composer 2>/dev/null'));
    if ($composer === '') {
        if (!is_file($root . '/vendor/autoload.php')) {
            fwrite(STDERR, "Нет ни composer, ни vendor/ — сборка невозможна\n");
            exit(1);
        }
        fwrite(STDERR, "composer не найден — беру текущий vendor/ как есть\n");
        return $root . '/vendor';
    }

    $work = sys_get_temp_dir() . '/reposter-build-' . getmypid();
    removeDirectory($work);
    mkdir($work, 0755, true);
    copy($root . '/composer.json', $work . '/composer.json');
    copy($root . '/composer.lock', $work . '/composer.lock');
    mkdir($work . '/app', 0755, true);      // для classmap PSR-4 App\\ → app/
    copyTree($root . '/app', $work . '/app');

    echo "Собираю зависимости без dev…\n";
    $command = sprintf(
        'cd %s && COMPOSER_ALLOW_SUPERUSER=1 %s install --no-dev --prefer-dist --optimize-autoloader '
        . '--no-interaction --no-progress --quiet 2>&1',
        escapeshellarg($work), escapeshellarg($composer)
    );
    exec($command, $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, "composer install не удался:\n" . implode("\n", $output) . "\n");
        exit(1);
    }
    register_shutdown_function(static fn() => removeDirectory($work));
    return $work . '/vendor';
}

function copyTree(string $from, string $to): void
{
    if (!is_dir($from)) {
        return;
    }
    if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) {
        throw new RuntimeException('Не создать каталог ' . $to);
    }
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $source = $from . '/' . $entry;
        $target = $to . '/' . $entry;
        if (is_dir($source)) {
            // в релизе не нужны ни история git, ни тесты библиотек
            if (in_array($entry, ['.git', 'tests', 'Tests', 'docs', 'examples'], true)) {
                continue;
            }
            copyTree($source, $target);
            continue;
        }
        if (str_ends_with($entry, '.log')) {
            continue;
        }
        copy($source, $target);
    }
}

function removeDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $item = $path . '/' . $entry;
        is_dir($item) ? removeDirectory($item) : unlink($item);
    }
    rmdir($path);
}

function addToZip(ZipArchive $zip, string $directory, string $prefix): void
{
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . '/' . $entry;
        $local = $prefix === '' ? $entry : $prefix . '/' . $entry;
        if (is_dir($path)) {
            $zip->addEmptyDir($local);
            addToZip($zip, $path, $local);
        } else {
            $zip->addFile($path, $local);
        }
    }
}
