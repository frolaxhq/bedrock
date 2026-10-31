<?php

declare(strict_types=1);

use Frolax\Bedrock\Models\Module;
use Frolax\Bedrock\Services\Module\CompiledModuleBoot;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Module::query()->count();
    app()->detectEnvironment(fn (): string => 'production');
    config(['bedrock.modules.boot.compiled' => true, 'bedrock.modules.php.validation.release' => 'test-'.bin2hex(random_bytes(8))]);
});

it('publishes a verified artifact and invalidates it on registry clear', function (): void {
    $this->artisan('module:cache', ['--warm' => true])->assertFailed();
    config(['bedrock.modules.php.validation.cache' => true]);
    $this->artisan('module:cache', ['--warm' => true])->assertSuccessful();
    expect(app(CompiledModuleBoot::class)->read())->toMatchArray(['schema' => 3, 'modules' => []]);
    $this->artisan('module:clear')->assertSuccessful();
    expect(app(CompiledModuleBoot::class)->read())->toBeNull();
});

it('rejects release and environment mismatches and missing artifacts', function (): void {
    $boot = app(CompiledModuleBoot::class);
    $boot->invalidate();
    expect($boot->read())->toBeNull();
    $boot->warm();
    expect($boot->read())->not->toBeNull();
    config(['bedrock.modules.php.validation.release' => 'different']);
    expect($boot->read())->toBeNull();
    app()->detectEnvironment(fn (): string => 'local');
    expect($boot->enabled())->toBeFalse();
});

it('falls back safely when the generation cache is unavailable', function (): void {
    Cache::shouldReceive('store')->andThrow(new RuntimeException('offline'));
    expect(app(CompiledModuleBoot::class)->read())->toBeNull();
    expect(fn () => app(CompiledModuleBoot::class)->invalidate())->toThrow(RuntimeException::class);
});

afterEach(function (): void {
    app()->detectEnvironment(fn (): string => 'testing');
});

it('rejects corrupt artifacts and never overwrites a published filename', function (): void {
    $boot = app(CompiledModuleBoot::class);
    $boot->invalidate();
    $before = glob(app()->bootstrapPath('cache/bedrock-*.php'));
    $boot->warm();
    $files = array_values(array_diff(glob(app()->bootstrapPath('cache/bedrock-*.php')), $before));
    expect($files)->toHaveCount(1);
    expect(fn () => $boot->warm())->toThrow(RuntimeException::class, 'Clear');
    file_put_contents($files[0], '<?php return ["schema" => 1];');
    expect((new CompiledModuleBoot)->read())->toBeNull();
    unlink($files[0]);
});

it('refuses to publish when the generation changes during compilation', function (): void {
    $store = Mockery::mock(Repository::class);
    $store->shouldReceive('get')->twice()->andReturn((string) Str::uuid(), (string) Str::uuid());
    $boot = Mockery::mock(CompiledModuleBoot::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $boot->shouldReceive('store')->andReturn($store);
    expect(fn () => $boot->warm())->toThrow(RuntimeException::class, 'changed during compilation');
});
