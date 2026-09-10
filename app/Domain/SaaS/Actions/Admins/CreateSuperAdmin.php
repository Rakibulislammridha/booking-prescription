<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Admins;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Data\SuperAdminData;
use App\Domain\SaaS\Services\CentralAudit;
use App\Models\Central\SuperAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A new platform operator from the console (the `super:create` command's sibling). Two ways in: a password typed
 * by the creating operator, or none — in which case the row gets an unguessable one and a set-password link goes
 * to the new operator's inbox, so the person who created the account never knows the credential.
 */
final class CreateSuperAdmin
{
    public function __construct(
        private readonly CentralAudit $audit,
        private readonly SendSuperSetPasswordLink $link,
    ) {}

    public function handle(SuperAdminData $data, SuperAdmin $by): SuperAdmin
    {
        $admin = DB::connection('pgsql')->transaction(function () use ($data, $by): SuperAdmin {
            $admin = SuperAdmin::query()->create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->sendsLink() ? Str::random(64) : (string) $data->password,
                'is_active' => true,
                'email_verified_at' => CarbonImmutable::now(),
            ]);

            $this->audit->record(
                CentralAuditAction::Create,
                null,
                $admin,
                null,
                ['name' => $admin->name, 'email' => $admin->email, 'is_active' => true, 'password' => $data->sendsLink() ? 'link' : 'set'],
                $by->id,
            );

            return $admin;
        });

        if ($data->sendsLink()) {
            $this->link->handle($admin, $by);
        }

        return $admin;
    }
}
