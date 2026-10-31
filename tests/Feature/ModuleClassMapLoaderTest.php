<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Frolax\Bedrock\Services\Module\ComposerModuleAutoloader;
use Frolax\Bedrock\Services\Module\ModuleClassMapLoader;
use Illuminate\Support\Facades\File;

it('inserts private classmaps without exposing later modules or changing existing callback order', function (): void {
    $host = new ClassLoader;
    $host->register();
    $before = spl_autoload_functions();
    $loader = new ModuleClassMapLoader;

    try {
        $loader->registerBefore($host);
        $after = spl_autoload_functions();
        $position = array_search([$host, 'loadClass'], $after, true);
        expect($after[$position - 1])->toBe([$loader, 'autoload']);
        array_splice($after, $position - 1, 1);
        expect(array_values($after))->toBe(array_values($before));
        $loader->addClassMap(['BedrockPrivate\\First' => '/first.php']);
        expect($loader->findFile('BedrockPrivate\\First'))->toBe('/first.php')
            ->and($loader->findFile('BedrockPrivate\\Later'))->toBeFalse();
        $loader->addClassMap(['BedrockPrivate\\Later' => '/later.php', 'BedrockPrivate\\First' => '/updated.php']);
        expect($loader->getClassMap())->toBe(['BedrockPrivate\\First' => '/updated.php', 'BedrockPrivate\\Later' => '/later.php']);
    } finally {
        $loader->unregister();
        $host->unregister();
    }
});

it('preserves public maps, file execution order and private maps across autoloader instances', function (): void {
    $host = new ClassLoader;
    $host->register();
    $before = spl_autoload_functions();
    $path = sys_get_temp_dir().'/bedrock-loader-'.bin2hex(random_bytes(8));
    mkdir($path);
    $namespace = 'BedrockPrivate\\T'.bin2hex(random_bytes(8));
    $public = 'PublicCompiledFixture\\T'.bin2hex(random_bytes(8));
    file_put_contents($path.'/public.php', '<?php namespace PublicCompiledFixture; class '.substr($public, strrpos($public, '\\') + 1).' {}');
    file_put_contents($path.'/one.php', '<?php namespace '.$namespace.'; class One {}');
    file_put_contents($path.'/two.php', '<?php namespace '.$namespace.'; class Two {}');
    file_put_contents($path.'/first-file.php', '<?php $GLOBALS["bedrock_loader_order"] = [class_exists('.var_export($namespace.'\\One', true).'), class_exists('.var_export($namespace.'\\Two', true).')];');
    $make = fn () => new class($host) extends ComposerModuleAutoloader
    {
        public function __construct(protected ClassLoader $host) {}

        protected function loader(): ClassLoader
        {
            return $this->host;
        }
    };

    try {
        $make()->registerLoading(['root' => $path, 'maps' => ['psr4' => [], 'classmap' => [$namespace.'\\One' => $path.'/one.php', $public => $path.'/public.php'], 'files' => [$path.'/first-file.php']]]);
        expect($GLOBALS['bedrock_loader_order'])->toBe([true, false]);
        expect($host->findFile($public))->toBeFalse();
        expect(class_exists($public))->toBeTrue();
        expect(class_exists($namespace.'\\One'))->toBeTrue();
        $make()->registerLoading(['root' => $path, 'maps' => ['psr4' => [], 'classmap' => [$namespace.'\\Two' => $path.'/two.php'], 'files' => []]]);
        expect(class_exists($namespace.'\\Two'))->toBeTrue();
        $added = array_filter(spl_autoload_functions(), fn ($callback) => is_array($callback) && $callback[0] instanceof ModuleClassMapLoader && ! in_array($callback, $before, true));
        expect($added)->toHaveCount(1);
    } finally {
        foreach (spl_autoload_functions() as $callback) {
            if (is_array($callback) && $callback[0] instanceof ModuleClassMapLoader && ! in_array($callback, $before, true)) {
                $callback[0]->unregister();
            }
        }
        $host->unregister();
        File::deleteDirectory($path);
        unset($GLOBALS['bedrock_loader_order']);
    }
});

it('preserves host collisions and later host classmap overrides', function (): void {
    $host = new ClassLoader;
    $host->register();
    $host->addClassMap(['ExistingHostClass' => '/host-original.php']);
    $loader = new ModuleClassMapLoader;

    try {
        $loader->registerBefore($host);
        $loader->addClassMap(['ExistingHostClass' => '/module-override.php', 'NewModuleClass' => '/module.php']);
        expect($host->findFile('ExistingHostClass'))->toBe('/module-override.php')
            ->and($loader->findFile('ExistingHostClass'))->toBeFalse()
            ->and($loader->findFile('NewModuleClass'))->toBe('/module.php');
        $host->addClassMap(['NewModuleClass' => '/host-later.php']);
        expect($loader->findFile('NewModuleClass'))->toBeFalse()
            ->and($host->findFile('NewModuleClass'))->toBe('/host-later.php');
        $loader->addClassMap(['NewModuleClass' => '/module-later.php']);
        expect($host->findFile('NewModuleClass'))->toBe('/module-later.php');
    } finally {
        $loader->unregister();
        $host->unregister();
    }
});

it('activates compiled namespace chunks incrementally with last registration winning', function (): void {
    $host = new ClassLoader;
    $host->register();
    $loader = new ModuleClassMapLoader;

    try {
        $loader->registerBefore($host);
        $loader->addGroupedClassMap(['Same\\Group' => ['Same\\Group\\First' => '/first.php', 'Same\\Group\\Shared' => '/old.php']]);
        expect($loader->findFile('Same\\Group\\Later'))->toBeFalse();
        $loader->addGroupedClassMap(['Same\\Group' => ['Same\\Group\\Later' => '/later.php', 'Same\\Group\\Shared' => '/new.php']]);
        expect($loader->findFile('Same\\Group\\First'))->toBe('/first.php')
            ->and($loader->findFile('Same\\Group\\Shared'))->toBe('/new.php')
            ->and($loader->getClassMap()['Same\\Group\\Later'])->toBe('/later.php');
        $host->addClassMap(['Same\\Group\\Shared' => '/host.php']);
        expect($loader->findFile('Same\\Group\\Shared'))->toBeFalse();
        $loader->addGroupedClassMap(['Same\\Group' => ['Same\\Group\\Shared' => '/module-again.php']]);
        expect($host->findFile('Same\\Group\\Shared'))->toBe('/module-again.php');
    } finally {
        $loader->unregister();
        $host->unregister();
    }
});
