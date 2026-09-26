<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Console\Commands;

use Illuminate\Console\Command;

class BedrockCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'bedrock:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package bedrock.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('Bedrock placeholder command executed.');

        return self::SUCCESS;
    }
}
