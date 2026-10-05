<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Support\Module;

use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\BedrockException;
use Frolax\Bedrock\Exceptions\Module\InvalidManifestException;
use Frolax\Bedrock\Exceptions\Module\ManifestNotFoundException;
use Frolax\Bedrock\Services\Module\VersionConstraint;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Throwable;

/**
 * Reads and validates a module manifest file from disk.
 */
class ModuleManifest
{
    public function __construct(
        protected Filesystem $files,
        protected VersionConstraint $constraints,
    ) {}

    /**
     * Load the manifest located at the given file path.
     *
     * @throws FileNotFoundException
     */
    public function load(string $path): Manifest
    {
        if (! $this->files->isFile($path)) {
            throw ManifestNotFoundException::for($path);
        }

        $contents = $this->files->get($path);

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidManifestException::malformedJson($exception->getMessage())->withPath($path);
        }

        if (! is_array($decoded)) {
            throw InvalidManifestException::invalidType('manifest', 'a JSON object')->withPath($path);
        }

        try {
            $this->validateDecoded($decoded);

            return $this->validate(
                Manifest::from($this->normalize($decoded)),
            );
        } catch (BedrockException $exception) {
            throw $exception->withPath($path);
        } catch (Throwable $exception) {
            throw InvalidManifestException::malformedJson($exception->getMessage())->withPath($path);
        }
    }

    /**
     * Validate the required shape of a manifest.
     *
     * @throws InvalidManifestException
     */
    protected function validate(Manifest $manifest): Manifest
    {
        foreach (['key', 'name', 'version', 'provider'] as $field) {
            if (trim($manifest->{$field}) === '') {
                throw InvalidManifestException::missingField($field);
            }
        }

        if (preg_match(Manifest::KEY_PATTERN, $manifest->key) !== 1) {
            throw InvalidManifestException::invalidKey($manifest->key);
        }

        if ($manifest->schema !== Manifest::SCHEMA_VERSION) {
            throw InvalidManifestException::unsupportedSchema($manifest->schema);
        }

        if (preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+[A-Za-z_][A-Za-z0-9_]*$/', $manifest->provider) !== 1) {
            throw InvalidManifestException::invalidProvider($manifest->provider);
        }

        if (preg_match(Manifest::VERSION_PATTERN, $manifest->version) !== 1) {
            throw InvalidManifestException::invalidVersion($manifest->version);
        }

        if ($manifest->core !== null && ! $this->constraints->isValid($manifest->core)) {
            throw InvalidManifestException::invalidCoreConstraint($manifest->core);
        }

        foreach ($manifest->requires as $key => $constraint) {
            if (
                preg_match(Manifest::KEY_PATTERN, (string) $key) !== 1
                || ! $this->constraints->isValid($constraint)
            ) {
                throw InvalidManifestException::invalidRequires((string) $key);
            }
        }

        $this->validateAutoload($manifest->autoload);
        $this->validateComposer($manifest->composer);
        $this->validateSettings($manifest->settings);
        $this->validatePermissions($manifest->permissions);
        $this->validateFrontend($manifest->frontend);

        return $manifest;
    }

    /**
     * Validate known JSON field types before the data object can coerce them.
     *
     * @param  array<mixed>  $decoded
     */
    protected function validateDecoded(array $decoded): void
    {
        foreach (['key', 'name', 'version', 'provider'] as $field) {
            if (! array_key_exists($field, $decoded)) {
                throw InvalidManifestException::missingField($field);
            }

            if (! is_string($decoded[$field])) {
                throw InvalidManifestException::invalidType($field, 'a string');
            }
        }

        if (isset($decoded['schema']) && ! is_int($decoded['schema'])) {
            throw InvalidManifestException::invalidType('schema', 'an integer');
        }

        foreach (['description', 'core'] as $field) {
            if (array_key_exists($field, $decoded) && $decoded[$field] !== null && ! is_string($decoded[$field])) {
                throw InvalidManifestException::invalidType($field, 'a string or null');
            }
        }

        foreach (['requires', 'settings', 'database', 'frontend', 'assets', 'autoload', 'composer'] as $field) {
            if (array_key_exists($field, $decoded) && ! is_array($decoded[$field])) {
                throw InvalidManifestException::invalidType($field, 'a JSON object');
            }

            if (isset($decoded[$field]) && $decoded[$field] !== [] && array_is_list($decoded[$field])) {
                throw InvalidManifestException::invalidType($field, 'a JSON object');
            }
        }

        foreach (($decoded['requires'] ?? []) as $key => $constraint) {
            if (! is_string($key) || ! is_string($constraint)) {
                throw InvalidManifestException::invalidRequires((string) $key);
            }
        }

        if (array_key_exists('permissions', $decoded)) {
            if (! is_array($decoded['permissions']) || ! array_is_list($decoded['permissions'])) {
                throw InvalidManifestException::invalidType('permissions', 'a JSON array of strings or permission definitions');
            }

            foreach ($decoded['permissions'] as $permission) {
                if (is_string($permission) && trim($permission) !== '') {
                    continue;
                }

                if (! is_array($permission) || array_is_list($permission) || ! is_string($permission['key'] ?? null) || trim($permission['key']) === '') {
                    throw InvalidManifestException::invalidType('permissions', 'a JSON array of non-empty strings or objects with a key');
                }

                foreach (['label', 'description'] as $field) {
                    if (isset($permission[$field]) && (! is_string($permission[$field]) || trim($permission[$field]) === '')) {
                        throw InvalidManifestException::invalidType('permissions.'.$field, 'a non-empty string');
                    }
                }
            }
        }

        $database = $decoded['database'] ?? [];

        foreach (['migrations', 'seeders'] as $field) {
            if (array_key_exists($field, $database) && ! is_bool($database[$field])) {
                throw InvalidManifestException::invalidType('database.'.$field, 'a boolean');
            }
        }

        if (array_key_exists('seeder', $database) && $database['seeder'] !== null && ! is_string($database['seeder'])) {
            throw InvalidManifestException::invalidType('database.seeder', 'a string or null');
        }

        if (isset($database['seeder']) && (trim($database['seeder']) === '' || preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+[A-Za-z_][A-Za-z0-9_]*$/', $database['seeder']) !== 1)) {
            throw InvalidManifestException::invalidType('database.seeder', 'a fully qualified class name');
        }

        $frontend = $decoded['frontend'] ?? [];

        foreach (['driver', 'pages', 'artifact'] as $field) {
            if (array_key_exists($field, $frontend) && (! is_string($frontend[$field]) || trim($frontend[$field]) === '')) {
                throw InvalidManifestException::invalidType('frontend.'.$field, 'a non-empty string');
            }
        }

        if (array_key_exists('pages', $frontend) && (! is_string($frontend['pages']) || ! $this->safeRelativePath($frontend['pages']))) {
            throw InvalidManifestException::invalidType('frontend.pages', 'a safe relative path');
        }

        if (array_key_exists('artifact', $frontend) && (! is_string($frontend['artifact']) || ! $this->safeRelativePath($frontend['artifact']))) {
            throw InvalidManifestException::invalidType('frontend.artifact', 'a safe relative path');
        }

        if (array_key_exists('requires', $frontend)) {
            if (! is_array($frontend['requires']) || ($frontend['requires'] !== [] && array_is_list($frontend['requires']))) {
                throw InvalidManifestException::invalidType('frontend.requires', 'a JSON object');
            }

            foreach ($frontend['requires'] as $package => $constraint) {
                if (! is_string($package) || ! is_string($constraint) || trim($constraint) === '') {
                    throw InvalidManifestException::invalidType('frontend.requires', 'package names mapped to constraints');
                }
            }
        }

        if (array_key_exists('navigation', $frontend)) {
            throw InvalidManifestException::frontendNavigationMoved();
        }

        $assets = $decoded['assets'] ?? [];

        foreach (['css', 'js'] as $kind) {
            if (! array_key_exists($kind, $assets)) {
                continue;
            }

            if (! is_array($assets[$kind]) || ! array_is_list($assets[$kind])) {
                throw InvalidManifestException::invalidType('assets.'.$kind, 'a JSON array of safe relative paths');
            }

            foreach ($assets[$kind] as $asset) {
                if (! is_string($asset) || ! $this->safeRelativePath($asset)) {
                    throw InvalidManifestException::invalidType('assets.'.$kind, 'a JSON array of safe relative paths');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $autoload
     */
    protected function validateAutoload(array $autoload): void
    {
        $psr4 = $autoload['psr-4'] ?? [];

        if (! is_array($psr4)) {
            throw InvalidManifestException::invalidType('autoload.psr-4', 'a JSON object');
        }

        foreach ($psr4 as $prefix => $paths) {
            if (! is_string($prefix) || $prefix === '' || ! str_ends_with($prefix, '\\')) {
                throw InvalidManifestException::invalidAutoload((string) $prefix);
            }

            $paths = is_string($paths) ? [$paths] : $paths;

            if (! is_array($paths) || $paths === [] || ! array_is_list($paths)) {
                throw InvalidManifestException::invalidAutoload($prefix);
            }

            foreach ($paths as $path) {
                if (! is_string($path) || ! $this->safeRelativePath($path)) {
                    throw InvalidManifestException::invalidAutoload($prefix);
                }
            }
        }

        $files = $autoload['files'] ?? [];

        if (! is_array($files) || ! array_is_list($files)) {
            throw InvalidManifestException::invalidType('autoload.files', 'a JSON array');
        }

        foreach ($files as $file) {
            if (! is_string($file) || ! $this->safeRelativePath($file)) {
                throw InvalidManifestException::invalidAutoload((string) $file);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $composer
     */
    protected function validateComposer(array $composer): void
    {
        $requirements = $composer['require'] ?? [];

        if (! is_array($requirements)) {
            throw InvalidManifestException::invalidType('composer.require', 'a JSON object');
        }

        foreach ($requirements as $package => $constraint) {
            if (
                ! is_string($package)
                || ! is_string($constraint)
                || trim($constraint) === ''
                || preg_match('/^(?:php|ext-[a-z0-9_.-]+|[a-z0-9_.-]+\/[a-z0-9_.-]+)$/', $package) !== 1
                || ! $this->constraints->isValid($constraint)
            ) {
                throw InvalidManifestException::invalidComposerRequirement((string) $package);
            }
        }
    }

    /** @param array<string, mixed> $settings */
    protected function validateSettings(array $settings): void
    {
        foreach ($settings as $key => $definition) {
            if (preg_match('/^[a-z][a-z0-9_.-]*$/', $key) !== 1) {
                throw InvalidManifestException::invalidType('settings', 'an object keyed by lowercase setting identifiers');
            }

            if (! is_array($definition) || array_is_list($definition)) {
                continue;
            }

            if (isset($definition['type']) && (! is_string($definition['type']) || ! in_array($definition['type'], ['text', 'textarea', 'number', 'boolean', 'select', 'multiselect', 'secret'], true))) {
                throw InvalidManifestException::invalidType('settings.'.$key.'.type', 'a supported setting type');
            }

            foreach (['label', 'description'] as $field) {
                if (isset($definition[$field]) && (! is_string($definition[$field]) || trim($definition[$field]) === '')) {
                    throw InvalidManifestException::invalidType('settings.'.$key.'.'.$field, 'a non-empty string');
                }
            }

            if (isset($definition['rules']) && (! is_array($definition['rules']) || ! array_is_list($definition['rules']) || collect($definition['rules'])->contains(fn (mixed $rule): bool => ! is_string($rule)))) {
                throw InvalidManifestException::invalidType('settings.'.$key.'.rules', 'a JSON array of Laravel validation rule strings');
            }

            if (isset($definition['options']) && (! is_array($definition['options']) || array_is_list($definition['options']) || collect($definition['options'])->contains(fn (mixed $label, mixed $value): bool => ! is_string($value) || ! is_string($label)))) {
                throw InvalidManifestException::invalidType('settings.'.$key.'.options', 'an object mapping option values to labels');
            }
        }
    }

    /** @param list<mixed> $permissions */
    protected function validatePermissions(array $permissions): void
    {
        foreach ($permissions as $permission) {
            $key = is_string($permission) ? $permission : (is_array($permission) ? $permission['key'] ?? null : null);

            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_.-]*$/', $key) !== 1) {
                throw InvalidManifestException::invalidType('permissions', 'valid permission identifiers');
            }
        }
    }

    /** @param array<string, mixed> $frontend */
    protected function validateFrontend(array $frontend): void
    {
        if (! isset($frontend['driver'])) {
            if (isset($frontend['artifact']) || isset($frontend['requires'])) {
                throw InvalidManifestException::invalidType('frontend.driver', 'inertia-react when frontend artifacts are declared');
            }

            return;
        }

        if ($frontend['driver'] !== 'inertia-react') {
            throw InvalidManifestException::invalidType('frontend.driver', 'inertia-react');
        }

        foreach (['pages', 'artifact'] as $field) {
            if (! isset($frontend[$field]) || ! is_string($frontend[$field]) || ! $this->safeRelativePath($frontend[$field])) {
                throw InvalidManifestException::invalidType('frontend.'.$field, 'a safe relative path');
            }
        }

        foreach (($frontend['requires'] ?? []) as $package => $constraint) {
            if (
                ! is_string($package)
                || preg_match('/^(?:@[a-z0-9_.-]+\/[a-z0-9_.-]+|[a-z0-9_.-]+(?:\/[a-z0-9_.-]+)?)$/', $package) !== 1
                || ! is_string($constraint)
                || ! $this->constraints->isValid($constraint)
            ) {
                throw InvalidManifestException::invalidType('frontend.requires', 'valid npm package constraints');
            }
        }
    }

    protected function safeRelativePath(string $path): bool
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }

        return ! in_array('..', explode('/', $path), true);
    }

    /**
     * Keep only the manifest keys Bedrock understands.
     *
     * Any other key in the file is ignored so a newer manifest remains readable.
     *
     * @param  array<mixed>  $decoded
     * @return array<string, mixed>
     */
    protected function normalize(array $decoded): array
    {
        $manifest = [];

        foreach (['schema', 'key', 'name', 'version', 'provider', 'description', 'core'] as $field) {
            if (array_key_exists($field, $decoded)) {
                $manifest[$field] = $decoded[$field];
            }
        }

        foreach (['requires', 'settings', 'database', 'frontend', 'assets', 'autoload', 'composer'] as $field) {
            $value = $decoded[$field] ?? [];

            $manifest[$field] = is_array($value) ? $value : [];
        }

        $permissions = $decoded['permissions'] ?? [];

        $manifest['permissions'] = is_array($permissions) ? array_values($permissions) : [];

        return $manifest;
    }
}
