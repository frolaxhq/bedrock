<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Support\Frontend;

use Illuminate\Contracts\Foundation\Application;

final class FrontendPath
{
    public static function clientRoot(Application $app): string
    {
        return self::absolute($app, $app->make('config')->get('bedrock.frontend.client.path'), 'public/vendor/bedrock/modules');
    }

    public static function serverRoot(Application $app): string
    {
        return self::absolute($app, $app->make('config')->get('bedrock.frontend.server.path'), 'storage/framework/bedrock/frontend');
    }

    public static function developmentRoot(Application $app): string
    {
        return self::absolute($app, $app->make('config')->get('bedrock.frontend.development.path'), 'storage/framework/bedrock/frontend-dev');
    }

    public static function client(Application $app, string $key, string $version): string
    {
        return self::clientRoot($app).'/'.$key.'/'.$version;
    }

    public static function server(Application $app, string $key, string $version): string
    {
        return self::serverRoot($app).'/'.$key.'/'.$version;
    }

    private static function absolute(Application $app, mixed $path, string $default): string
    {
        $path = is_string($path) && trim($path) !== '' ? $path : $default;

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return rtrim($path, '/\\');
        }

        return rtrim($app->basePath($path), '/\\');
    }
}
