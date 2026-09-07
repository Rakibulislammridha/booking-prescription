<?php

declare(strict_types=1);

namespace App\Domain\Patients\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Patients\Events\PatientMerged;
use App\Domain\Patients\Exceptions\CannotMergeSelf;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientRelation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Duplicate merge (SCHEMA §5.4): repoints every FK from the loser to the winner and soft-deletes the loser.
 * Hospital Admin / Super Admin action (patients.merge); never automatic. Tables of modules that are not
 * installed yet are skipped (Schema::hasTable), so the list can be complete today.
 */
final class MergePatients
{
    /** @var array<int, array{0: string, 1: string}> table, column */
    public const REPOINTED = [
        ['patient_allergies', 'patient_id'], ['patient_conditions', 'patient_id'], ['patient_medications', 'patient_id'],
        ['patient_documents', 'patient_id'], ['patient_consents', 'patient_id'], ['patient_otp_codes', 'patient_id'],
        ['audit_logs', 'patient_id'],
        ['serials', 'patient_id'], ['appointments', 'patient_id'], ['visits', 'patient_id'], ['vitals', 'patient_id'],
        ['prescriptions', 'patient_id'], ['invoices', 'patient_id'], ['payments', 'patient_id'], ['notifications', 'patient_id'],
        ['telemedicine_sessions', 'patient_id'],
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Patient $winner, Patient $loser, Actor $actor, ?string $reason = null): Patient
    {
        if ($winner->is($loser)) {
            throw new CannotMergeSelf;
        }

        return DB::transaction(function () use ($winner, $loser, $reason): Patient {
            $repointed = [];

            foreach (self::REPOINTED as [$table, $column]) {
                if (! Schema::connection('pgsql')->hasTable($table)) {
                    continue;
                }

                $n = DB::connection('pgsql')->table($table)->where($column, $loser->id)->update([$column => $winner->id]);

                if ($n > 0) {
                    $repointed[$table] = $n;
                }
            }

            // Family links: the loser's dependents move under the winner's owner; the loser's own link goes away.
            $owner = $winner->primaryRelation->primary ?? $winner;
            PatientRelation::query()->where('dependent_patient_id', $loser->id)->delete();
            PatientRelation::query()->where('primary_patient_id', $loser->id)
                ->whereNot('dependent_patient_id', $owner->id)
                ->update(['primary_patient_id' => $owner->id]);
            PatientRelation::query()->where('primary_patient_id', $loser->id)->delete();

            $winner->forceFill([
                'visit_count' => $winner->visit_count + $loser->visit_count,
                'last_visit_at' => max($winner->last_visit_at, $loser->last_visit_at),
                'notes' => $this->mergeNotes($winner->notes, $loser->notes),
            ])->save();

            $loser->forceFill(['is_active' => false])->save();
            $loser->delete();

            $this->audit->record(AuditAction::Update, $winner, null, ['merged_from' => $loser->public_id], ['reason' => $reason, 'repointed' => $repointed, 'loser_id' => $loser->id]);

            DB::afterCommit(fn () => event(new PatientMerged($winner, $loser)));

            return $winner->refresh();
        });
    }

    private function mergeNotes(?string $a, ?string $b): ?string
    {
        $parts = array_filter([$a, $b], fn (?string $s) => $s !== null && trim($s) !== '');

        return $parts === [] ? null : implode("\n---\n", $parts);
    }
}
