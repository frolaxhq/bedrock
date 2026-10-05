<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Tests\Fixtures\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ItemsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('module_items')->insert(['name' => 'seeded']);
    }
}
