<?php

declare(strict_types=1);
use App\Domain\Audit\AuditServiceProvider;
use App\Domain\Billing\BillingServiceProvider;
use App\Domain\Booking\BookingServiceProvider;
use App\Domain\Catalog\CatalogServiceProvider;
use App\Domain\Clinic\ClinicServiceProvider;
use App\Domain\Notifications\NotificationsServiceProvider;
use App\Domain\Patients\PatientsServiceProvider;
use App\Domain\Prescription\PrescriptionServiceProvider;
use App\Domain\Queue\QueueServiceProvider;
use App\Domain\Reception\ReceptionServiceProvider;
use App\Domain\Reports\ReportsServiceProvider;
use App\Domain\Scheduling\SchedulingServiceProvider;
use App\Domain\Serials\SerialsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Tenancy\TenancyServiceProvider;

return [
    TenancyServiceProvider::class,
    AppServiceProvider::class,
    AuthServiceProvider::class,
    HorizonServiceProvider::class,
    AuditServiceProvider::class,
    ClinicServiceProvider::class,
    CatalogServiceProvider::class,
    PatientsServiceProvider::class,
    SchedulingServiceProvider::class,
    SerialsServiceProvider::class,
    BookingServiceProvider::class,
    ReceptionServiceProvider::class,
    PrescriptionServiceProvider::class,
    QueueServiceProvider::class,
    BillingServiceProvider::class,
    NotificationsServiceProvider::class,
    ReportsServiceProvider::class,
];
