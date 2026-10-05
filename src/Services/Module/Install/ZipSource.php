<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module\Install;

use Frolax\Bedrock\Contracts\Module\DisposableModuleSource;
use Frolax\Bedrock\Contracts\Module\ModuleSource;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleArchiveException;
use Illuminate\Filesystem\Filesystem;
use Throwable;
use ZipArchive;

/**
 * Installs a module from a ZIP archive.
 *
 * Every entry is inspected before anything is written: entries that point
 * outside the archive, symbolic links and non regular entries all reject the
 * whole archive. Each file is then extracted, its checksum compared with the
 * one the archive records, and only then moved into the staged directory, so a
 * file that is corrupt or short never reaches the module root.
 */
class ZipSource implements DisposableModuleSource, ModuleSource
{
    protected ?string $staged = null;

    public function __construct(
        protected Filesystem $files,
        protected string $path,
        protected string $manifestFile = 'module.json',
        protected int $maxExtractedBytes = 268_435_456,
        protected int $maxEntries = 10_000,
        protected int $maxPathLength = 240,
    ) {}

    public function stage(): string
    {
        $this->cleanup();

        $path = rtrim($this->path, '/\\');

        if (! $this->files->isFile($path)) {
            throw InvalidModuleArchiveException::notFound($path);
        }

        $archive = $this->open($path);

        try {
            $entries = $this->inspect($archive, $path);
            $root = $this->resolveRoot($entries, $path);
            $directory = $this->extract($archive, $entries, $root);

            $this->staged = $directory;

            return $directory.'/staged';
        } finally {
            $archive->close();
        }
    }

    public function cleanup(): void
    {
        if ($this->staged !== null && $this->files->isDirectory($this->staged)) {
            $this->files->deleteDirectory($this->staged);
        }

        $this->staged = null;
    }

    /**
     * Open the archive, turning a failure into a package exception.
     */
    protected function open(string $path): ZipArchive
    {
        $archive = new ZipArchive;

        $code = $archive->open($path, ZipArchive::RDONLY);

        if ($code !== true) {
            throw InvalidModuleArchiveException::unreadable($path, $code);
        }

        return $archive;
    }

    /**
     * Read the entry table and reject anything unsafe.
     *
     * @return array<string, array{name: string, size: int, crc: int, directory: bool}>
     */
    protected function inspect(ZipArchive $archive, string $path): array
    {
        if ($archive->numFiles > $this->maxEntries) {
            throw InvalidModuleArchiveException::tooManyEntries($archive->numFiles, $this->maxEntries);
        }

        /** @var array<string, array{name: string, size: int, crc: int, directory: bool}> $entries */
        $entries = [];

        /** @var array<string, string> $targets */
        $targets = [];

        $total = 0;

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $stat = $archive->statIndex($index);

            if ($stat === false) {
                throw InvalidModuleArchiveException::unreadable($path, ZipArchive::ER_INCONS);
            }

            $name = str_replace('\\', '/', $stat['name']);

            if (strlen($name) > $this->maxPathLength) {
                throw InvalidModuleArchiveException::pathTooLong($name, $this->maxPathLength);
            }
            $directory = str_ends_with($name, '/');
            $base = $directory ? substr($name, 0, -1) : $name;

            $this->assertSafe($name);

            $archive->getExternalAttributesIndex($index, $operatingSystem, $attributes);

            $mode = $attributes >> 16;

            if ($operatingSystem === ZipArchive::OPSYS_UNIX && $mode !== 0) {
                $type = $mode & 0170000;

                if ($type === 0120000) {
                    throw InvalidModuleArchiveException::symlink($name);
                }

                if ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
                    throw InvalidModuleArchiveException::unsupportedEntry($name);
                }
            }

            // Two entries whose names differ only by case would collide on a
            // case insensitive filesystem, so the second one is rejected.
            $collision = strtolower($base);

            if (isset($targets[$collision])) {
                throw InvalidModuleArchiveException::unsupportedEntry($name);
            }

            $targets[$collision] = $name;

            if ($directory) {
                $entries[$base] = ['name' => $name, 'size' => 0, 'crc' => 0, 'directory' => true];

                continue;
            }

            $total += $stat['size'];

            if ($total > $this->maxExtractedBytes) {
                throw InvalidModuleArchiveException::tooLarge($total, $this->maxExtractedBytes);
            }

