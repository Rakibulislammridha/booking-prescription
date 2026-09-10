<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Console;

use App\Domain\SaaS\Support\SuperPassword;
use App\Models\Central\SuperAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * First-boot path for the platform: create (or reactivate) a super admin without tinker.
 *
 * The password may come from the `--password` option for scripted provisioning, or interactively. It is never
 * echoed. When 2FA is required (`saas.two_factor.required`, the default) the operator is forced to enrol on their
 * first login, so nothing here weakens that: this command only gets them to the login page.
 */
final class CreateSuperAdminCommand extends Command
{
    protected $signature = 'super:create
        {--name= : Display name}
        {--email= : Login email (unique)}
        {--password= : Password; prompted when omitted}
        {--reactivate : If the email exists but is inactive, reactivate it and reset the password}';

    protected $description = 'Create a platform super admin (first-boot; no tinker required)';

    public function handle(): int
    {
        $name = (string) ($this->option('name') ?: text('Name', required: true));
        $email = strtolower(trim((string) ($this->option('email') ?: text('Email', required: true))));
        $plain = (string) ($this->option('password') ?: password('Password', required: true));

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $plain],
            ['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255'], 'password' => ['required', SuperPassword::rule()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::INVALID;
        }

        $existing = SuperAdmin::query()->withTrashed()->where('email', $email)->first();

        if ($existing !== null) {
            if (! $this->option('reactivate')) {
                $this->error("A super admin with email {$email} already exists (use --reactivate to restore and reset).");

                return self::FAILURE;
            }

            if ($existing->trashed()) {
                $existing->restore();
            }

            $existing->forceFill(['name' => $name, 'password' => $plain, 'is_active' => true])->save();
            $this->info("Reactivated super admin {$email}.");

            return self::SUCCESS;
        }

        SuperAdmin::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $plain,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->info("Created super admin {$email}. 2FA enrolment is enforced on first login when saas.two_factor.required is true.");

        return self::SUCCESS;
    }
}
