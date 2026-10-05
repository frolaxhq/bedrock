<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Support\Module;

use Illuminate\Contracts\Foundation\Application;

/**
 * Resolves the filesystem locations Bedrock uses.
 */
class ModulePath
{
    /**
     * The absolute path to the configured module root.
     */
    public static function modules(Application $app): string
    {
        $path = $app->make('config')->get('bedrock.modules.path', 'modules');

        $path = is_string($path) && $path !== '' ? $path : 'modules';

        return self::absolute($app, $path);
    }

    /**
     * The manifest file name configured for modules.
     */
    public static function manifest(Application $app): string
    {
        $manifest = $app->make('config')->get('bedrock.modules.manifest', 'module.json');

        return is_string($manifest) && $manifest !== '' ? $manifest : 'module.json';
    }

    /**
     * Turn a configured path into an absolute path.
     */
    public static function absolute(Application $app, string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return rtrim($path, '/\\');
        }

        return $app->basePath(trim($path, '/\\'));
    }
}
