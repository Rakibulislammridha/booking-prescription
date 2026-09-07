<?php

declare(strict_types=1);

namespace Database\Seeders\Central;

use App\Models\Central\SuperAdmin;
use Illuminate\Database\Seeder;

final class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        SuperAdmin::query()->firstOrCreate(
            ['email' => 'super@bp.localhost'],
            ['name' => 'Platform Admin', 'password' => 'password', 'is_active' => true, 'email_verified_at' => now()],
        );
    }
}
