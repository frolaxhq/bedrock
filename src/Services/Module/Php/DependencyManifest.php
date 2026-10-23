<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module\Php;

use Composer\Semver\Intervals;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use JsonException;
use UnexpectedValueException;

/** Reads dependency metadata only; never loads module PHP or invokes Composer. */
class DependencyManifest
{
    /**
     * @return array{composer: array<string, mixed>, lock: array<string, mixed>, packages: list<array<string, mixed>>, fingerprint: string}|null
     */
    public function inspect(Manifest $manifest, string $path, bool $requireLock = true): ?array
    {
        $path = rtrim($path, '/\\');

        foreach (['composer.json', 'composer.lock', 'vendor', 'vendor/composer'] as $entry) {
            if (is_link($path.'/'.$entry)) {
                throw new InvalidModuleSourceException(sprintf('Module dependency path [%s] must not be a symlink.', $entry));
            }
        }

        if (! file_exists($path.'/composer.json')) {
            if (file_exists($path.'/composer.lock') || file_exists($path.'/vendor')) {
                throw new InvalidModuleSourceException('Module dependencies require their own composer.json.');
            }

            return null;
        }

        $composer = $this->readObject($path.'/composer.json');
        $config = $this->map($composer, 'config');

        if (array_key_exists('repositories', $composer) && $composer['repositories'] !== []) {
            throw new InvalidModuleSourceException('Module composer.json must not declare custom repositories.');
        }

        if (array_key_exists('vendor-dir', $config) && $config['vendor-dir'] !== 'vendor') {
            throw new InvalidModuleSourceException('Module composer.json must use the standard vendor directory.');
        }

        $shared = array_keys($this->requirements($manifest->composer, 'require'));
        $this->validatePackage($composer, $shared);
        $this->validatePlatform($this->requirements($composer, 'require'));

        if (! file_exists($path.'/composer.lock')) {
            if ($requireLock || file_exists($path.'/vendor')) {
                throw new InvalidModuleSourceException('Module dependencies require a composer.lock file.');
            }

            return $this->data($composer, [], []);
        }

        $lock = $this->readObject($path.'/composer.lock');

        if (! is_string($lock['content-hash'] ?? null) || $lock['content-hash'] !== $this->contentHash($composer)) {
            throw new InvalidModuleSourceException('Module composer.lock content-hash is missing or stale; regenerate the lock file.');
        }

        $packages = $this->packages($lock['packages'] ?? null, 'composer.lock packages');
        $this->validatePlatform($this->requirements($lock, 'platform'));

        foreach ($packages as $package) {
            $this->validatePackage($package, $shared);
            $this->validatePlatform($this->requirements($package, 'require'));
        }

        $versions = $this->packageVersions($packages, $lock);

        foreach ([$composer, ...$packages] as $package) {
            $this->validateRequirements($this->requirements($package, 'require'), $packages, $versions);
        }

        $data = $this->data($composer, $lock, $packages);
        $this->validateVendor($path, $data);

        return $data;
    }

    /**
     * Composer Locker::getContentHash: sort only the selected top-level keys,
     * preserve nested order, and encode with default JSON escaping (options 0).
     *
     * @see https://github.com/composer/composer/blob/2.8.12/src/Composer/Package/Locker.php
     *
     * @param  array<string, mixed>  $composer
     */
    public function contentHash(array $composer): string
    {
        // Composer parses JSON as associative arrays, including empty objects.
        $normalized = json_decode($this->encode($composer), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($normalized)) {
            throw new InvalidModuleSourceException('Module Composer metadata must be an object.');
        }

        $composer = $normalized;
        $relevant = array_intersect_key($composer, array_flip([
            'name', 'version', 'require', 'require-dev', 'conflict', 'replace',
            'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra',
        ]));

        if (isset($composer['config']['platform'])) {
            $relevant['config']['platform'] = $composer['config']['platform'];
        }

        ksort($relevant);

        return hash('md5', $this->encode($relevant));
    }

