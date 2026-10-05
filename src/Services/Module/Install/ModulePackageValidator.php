<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module\Install;

use FilesystemIterator;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ModulePackageValidator
{
    /** @param array<string, mixed> $limits */
    public function __construct(protected array $limits = []) {}

    public function validate(string $root): void
    {
        $root = realpath($root) ?: $root;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        $targets = [];
        $entries = 0;
        $bytes = 0;

        foreach ($iterator as $entry) {
            $entries++;
            $relative = str_replace('\\', '/', ltrim(substr($entry->getPathname(), strlen($root)), '/\\'));

            if ($entries > $this->integer('max_entries', 10_000)) {
                throw new InvalidModuleSourceException('The module source contains too many entries.');
            }

            if (strlen($relative) > $this->integer('max_path_length', 240)) {
                throw new InvalidModuleSourceException(sprintf('The module source path [%s] is too long.', $relative));
            }

            $collision = strtolower($relative);

            if (isset($targets[$collision])) {
                throw new InvalidModuleSourceException(sprintf('The module source contains a case-colliding path [%s].', $relative));
            }

            $targets[$collision] = true;

            if ($entry->isLink()) {
                throw new InvalidModuleSourceException(sprintf('The module source contains the symbolic link [%s].', $relative));
            }

            if (! $entry->isDir() && ! $entry->isFile()) {
                throw new InvalidModuleSourceException(sprintf('The module source contains an unsupported entry [%s].', $relative));
            }

            if ($entry->isFile()) {
                $bytes += $entry->getSize();

                if ($bytes > $this->integer('max_archive_bytes', 268_435_456)) {
                    throw new InvalidModuleSourceException('The module source exceeds the configured size limit.');
                }
            }
        }
    }

    protected function integer(string $key, int $default): int
    {
        $value = $this->limits[$key] ?? $default;

        return is_int($value) && $value > 0 ? $value : $default;
    }
}
