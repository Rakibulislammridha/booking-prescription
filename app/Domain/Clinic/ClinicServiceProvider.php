<?php

declare(strict_types=1);

namespace App\Domain\Clinic;

use App\Domain\Clinic\Listeners\ForgetStaffSession;
use App\Domain\Clinic\Listeners\RecordStaffSession;
use App\Domain\Clinic\Policies\BranchPolicy;
use App\Domain\Clinic\Policies\DepartmentPolicy;
use App\Domain\Clinic\Policies\DoctorLeavePolicy;
use App\Domain\Clinic\Policies\DoctorPolicy;
use App\Domain\Clinic\Policies\HolidayPolicy;
use App\Domain\Clinic\Policies\SettingPolicy;
use App\Domain\Clinic\Policies\SpecialtyPolicy;
use App\Domain\Clinic\Policies\UserPolicy;
use App\Domain\Clinic\Services\ActiveBranch;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Clinic\Services\StaffSessionIndex;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Department;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorLeave;
use App\Models\Tenant\Holiday;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Specialty;
use App\Models\Tenant\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class ClinicServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActiveBranch::class);
        $this->app->singleton(Settings::class);
        $this->app->singleton(StaffSessionIndex::class);
    }

    public function boot(): void
    {
        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(Specialty::class, SpecialtyPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Doctor::class, DoctorPolicy::class);
        Gate::policy(Holiday::class, HolidayPolicy::class);
        Gate::policy(DoctorLeave::class, DoctorLeavePolicy::class);
        Gate::policy(Setting::class, SettingPolicy::class);

        // Staff device management (BRIEF §5.N, ARCHITECTURE §6.1): the `sessions_by_user` index is maintained from
        // the auth events, so every way of logging in — form, remember-me recaller, Auth::login — is covered.
        Event::listen(Login::class, RecordStaffSession::class);
        Event::listen(Logout::class, ForgetStaffSession::class);
    }
}
