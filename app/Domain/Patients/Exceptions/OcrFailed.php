<?php

declare(strict_types=1);

namespace App\Domain\Patients\Exceptions;

use RuntimeException;

/**
 * This document cannot be read, and repeating the attempt will not change that: a damaged file, a content type
 * the engine refuses, a malformed provider reply. Not a DomainException — nothing about it reaches a request;
 * it is caught inside the namer, which falls back to naming by type and records `ocr_status = failed`.
 *
 * The message is written for the log and never carries the document bytes or the API key.
 */
final class OcrFailed extends RuntimeException {}
