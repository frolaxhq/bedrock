<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands\Administration;

use Frolax\Bedrock\Services\Administration\AdministrationManager;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

class AdminCreateCommand extends Command
{
    protected $signature = 'bedrock:admin-create';

    protected $description = 'Interactively create the first Bedrock administrator';

    public function handle(AdministrationManager $administration): int
    {
        if (! $this->input->isInteractive()) {
            $this->components->error('Run interactively, or grant an existing user access with bedrock:admin-user.');

            return self::FAILURE;
        }

        try {
            $model = $administration->userModel();
            $data = ['name' => $this->ask('Name'), 'email' => $this->ask('Email'), 'password' => $this->secret('Password'), 'password_confirmation' => $this->secret('Confirm password')];
            Validator::make($data, ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:'.$model.',email'], 'password' => ['required', 'confirmed', Password::defaults()]])->validate();
            DB::transaction(function () use ($model, $data, $administration): void {
                /** @var Model $user */
                $user = new $model;
                $user->forceFill(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])])->save();
                $administration->grantAdministrator($user);
            });
            $this->components->info('Administrator created. Sign in at /login.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
