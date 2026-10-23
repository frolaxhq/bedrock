<?php

declare(strict_types=1);

namespace Frolax\Bedrock\DataObjects\Frontend;

use Spatie\LaravelData\Data;

class FrontendModule extends Data
{
    /**
     * @param  list<string>  $css
     * @param  array<string, string>  $requires
     * @param  array{groups: list<array<string, mixed>>, items: list<array<string, mixed>>}  $navigation
     */
    public function __construct(
        public readonly string $key,
        public readonly string $version,
        public readonly string $driver,
        public readonly string $client,
        public readonly string $server,
        public readonly array $css = [],
        public readonly array $requires = [],
        public readonly array $navigation = ['groups' => [], 'items' => []],
        public readonly bool $development = false,
    ) {}

    /** @return array<string, mixed> */
    public function forInertia(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'driver' => $this->driver,
            'client' => $this->client,
            'server' => $this->server,
            'css' => $this->css,
            'requires' => $this->requires,
            'navigation' => $this->navigation,
            'development' => $this->development,
        ];
    }
}
