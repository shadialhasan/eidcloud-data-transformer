<?php

declare(strict_types=1);

namespace EidCloud\DataTransformer;

/**
 * Lightweight PSR-4 autoloader for EidCloud\DataTransformer.
 * Enables zero-dependency standalone execution without composer.
 */
class Autoloader
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        spl_autoload_register(function (string $class): void {
            $prefix = 'EidCloud\\DataTransformer\\';
            $baseDir = __DIR__ . '/';

            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

            if (file_exists($file)) {
                require_once $file;
            }
        });

        self::$registered = true;
    }
}

// Auto-register upon inclusion
Autoloader::register();
