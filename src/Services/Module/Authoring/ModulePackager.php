<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module\Authoring;

use FilesystemIterator;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleArchiveException;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Frolax\Bedrock\Services\Module\Install\ZipSource;
use Illuminate\Filesystem\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use ZipArchive;

class ModulePackager
{
    public function __construct(
        protected Filesystem $files,
        protected ModuleSourceValidator $validator,
        /** @var array<string, mixed> */
        protected array $limits = [],
        protected string $manifestFile = 'module.json',
    ) {}

    public function pack(string $source, string $outputDirectory, bool $force = false): string
    {
        $source = realpath($source) ?: $source;
        $manifest = $this->validator->manifest($source);

        if (isset($manifest->frontend['driver'])) {
            $this->buildFrontend($source);
        }

        $this->files->ensureDirectoryExists($outputDirectory);
        $archivePath = rtrim($outputDirectory, '/\\').'/'.$manifest->key.'-'.$manifest->version.'.zip';

        if ($this->files->exists($archivePath) && ! $force) {
            throw new InvalidModuleArchiveException(sprintf('The archive [%s] already exists.', $archivePath));
        }

        $staging = sys_get_temp_dir().'/bedrock-pack-'.$manifest->key.'-'.bin2hex(random_bytes(8));

        try {
            $this->copyPackage($source, $staging, $this->ignorePatterns($source), $archivePath);
            $this->validator->validate($staging);
            $this->writeArchive($staging, $archivePath);
            $this->validateArchive($archivePath);
        } finally {
            $this->files->deleteDirectory($staging);
        }

        return $archivePath;
    }

    protected function validateArchive(string $archivePath): void
    {
        $source = new ZipSource(
            $this->files,
            $archivePath,
            $this->manifestFile,
            $this->limit('max_archive_bytes', 268_435_456),
            $this->limit('max_entries', 10_000),
            $this->limit('max_path_length', 240),
        );

        try {
            $this->validator->validate($source->stage());
        } finally {
            $source->cleanup();
        }
    }

    protected function limit(string $key, int $default): int
    {
        $value = $this->limits[$key] ?? $default;

        return is_int($value) && $value > 0 ? $value : $default;
    }

    protected function buildFrontend(string $source): void
    {
        $package = $source.'/package.json';

        if (! $this->files->isFile($package)) {
            throw new InvalidModuleSourceException('A frontend module must contain package.json.');
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($this->files->get($package), true);
        $manager = is_array($data) && is_string($data['packageManager'] ?? null)
            ? explode('@', $data['packageManager'], 2)[0]
            : $this->managerFromLock($source);

        if ($manager === null || ! in_array($manager, ['npm', 'pnpm', 'yarn', 'bun'], true)) {
            throw new InvalidModuleSourceException('A frontend module must declare a supported packageManager.');
        }

        if (! $this->hasLock($source, $manager)) {
            throw new InvalidModuleSourceException(sprintf('The frontend module needs a %s lockfile before it can be packaged.', $manager));
        }

        $command = in_array($manager, ['pnpm', 'yarn'], true)
            ? ['corepack', $manager, 'run', 'build']
            : [$manager, 'run', 'build'];
        $process = new Process($command, $source, timeout: null);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new InvalidModuleSourceException(trim($process->getErrorOutput().PHP_EOL.$process->getOutput()));
        }
    }

    protected function managerFromLock(string $source): ?string
    {
        foreach (['pnpm-lock.yaml' => 'pnpm', 'package-lock.json' => 'npm', 'yarn.lock' => 'yarn', 'bun.lock' => 'bun', 'bun.lockb' => 'bun'] as $lock => $manager) {
            if ($this->files->isFile($source.'/'.$lock)) {
                return $manager;
            }
        }

        return null;
    }

    protected function hasLock(string $source, string $manager): bool
    {
        $locks = match ($manager) {
            'npm' => ['package-lock.json'],
            'pnpm' => ['pnpm-lock.yaml'],
            'yarn' => ['yarn.lock'],
            'bun' => ['bun.lock', 'bun.lockb'],
            default => [],
        };

        return collect($locks)->contains(fn (string $lock): bool => $this->files->isFile($source.'/'.$lock));
    }

    /** @return list<string> */
    protected function ignorePatterns(string $source): array
    {
        $patterns = ['.git', '.git/**', 'node_modules', 'node_modules/**', '.DS_Store', '*.log'];
        $path = $source.'/.bedrockignore';

        if (! $this->files->isFile($path)) {
            return $patterns;
        }

        foreach (preg_split('/\R/', $this->files->get($path)) ?: [] as $pattern) {
            $pattern = trim($pattern);

            if ($pattern !== '' && ! str_starts_with($pattern, '#')) {
                $patterns[] = ltrim(str_replace('\\', '/', $pattern), '/');
            }
        }

        return array_values(array_unique($patterns));
    }

    /** @param list<string> $patterns */
    protected function copyPackage(string $source, string $target, array $patterns, string $archivePath): void
    {
        $this->files->ensureDirectoryExists($target);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            $relative = str_replace('\\', '/', ltrim(substr($entry->getPathname(), strlen($source)), '/\\'));

            if ($entry->getPathname() === $archivePath || $this->ignored($relative, $patterns)) {
                continue;
            }

            if ($entry->isLink()) {
                throw new InvalidModuleSourceException(sprintf('The module source contains the symbolic link [%s].', $relative));
            }

            $destination = $target.'/'.$relative;

            if ($entry->isDir()) {
                $this->files->ensureDirectoryExists($destination);
            } elseif ($entry->isFile()) {
                $this->files->ensureDirectoryExists(dirname($destination));
                $this->files->copy($entry->getPathname(), $destination);
            }
        }
    }

    /** @param list<string> $patterns */
    protected function ignored(string $relative, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $regex = preg_quote($pattern, '#');
            $regex = str_replace(['\*\*', '\*'], ['.*', '[^/]*'], $regex);

            if (preg_match('#^'.$regex.'$#', $relative) === 1 || preg_match('#^'.$regex.'/#', $relative) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function writeArchive(string $root, string $archivePath): void
    {
        $archive = new ZipArchive;

        if ($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new InvalidModuleArchiveException(sprintf('The archive [%s] could not be created.', $archivePath));
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            $paths[] = [
                str_replace('\\', '/', ltrim(substr($entry->getPathname(), strlen($root)), '/\\')),
                $entry->getPathname(),
                $entry->isDir(),
            ];
        }

        usort($paths, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        foreach ($paths as [$relative, $path, $directory]) {
            $name = $directory ? rtrim($relative, '/').'/' : $relative;
            $directory ? $archive->addEmptyDir($relative) : $archive->addFile($path, $relative);
            $archive->setMtimeName($name, 315532800);
            $archive->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, ($directory ? 0040755 : 0100644) << 16);
        }

        if (! $archive->close()) {
            throw new InvalidModuleArchiveException(sprintf('The archive [%s] could not be finalized.', $archivePath));
        }
    }
}
