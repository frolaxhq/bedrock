<?php

declare(strict_types=1);

/**
 * Validate that a tag can describe every package released from this repository.
 *
 * Run this before a release workflow mutates any remote repository or registry.
 */
$tag = $argv[1] ?? '';

if (preg_match('/^v?(?<version>\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?)$/', $tag, $matches) !== 1) {
    fwrite(STDERR, "Expected a semantic version tag such as v1.0.0.\n");

    exit(1);
}

$version = $matches['version'];
$root = dirname(__DIR__);

/** @return array<string, mixed> */
function packageJson(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Unable to read [{$path}].");
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException("Expected [{$path}] to contain a JSON object.");
    }

    return $decoded;
}

/** @param array<string, mixed> $package */
function assertPackage(array $package, string $expectedName, string $path, bool $requiresLibraryType = true): void
{
    if (($package['name'] ?? null) !== $expectedName) {
        throw new RuntimeException("Expected [{$path}] to name package [{$expectedName}].");
    }

    if ($requiresLibraryType && ($package['type'] ?? null) !== 'library') {
        throw new RuntimeException("Expected [{$path}] to be a Composer library.");
    }
}

try {
    $core = packageJson("{$root}/composer.json");
    assertPackage($core, 'frolaxhq/bedrock', "{$root}/composer.json", false);

    foreach ([
        'access-spatie' => 'frolaxhq/bedrock-access-spatie',
        'admin' => 'frolaxhq/bedrock-admin',
        'frontend' => 'frolaxhq/bedrock-frontend',
    ] as $directory => $name) {
        $path = "{$root}/packages/php/{$directory}/composer.json";
        $package = packageJson($path);
        assertPackage($package, $name, $path);

        if (($package['require']['frolaxhq/bedrock'] ?? null) !== '^1.0') {
            throw new RuntimeException("Package [{$name}] must require Bedrock with [^1.0].");
        }
    }

    $frontend = packageJson("{$root}/packages/frontend/package.json");

    if (($frontend['name'] ?? null) !== '@frolax/bedrock') {
        throw new RuntimeException('The frontend package must be named [@frolax/bedrock].');
    }

    if (($frontend['version'] ?? null) !== $version) {
        throw new RuntimeException("The frontend package version [{$frontend['version']}] does not match release [{$version}].");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");

    exit(1);
}

fwrite(STDOUT, "Release {$version} metadata is valid.\n");
