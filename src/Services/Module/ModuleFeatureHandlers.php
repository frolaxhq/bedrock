<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Frolax\Bedrock\Contracts\Module\ModuleFeatureHandler;
use Frolax\Bedrock\DataObjects\Module\DiagnosticCheck;
use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Enums\Module\DiagnosticStatus;
use Frolax\Bedrock\Exceptions\Frontend\InvalidFrontendArtifactException;
use Illuminate\Contracts\Container\Container;
use LogicException;

class ModuleFeatureHandlers
{
    /** @var array<string, class-string<ModuleFeatureHandler>> */
    protected array $handlers = [];

    public function __construct(protected Container $container) {}

    /** @param class-string<ModuleFeatureHandler> $handler */
    public function register(string $driver, string $handler): void
    {
        if (isset($this->handlers[$driver]) && $this->handlers[$driver] !== $handler) {
            throw new LogicException(sprintf('A handler for [%s] is already registered.', $driver));
        }

        $this->handlers[$driver] = $handler;
    }

    /** @return list<string> */
    public function failures(Manifest $manifest, string $path, bool $published = false): array
    {
        try {
            return $this->handler($manifest)?->failures($manifest, $path, $published) ?? [];
        } catch (InvalidFrontendArtifactException $exception) {
            return [$exception->getMessage()];
        }
    }

    public function ensureValid(Manifest $manifest, string $path, bool $published = false): void
    {
        $failure = $this->failures($manifest, $path, $published)[0] ?? null;

        if ($failure !== null) {
            throw InvalidFrontendArtifactException::for($manifest->key, $failure);
        }
    }

    public function publish(Manifest $manifest, string $path): void
    {
        $this->handler($manifest)?->publish($manifest, $path);
    }

    public function ensureSupported(Manifest $manifest): void
    {
        $this->handler($manifest);
    }

    public function remove(Manifest $manifest): void
    {
        $this->handler($manifest)?->remove($manifest->key, $manifest->version);
    }

    /** @return list<DiagnosticCheck> */
    public function diagnostics(Manifest $manifest, string $path, bool $published = false): array
    {
        try {
            return $this->handler($manifest)?->diagnostics($manifest, $path, $published) ?? [];
        } catch (InvalidFrontendArtifactException $exception) {
            return [new DiagnosticCheck('frontend.handler', DiagnosticStatus::Failed, $manifest->key, $exception->getMessage())];
        }
    }

    protected function handler(Manifest $manifest): ?ModuleFeatureHandler
    {
        if ($manifest->frontend === []) {
            return null;
        }

        $driver = $manifest->frontend['driver'] ?? '';
        $handler = is_string($driver) ? ($this->handlers[$driver] ?? null) : null;

        if ($handler === null) {
            throw InvalidFrontendArtifactException::for($manifest->key, sprintf(
                'No handler is installed for frontend driver [%s]. Install frolaxhq/bedrock for inertia-react modules.',
                is_string($driver) ? $driver : 'invalid',
            ));
        }

        return $this->container->make($handler);
    }
}
