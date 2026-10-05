<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Frontend;

use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Illuminate\Filesystem\Filesystem;

class FrontendOverrideCatalog
{
    /** @var array<string, array{id: string, source: string}>|null */
    protected ?array $entries = null;

    public function __construct(
        protected Filesystem $files,
        protected string $packageRoot,
        protected string $basePath,
    ) {}

    /** @return list<string> */
    public function all(): array
    {
        return array_keys($this->entries());
    }

    public function normalize(string $target): string
    {
        $normalized = strtolower(str_replace('\\', '/', trim($target, " /\\\t\n\r\0\x0B")));

        if (! str_contains($normalized, '/')) {
            $normalized = 'components/ui/'.$normalized;
        }

        if (! isset($this->entries()[$normalized])) {
            throw new InvalidModuleSourceException(sprintf('Frontend override [%s] is not in the Bedrock catalog.', $target));
        }

        return $normalized;
    }

    public function seed(string $target): string
    {
        $id = $this->normalize($target);
        $entry = $this->entries()[$id];
        $source = $this->packageRoot.'/packages/frontend/'.$entry['source'];
        $destination = $this->basePath.'/resources/js/'.$id.'.tsx';

        if (str_ends_with($entry['source'], '.ts')) {
            $destination = substr($destination, 0, -1);
        }

        if ($this->files->exists($destination)) {
            throw new InvalidModuleSourceException(sprintf('Frontend override [%s] already exists.', $id));
        }

        if (! $this->files->isFile($source)) {
            throw new InvalidModuleSourceException(sprintf('The package source for frontend override [%s] is unavailable.', $id));
        }

        $contents = str_replace(
            [
                '@bedrock/components/',
                '@bedrock/hooks/',
                '@bedrock/lib/utils',
                '@bedrock/lib',
                '@bedrock/panel',
                '@bedrock/react',
                'from "cn"',
                "from 'cn'",
            ],
            [
                '@frolax/bedrock/components/',
                '@frolax/bedrock/hooks/',
                '@frolax/bedrock/lib',
                '@frolax/bedrock/lib',
                '@frolax/bedrock/ui',
                '@frolax/bedrock/react',
                'from "@frolax/bedrock/lib"',
                "from '@frolax/bedrock/lib'",
            ],
            $this->files->get($source),
        );

        $this->files->ensureDirectoryExists(dirname($destination));
        $this->files->put($destination, $contents);

        return $id;
    }

    /** @return array<string, array{id: string, source: string}> */
    protected function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $catalog = json_decode(
            $this->files->get($this->packageRoot.'/packages/frontend/catalog.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $entries = [];

        foreach ((array) ($catalog['entries'] ?? []) as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null) || ! is_string($entry['source'] ?? null)) {
                continue;
            }

            $entries[$entry['id']] = ['id' => $entry['id'], 'source' => $entry['source']];
        }

        return $this->entries = $entries;
    }
}
