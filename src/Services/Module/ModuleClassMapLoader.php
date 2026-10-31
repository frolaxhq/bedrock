<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module;

use Composer\Autoload\ClassLoader;

/** Incremental compiled module maps, preserving host overrides and callback priority. */
class ModuleClassMapLoader extends ClassLoader
{
    /** @var array<string, list<array<string, string>>> */
    protected array $groups = [];

    protected ?ClassLoader $host = null;

    public static function group(string $class): string
    {
        $first = strpos($class, '\\');
        $second = $first === false ? false : strpos($class, '\\', $first + 1);

        return $second === false ? $class : substr($class, 0, $second);
    }

    /** @param array<string, string> $classMap */
    public function addClassMap(array $classMap): void
    {
        $groups = [];
        foreach ($classMap as $class => $file) {
            $groups[self::group($class)][$class] = $file;
        }
        $this->addGroupedClassMap($groups);
    }

    /** @param array<string, array<string, string>> $groups */
    public function addGroupedClassMap(array $groups): void
    {
        $hostMap = $this->host?->getClassMap() ?? [];
        foreach ($groups as $group => $map) {
            // Native intersection preserves dynamic host collisions without expanding
            // the immutable compiled map into thousands of per-request PHP entries.
            $collisions = array_intersect_key($map, $hostMap);

            if ($collisions !== [] && $this->host !== null) {
                $this->host->addClassMap($collisions);
            }
            $this->groups[$group][] = $map;
        }
    }

    /** @return array<string, string> */
    public function getClassMap(): array
    {
        $map = [];
        foreach ($this->groups as $chunks) {
            foreach ($chunks as $chunk) {
                foreach ($chunk as $class => $file) {
                    $map[$class] = $file;
                }
            }
        }

        return $map;
    }

    public function findFile(mixed $class): string|false
    {
        if ($this->host !== null && isset($this->host->getClassMap()[$class])) {
            return false;
        }
        $chunks = $this->groups[self::group($class)] ?? [];
        for ($index = count($chunks) - 1; $index >= 0; $index--) {
            if (isset($chunks[$index][$class])) {
                return $chunks[$index][$class];
            }
        }

        return false;
    }

    public function autoload(string $class): void
    {
        $this->loadClass($class);
    }

    public function unregister(): void
    {
        spl_autoload_unregister([$this, 'autoload']);
        parent::unregister();
    }

    /** Keep the relative order of existing autoload callbacks unchanged. */
    public function registerBefore(ClassLoader $host): void
    {
        $this->host = $host;
        $callbacks = spl_autoload_functions();
        foreach ($callbacks as $callback) {
            spl_autoload_unregister($callback);
        }
        $registered = false;
        foreach ($callbacks as $callback) {
            if (! $registered && is_array($callback) && $callback[0] === $host) {
                $registered = true;
                spl_autoload_register([$this, 'autoload']);
            }
            spl_autoload_register($callback);
        }
    }
}
