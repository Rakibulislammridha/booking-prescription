<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Audit\Enums\AuditAction;
use App\Models\Tenant\AuditLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

trait InteractsWithAudit
{
    /** @param  array<string, mixed>|null  $contextSubset */
    protected function assertAudited(AuditAction $action, Model $subject, ?array $contextSubset = null): AuditLog
    {
        $logs = $this->auditLogsFor($action, $subject);

        $this->assertNotEmpty($logs, sprintf('No audit_logs row [%s] for %s#%s.', $action->value, $subject->getMorphClass(), $subject->getKey()));

        if ($contextSubset !== null) {
            $matching = $logs->first(fn (AuditLog $log) => array_intersect_key($log->context, $contextSubset) == $contextSubset);
            $this->assertNotNull($matching, 'No audit row matched the expected context subset '.json_encode($contextSubset));

            return $matching;
        }

        return $logs->first();
    }

    protected function assertNotAudited(AuditAction $action, Model $subject): void
    {
        $this->assertCount(0, $this->auditLogsFor($action, $subject), sprintf('Unexpected audit_logs row [%s] for %s#%s.', $action->value, $subject->getMorphClass(), $subject->getKey()));
    }

    /** @return Collection<int, AuditLog> */
    private function auditLogsFor(AuditAction $action, Model $subject)
    {
        return AuditLog::query()
            ->where('action', $action->value)
            ->where('auditable_type', $subject->getMorphClass())
            ->where('auditable_id', $subject->getKey())
            ->orderByDesc('id')
            ->get();
    }
}
