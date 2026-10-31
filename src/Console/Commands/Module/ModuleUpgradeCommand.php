<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Module;

use Frolax\Bedrock\Exceptions\BedrockException;

class ModuleUpgradeCommand extends ModuleCommand
{
    protected $signature = 'module:upgrade {key : The inactive module whose new code is already deployed} {--offline : Never download dependencies or tooling}';

    protected $description = 'Upgrade an inactive module from deployed code and run pending migrations without seeders';

    public function handle(): int
    {
        /** @var string $key */
        $key = $this->argument('key');
        $offline = config('bedrock.modules.php.offline');
        config()->set('bedrock.modules.php.offline', (bool) $offline || (bool) $this->option('offline'));

        try {
            $this->manager()->upgrade($key);
        } catch (BedrockException $exception) {
            return $this->failure($exception->getMessage());
        } finally {
            config()->set('bedrock.modules.php.offline', $offline);
        }

        $this->components->info(sprintf('Upgraded module [%s]. Rebuild deployment caches and reload application workers before enabling it.', $key));

        return self::SUCCESS;
    }
}
