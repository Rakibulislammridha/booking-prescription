<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Patients;

use App\Domain\Patients\Services\MobileNumber;
use App\Domain\Patients\Services\PatientSearch;
use App\Http\Controllers\Controller;
use App\Http\Resources\Patients\FamilyMemberResource;
use App\Models\Tenant\Patient;
use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** GET /api/patients/by-mobile/{mobile} — the household on a number (owner first), for the booking flow. */
final class PatientByMobileController extends Controller
{
    public function __invoke(Request $request, string $mobile, PatientSearch $search): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Patient::class);
        /** @var User $user */
        $user = $request->user('web');
        $household = $search->household($mobile, $user)->load('primaryRelation');

        return FamilyMemberResource::collection($household)
            ->additional(['meta' => ['mobile' => MobileNumber::tryNormalize($mobile), 'valid' => MobileNumber::isValid($mobile)]]);
    }
}
