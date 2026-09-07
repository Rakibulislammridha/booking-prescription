<?php

declare(strict_types=1);

namespace App\Domain\Prescription\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Provider-agnostic chat-completion over HTTP (OpenAI-compatible `/chat/completions` JSON): base_url, key, model,
 * timeout (8 s) from config('services.ai'); prompts in resources/ai/prompts/{summary,differentials}.v1.md whose
 * first line carries the template version (stored as the first line of ai_suggestions.prompt).
 */
final class HttpChatAssistant implements AiAssistant
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $key,
        private readonly string $model,
        private readonly int $timeout = 8,
        private readonly string $provider = 'http',
    ) {}

    public function isAvailable(): bool
    {
        return $this->key !== '' && $this->baseUrl !== '';
    }

    public function summariseVisits(VisitSummaryRequest $r): AiResult
    {
        $prompt = self::prompt('summary').PHP_EOL.PHP_EOL.json_encode(['age_years' => $r->ageYears, 'sex' => $r->sex, 'language' => $r->language, 'visits' => $r->visits], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $result = $this->complete($prompt);

        if (! $result->available) {
            return $result;
        }

        $lines = array_values(array_filter(array_map(fn ($l) => Str::limit(trim((string) $l), 140, ''), (array) ($result->payload['lines'] ?? []))));

        return new AiResult(true, ['lines' => array_slice(array_pad($lines, 3, ''), 0, 3)], $result->provider, $result->model, $prompt, $result->rawResponse, $result->inputTokens, $result->outputTokens, $result->latencyMs, $result->requestId);
    }

    public function suggestDifferentials(DifferentialRequest $r): AiResult
    {
        $prompt = self::prompt('differentials').PHP_EOL.PHP_EOL.json_encode(['age_years' => $r->ageYears, 'sex' => $r->sex, 'language' => $r->language, 'chief_complaints' => $r->chiefComplaints, 'examination_findings' => $r->examinationFindings, 'vitals' => $r->vitals], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $result = $this->complete($prompt);

        if (! $result->available) {
            return $result;
        }

        $items = [];

        foreach (array_slice((array) ($result->payload['items'] ?? []), 0, 5) as $item) {
            $items[] = ['label' => (string) ($item['label'] ?? ''), 'icd10_code' => isset($item['icd10_code']) && $item['icd10_code'] !== '' ? strtoupper((string) $item['icd10_code']) : null, 'rationale' => (string) ($item['rationale'] ?? '')];
        }

        return new AiResult(true, ['items' => $items], $result->provider, $result->model, $prompt, $result->rawResponse, $result->inputTokens, $result->outputTokens, $result->latencyMs, $result->requestId);
    }

    private function complete(string $prompt): AiResult
    {
        $started = hrtime(true);
        $response = Http::withToken($this->key)->timeout($this->timeout)->acceptJson()
            ->post(rtrim($this->baseUrl, '/').'/chat/completions', [
                'model' => $this->model,
                'messages' => [['role' => 'system', 'content' => 'You are a clinical documentation assistant. Reply with strict JSON only.'], ['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.2,
                'response_format' => ['type' => 'json_object'],
            ]);

        $latency = (int) ((hrtime(true) - $started) / 1_000_000);

        if (! $response->successful()) {
            return AiResult::unavailable();
        }

        $body = $response->json();
        $content = (string) ($body['choices'][0]['message']['content'] ?? '');
        $payload = json_decode($content, true);

        return new AiResult(
            available: is_array($payload), payload: is_array($payload) ? $payload : [], provider: $this->provider, model: (string) ($body['model'] ?? $this->model),
            prompt: $prompt, rawResponse: $content, inputTokens: isset($body['usage']['prompt_tokens']) ? (int) $body['usage']['prompt_tokens'] : null,
            outputTokens: isset($body['usage']['completion_tokens']) ? (int) $body['usage']['completion_tokens'] : null, latencyMs: $latency,
            requestId: $response->header('x-request-id') !== '' ? $response->header('x-request-id') : (isset($body['id']) ? (string) $body['id'] : null),
        );
    }

    public static function prompt(string $name): string
    {
        $path = resource_path("ai/prompts/{$name}.v1.md");

        return is_file($path) ? trim((string) file_get_contents($path)) : "# {$name}.v1";
    }
}