            $entries[$base] = [
                'name' => $name,
                'size' => $stat['size'],
                'crc' => $stat['crc'],
                'directory' => false,
            ];
        }

        return $entries;
    }

    /**
     * Reject an entry name that could escape the extraction directory.
     */
    protected function assertSafe(string $name): void
    {
        if ($name === '') {
            throw InvalidModuleArchiveException::unsupportedEntry($name);
        }

        if (str_starts_with($name, '/') || str_contains($name, "\0")) {
            throw InvalidModuleArchiveException::traversal($name);
        }

        if (preg_match('/^[A-Za-z]:/', $name) === 1) {
            throw InvalidModuleArchiveException::traversal($name);
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '..') {
                throw InvalidModuleArchiveException::traversal($name);
            }
        }
    }

    /**
     * Find the directory that holds the module inside the archive.
     *
     * An archive either holds the module at its root or inside one top level
     * directory, which is how a repository is normally zipped. Anything deeper
     * is rejected so the installer never has to guess which directory is the
     * module.
     *
     * @param  array<string, array{name: string, size: int, crc: int, directory: bool}>  $entries
     */
    protected function resolveRoot(array $entries, string $path): string
    {
        if (isset($entries[$this->manifestFile])) {
            return '';
        }

        /** @var array<string, bool> $candidates */
        $candidates = [];

        foreach ($entries as $entry) {
            if ($entry['directory'] || ! str_contains($entry['name'], '/')) {
                continue;
            }

            $candidates[strstr($entry['name'], '/', true)] = true;
        }

        if (count($candidates) === 1) {
            $candidate = array_key_first($candidates);

            if (isset($entries[$candidate.'/'.$this->manifestFile])) {
                return $candidate.'/';
            }
        }

        throw InvalidModuleArchiveException::missingManifest($path, $this->manifestFile);
    }

    /**
     * Extract the validated entries into a fresh staging directory.
     *
     * @param  array<string, array{name: string, size: int, crc: int, directory: bool}>  $entries
     * @return string The temporary directory holding the staged module.
     */
    protected function extract(ZipArchive $archive, array $entries, string $root): string
    {
        $prefix = pathinfo($this->path, PATHINFO_FILENAME);
        $prefix = $prefix === '' ? 'archive' : $prefix;

        $directory = $this->temporaryDirectory($prefix);
        $incoming = $directory.'/incoming';

        $this->files->ensureDirectoryExists($incoming);

        try {
            $this->files->ensureDirectoryExists($directory.'/staged');

            foreach ($entries as $entry) {
                if ($entry['directory']) {
                    continue;
                }

                if (! str_starts_with($entry['name'], $root)) {
                    throw InvalidModuleArchiveException::missingManifest($this->path, $this->manifestFile);
                }

                $relative = substr($entry['name'], strlen($root));

                if ($relative === '') {
                    throw InvalidModuleArchiveException::traversal($entry['name']);
                }

                $this->write($archive, $entry, $incoming, $relative);
            }
        } catch (Throwable $exception) {
            $this->files->deleteDirectory($directory);

            throw $exception;
        }

        $staged = $directory.'/staged';

        foreach ($this->files->allFiles($incoming) as $file) {
            $relative = ltrim(substr($file->getPathname(), strlen($incoming)), '/\\');

            $this->files->ensureDirectoryExists($staged.'/'.dirname($relative));
            $this->files->move($file->getPathname(), $staged.'/'.$relative);
        }

        $this->files->deleteDirectory($incoming);

        return $directory;
    }

    /**
     * Extract one file and verify it against the recorded checksum.
     *
     * @param  array{name: string, size: int, crc: int, directory: bool}  $entry
     */
    protected function write(ZipArchive $archive, array $entry, string $incoming, string $relative): void
    {
        $target = $incoming.'/'.str_replace('/', DIRECTORY_SEPARATOR, $relative);

        $this->files->ensureDirectoryExists(dirname($target));

        $source = $archive->getStream($entry['name']);

        if ($source === false) {
            throw InvalidModuleArchiveException::extractionFailed($this->path);
        }

        $destination = @fopen($target, 'wb');

        if ($destination === false) {
            fclose($source);

            throw InvalidModuleArchiveException::extractionFailed($this->path);
        }

        // A corrupt entry makes libzip report a CRC error, which the error
        // handler would raise as an ErrorException. Suppressing the warning
        // turns that into a plain false so the caller gets a package exception.
        $copied = @stream_copy_to_stream($source, $destination);

        fclose($source);
        fclose($destination);

        if ($copied === false || $copied !== $entry['size']) {
            throw InvalidModuleArchiveException::checksumMismatch($entry['name']);
        }

        if (hash_file('crc32b', $target) !== sprintf('%08x', $entry['crc'])) {
            throw InvalidModuleArchiveException::checksumMismatch($entry['name']);
        }
    }

    /**
     * Create a staging directory outside the module root.
     */
    protected function temporaryDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir().'/bedrock-'.$prefix.'-'.bin2hex(random_bytes(8));

        if (! $this->files->makeDirectory($path, 0755, true)) {
            throw InvalidModuleArchiveException::extractionFailed($this->path);
        }

        return $path;
    }
}
