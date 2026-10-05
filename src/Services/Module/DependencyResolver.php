<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\Module\CircularDependencyException;

/**
 * Orders locally available modules so dependencies are handled first.
 */
class DependencyResolver
{
    /**
     * Resolve all manifests, or only the dependency closure of one module.
     *
     * Dependencies that are not in the supplied local set are intentionally
     * ignored here. Compatibility and availability are enforced when a module
     * is enabled and reconciled, while local installation remains repairable.
     *
     * @param  iterable<Manifest>  $manifests
     * @return list<string>
     *
     * @throws CircularDependencyException
     */
    public function order(iterable $manifests, ?string $root = null): array
    {
        /** @var array<string, Manifest> $available */
        $available = [];

        foreach ($manifests as $manifest) {
            $available[$manifest->key] = $manifest;
        }

        ksort($available);

        /** @var array<string, int> $states */
        $states = [];

        /** @var list<string> $stack */
        $stack = [];

        /** @var list<string> $ordered */
        $ordered = [];

        $visit = function (string $key) use (&$visit, &$states, &$stack, &$ordered, $available): void {
            if (($states[$key] ?? 0) === 2 || ! isset($available[$key])) {
                return;
            }

            if (($states[$key] ?? 0) === 1) {
                $start = array_search($key, $stack, true);
                $cycle = array_slice($stack, is_int($start) ? $start : 0);
                $cycle[] = $key;

                throw new CircularDependencyException($cycle);
            }

            $states[$key] = 1;
            $stack[] = $key;

            $dependencies = $available[$key]->requirements();
            sort($dependencies);

            foreach ($dependencies as $dependency) {
                $visit($dependency);
            }

            array_pop($stack);
            $states[$key] = 2;
            $ordered[] = $key;
        };

        if ($root !== null) {
            $visit($root);

            return $ordered;
        }

        foreach (array_keys($available) as $key) {
            $visit($key);
        }

        return $ordered;
    }
}
