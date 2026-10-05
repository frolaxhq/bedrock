<?php

declare(strict_types=1);

use Frolax\Bedrock\BedrockServiceProvider;
use Frolax\Bedrock\Providers\Module\ModuleServiceProvider;

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('Frolax\Bedrock')
    ->toUseStrictTypes();

/**
 * Package capability providers live directly in `src/Providers`; extension
 * points used by module authors live in the `Providers\Module` namespace.
 */
function bedrockRegisteredProviders(): array
{
    $property = (new ReflectionClass(BedrockServiceProvider::class))->getProperty('providers');

    /** @var list<class-string> $providers */
    $providers = $property->getValue(new BedrockServiceProvider(app()));

    return $providers;
}

it('wires every provider that sits directly in the providers directory', function () {
    $files = glob(dirname(__DIR__).'/src/Providers/*.php') ?: [];

    expect($files)->not->toBeEmpty();

    $classes = array_map(
        static fn (string $file): string => 'Frolax\\Bedrock\\Providers\\'.basename($file, '.php'),
        $files,
    );

    // Every top level provider is concrete and is registered by the entry point.
    expect($classes)->each->toBeIn(bedrockRegisteredProviders());

    foreach ($classes as $class) {
        expect((new ReflectionClass($class))->isAbstract())->toBeFalse();
    }
});

it('keeps the module extension point in its own directory and unregistered', function () {
    $files = glob(dirname(__DIR__).'/src/Providers/Module/*.php') ?: [];

    expect(array_map('basename', $files))->toBe(['ModuleServiceProvider.php']);

    $reflection = new ReflectionClass(ModuleServiceProvider::class);

    // Abstract, because a module extends it; unregistered, because it is not wiring.
    expect($reflection->isAbstract())->toBeTrue()
        ->and(bedrockRegisteredProviders())->not->toContain($reflection->getName());
});
