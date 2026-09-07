<?php

declare(strict_types=1);

namespace App\Domain\Patients\Data;

use App\Domain\Patients\Enums\ConsentChannel;
use App\Domain\Patients\Enums\ConsentStatus;
use App\Domain\Patients\Enums\ConsentType;
use Illuminate\Foundation\Http\FormRequest;

final readonly class ConsentData
{
    /** @param  array<string, mixed>  $evidence */
    public function __construct(
        public ConsentType $type,
        public ConsentStatus $status,
        public string $policyVersion,
        public ConsentChannel $channel,
        public ?string $signatureData = null,
        public array $evidence = [],
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();

        return new self(
            type: ConsentType::from((string) $v['type']),
            status: ConsentStatus::from((string) $v['status']),
            policyVersion: (string) $v['policy_version'],
            channel: isset($v['channel']) ? ConsentChannel::from((string) $v['channel']) : ConsentChannel::Counter,
            signatureData: isset($v['signature_data']) && $v['signature_data'] !== '' ? (string) $v['signature_data'] : null,
            evidence: ['otp_verified' => (bool) ($v['otp_verified'] ?? false), 'text_shown' => (string) ($v['text_shown'] ?? '')],
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }
}
