<?php

declare(strict_types=1);

namespace App\Domain\Patients\Services;

use App\Domain\Patients\Contracts\OcrEngine;
use App\Domain\Patients\Exceptions\OcrEngineUnavailable;
use App\Domain\Patients\Exceptions\OcrFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google Cloud Vision, against its documented REST shape (PRESCRIPTION.md §8 — the "real OCR driver"):
 *
 *   POST https://vision.googleapis.com/v1/images:annotate?key=<API key>
 *   {"requests":[{"image":{"content":"<base64>"},
 *                 "features":[{"type":"DOCUMENT_TEXT_DETECTION"}],
 *                 "imageContext":{"languageHints":["bn","en"]}}]}
 *   200: {"responses":[{"fullTextAnnotation":{"text":"…"}}]}   |   {"responses":[{"error":{"code":3,…}}]}
 *
 * DOCUMENT_TEXT_DETECTION (not TEXT_DETECTION) is the dense-document model — a lab report is a page of small
 * print in two scripts, and `languageHints` decides whether বাংলা comes back as Bangla or as noise.
 *
 * Two rules this class exists to keep. First, the API key travels in the QUERY STRING, so the request URL is a
 * credential: it is never logged, and a client exception — whose message quotes the full URL — is caught and
 * replaced rather than chained, or the key would land in the worker's failed-job log. Second, the document is
 * clinical data: neither the bytes nor the recognised text are ever logged, only a status code and a mime type.
 */
final class GoogleVisionOcrEngine implements OcrEngine
{
    public const DEFAULT_ENDPOINT = 'https://vision.googleapis.com/v1/images:annotate';

    /** What images:annotate accepts. A PDF needs files:asyncBatchAnnotate + a GCS bucket, so it is not sent. */
    private const SUPPORTED = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif', 'image/bmp'];

    public function __construct(
        private readonly string $key,
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
        private readonly int $timeout = 15,
    ) {}

    public function isConfigured(): bool
    {
        return $this->key !== '' && $this->endpoint !== '';
    }

    public function supports(string $mimeType): bool
    {
        return in_array(strtolower($mimeType), self::SUPPORTED, true);
    }

    public function text(string $bytes, string $mimeType): ?string
    {
        if (! $this->isConfigured() || ! $this->supports($mimeType)) {
            return null;
        }

        $response = $this->annotate($bytes);

        if ($response->status() === 429 || $response->serverError()) {
            Log::warning('patients.ocr.vision_unavailable', ['status' => $response->status(), 'mime_type' => $mimeType]);

            throw new OcrEngineUnavailable('google_vision: HTTP '.$response->status());
        }

        if ($response->failed()) {
            Log::warning('patients.ocr.vision_refused', ['status' => $response->status(), 'mime_type' => $mimeType]);

            throw new OcrFailed('google_vision: HTTP '.$response->status());
        }

        $body = $response->json();
        $first = is_array($body) && is_array($body['responses'][0] ?? null) ? $body['responses'][0] : null;

        if ($first === null) {
            throw new OcrFailed('google_vision: malformed response');
        }

        if (isset($first['error'])) {
            $code = is_array($first['error']) && is_scalar($first['error']['code'] ?? null) ? (string) $first['error']['code'] : '?';
            Log::warning('patients.ocr.vision_error', ['code' => $code, 'mime_type' => $mimeType]);

            throw new OcrFailed('google_vision: the image was rejected (code '.$code.')');
        }

        $text = is_array($first['fullTextAnnotation'] ?? null) ? $first['fullTextAnnotation']['text'] ?? null : null;

        return is_string($text) && trim($text) !== '' ? $text : null;
    }

    private function annotate(string $bytes): Response
    {
        try {
            return Http::asJson()
                ->timeout($this->timeout)
                ->retry(2, 250, $this->worthRetrying(...), throw: false)
                ->post($this->endpoint.'?key='.urlencode($this->key), [
                    'requests' => [[
                        'image' => ['content' => base64_encode($bytes)],
                        'features' => [['type' => 'DOCUMENT_TEXT_DETECTION']],
                        'imageContext' => ['languageHints' => ['bn', 'en']],
                    ]],
                ]);
        } catch (ConnectionException) {
            // Not chained on purpose: the cURL message quotes the request URL, and the URL carries the API key.
            Log::warning('patients.ocr.vision_unreachable', []);

            throw new OcrEngineUnavailable('google_vision: the endpoint could not be reached');
        }
    }

    /** Only a blip is worth a second attempt: a rejected key or an unreadable image says the same thing twice. */
    private function worthRetrying(Throwable $e): bool
    {
        if ($e instanceof RequestException) {
            return $e->response->status() === 429 || $e->response->serverError();
        }

        return $e instanceof ConnectionException;
    }
}
