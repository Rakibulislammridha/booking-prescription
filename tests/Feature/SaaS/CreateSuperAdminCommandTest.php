<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Models\Central\SuperAdmin;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CreateSuperAdminCommandTest extends TestCase
{
    public function test_it_creates_an_active_super_admin_with_a_hashed_password(): void
    {
        $this->artisan('super:create', ['--name' => 'Ops', '--email' => 'Ops@Example.test', '--password' => 'correct-horse-battery-9'])
            ->assertSuccessful();

        $admin = SuperAdmin::query()->where('email', 'ops@example.test')->firstOrFail();
        $this->assertTrue($admin->is_active);
        $this->assertNotSame('correct-horse-battery-9', $admin->password);
        $this->assertTrue(Hash::check('correct-horse-battery-9', $admin->password));
    }

    public function test_it_refuses_a_duplicate_email_unless_reactivating(): void
    {
        SuperAdmin::factory()->create(['email' => 'dup@example.test', 'is_active' => false]);

        $this->artisan('super:create', ['--name' => 'X', '--email' => 'dup@example.test', '--password' => 'correct-horse-battery-9'])
            ->assertFailed();

        $this->artisan('super:create', ['--name' => 'X', '--email' => 'dup@example.test', '--password' => 'correct-horse-battery-9', '--reactivate' => true])
            ->assertSuccessful();

        $this->assertTrue(SuperAdmin::query()->where('email', 'dup@example.test')->firstOrFail()->is_active);
    }

    public function test_it_rejects_a_weak_password(): void
    {
        $this->artisan('super:create', ['--name' => 'X', '--email' => 'weak@example.test', '--password' => 'short'])
            ->assertExitCode(2);

        $this->assertDatabaseMissing('public.super_admins', ['email' => 'weak@example.test']);
    }
}
