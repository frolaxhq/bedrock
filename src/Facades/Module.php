<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Facades;

use Frolax\Bedrock\Contracts\Module\ModuleSource;
use Frolax\Bedrock\DataObjects\Module\DiagnosticReport;
use Frolax\Bedrock\DataObjects\Module\ModuleDiscoveryResult;
use Frolax\Bedrock\Models\Module as ModuleModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Collection<int, ModuleModel> all()
 * @method static Collection<int, ModuleModel> enabled()
 * @method static ModuleModel|null find(string $key)
 * @method static bool isEnabled(string $key)
 * @method static ModuleDiscoveryResult sync()
 * @method static bool upgrade(string $key)
 * @method static bool install(string $key)
 * @method static bool installFrom(ModuleSource $source, string $key)
 * @method static bool uninstall(string $key)
 * @method static DiagnosticReport diagnose(?string $key = null)
 * @method static bool enable(string $key)
 * @method static bool disable(string $key)
 * @method static void refresh()
 * @method static string path()
 *
 * @see \Frolax\Bedrock\Module
 */
class Module extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Frolax\Bedrock\Module::class;
    }
}
