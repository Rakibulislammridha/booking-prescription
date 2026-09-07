<?php

declare(strict_types=1);

namespace App\Domain\Patients\Data;

use App\Domain\Patients\Enums\DocumentType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

final readonly class DocumentUploadData
{
    public function __construct(
        public UploadedFile $file,
        public DocumentType $type = DocumentType::Other,
        public ?string $title = null,
        public ?CarbonImmutable $documentDate = null,
        public ?int $visitId = null,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw new \InvalidArgumentException('file is required.');
        }

        return new self(
            file: $file,
            type: isset($v['type']) ? DocumentType::from((string) $v['type']) : DocumentType::Other,
            title: isset($v['title']) && trim((string) $v['title']) !== '' ? trim((string) $v['title']) : null,
            documentDate: isset($v['document_date']) && $v['document_date'] !== '' ? CarbonImmutable::parse((string) $v['document_date'])->startOfDay() : null,
            visitId: isset($v['visit_id']) ? (int) $v['visit_id'] : null,
        );
    }
}
