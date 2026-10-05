<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Exceptions\Module;

use Frolax\Bedrock\Exceptions\BedrockException;

class InvalidManifestException extends BedrockException
{
    /**
     * Create an exception for a manifest that is not valid JSON.
     */
    public static function malformedJson(string $detail): self
    {
        return new self(sprintf('The module manifest is not valid JSON: %s.', $detail));
    }

    /**
     * Create an exception for a manifest that is missing a required field.
     */
    public static function missingField(string $field): self
    {
        return new self(sprintf('The module manifest is missing the required [%s] field.', $field));
    }

    /**
     * Create an exception for a manifest field with the wrong type.
     */
    public static function invalidType(string $field, string $expected): self
    {
        return new self(sprintf('The module manifest field [%s] must be %s.', $field, $expected));
    }

    /**
     * Create an exception for a module key that does not follow the convention.
     */
    public static function invalidKey(string $key): self
    {
        return new self(sprintf(
            'The module key [%s] is invalid; keys start with a letter and may contain lowercase letters, digits and dashes.',
            $key,
        ));
    }

    /**
     * Create an exception for a module version that is not semantic.
     */
    public static function invalidVersion(string $version): self
    {
        return new self(sprintf('The module version [%s] is not a semantic version such as [1.0.0].', $version));
    }

    /**
     * Create an exception for a module dependency that is not valid.
     */
    public static function invalidRequires(string $key): self
    {
        return new self(sprintf(
            'The module dependency [%s] is invalid; dependencies map a module key to a version constraint.',
            $key,
        ));
    }

    /**
     * Create an exception for an invalid Bedrock core constraint.
     */
    public static function invalidCoreConstraint(string $constraint): self
    {
        return new self(sprintf(
            'The module core constraint [%s] is not a valid Composer version constraint.',
            $constraint,
        ));
    }

    public static function unsupportedSchema(int $schema): self
    {
        return new self(sprintf('The module manifest schema [%d] is not supported.', $schema));
    }

    public static function invalidProvider(string $provider): self
    {
        return new self(sprintf('The module provider [%s] is not a valid class name.', $provider));
    }

    public static function invalidAutoload(string $value): self
    {
        return new self(sprintf('The module autoload declaration [%s] is invalid.', $value));
    }

    public static function invalidComposerRequirement(string $package): self
    {
        return new self(sprintf('The module Composer requirement [%s] is invalid.', $package));
    }

    public static function frontendNavigationMoved(): self
    {
        return new self(
            'The module manifest field [frontend.navigation] is no longer supported; define navigation in the TypeScript module entry and rebuild the frontend artifact.',
        );
    }
}
