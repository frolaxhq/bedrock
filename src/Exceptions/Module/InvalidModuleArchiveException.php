<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Exceptions\Module;

use Frolax\Bedrock\Exceptions\BedrockException;
use ZipArchive;

class InvalidModuleArchiveException extends BedrockException
{
    /**
     * Create an exception for an archive that does not exist.
     */
    public static function notFound(string $path): self
    {
        return new self(sprintf('The module archive [%s] does not exist.', $path));
    }

    /**
     * Create an exception for an archive that could not be opened.
     */
    public static function unreadable(string $path, int $code): self
    {
        return new self(sprintf(
            'The module archive [%s] could not be read: %s (ZipArchive error %d).',
            $path,
            self::reason($code),
            $code,
        ));
    }

    /**
     * Create an exception for an entry that points outside the archive.
     */
    public static function traversal(string $entry): self
    {
        return new self(sprintf(
            'The module archive contains the entry [%s], which points outside the archive. The archive was rejected.',
            $entry,
        ));
    }

    /**
     * Create an exception for an entry that is a symbolic link.
     */
    public static function symlink(string $entry): self
    {
        return new self(sprintf(
            'The module archive contains the symbolic link [%s]. Modules may not contain links; the archive was rejected.',
            $entry,
        ));
    }

    /**
     * Create an exception for an entry that is neither a file nor a directory.
     */
    public static function unsupportedEntry(string $entry): self
    {
        return new self(sprintf(
            'The module archive contains the entry [%s], which is not a regular file or directory. The archive was rejected.',
            $entry,
        ));
    }

    /**
     * Create an exception for an archive that would expand too far.
     */
    public static function tooLarge(int $bytes, int $limit): self
    {
        return new self(sprintf(
            'The module archive expands to %d bytes, which exceeds the limit of %d bytes.',
            $bytes,
            $limit,
        ));
    }

    public static function tooManyEntries(int $entries, int $limit): self
    {
        return new self(sprintf('The module archive contains %d entries, which exceeds the limit of %d.', $entries, $limit));
    }

    public static function pathTooLong(string $entry, int $limit): self
    {
        return new self(sprintf('The module archive entry [%s] exceeds the path length limit of %d.', $entry, $limit));
    }

    /**
     * Create an exception for an archive without a module directory.
     */
    public static function missingManifest(string $path, string $manifestFile): self
    {
        return new self(sprintf(
            'The module archive [%s] does not contain a [%s] file at its root or in a single top level directory.',
            $path,
            $manifestFile,
        ));
    }

    /**
     * Create an exception for an archive that could not be extracted.
     */
    public static function extractionFailed(string $path): self
    {
        return new self(sprintf('The module archive [%s] could not be extracted.', $path));
    }

    /**
     * Create an exception for an extracted file whose checksum does not match.
     */
    public static function checksumMismatch(string $entry): self
    {
        return new self(sprintf(
            'The module archive entry [%s] does not match its recorded checksum. The archive is corrupt.',
            $entry,
        ));
    }

    /**
     * Describe a ZipArchive error code.
     */
    protected static function reason(int $code): string
    {
        return match ($code) {
            ZipArchive::ER_NOENT => 'the file does not exist',
            ZipArchive::ER_NOZIP => 'the file is not a ZIP archive',
            ZipArchive::ER_READ => 'the file could not be read',
            ZipArchive::ER_OPEN => 'the file could not be opened',
            ZipArchive::ER_INCONS => 'the archive is inconsistent',
            ZipArchive::ER_MEMORY => 'memory allocation failed',
            ZipArchive::ER_INVAL => 'invalid arguments were given',
            default => 'the archive is unusable',
        };
    }
}
