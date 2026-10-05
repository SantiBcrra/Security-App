<?php
declare(strict_types=1);

namespace App\Core;

/** Vistas PHP planas en app/Views. Escapar SIEMPRE con e(). */
final class View
{
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/main'): string
    {
        $content = self::renderFile($view, $data);
        if ($layout === null) {
            return $content;
        }
        return self::renderFile($layout, $data + ['content' => $content]);
    }

    private static function renderFile(string $view, array $data): string
    {
        $file = BASE_PATH . '/app/Views/' . $view . '.php';
        if (!preg_match('#^[a-zA-Z0-9_/\-]+$#', $view) || !is_file($file)) {
            throw new \RuntimeException("Vista no encontrada: {$view}");
        }
        ob_start();
        try {
            (static function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($file, $data);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
