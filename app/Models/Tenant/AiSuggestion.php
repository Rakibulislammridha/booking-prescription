<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Prescription\Enums\AiSuggestionType;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\AiSuggestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every AI-assist call and whether the doctor accepted it (SCHEMA §3.4). prompt/response/accepted_fragment are ENC.
 *
 * @property int $id
 * @property int $visit_id
 * @property int $doctor_id
 * @property int $patient_id
 * @property AiSuggestionType $type
 * @property string $provider
 * @property string $model
 * @property string $prompt
 * @property string $response
 * @property bool|null $accepted
 * @property CarbonImmutable|null $accepted_at
 * @property string|null $accepted_fragment
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $latency_ms
 * @property string|null $request_id
 * @property CarbonImmutable|null $created_at
 * @property-read Visit $visit
 */
final class AiSuggestion extends TenantModel
{
    /** @use HasFactory<AiSuggestionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static string $factory = AiSuggestionFactory::class;

    protected $table = 'ai_suggestions';

    protected $fillable = [
        'visit_id', 'doctor_id', 'patient_id', 'type', 'provider', 'model', 'prompt', 'response', 'accepted', 'accepted_at',
        'accepted_fragment', 'input_tokens', 'output_tokens', 'latency_ms', 'request_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AiSuggestionType::class, 'prompt' => 'encrypted', 'response' => 'encrypted', 'accepted' => 'boolean',
            'accepted_at' => 'immutable_datetime', 'accepted_fragment' => 'encrypted', 'input_tokens' => 'integer',
            'output_tokens' => 'integer', 'latency_ms' => 'integer', 'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /**
     * The decoded response payload (lines / items).
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $decoded = json_decode($this->response, true);

        return is_array($decoded) ? $decoded : [];
    }
}
