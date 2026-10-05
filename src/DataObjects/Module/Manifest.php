<?php

declare(strict_types=1);

namespace Frolax\Bedrock\DataObjects\Module;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * The machine readable description of a packaged module.
 *
 * Unknown manifest keys are ignored so a manifest written for a newer Bedrock
 * release keeps loading on an older one.
 */
class Manifest extends Data
{
    /**
     * The current manifest schema version.
     */
    public const int SCHEMA_VERSION = 1;

    public const string KEY_PATTERN = '/^[a-z][a-z0-9-]*$/';

    public const string VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/';

    /**
     * Create a new manifest.
     *
     * @param  array<string, string>  $requires
     * @param  list<string|array<string, mixed>>  $permissions
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $database
     * @param  array<string, mixed>  $frontend
     * @param  array<string, mixed>  $assets
     * @param  array<string, mixed>  $autoload
     * @param  array<string, mixed>  $composer
     */
    public function __construct(
        public readonly string $key = '',
        public readonly string $name = '',
        public readonly string $version = '',
        public readonly string $provider = '',
        public readonly int $schema = self::SCHEMA_VERSION,
        public readonly ?string $description = null,
        public readonly ?string $core = null,
        public readonly array $requires = [],
        public readonly array $permissions = [],
        public readonly array $settings = [],
        public readonly array $database = [],
        public readonly array $frontend = [],
        #[MapInputName('assets')]
        public readonly array $assets = [],
        public readonly array $autoload = [],
        public readonly array $composer = [],
    ) {}

    /**
     * The keys of every module this manifest requires.
     *
     * @return list<string>
     */
    public function requirements(): array
    {
        return array_keys($this->requires);
    }

    /**
     * The version constraint required for the given module key.
     */
    public function constraintFor(string $key): ?string
    {
        return $this->requires[$key] ?? null;
    }
}