    /** @param array{packages: list<array<string, mixed>>} $data */
    public function validateVendor(string $path, array $data): void
    {
        $vendor = rtrim($path, '/\\').'/vendor';

        if (is_link($vendor) || is_link($vendor.'/composer')) {
            throw new InvalidModuleSourceException('Module vendor inventory must not use symlink directories.');
        }

        if (! file_exists($vendor)) {
            return;
        }

        $inventory = $this->readJson($vendor.'/composer/installed.json');

        if (! is_array($inventory)) {
            throw new InvalidModuleSourceException('Module vendor/composer/installed.json must contain a package inventory.');
        }

        $installed = $this->packages(array_is_list($inventory) ? $inventory : ($inventory['packages'] ?? null), 'vendor/composer/installed.json');
        $locked = $this->packages($data['packages'], 'composer.lock packages');

        if (count($installed) !== count($locked)) {
            throw new InvalidModuleSourceException('Module vendor inventory does not match the production composer.lock packages.');
        }

        $byName = array_column($installed, null, 'name');

        foreach ($locked as $package) {
            $actual = $byName[$package['name']] ?? null;

            if ($actual === null || $actual['version'] !== $package['version']) {
                throw new InvalidModuleSourceException(sprintf('Module vendor inventory does not match locked package [%s].', $package['name']));
            }

            foreach (['source', 'dist'] as $kind) {
                $expected = $this->map($package, $kind);
                $found = $this->map($actual, $kind);

                if (($expected['reference'] ?? null) !== ($found['reference'] ?? null)) {
                    throw new InvalidModuleSourceException(sprintf('Module vendor inventory has a different %s reference for [%s].', $kind, $package['name']));
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  list<string>  $shared
     */
    private function validatePackage(array $package, array $shared): void
    {
        $name = $package['name'] ?? 'module';
        $type = $package['type'] ?? 'library';

        if (! is_string($name) || ! is_string($type) || ! in_array($type, ['library', 'metapackage', 'project'], true)) {
            throw new InvalidModuleSourceException('Module production dependencies must not contain plugins or custom installers.');
        }

        if (in_array($name, ['composer/installers', 'oomphinc/composer-installers-extender'], true)) {
            throw new InvalidModuleSourceException(sprintf('Module production dependency [%s] is a custom installer.', $name));
        }

        $this->assertPrivate($name, $shared);

        foreach (['require', 'provide', 'replace'] as $link) {
            foreach ($this->requirements($package, $link, $link !== 'require') as $dependency => $constraint) {
                $this->assertPrivate($dependency, $shared);

                if ($dependency === 'composer-plugin-api') {
                    throw new InvalidModuleSourceException('Module production dependencies must not require composer-plugin-api.');
                }
            }
        }
    }

    /** @param list<string> $shared */
    private function assertPrivate(string $name, array $shared): void
    {
        if ($name === 'laravel/framework' || str_starts_with($name, 'illuminate/') || str_starts_with($name, 'frolaxhq/bedrock') || in_array($name, $shared, true)) {
            // Platform requirements are always evaluated against the real runtime.
            if ($name === 'php' || str_starts_with($name, 'ext-')) {
                return;
            }

            throw new InvalidModuleSourceException(sprintf('Shared package [%s] cannot belong to the module private dependency graph; declare it in module.json composer.require.', $name));
        }
    }

    /** @param array<string, string> $requirements */
    private function validatePlatform(array $requirements): void
    {
        foreach ($requirements as $name => $constraint) {
            if ($name === 'php' || str_starts_with($name, 'php-')) {
                $available = match ($name) {
                    'php' => true,
                    'php-64bit' => PHP_INT_SIZE === 8,
                    'php-debug' => PHP_DEBUG === 1,
                    'php-zts' => PHP_ZTS === 1,
                    default => false,
                };
                $version = $available ? PHP_VERSION : null;
            } elseif (str_starts_with($name, 'ext-')) {
                $extension = null;

                foreach (get_loaded_extensions() as $loaded) {
                    if ('ext-'.str_replace(' ', '-', strtolower($loaded)) === $name) {
                        $extension = $loaded;

                        break;
                    }
                }

                $version = $extension === null ? null : (phpversion($extension) ?: '0');
            } else {
                continue;
            }

            if ($version === null || ! $this->satisfies($version, $constraint)) {
                throw new InvalidModuleSourceException(sprintf('Module platform requirement [%s %s] is not satisfied by the actual PHP runtime.', $name, $constraint));
            }
        }
    }

    /**
     * @param  array<string, string>  $requirements
     * @param  list<array<string, mixed>>  $packages
     * @param  array<string, list<string>>  $versions
     */
    private function validateRequirements(array $requirements, array $packages, array $versions): void
    {
        foreach ($requirements as $name => $constraint) {
            if ($name === 'php' || str_starts_with($name, 'php-') || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-') || $name === 'composer-runtime-api') {
                continue;
            }

            foreach ($packages as $package) {
                if ($package['name'] === $name) {
                    foreach ($versions[$package['name']] as $version) {
                        if ($this->satisfies($version, $constraint)) {
                            continue 3;
                        }
                    }
                }

                foreach (['provide', 'replace'] as $link) {
                    $provided = $this->requirements($package, $link, true)[$name] ?? null;

                    if ($provided === 'self.version') {
                        foreach ($versions[$package['name']] as $version) {
                            if ($this->satisfies($version, $constraint)) {
                                continue 4;
                            }
                        }

                        continue;
                    }

                    if ($provided !== null && Intervals::haveIntersections((new VersionParser)->parseConstraints($provided), (new VersionParser)->parseConstraints($constraint))) {
                        continue 3;
                    }
                }
            }

            throw new InvalidModuleSourceException(sprintf('Module composer.lock does not satisfy production requirement [%s %s].', $name, $constraint));
        }
    }

    /**
     * Keep aliases separate from the physical lock/vendor inventory.
     *
     * @param  list<array<string, mixed>>  $packages
     * @param  array<string, mixed>  $lock
     * @return array<string, list<string>>
     */
    private function packageVersions(array $packages, array $lock): array
    {
        $parser = new VersionParser;
        $versions = [];

        foreach ($packages as $package) {
            $versions[$package['name']] = [$parser->normalize($package['version'])];
            $branch = $this->branchAlias($package);

            if ($branch !== null) {
                $versions[$package['name']][] = $branch;
            }
        }

        $aliases = $lock['aliases'] ?? [];

        if (! is_array($aliases) || ! array_is_list($aliases)) {
            throw new InvalidModuleSourceException('Module composer.lock aliases must be a list.');
        }

        foreach ($aliases as $alias) {
            if (! is_array($alias) || ! is_string($alias['package'] ?? null) || ! is_string($alias['version'] ?? null) || ! is_string($alias['alias'] ?? null) || ! is_string($alias['alias_normalized'] ?? null)) {
                throw new InvalidModuleSourceException('Module composer.lock contains an invalid alias.');
            }

            // Composer's production lock repository ignores aliases for dev-only packages.
            if (! isset($versions[$alias['package']])) {
                continue;
            }

            try {
                $normalized = $parser->normalize($alias['alias_normalized']);

                if ($normalized !== $parser->normalize($alias['alias'])) {
                    throw new UnexpectedValueException('Alias versions disagree.');
                }
            } catch (UnexpectedValueException $exception) {
                throw new InvalidModuleSourceException(sprintf('Module composer.lock contains an invalid alias for [%s].', $alias['package']), previous: $exception);
            }

            // Like Composer Locker, attach root aliases by package name. The source
            // version field can use Composer 1's legacy default-branch normalization.
            $versions[$alias['package']][] = $normalized;
        }

        return $versions;
    }

    /**
     * Match Composer ArrayLoader::getBranchAlias using the installed semver parser.
     *
     * @see https://github.com/composer/composer/blob/2.8.12/src/Composer/Package/Loader/ArrayLoader.php
     *
     * @param  array<string, mixed>  $package
     */
    private function branchAlias(array $package): ?string
    {
        $version = $package['version'];

        if (! str_starts_with($version, 'dev-') && ! str_ends_with($version, '-dev')) {
            return null;
        }

        $parser = new VersionParser;
        $extra = is_array($package['extra'] ?? null) ? $package['extra'] : [];
        $aliases = is_array($extra['branch-alias'] ?? null) ? $extra['branch-alias'] : [];

        foreach ($aliases as $source => $target) {
            if (! is_string($target) || ! str_ends_with($target, '-dev') || strtolower((string) $source) !== strtolower($version)) {
                continue;
            }

            $normalized = $target === '9999999-dev' ? $target : $parser->normalizeBranch(substr($target, 0, -4));

            if (! str_ends_with($normalized, '-dev')) {
                continue;
            }

            $sourcePrefix = $parser->parseNumericAliasPrefix($source);
            $targetPrefix = $parser->parseNumericAliasPrefix($target);

            if ($sourcePrefix !== false && $targetPrefix !== false && ! str_starts_with($targetPrefix, $sourcePrefix)) {
                continue;
            }

            return $normalized;
        }

        if (($package['default-branch'] ?? false) === true && $parser->parseNumericAliasPrefix(preg_replace('/^v/', '', $version)) === false) {
            return '9999999-dev';
        }

        return null;
    }

    private function satisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (UnexpectedValueException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function requirements(array $data, string $key, bool $allowSelfVersion = false): array
    {
        $requirements = $this->map($data, $key);

        foreach ($requirements as $name => $constraint) {
            if (preg_match('/^(?:php(?:-[a-z0-9-]+)?|(?:ext|lib)-[a-z0-9_.-]+|composer-(?:plugin|runtime)-api|[a-z0-9_.-]+\/[a-z0-9_.-]+)$/D', $name) !== 1 || ! is_string($constraint) || trim($constraint) === '') {
                throw new InvalidModuleSourceException(sprintf('Invalid Composer requirement [%s] in [%s].', $name, $key));
            }

            try {
                if (! $allowSelfVersion || $constraint !== 'self.version') {
                    (new VersionParser)->parseConstraints($constraint);
                }
            } catch (UnexpectedValueException $exception) {
                throw new InvalidModuleSourceException(sprintf('Invalid Composer constraint for [%s].', $name), previous: $exception);
            }
        }

        return $requirements;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function packages(mixed $packages, string $source): array
    {
        if (! is_array($packages) || ! array_is_list($packages)) {
            throw new InvalidModuleSourceException(sprintf('[%s] must contain a list of packages.', $source));
        }

        $names = [];

        foreach ($packages as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null) || preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/D', $package['name']) !== 1 || ! is_string($package['version'] ?? null) || isset($names[$package['name']])) {
                throw new InvalidModuleSourceException(sprintf('[%s] contains an invalid or duplicate package.', $source));
            }

            try {
                (new VersionParser)->normalize($package['version']);
            } catch (UnexpectedValueException $exception) {
                throw new InvalidModuleSourceException(sprintf('[%s] contains an invalid package version.', $source), previous: $exception);
            }

            $names[$package['name']] = true;

            foreach (['source', 'dist'] as $kind) {
                $transport = $this->map($package, $kind);

                if (in_array($transport['type'] ?? null, ['path', 'symlink'], true)) {
                    throw new InvalidModuleSourceException(sprintf('Module dependency [%s] must not use a %s package source.', $package['name'], $transport['type']));
                }
            }
        }

        return $packages;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function map(array $data, string $key): array
    {
        if (! array_key_exists($key, $data)) {
            return [];
        }

        if (! is_array($data[$key]) || ($data[$key] !== [] && array_is_list($data[$key]))) {
            throw new InvalidModuleSourceException(sprintf('Composer metadata [%s] must be an object.', $key));
        }

        return $data[$key];
    }

    /** @return array<string, mixed> */
    private function readObject(string $path): array
    {
        $data = $this->readJson($path);

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidModuleSourceException(sprintf('[%s] must contain a JSON object.', $path));
        }

        return $data;
    }

    private function readJson(string $path): mixed
    {
        if (is_link($path) || (is_file($path) && filesize($path) > 16 * 1024 * 1024)) {
            throw new InvalidModuleSourceException(sprintf('Module dependency metadata [%s] is symlinked or oversized.', $path));
        }

        $contents = is_file($path) ? @file_get_contents($path) : false;

        if ($contents === false) {
            throw new InvalidModuleSourceException(sprintf('Cannot read module dependency metadata [%s].', $path));
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidModuleSourceException(sprintf('Invalid JSON in module dependency metadata [%s].', $path), previous: $exception);
        }

        if (! str_starts_with(ltrim($contents), '{') && ! str_ends_with($path, '/installed.json')) {
            throw new InvalidModuleSourceException(sprintf('[%s] must contain a JSON object.', $path));
        }

        return $data;
    }

    /** @param array<mixed> $data */
    private function encode(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidModuleSourceException('Invalid module dependency metadata.', previous: $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $composer
     * @param  array<string, mixed>  $lock
     * @param  list<array<string, mixed>>  $packages
     * @return array{composer: array<string, mixed>, lock: array<string, mixed>, packages: list<array<string, mixed>>, fingerprint: string}
     */
    private function data(array $composer, array $lock, array $packages): array
    {
        return [
            'composer' => $composer,
            'lock' => $lock,
            'packages' => $packages,
            'fingerprint' => hash('sha256', $this->encode([$composer, $lock])),
        ];
    }
}
