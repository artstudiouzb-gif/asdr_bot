<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $file = dirname(__DIR__) . '/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Шаблон не найден: {$template}");
        }
        $content = self::capture($file, $data + self::$shared);
        if ($layout === null) {
            return $content;
        }
        return self::capture(dirname(__DIR__) . '/Views/' . $layout . '.php',
            ['content' => $content] + $data + self::$shared);
    }

    private static function capture(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();     // недорисованная страница не должна уйти в ответ вместе с ошибкой
            throw $e;
        }
        return (string)ob_get_clean();
    }
}
