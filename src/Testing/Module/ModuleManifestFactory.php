<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Testing\Module;

use RuntimeException;

/**
 * Builds module manifests and module directories for tests.
 *
 * Module authors and host applications can use this helper to stand up a
 * temporary module on disk without hand writing a manifest file.
 */
class ModuleManifestFactory
{
    /**
     * The manifest values.
     *
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    public function __construct(
        protected string $key,
    ) {
        $this->attributes = [
            'schema' => 1,
            'key' => $key,
            'name' => ucfirst($key).' Module',
            'version' => '1.0.0',
            'provider' => 'Modules\\'.ucfirst($key).'\\'.ucfirst($key).'ServiceProvider',
            'autoload' => [
                'psr-4' => [
                    'Modules\\'.ucfirst($key).'\\' => 'src/',
                ],
            ],
        ];
    }

    /**
     * Create a manifest factory for a module key.
     */
    public static function for(string $key): self
    {
        return new self($key);
    }

    /**
     * Merge values into the manifest.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function with(array $attributes): self
    {
        $this->attributes = array_merge($this->attributes, $attributes);

        return $this;
    }

    /**
     * The manifest as a plain array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * Create the module directory with its manifest file.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(string $basePath, array $attributes = []): string
    {
        $path = rtrim($basePath, '/\\').'/'.$this->key;

        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException(sprintf('Unable to create the module directory [%s].', $path));
        }

        file_put_contents(
            $path.'/module.json',
            json_encode($this->with($attributes)->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        $namespace = 'Modules\\'.ucfirst($this->key);
        $class = ucfirst($this->key).'ServiceProvider';
        $source = $path.'/src';

        if (! is_dir($source) && ! mkdir($source, 0755, true) && ! is_dir($source)) {
            throw new RuntimeException(sprintf('Unable to create the module source directory [%s].', $source));
        }

        file_put_contents($source.'/'.$class.'.php', sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace %s;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nclass %s extends ServiceProvider {}\n",
            $namespace,
            $class,
        ));

        return $path;
    }
}
