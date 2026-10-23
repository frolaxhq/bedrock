<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Frontend;

use Frolax\Bedrock\Contracts\Frontend\FrontendAssetManager;
use Frolax\Bedrock\DataObjects\Frontend\FrontendModule;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\Frontend\InvalidFrontendArtifactException;
use Frolax\Bedrock\Support\Frontend\FrontendPath;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Throwable;

class FrontendArtifactManager implements FrontendAssetManager
{
    public function __construct(
        protected Application $app,
        protected Filesystem $files,
    ) {}

    public function failures(Manifest $manifest, string $modulePath, bool $published = false): array
    {
        if (! $this->participates($manifest)) {
            return [];
        }

        try {
            $artifact = $this->artifact($manifest, $modulePath);
            $this->validateFiles($manifest, dirname($this->artifactPath($manifest, $modulePath)), $artifact, $published);

            return [];
        } catch (Throwable $exception) {
            return [$exception->getMessage()];
        }
    }

    public function ensureValid(Manifest $manifest, string $modulePath, bool $published = false): void
    {
        $failure = $this->failures($manifest, $modulePath, $published)[0] ?? null;

        if ($failure !== null) {
            throw InvalidFrontendArtifactException::for($manifest->key, $failure);
        }
    }

    public function publish(Manifest $manifest, string $modulePath): void
    {
        if (! $this->participates($manifest)) {
            return;
        }

        $this->ensureValid($manifest, $modulePath);
        $artifact = $this->artifact($manifest, $modulePath);
        $source = dirname($this->artifactPath($manifest, $modulePath));
        $client = FrontendPath::client($this->app, $manifest->key, $manifest->version);
        $server = FrontendPath::server($this->app, $manifest->key, $manifest->version);
        $clientTemporary = dirname($client).'/.'.basename($client).'.incoming.'.bin2hex(random_bytes(8));
        $serverTemporary = dirname($server).'/.'.basename($server).'.incoming.'.bin2hex(random_bytes(8));

        $this->files->ensureDirectoryExists($clientTemporary);
        $this->files->ensureDirectoryExists($serverTemporary);

        try {
            foreach ($artifact['files'] as $relative => $hash) {
                $targetRoot = str_starts_with($relative, 'client/') ? $clientTemporary : $serverTemporary;
                $targetRelative = preg_replace('/^(?:client|server)\//', '', $relative) ?? $relative;
                $target = $targetRoot.'/'.$targetRelative;
                $this->files->ensureDirectoryExists(dirname($target));

                if (! $this->files->copy($source.'/'.$relative, $target)) {
                    throw InvalidFrontendArtifactException::for($manifest->key, sprintf('Unable to publish [%s].', $relative));
                }
            }

            $this->files->put($serverTemporary.'/frontend.json', json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
            $this->replaceAll([
                $clientTemporary => $client,
                $serverTemporary => $server,
            ]);
        } catch (Throwable $exception) {
            $this->files->deleteDirectory($clientTemporary);
            $this->files->deleteDirectory($serverTemporary);

            throw $exception;
        }
    }

    public function remove(string $key, string $version): void
    {
        if (preg_match(Manifest::KEY_PATTERN, $key) !== 1 || preg_match(Manifest::VERSION_PATTERN, $version) !== 1) {
            throw InvalidFrontendArtifactException::for($key, 'The managed asset identity is invalid.');
        }

        foreach ([FrontendPath::client($this->app, $key, $version), FrontendPath::server($this->app, $key, $version)] as $path) {
            if ($this->files->isDirectory($path) && ! $this->files->deleteDirectory($path)) {
                throw InvalidFrontendArtifactException::for($key, sprintf('Unable to remove managed assets [%s].', $path));
            }

            $parent = dirname($path);

            if ($this->files->isDirectory($parent) && $this->files->files($parent) === [] && $this->files->directories($parent) === []) {
                @rmdir($parent);
            }
        }
    }

    public function descriptor(Manifest $manifest): ?FrontendModule
    {
        if (! $this->participates($manifest)) {
            return null;
        }

        $development = $this->developmentDescriptor($manifest);

        if ($development !== null) {
            return $development;
        }

        $published = FrontendPath::server($this->app, $manifest->key, $manifest->version).'/frontend.json';

        if (! $this->files->isFile($published)) {
            return null;
        }

        try {
            $artifact = json_decode($this->files->get($published), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($artifact)) {
            return null;
        }

        $client = $artifact['client']['manifest'] ?? null;
        $server = $artifact['server']['entry'] ?? null;
        $css = $artifact['client']['css'] ?? [];
        $navigation = $artifact['navigation'] ?? null;

        if (! is_string($client) || ! is_string($server) || ! is_array($css) || ! is_array($navigation)) {
            return null;
        }

        try {
            $navigation = $this->validateNavigation($manifest, $navigation);
        } catch (InvalidFrontendArtifactException) {
            return null;
        }

        $url = rtrim((string) $this->app->make('config')->get('bedrock.frontend.client.url', '/vendor/bedrock/modules'), '/');
        $prefix = $url.'/'.$manifest->key.'/'.$manifest->version.'/';
        $client = $prefix.$this->stripArea($client);
        $server = FrontendPath::server($this->app, $manifest->key, $manifest->version).'/'.$this->stripArea($server);
        $styles = array_values(array_map(fn (mixed $path): string => $prefix.$this->stripArea((string) $path), $css));

        return new FrontendModule(
            $manifest->key,
            $manifest->version,
            (string) $manifest->frontend['driver'],
            $client,
            $server,
            $styles,
            (array) ($manifest->frontend['requires'] ?? []),
            $navigation,
        );
    }

    public function navigation(Manifest $manifest, string $modulePath): array
    {
        if (! $this->participates($manifest)) {
            return ['groups' => [], 'items' => []];
        }

        return $this->artifact($manifest, $modulePath)['navigation'];
    }

    protected function participates(Manifest $manifest): bool
    {
        return isset($manifest->frontend['driver']);
    }

    /** @return array{schema: int, module: string, version: string, client: array{manifest: string, css: list<string>}, server: array{entry: string}, navigation: array{groups: list<array<string, mixed>>, items: list<array<string, mixed>>}, files: array<string, string>} */
    protected function artifact(Manifest $manifest, string $modulePath): array
    {
        $path = $this->artifactPath($manifest, $modulePath);

        if (! $this->files->isFile($path)) {
            throw InvalidFrontendArtifactException::for($manifest->key, sprintf('Descriptor [%s] does not exist.', $path));
        }

        try {
            $artifact = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidFrontendArtifactException::for($manifest->key, $exception->getMessage());
        }

        if (! is_array($artifact)
            || ($artifact['schema'] ?? null) !== 1
            || ($artifact['module'] ?? null) !== $manifest->key
            || ($artifact['version'] ?? null) !== $manifest->version
            || ! is_array($artifact['client'] ?? null)
            || ! is_array($artifact['server'] ?? null)
            || ! is_array($artifact['navigation'] ?? null)
            || ! is_array($artifact['files'] ?? null)
        ) {
            throw InvalidFrontendArtifactException::for($manifest->key, 'The descriptor identity or shape is invalid.');
        }

        $client = $artifact['client']['manifest'] ?? null;
        $server = $artifact['server']['entry'] ?? null;
        $css = $artifact['client']['css'] ?? [];

        if (! is_string($client) || ! str_starts_with($client, 'client/') || ! $this->safe($client)
            || ! is_string($server) || ! str_starts_with($server, 'server/') || ! $this->safe($server)
            || ! is_array($css) || ! array_is_list($css)
        ) {
            throw InvalidFrontendArtifactException::for($manifest->key, 'The client or server entry is invalid.');
        }

        foreach ($css as $stylesheet) {
            if (! is_string($stylesheet) || ! str_starts_with($stylesheet, 'client/') || ! $this->safe($stylesheet)) {
                throw InvalidFrontendArtifactException::for($manifest->key, 'A stylesheet path is invalid.');
            }
        }

        $files = $artifact['files'];
        $artifact['navigation'] = $this->validateNavigation($manifest, $artifact['navigation']);

        foreach ($files as $file => $hash) {
            if (! is_string($file) || ! $this->safe($file)
                || (! str_starts_with($file, 'client/') && ! str_starts_with($file, 'server/'))
                || ! is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1
            ) {
                throw InvalidFrontendArtifactException::for($manifest->key, sprintf('The file declaration [%s] is invalid.', (string) $file));
            }
        }

        foreach ([$client, $server, ...$css] as $required) {
            if (! isset($files[$required])) {
                throw InvalidFrontendArtifactException::for($manifest->key, sprintf('Required artifact [%s] has no checksum.', $required));
            }
        }

        /** @var array{schema: int, module: string, version: string, client: array{manifest: string, css: list<string>}, server: array{entry: string}, navigation: array{groups: list<array<string, mixed>>, items: list<array<string, mixed>>}, files: array<string, string>} $artifact */
        return $artifact;
    }

    /**
     * @param  array{files: array<string, string>}  $artifact
     */
    protected function validateFiles(Manifest $manifest, string $root, array $artifact, bool $published): void
    {
        foreach ($artifact['files'] as $relative => $expected) {
            $path = $root.'/'.$relative;

            if ($published) {
                $area = str_starts_with($relative, 'client/')
                    ? FrontendPath::client($this->app, $manifest->key, $manifest->version)
                    : FrontendPath::server($this->app, $manifest->key, $manifest->version);
                $path = $area.'/'.$this->stripArea($relative);
            }

            if (! $this->files->isFile($path) || is_link($path)) {
                throw InvalidFrontendArtifactException::for($manifest->key, sprintf('Artifact file [%s] is missing.', $relative));
            }

            if (! hash_equals($expected, hash_file('sha256', $path) ?: '')) {
                throw InvalidFrontendArtifactException::for($manifest->key, sprintf('Artifact file [%s] failed its SHA-256 check.', $relative));
            }
        }
    }

    protected function artifactPath(Manifest $manifest, string $modulePath): string
    {
        $relative = $manifest->frontend['artifact'] ?? null;

        if (! is_string($relative) || ! $this->safe($relative)) {
            throw InvalidFrontendArtifactException::for($manifest->key, 'The frontend artifact path is missing or unsafe.');
        }

        return rtrim($modulePath, '/\\').'/'.$relative;
    }

    /** @param array<string, string> $directories */
    protected function replaceAll(array $directories): void
    {
        $token = bin2hex(random_bytes(8));
        $backups = [];
        $replaced = [];

        try {
            foreach ($directories as $temporary => $target) {
                $this->files->ensureDirectoryExists(dirname($target));

                if ($this->files->isDirectory($target)) {
                    $backups[$target] = dirname($target).'/.'.basename($target).'.previous.'.$token;

                    if (! @rename($target, $backups[$target])) {
                        throw new InvalidFrontendArtifactException(sprintf('Unable to stage managed frontend directory [%s].', $target));
                    }
                }

                if (! @rename($temporary, $target)) {
                    throw new InvalidFrontendArtifactException(sprintf('Unable to publish managed frontend directory [%s].', $target));
                }

                $replaced[] = $target;
            }
        } catch (Throwable $exception) {
            foreach (array_reverse($replaced) as $target) {
                $this->files->deleteDirectory($target);

                if (isset($backups[$target])) {
                    @rename($backups[$target], $target);
                    unset($backups[$target]);
                }
            }

            foreach ($backups as $target => $backup) {
                if (! $this->files->isDirectory($target)) {
                    @rename($backup, $target);
                }
            }

            throw $exception;
        }

        foreach ($backups as $backup) {
            $this->files->deleteDirectory($backup);
        }
    }

    protected function safe(string $path): bool
    {
        $path = str_replace('\\', '/', trim($path));

        return $path !== ''
            && ! str_starts_with($path, '/')
            && preg_match('/^[A-Za-z]:/', $path) !== 1
            && ! in_array('..', explode('/', $path), true);
    }

    protected function stripArea(string $path): string
    {
        return preg_replace('/^(?:client|server)\//', '', str_replace('\\', '/', $path)) ?? $path;
    }

    /**
     * @param  array<string, mixed>  $navigation
     * @return array{groups: list<array<string, mixed>>, items: list<array<string, mixed>>}
     */
    protected function validateNavigation(Manifest $manifest, array $navigation): array
    {
        if (! is_array($navigation['groups'] ?? null)
            || ! array_is_list($navigation['groups'])
            || ! is_array($navigation['items'] ?? null)
            || ! array_is_list($navigation['items'])
        ) {
            throw InvalidFrontendArtifactException::for($manifest->key, 'The static navigation descriptor is invalid.');
        }

        $groups = [];
        $validatedGroups = [];

        foreach ($navigation['groups'] as $group) {
            if (! is_array($group)
                || ! $this->navigationKey($group['key'] ?? null)
                || ! is_string($group['label'] ?? null)
                || trim($group['label']) === ''
                || ! is_int($group['order'] ?? null)
                || isset($groups[$group['key']])
            ) {
                throw InvalidFrontendArtifactException::for($manifest->key, 'A static navigation group is invalid.');
            }

            $groups[$group['key']] = true;
            $validatedGroups[] = $group;
        }

        $keys = [];
        $items = [];

        foreach ($navigation['items'] as $item) {
            [$items[], $keys] = $this->validateNavigationItem($manifest, $item, $groups, $keys, false);
        }

        return ['groups' => $validatedGroups, 'items' => $items];
    }

    /**
     * @param  array<string, bool>  $groups
     * @param  array<string, bool>  $keys
     * @return array{array<string, mixed>, array<string, bool>}
     */
    protected function validateNavigationItem(Manifest $manifest, mixed $item, array $groups, array $keys, bool $nested): array
    {
        if (! is_array($item)
            || ! $this->navigationKey($item['key'] ?? null)
            || isset($keys[$item['key']])
            || ! in_array($item['kind'] ?? null, ['link', 'action'], true)
            || ! is_string($item['label'] ?? null)
            || trim($item['label']) === ''
            || ! is_int($item['order'] ?? null)
            || ! is_array($item['panels'] ?? null)
            || ! array_is_list($item['panels'])
            || ! is_array($item['active'] ?? null)
            || ! array_is_list($item['active'])
            || ! is_array($item['children'] ?? null)
            || ! array_is_list($item['children'])
        ) {
            throw InvalidFrontendArtifactException::for($manifest->key, 'A static navigation item is invalid.');
        }

        $keys[$item['key']] = true;

        if (($item['kind'] === 'link' && (! is_string($item['route'] ?? null) || trim($item['route']) === ''))
            || ($item['kind'] === 'action' && (isset($item['route']) || isset($item['href'])))
            || (isset($item['group']) && (! is_string($item['group']) || ! isset($groups[$item['group']])))
            || (isset($item['permission']) && ! $this->navigationKey($item['permission']))
            || (isset($item['parent']) && (! is_string($item['parent']) || ! $this->navigationKey($item['parent'])))
        ) {
            throw InvalidFrontendArtifactException::for($manifest->key, sprintf('Static navigation item [%s] contains unsafe security metadata.', $item['key']));
        }

        foreach ([...$item['panels'], ...$item['active']] as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw InvalidFrontendArtifactException::for($manifest->key, sprintf('Static navigation item [%s] contains an invalid filter.', $item['key']));
            }
        }

        if ($nested && $item['children'] !== []) {
            throw InvalidFrontendArtifactException::for($manifest->key, 'Static navigation supports only one child level.');
        }

        $children = [];

        foreach ($item['children'] as $child) {
            [$children[], $keys] = $this->validateNavigationItem($manifest, $child, $groups, $keys, true);
        }

        $item['children'] = $children;

        return [$item, $keys];
    }

    protected function navigationKey(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9-]*$/', $value) === 1;
    }

