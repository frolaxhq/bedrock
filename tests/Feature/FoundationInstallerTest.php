<?php

declare(strict_types=1);

use Frolax\Bedrock\Services\Installation\FoundationInstaller;
use Illuminate\Filesystem\Filesystem;

it('prepares a fresh application repeatably and refuses edited generated files', function () {
    $root = $this->tempDirectory('foundation-installer');
    $files = new Filesystem;
    $files->ensureDirectoryExists($root.'/app/Models');
    $files->put($root.'/app/Models/User.php', '<?php namespace App\\Models; class User extends \\Illuminate\\Foundation\\Auth\\User { }');
    $files->put($root.'/package.json', '{"private":true,"type":"module"}');

    try {
        $installer = new FoundationInstaller($files, $root);
        expect($installer->inspect()['problems'])->toBe([]);
        expect($files->exists($root.'/config/bedrock.php'))->toBeFalse();
        $installer->install();
        expect($installer->inspect()['changes'])->toBe([])
            ->and($files->get($root.'/app/Models/User.php'))->toContain('HasRoles');
        $installer->install();
        $files->append($root.'/resources/js/bedrock.tsx', '\n// customized');
        expect($installer->inspect()['problems'])->not->toBeEmpty();
    } finally {
        $files->deleteDirectory($root);
    }
});

it('reports conflicting application configuration without writing partial files', function () {
    $root = $this->tempDirectory('foundation-conflict');
    $files = new Filesystem;
    $files->ensureDirectoryExists($root.'/config');
    $files->put($root.'/config/bedrock.php', '<?php return ["custom" => true];');

    try {
        $installer = new FoundationInstaller($files, $root);
        expect(fn () => $installer->install())->toThrow(RuntimeException::class);
        expect($files->exists($root.'/resources/js/bedrock.tsx'))->toBeFalse();
    } finally {
        $files->deleteDirectory($root);
    }
});

it('does not mistake a HasRoles import or comment for an applied trait', function () {
    $root = $this->tempDirectory('foundation-user-trait');
    $files = new Filesystem;
    $files->ensureDirectoryExists($root.'/app/Models');
    $files->put($root.'/app/Models/User.php', <<<'USER'
    <?php
    namespace App\Models;
    use Spatie\Permission\Traits\HasRoles;
    // class User extends Base implements MustVerifyEmail { use HasRoles; }
    class User extends \Illuminate\Foundation\Auth\User
    {
        // TODO: enable HasRoles and MustVerifyEmail.
    }
    USER);

    try {
        (new FoundationInstaller($files, $root))->install();
        $source = $files->get($root.'/app/Models/User.php');
        expect($source)->toContain('use \\Spatie\\Permission\\Traits\\HasRoles;')
            ->toContain('implements \\Illuminate\\Contracts\\Auth\\MustVerifyEmail');
        // A second run must not insert another trait or interface.
        (new FoundationInstaller($files, $root))->install();
        expect($files->get($root.'/app/Models/User.php'))->toBe($source);
    } finally {
        $files->deleteDirectory($root);
    }
});

it('rejects malformed package metadata and incompatible toolchains before writing files', function (string $metadata) {
    $root = $this->tempDirectory('foundation-toolchain');
    $files = new Filesystem;
    $files->ensureDirectoryExists($root.'/app/Models');
    $files->put($root.'/app/Models/User.php', '<?php namespace App\\Models; class User extends \\Illuminate\\Foundation\\Auth\\User {}');
    $files->put($root.'/package.json', $metadata);

    try {
        $installer = new FoundationInstaller($files, $root);
        expect($installer->inspect()['problems'])->not->toBeEmpty();
        expect(fn () => $installer->install())->toThrow(RuntimeException::class);
        expect($files->exists($root.'/config/bedrock.php'))->toBeFalse();
    } finally {
        $files->deleteDirectory($root);
    }
})->with(['null', '[]', '{"dependencies":"invalid"}', '{"devDependencies":{"vite-plus":"2.0.0"}}']);
