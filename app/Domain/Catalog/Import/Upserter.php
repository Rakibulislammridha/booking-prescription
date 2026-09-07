<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Chunked `INSERT … ON CONFLICT DO UPDATE … WHERE row IS DISTINCT FROM EXCLUDED … RETURNING id` (CATALOG.md §5.4).
 * Unchanged rows are neither rewritten nor returned, which is what makes a re-import a no-op (ImportIdempotencyTest).
 */
final class Upserter
{
    public function __construct(private readonly ConnectionInterface $db, private readonly int $chunk = 1000) {}

    /**
     * @param  list<array<string, mixed>>  $rows  every row has the same keys; arrays are stored as jsonb
     * @param  list<string>  $conflict  conflict target columns or expressions (`lower(name)`, `COALESCE(trimester, 0)`)
     * @param  list<string>  $update  columns copied from EXCLUDED when the existing row differs
     * @param  array<string, string>  $expressions  column → raw SET expression (may reference the table alias `t` and EXCLUDED)
     * @return array{inserted: int, updated: int, ids: list<int>}
     */
    public function upsert(string $table, array $rows, array $conflict, array $update, int $versionId, array $expressions = [], bool $updatedAt = true): array
    {
        $result = ['inserted' => 0, 'updated' => 0, 'ids' => []];

        if ($rows === []) {
            return $result;
        }

        $columns = array_keys($rows[0]);
        $now = now()->toDateTimeString();
        $allColumns = [...$columns, 'catalog_version_id', 'created_at'];

        if ($updatedAt) {
            $allColumns[] = 'updated_at';
        }

        $set = [];
        $compareLeft = [];
        $compareRight = [];

        foreach ($update as $column) {
            $expr = $expressions[$column] ?? "EXCLUDED.\"{$column}\"";
            $set[] = "\"{$column}\" = {$expr}";
            $compareLeft[] = "t.\"{$column}\"";
            $compareRight[] = $expr;
        }

        $set[] = '"catalog_version_id" = EXCLUDED."catalog_version_id"';

        if ($updatedAt) {
            $set[] = '"updated_at" = EXCLUDED."updated_at"';
        }

        $where = $compareLeft === [] ? 'false' : '('.implode(', ', $compareLeft).') IS DISTINCT FROM ('.implode(', ', $compareRight).')';
        $columnList = implode(', ', array_map(fn ($c) => "\"{$c}\"", $allColumns));
        $conflictList = implode(', ', $conflict);

        foreach (array_chunk($rows, $this->chunk) as $chunk) {
            $placeholders = [];
            $bindings = [];

            foreach ($chunk as $row) {
                $slots = [];

                foreach ($columns as $column) {
                    [$slot, $value] = $this->bind($row[$column] ?? null);
                    $slots[] = $slot;
                    $bindings[] = $value;
                }

                $slots[] = '?';
                $bindings[] = $versionId;
                $slots[] = '?';
                $bindings[] = $now;

                if ($updatedAt) {
                    $slots[] = '?';
                    $bindings[] = $now;
                }

                $placeholders[] = '('.implode(', ', $slots).')';
            }

            $sql = "INSERT INTO \"{$table}\" AS t ({$columnList}) VALUES ".implode(', ', $placeholders)
                ." ON CONFLICT ({$conflictList}) DO UPDATE SET ".implode(', ', $set)
                ." WHERE {$where} RETURNING t.id, (xmax = 0) AS inserted";

            foreach ($this->db->select($sql, $bindings) as $returned) {
                $inserted = $returned->inserted === true || $returned->inserted === 't' || $returned->inserted === 1;
                $result[$inserted ? 'inserted' : 'updated']++;
                $result['ids'][] = (int) $returned->id;
            }
        }

        return $result;
    }

    /**
     * Ids for every row of the batch, changed or not: `SELECT id, <keyExpr> FROM table WHERE <keyExpr> IN (…)`.
     *
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    public function idsByKey(string $table, string $keyExpr, array $keys, ?string $extraWhere = null): array
    {
        $map = [];

        foreach (array_chunk(array_values(array_unique($keys)), $this->chunk) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            $sql = "SELECT id, {$keyExpr} AS k FROM \"{$table}\" WHERE {$keyExpr} IN ({$in})".($extraWhere !== null ? " AND {$extraWhere}" : '');

            foreach ($this->db->select($sql, $chunk) as $row) {
                $map[(string) $row->k] = (int) $row->id;
            }
        }

        return $map;
    }

    /**
     * `--full` deactivation: every active row of $table not in $keepIds → is_active = false (CATALOG.md §5.5).
     *
     * @param  list<int>  $keepIds
     * @return list<int> deactivated ids
     */
    public function deactivateExcept(string $table, array $keepIds, int $versionId, bool $stampDiscontinued = false): array
    {
        $now = now()->toDateTimeString();
        $keep = '{'.implode(',', array_map('intval', $keepIds)).'}';
        $extra = $stampDiscontinued ? ', "discontinued_at" = ?' : '';
        $bindings = $stampDiscontinued ? [$versionId, $now, $now, $keep] : [$versionId, $now, $keep];

        $rows = $this->db->select(
            "UPDATE \"{$table}\" SET \"is_active\" = false, \"catalog_version_id\" = ?, \"updated_at\" = ?{$extra} WHERE \"is_active\" = true AND NOT (id = ANY(?::bigint[])) RETURNING id",
            $bindings,
        );

        return array_map(fn ($r) => (int) $r->id, $rows);
    }

    /** @return array{0: string, 1: mixed} placeholder (with cast) and binding */
    private function bind(mixed $value): array
    {
        return match (true) {
            is_bool($value) => ['?::boolean', $value ? 1 : 0],
            is_array($value) => ['?::jsonb', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
            is_float($value) => ['?::numeric', (string) $value],
            default => ['?', $value],
        };
    }

    public static function forCatalogAdmin(): self
    {
        return new self(DB::connection('catalog_admin'), (int) config('catalog.import.chunk', 1000));
    }
}
