<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Shared\Actor;
use App\Models\Tenant\User;

final class UpdateSetting
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(string $key, mixed $value, Actor $actor): mixed
    {
        $by = $actor->userId !== null ? User::query()->find($actor->userId) : null;

        $this->settings->set($key, $value, $by);

        return $this->settings->get($key);
    }
}
