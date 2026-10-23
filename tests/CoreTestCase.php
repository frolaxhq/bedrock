<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Tests;

use Frolax\Bedrock\BedrockServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class CoreTestCase extends TestCase
{
    /** @param Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [BedrockServiceProvider::class, LaravelDataServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }
}
