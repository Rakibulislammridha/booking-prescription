<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Data;

use App\Domain\Clinic\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final readonly class DoctorData
{
    /** @param  array<int, int>  $specialtyIds */
    public function __construct(
        public string $name,
        public string $slug,
        public string $code,
        public DoctorProfileData $profile,
        public ?string $nameBn = null,
        public ?Gender $gender = null,
        public ?string $mobile = null,
        public ?string $email = null,
        public ?int $departmentId = null,
        public ?int $userId = null,
        public ?string $roomLabel = null,
        public bool $isActive = true,
        public bool $acceptsOnlineBooking = true,
        public bool $acceptsTelemedicine = false,
        public int $sortOrder = 0,
        public array $specialtyIds = [],
        public ?int $primarySpecialtyId = null,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();

        return new self(
            name: (string) $v['name'],
            slug: (string) ($v['slug'] ?? Str::slug((string) $v['name'])),
            code: strtoupper((string) $v['code']),
            profile: DoctorProfileData::fromArray($v['profile'] ?? []),
            nameBn: $v['name_bn'] ?? null,
            gender: isset($v['gender']) ? Gender::from((string) $v['gender']) : null,
            mobile: $v['mobile'] ?? null,
            email: $v['email'] ?? null,
            departmentId: isset($v['department_id']) ? (int) $v['department_id'] : null,
            userId: isset($v['user_id']) ? (int) $v['user_id'] : null,
            roomLabel: $v['room_label'] ?? null,
            isActive: (bool) ($v['is_active'] ?? true),
            acceptsOnlineBooking: (bool) ($v['accepts_online_booking'] ?? true),
            acceptsTelemedicine: (bool) ($v['accepts_telemedicine'] ?? false),
            sortOrder: (int) ($v['sort_order'] ?? 0),
            specialtyIds: array_map('intval', $v['specialty_ids'] ?? []),
            primarySpecialtyId: isset($v['primary_specialty_id']) ? (int) $v['primary_specialty_id'] : null,
        );
    }

    /** The doctor form may create the staff login as part of the same submit; the id is only known afterwards. */
    public function withUserId(int $userId): self
    {
        return new self(
            $this->name, $this->slug, $this->code, $this->profile, $this->nameBn, $this->gender, $this->mobile, $this->email,
            $this->departmentId, $userId, $this->roomLabel, $this->isActive, $this->acceptsOnlineBooking,
            $this->acceptsTelemedicine, $this->sortOrder, $this->specialtyIds, $this->primarySpecialtyId,
        );
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name, 'name_bn' => $this->nameBn, 'slug' => $this->slug, 'code' => $this->code, 'gender' => $this->gender,
            'mobile' => $this->mobile, 'email' => $this->email, 'department_id' => $this->departmentId, 'user_id' => $this->userId,
            'room_label' => $this->roomLabel, 'is_active' => $this->isActive, 'accepts_online_booking' => $this->acceptsOnlineBooking,
            'accepts_telemedicine' => $this->acceptsTelemedicine, 'sort_order' => $this->sortOrder,
        ];
    }
}
