<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Administration;

use Frolax\Bedrock\Services\Administration\AdministrationManager;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class AdminUserCommand extends Command
{
    protected $signature = 'bedrock:admin-user {identifier : A user primary key or email address}';

    protected $description = 'Grant an existing user the protected Bedrock administrator role';

    public function handle(AdministrationManager $administration): int
    {
        try {
            $model = $administration->userModel();
            /** @var Model $query */
            $query = $model::query();
            $identifier = $this->argument('identifier');
            /** @var Model|null $user */
            $user = $query->whereKey($identifier)->orWhere('email', $identifier)->first();

            if ($user === null) {
                $this->components->error(sprintf('No user matched [%s].', $identifier));

                return self::FAILURE;
            }

            $administration->grantAdministrator($user);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('The user now has the Bedrock administrator role.');

        return self::SUCCESS;
    }
}
