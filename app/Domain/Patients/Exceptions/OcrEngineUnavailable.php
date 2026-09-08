<?php

declare(strict_types=1);

namespace App\Domain\Patients\Exceptions;

use RuntimeException;

/**
 * The engine could not be reached — connection error, timeout, HTTP 429/5xx. Deliberately NOT caught by the
 * namer: it escapes so NameUploadedDocument's $tries/$backoff retry the document later, and the job's failed()
 * hook settles it on `failed` once the attempts are spent. A clinic's report is never lost to a bad afternoon
 * at the provider.
 *
 * The message is written for the log and never carries the document bytes or the API key.
 */
final class OcrEngineUnavailable extends RuntimeException {}
