<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Models\Tenant\AiSuggestion;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionItem;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;
use Illuminate\Database\Eloquent\Model;

/**
 * Every clinical write and view of the module produces one audit_logs row with the closed `action` plus
 * `context.event` (PRESCRIPTION.md §6.6, I7). The models themselves are not auto-audited (the writer autosaves every
 * 400 ms; one row per save, not one per child row) — this class is the single audit path.
 */
final class PrescriptionAuditor
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function visitStarted(Visit $visit, string $source): void
    {
        $this->recorder->record(AuditAction::Create, $visit, null, ['status' => 'open', 'type' => $visit->type->value], ['event' => 'visit_started', 'source' => $source, 'serial_id' => $visit->serial_id]);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function visitUpdated(Visit $visit, array $before, array $after, string $event = 'visit_updated'): void
    {
        $this->recorder->record(AuditAction::Update, $visit, $before, $after, ['event' => $event]);
    }

    public function visitClosed(Visit $visit): void
    {
        $this->recorder->record(AuditAction::Update, $visit, ['status' => 'open'], ['status' => 'closed'], ['event' => 'visit_closed']);
    }

    public function draftCreated(Prescription $rx): void
    {
        $this->recorder->record(AuditAction::Create, $rx, null, ['status' => 'draft', 'version' => $rx->version], ['event' => 'created', 'visit_id' => $rx->visit_id, 'version' => $rx->version, 'supersedes_id' => $rx->supersedes_prescription_id]);
    }

    public function viewed(Prescription $rx): void
    {
        $this->recorder->record(AuditAction::View, $rx, null, null, ['event' => 'viewed', 'version' => $rx->version]);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function draftSaved(Prescription $rx, array $before, array $after): void
    {
        $this->recorder->record(AuditAction::Update, $rx, $before, $after, ['event' => 'draft_saved']);
    }

    public function vitalsRecorded(Vital $vital): void
    {
        $this->recorder->record(AuditAction::Create, $vital, null, $vital->auditedSubset($vital->getAttributes()), ['event' => 'vitals_recorded', 'visit_id' => $vital->visit_id]);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function vitalsUpdated(Vital $vital, array $before, array $after, bool $reviewOnly): void
    {
        $this->recorder->record(AuditAction::Update, $vital, $before, $after, ['event' => $reviewOnly ? 'vitals_reviewed' : 'vitals_updated', 'visit_id' => $vital->visit_id]);
    }

    public function templateApplied(Prescription $rx, int $templateId, string $mode): void
    {
        $this->recorder->record(AuditAction::Update, $rx, null, null, ['event' => 'template_applied', 'template_id' => $templateId, 'mode' => $mode]);
    }

    /** @param  array<string, mixed>  $alert */
    public function safetyOverride(PrescriptionItem $item, array $alert, string $reason): void
    {
        $this->recorder->record(AuditAction::Update, $item, null, null, ['event' => 'safety_override', 'alert' => $alert, 'reason' => $reason]);
    }

    /** @param  array<string, int>  $alertsSummary */
    public function issued(Prescription $rx, array $alertsSummary): void
    {
        $this->recorder->record(AuditAction::Issue, $rx, ['status' => 'draft'], ['status' => 'issued', 'issued_at' => $rx->issued_at?->toIso8601String()], ['event' => 'issued', 'version' => $rx->version, 'verification_code' => $rx->verification_code, 'alerts_summary' => $alertsSummary]);
    }

    public function amendStarted(Prescription $old, Prescription $new, string $reason): void
    {
        $this->recorder->record(AuditAction::Amend, $old, null, null, ['event' => 'amend_started', 'from_version' => $old->version, 'to_version' => $new->version, 'reason' => $reason, 'to_prescription_id' => $new->id]);
        $this->recorder->record(AuditAction::Create, $new, null, ['status' => 'draft', 'version' => $new->version], ['event' => 'amend_started', 'from_version' => $old->version, 'to_version' => $new->version, 'reason' => $reason, 'supersedes_id' => $old->id]);
    }

    public function superseded(Prescription $old, Prescription $new): void
    {
        $this->recorder->record(AuditAction::Update, $old, ['status' => 'issued'], ['status' => 'amended'], ['event' => 'superseded', 'by_version' => $new->version, 'by_prescription_id' => $new->id]);
    }

    public function voided(Prescription $rx, string $reason): void
    {
        $this->recorder->record(AuditAction::Void, $rx, ['status' => 'issued'], ['status' => 'voided'], ['event' => 'voided', 'reason' => $reason]);
    }

    public function draftDeleted(Prescription $rx): void
    {
        $this->recorder->record(AuditAction::Delete, $rx, ['status' => 'draft', 'version' => $rx->version], null, ['event' => 'draft_deleted', 'supersedes_id' => $rx->supersedes_prescription_id]);
    }

    /** @param  array<string, mixed>  $options */
    public function printed(Prescription $rx, array $options): void
    {
        $this->recorder->record(AuditAction::Print, $rx, null, null, ['event' => 'printed'] + $options);
    }

    /** @param  array<string, mixed>  $context */
    public function downloaded(Prescription $rx, array $context = []): void
    {
        $this->recorder->record(AuditAction::Download, $rx, null, null, ['event' => 'downloaded'] + $context);
    }

    /** The pharmacy sheet leaves the building as its own artefact — SCHEMA's `export` action, not `print`. */
    public function exported(Prescription $rx, string $format, string $layout): void
    {
        $this->recorder->record(AuditAction::Export, $rx, null, null, ['event' => 'exported', 'format' => $format, 'layout' => $layout]);
    }

    /**
     * A handwriting sheet or annotation served off the private `uploads` disk. It is a clinical record leaving the
     * server as an image, so it is audited exactly like any other read of the prescription (CONVENTIONS §5).
     */
    public function attachmentViewed(Prescription $rx, string $file): void
    {
        $this->recorder->record(AuditAction::View, $rx, null, null, ['event' => 'attachment_viewed', 'file' => $file]);
    }

    public function sent(Prescription $rx, string $channel, string $toMasked): void
    {
        $this->recorder->record(AuditAction::Share, $rx, null, null, ['event' => 'sent', 'channel' => $channel, 'to_masked' => $toMasked]);
    }

    public function verifiedView(Prescription $rx): void
    {
        $this->recorder->record(AuditAction::View, $rx, null, null, ['event' => 'verified_view']);
    }

    public function handwritingUploaded(Prescription $rx, int $page): void
    {
        $this->recorder->record(AuditAction::Update, $rx, null, null, ['event' => 'handwriting_uploaded', 'page' => $page]);
    }

    public function drawingSaved(Prescription $rx): void
    {
        $this->recorder->record(AuditAction::Update, $rx, null, null, ['event' => 'drawing_saved']);
    }

    public function aiSuggested(AiSuggestion $s): void
    {
        $this->recorder->record(AuditAction::Create, $s, null, ['type' => $s->type->value, 'provider' => $s->provider, 'model' => $s->model], ['event' => 'ai_suggested', 'type' => $s->type->value]);
    }

    public function aiDecided(AiSuggestion $s, bool $accepted): void
    {
        $this->recorder->record(AuditAction::Update, $s, ['accepted' => null], ['accepted' => $accepted], ['event' => $accepted ? 'ai_accepted' : 'ai_rejected', 'type' => $s->type->value]);
    }

    /**
     * Generic view for any clinical model this module serves (visit read endpoints).
     *
     * @param  array<string, mixed>  $context
     */
    public function view(Model $subject, array $context = []): void
    {
        $this->recorder->view($subject, $context);
    }
}
