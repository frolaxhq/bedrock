<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Tests\Fixtures\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A seeder that fails at runtime, as one would when its table is missing.
 */
class FailingSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('missing_table')->insert(['name' => 'never inserted']);
    }
}
