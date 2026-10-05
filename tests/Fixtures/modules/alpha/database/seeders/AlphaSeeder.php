<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Tests\Fixtures\Modules\Alpha\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AlphaSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('alpha_items')->insert([
            'name' => 'seeded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