    protected function developmentDescriptor(Manifest $manifest): ?FrontendModule
    {
        $config = $this->app->make('config');

        if (! (bool) $config->get('bedrock.frontend.development.enabled', false) || ! $this->app->environment('local')) {
            return null;
        }

        $path = FrontendPath::developmentRoot($this->app).'/'.$manifest->key.'.json';

        if (! $this->files->isFile($path)) {
            return null;
        }

        try {
            $data = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($data)
            || ($data['key'] ?? null) !== $manifest->key
            || ! is_int($data['expires_at'] ?? null)
            || $data['expires_at'] < time()
            || ! is_string($data['client'] ?? null)
            || ! is_string($data['server'] ?? null)
        ) {
            return null;
        }

        return new FrontendModule(
            $manifest->key,
            $manifest->version,
            (string) $manifest->frontend['driver'],
            $data['client'],
            $data['server'],
            array_values(array_filter((array) ($data['css'] ?? []), 'is_string')),
            (array) ($manifest->frontend['requires'] ?? []),
            $this->publishedNavigation($manifest),
            true,
        );
    }

    /** @return array{groups: list<array<string, mixed>>, items: list<array<string, mixed>>} */
    protected function publishedNavigation(Manifest $manifest): array
    {
        $path = FrontendPath::server($this->app, $manifest->key, $manifest->version).'/frontend.json';

        if (! $this->files->isFile($path)) {
            return ['groups' => [], 'items' => []];
        }

        try {
            $artifact = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['groups' => [], 'items' => []];
        }

        $navigation = is_array($artifact) ? ($artifact['navigation'] ?? null) : null;

        if (! is_array($navigation)) {
            return ['groups' => [], 'items' => []];
        }

        try {
            return $this->validateNavigation($manifest, $navigation);
        } catch (InvalidFrontendArtifactException) {
            return ['groups' => [], 'items' => []];
        }
    }
}
