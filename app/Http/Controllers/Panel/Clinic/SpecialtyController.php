<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Clinic;

use App\Domain\Clinic\Actions\CreateSpecialty;
use App\Domain\Clinic\Actions\DeleteSpecialty;
use App\Domain\Clinic\Actions\UpdateSpecialty;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Clinic\StoreSpecialtyRequest;
use App\Http\Requests\Panel\Clinic\UpdateSpecialtyRequest;
use App\Http\Resources\Clinic\SpecialtyResource;
use App\Models\Tenant\Specialty;
use App\Models\Tenant\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BRIEF §5.A — specialties, in English and Bangla (`specialties.name_bn`). The Bangla name is what the public
 * booking site shows a patient searching for "হৃদরোগ".
 */
final class SpecialtyController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Specialty::class);
        /** @var User $user */
        $user = $request->user('web');

        return Inertia::render('Clinic/Specialties/Index', [
            'specialties' => SpecialtyResource::collection(
                Specialty::query()->withCount('doctorSpecialties')->orderBy('sort_order')->orderBy('name')->get()
            )->resolve(),
            'can' => ['manage' => $user->can('create', Specialty::class)],
        ]);
    }

    public function store(StoreSpecialtyRequest $request, CreateSpecialty $create): RedirectResponse
    {
        $specialty = $create->handle($request->toData(), Actor::fromRequest($request));

        return redirect()->route('panel.clinic.specialties.index')->with('flash.success', __('clinic.specialties.flash.created', ['name' => $specialty->name]));
    }

    public function update(UpdateSpecialtyRequest $request, Specialty $specialty, UpdateSpecialty $update): RedirectResponse
    {
        $update->handle($specialty, $request->toData(), Actor::fromRequest($request));

        return redirect()->route('panel.clinic.specialties.index')->with('flash.success', __('clinic.specialties.flash.updated', ['name' => $specialty->name]));
    }

    public function destroy(Request $request, Specialty $specialty, DeleteSpecialty $delete): RedirectResponse
    {
        $this->authorize('delete', $specialty);
        $delete->handle($specialty, Actor::fromRequest($request));

        return redirect()->route('panel.clinic.specialties.index')->with('flash.success', __('clinic.specialties.flash.deleted', ['name' => $specialty->name]));
    }
}
