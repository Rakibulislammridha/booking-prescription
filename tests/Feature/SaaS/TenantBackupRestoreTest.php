<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\SaaS\Actions\Tenants\BackupTenant;
use App\Domain\SaaS\Actions\Tenants\RestoreTenantBackup;
use App\Domain\SaaS\Enums\BackupEncryption;
use App\Domain\SaaS\Enums\BackupStatus;
use App\Domain\SaaS\Enums\BackupType;
use App\Domain\SaaS\Exceptions\BackupEncryptionUnavailable;
use App\Domain\SaaS\Services\BackupCipher;
use App\Domain\SaaS\Services\TenantSchemaDumper;
use App\Models\Central\TenantBackup;
use App\Models\Tenant\Patient;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * `tenants:backup` and `tenants:restore` — the two operations nobody wants to be the first to try in anger.
 *
 * Everything here is COMMITTED (`$connectionsToTransact = []`, CONVENTIONS §6.5): `pg_dump` is a separate process
 * on a separate connection, so a dump taken inside the usual test transaction would be a dump of an empty clinic.
 * That makes cleanup this class's own job — including the schemas a restore leaves behind.
 *
 * The restore assertions are the point of the file. A scratch restore followed by a transactional rename swap is
 * the most destructive thing this product can do to a clinic, and every failure path here ends with the same
 * question asked out loud: is `tenant_test_a` still the clinic it was a moment ago?
 */
final class TenantBackupRestoreTest extends TestCase
{
    /** @var array<int, string> */
    protected array $connectionsToTransact = [];

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requirePostgresBinaries();

        Storage::fake('backups');
        $this->key = base64_encode(random_bytes(32));
        config(['saas.backups.encryption_key' => $this->key]);
    }

    protected function tearDown(): void
    {
        Tenancy::check() && Tenancy::end();

        foreach ($this->leftoverSchemas() as $schema) {
            DB::connection('pgsql')->statement('drop schema if exists "'.$schema.'" cascade');
        }

        if ($this->schemaExists(self::TENANT_A)) {
            $this->truncateTenantTables('a', ['audit_logs', 'patients']);
        }

        DB::connection('pgsql')->table('public.audit_logs_central')->whereIn('tenant_id', [9001, 9002])->delete();
        DB::connection('pgsql')->table('public.tenant_backups')->whereIn('tenant_id', [9001, 9002])->delete();
        DB::connection('pgsql')->table('public.tenants')->whereIn('id', [9001, 9002])->update(['last_backup_at' => null]);

        parent::tearDown();
    }

    public function test_a_backup_registers_a_completed_row_and_streams_an_object_onto_the_backups_disk(): void
    {
        $tenant = $this->tenant('a');

        $backup = app(BackupTenant::class)->handle($tenant, BackupType::Manual);

        $this->assertSame(BackupStatus::Completed, $backup->status);
        $this->assertSame(BackupEncryption::XChaCha20Poly1305, $backup->encryption);
        $this->assertNull($backup->getAttribute('error'));
        $this->assertGreaterThan(0, (int) $backup->getAttribute('size_bytes'));
        $this->assertSame(64, strlen((string) $backup->getAttribute('checksum_sha256')));

        $path = (string) $backup->storage_path;
        $this->assertStringEndsWith('.dump.enc', $path, 'the object key must say what is in it');
        Storage::disk('backups')->assertExists($path);
        $this->assertSame((int) $backup->getAttribute('size_bytes'), (int) Storage::disk('backups')->size($path));

        $this->assertNotNull($tenant->refresh()->getAttribute('last_backup_at'));
    }

    public function test_the_stored_object_is_encrypted_and_is_not_a_readable_pg_dump_archive(): void
    {
        Tenancy::run($this->tenant('a'), fn () => Patient::factory()->create(['name' => 'CLEARTEXT-CANARY-PATIENT']));

        $backup = app(BackupTenant::class)->handle($this->tenant('a'), BackupType::Manual);
        $object = (string) Storage::disk('backups')->get((string) $backup->storage_path);

        // A pg_dump custom archive announces itself with PGDMP. This one announces our own format instead, and
        // `pg_restore` cannot read a byte of it.
        $this->assertStringStartsWith(BackupCipher::MAGIC.chr(BackupCipher::VERSION), $object);
        $this->assertNotSame('PGDMP', substr($object, 0, 5), 'the object on the disk must not be a readable custom-format archive');
        $this->assertFalse($this->listArchive($this->stage($object))->isSuccessful(), 'pg_restore must not be able to open the stored object');

        // …and it is the clinic's archive underneath: decrypting yields the PGDMP the checksum column describes,
        // table of contents and all. (A custom archive compresses its data, so the canary is legible only there.)
        $plain = $this->tempPath();
        app(BackupCipher::class)->decrypt($this->stage($object), $plain);

        $this->assertStringStartsWith('PGDMP', (string) file_get_contents($plain));
        $this->assertSame($backup->getAttribute('checksum_sha256'), hash_file('sha256', $plain), 'checksum_sha256 is the digest of the PLAINTEXT archive');

        $toc = $this->listArchive($plain);
        $this->assertTrue($toc->isSuccessful());
        $this->assertStringContainsString('TABLE DATA '.self::TENANT_A.' patients', $toc->getOutput());
        $this->assertStringNotContainsString(self::TENANT_B, $toc->getOutput(), 'a dump is scoped to one schema, and the schema is the boundary');
    }

    public function test_a_backup_without_a_key_outside_local_and_testing_fails_loudly_and_records_the_failure(): void
    {
        config(['saas.backups.encryption_key' => '']);
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertThrows(
            fn () => app(BackupTenant::class)->handle($this->tenant('a'), BackupType::Daily),
            BackupEncryptionUnavailable::class,
        );

        $row = TenantBackup::query()->where('tenant_id', 9001)->latest('id')->firstOrFail();

        $this->assertSame(BackupStatus::Failed, $row->status);
        $this->assertSame(BackupEncryption::None, $row->encryption);
        $this->assertNull($row->storage_path);
        $this->assertStringContainsString('BP_BACKUP_KEY', (string) $row->getAttribute('error'));
        $this->assertSame([], Storage::disk('backups')->allFiles(), 'nothing may reach the bucket when the key is missing');
    }

    public function test_a_malformed_key_is_refused_even_where_plaintext_would_be_allowed(): void
    {
        config(['saas.backups.encryption_key' => base64_encode(random_bytes(16))]);

        $this->assertThrows(
            fn () => app(BackupTenant::class)->handle($this->tenant('a'), BackupType::Daily),
            BackupEncryptionUnavailable::class,
        );

        $this->assertSame(BackupStatus::Failed, TenantBackup::query()->latest('id')->firstOrFail()->status);
    }

    public function test_testing_falls_back_to_a_plaintext_dump_loudly_and_says_so_on_the_row(): void
    {
        config(['saas.backups.encryption_key' => '']);
        $log = Log::spy();

        $backup = app(BackupTenant::class)->handle($this->tenant('a'), BackupType::Manual);

        $log->shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'UNENCRYPTED'))->once();

        $this->assertSame(BackupStatus::Completed, $backup->status);
        $this->assertSame(BackupEncryption::None, $backup->encryption, 'an operator must be able to see which objects are in the clear');
        $this->assertStringEndsWith('.dump', (string) $backup->storage_path);
        $this->assertStringStartsWith('PGDMP', (string) Storage::disk('backups')->get((string) $backup->storage_path));
    }

    public function test_the_scheduled_command_backs_up_every_provisioned_clinic_and_prints_the_mode(): void
    {
        $this->artisan('tenants:backup', ['--prune' => true])
            ->expectsOutputToContain('xchacha20poly1305')
            ->assertSuccessful();

        $rows = TenantBackup::query()->where('type', BackupType::Daily->value)->get();

        $this->assertSame([9001, 9002], $rows->pluck('tenant_id')->sort()->values()->all());

        foreach ($rows as $row) {
            $this->assertSame(BackupStatus::Completed, $row->status);
            $this->assertSame(BackupEncryption::XChaCha20Poly1305, $row->encryption);
            Storage::disk('backups')->assertExists((string) $row->storage_path);
        }
    }

    public function test_a_restore_rolls_the_clinic_back_to_the_dump_and_keeps_the_displaced_schema(): void
    {
        $tenant = $this->tenant('a');
        Tenancy::run($tenant, fn () => Patient::factory()->create(['name' => 'IN-THE-DUMP']));

        $backup = app(BackupTenant::class)->handle($tenant, BackupType::Manual);

        // Written AFTER the dump: the whole point of a restore is that this row goes away.
        Tenancy::run($tenant, fn () => Patient::factory()->create(['name' => 'AFTER-THE-DUMP']));
        $this->assertSame(['AFTER-THE-DUMP', 'IN-THE-DUMP'], $this->patientNames(self::TENANT_A));

        $result = app(RestoreTenantBackup::class)->handle($backup);

        $this->assertGreaterThan(50, $result['tables']);
        $this->assertSame(['IN-THE-DUMP'], $this->patientNames(self::TENANT_A), 'the live schema must now be the dump');
        $this->assertSame(['AFTER-THE-DUMP', 'IN-THE-DUMP'], $this->patientNames($result['replaced']), 'the displaced clinic must survive for a human to look at');

        // The swap itself: live present, scratch gone, displaced kept under the documented name.
        $this->assertTrue($this->schemaExists(self::TENANT_A));
        $this->assertFalse($this->schemaExists(self::TENANT_A.TenantSchemaDumper::RESTORE_SUFFIX), 'the scratch schema must not survive under its own name');
        $this->assertMatchesRegularExpression('/^'.self::TENANT_A.'_replaced_\d{14}$/', $result['replaced']);
        $this->assertTrue($this->schemaExists($result['replaced']));
        $this->assertSame($result['tables'], $this->tableCount(self::TENANT_A));

        // A restored clinic is a working clinic, not just a set of tables: Eloquent goes on writing into it.
        Tenancy::run($tenant, fn () => Patient::factory()->create(['name' => 'AFTER-THE-RESTORE']));
        $this->assertSame(['AFTER-THE-RESTORE', 'IN-THE-DUMP'], $this->patientNames(self::TENANT_A));

        $this->assertDatabaseHas('public.audit_logs_central', ['tenant_id' => $tenant->id, 'action' => 'restore']);
    }

    public function test_a_plaintext_dump_written_before_encryption_existed_still_restores(): void
    {
        config(['saas.backups.encryption_key' => '']);
        $tenant = $this->tenant('a');
        Tenancy::run($tenant, fn () => Patient::factory()->create(['name' => 'OLD-PLAINTEXT-ERA']));

        $backup = app(BackupTenant::class)->handle($tenant, BackupType::Manual);
        $this->assertSame(BackupEncryption::None, $backup->encryption);

        Tenancy::run($tenant, fn () => Patient::factory()->create(['name' => 'AFTER-THE-DUMP']));

        // A key configured today must not make yesterday's plaintext object unreadable: the format is detected
        // from the object's own magic, not from the row or the configuration.
        config(['saas.backups.encryption_key' => $this->key]);
        $result = app(RestoreTenantBackup::class)->handle($backup);

        $this->assertSame(['OLD-PLAINTEXT-ERA'], $this->patientNames(self::TENANT_A));
        $this->assertTrue($this->schemaExists($result['replaced']));
    }

    public function test_a_truncated_object_is_refused_and_the_live_clinic_is_untouched(): void
    {
        $backup = $this->backupWithLiveRow('SURVIVES-A-TRUNCATED-ARCHIVE');
        $object = (string) Storage::disk('backups')->get((string) $backup->storage_path);
        Storage::disk('backups')->put((string) $backup->storage_path, substr($object, 0, intdiv(strlen($object), 2)));

        $this->assertRestoreRefused($backup, 'truncated');
    }

    public function test_a_wrong_key_is_refused_and_the_live_clinic_is_untouched(): void
    {
        $backup = $this->backupWithLiveRow('SURVIVES-THE-WRONG-KEY');

        config(['saas.backups.encryption_key' => base64_encode(random_bytes(32))]);

        $this->assertRestoreRefused($backup, 'authentication');
    }

    public function test_an_object_that_is_not_a_pg_dump_leaves_no_scratch_and_no_replaced_schema(): void
    {
        $backup = $this->backupWithLiveRow('SURVIVES-A-GARBAGE-ARCHIVE');

        // Plausible-looking rubbish, checksummed so the archive itself is what fails, not the bookkeeping.
        $rubbish = 'PGDMP'.str_repeat("\x00\x11\x22", 512);
        Storage::disk('backups')->put($path = 'tenants/9001/rubbish.dump', $rubbish);
        $backup->forceFill([
            'storage_path' => $path,
            'encryption' => BackupEncryption::None,
            'checksum_sha256' => hash('sha256', $rubbish),
        ])->save();

        $this->assertRestoreRefused($backup, 'pg_restore failed');
    }

    public function test_a_checksum_mismatch_is_refused_before_postgres_is_touched(): void
    {
        $backup = $this->backupWithLiveRow('SURVIVES-A-BAD-CHECKSUM');
        $backup->forceFill(['checksum_sha256' => hash('sha256', 'not this archive')])->save();

        $this->assertRestoreRefused($backup, 'does not match its recorded checksum');
    }

    public function test_a_backup_row_that_never_completed_is_not_restorable(): void
    {
        $backup = TenantBackup::query()->create([
            'tenant_id' => 9001,
            'type' => BackupType::Daily,
            'status' => BackupStatus::Failed,
            'storage_disk' => 'backups',
            'encryption' => BackupEncryption::None,
        ]);

        $this->assertRestoreRefused($backup, 'is not restorable');
    }

    /** A completed, encrypted backup plus one live row that every failure path must leave exactly where it is. */
    private function backupWithLiveRow(string $marker): TenantBackup
    {
        $tenant = $this->tenant('a');
        Tenancy::run($tenant, fn () => Patient::factory()->create(['name' => $marker]));

        return app(BackupTenant::class)->handle($tenant, BackupType::Manual);
    }

    private function assertRestoreRefused(TenantBackup $backup, string $expectedMessage): void
    {
        $before = $this->patientNames(self::TENANT_A);
        $tables = $this->tableCount(self::TENANT_A);

        try {
            app(RestoreTenantBackup::class)->handle($backup);
            $this->fail('the restore was expected to refuse this backup');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
        }

        $this->assertTrue($this->schemaExists(self::TENANT_A), 'the live schema must still exist');
        $this->assertSame($tables, $this->tableCount(self::TENANT_A), 'the live schema must still hold its tables');
        $this->assertSame($before, $this->patientNames(self::TENANT_A), 'the live schema must still hold its rows');
        $this->assertFalse($this->schemaExists(self::TENANT_A.TenantSchemaDumper::RESTORE_SUFFIX), 'a failed restore must not leave a scratch schema');
        $this->assertSame([], $this->leftoverSchemas(), 'a failed restore must never displace the live schema');
    }

    /** @return array<int, string> */
    private function patientNames(string $schema): array
    {
        /** @var array<int, object{name: string}> $rows */
        $rows = DB::connection('pgsql')->select('select name from "'.$schema.'".patients order by name');

        return array_map(fn (object $row) => (string) $row->name, $rows);
    }

    private function tableCount(string $schema): int
    {
        return (int) DB::connection('pgsql')->scalar(
            "select count(*) from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE'",
            [$schema],
        );
    }

    private function schemaExists(string $schema): bool
    {
        return (int) DB::connection('pgsql')->scalar('select count(*) from information_schema.schemata where schema_name = ?', [$schema]) > 0;
    }

    /**
     * Every schema a restore may have created: the scratch and any displaced copies.
     *
     * @return array<int, string>
     */
    private function leftoverSchemas(): array
    {
        /** @var array<int, object{schema_name: string}> $rows */
        $rows = DB::connection('pgsql')->select(
            "select schema_name from information_schema.schemata where schema_name like 'tenant\\_test\\_%\\_replaced\\_%' or schema_name like 'tenant\\_test\\_%\\_restore' order by schema_name",
        );

        return array_map(fn (object $row) => (string) $row->schema_name, $rows);
    }

    /** `pg_restore --list`: the cheapest possible "is this actually a readable custom-format archive". */
    private function listArchive(string $path): Process
    {
        $process = new Process(['pg_restore', '--list', $path]);
        $process->run();

        return $process;
    }

    private function stage(string $contents): string
    {
        $path = $this->tempPath();
        file_put_contents($path, $contents);

        return $path;
    }

    private function tempPath(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'bp-backup-test-');
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    /**
     * An untested restore is the whole point of this file, so it tries hard to run — but a box without the client
     * binaries, or with ones older than the server, cannot dump at all and says so rather than failing red.
     */
    private function requirePostgresBinaries(): void
    {
        foreach (['pg_dump', 'pg_restore', 'psql'] as $binary) {
            $which = new Process(['which', $binary]);
            $which->run();

            if (! $which->isSuccessful()) {
                $this->markTestSkipped("{$binary} is not installed; tenant backups cannot be exercised on this host.");
            }
        }

        $version = new Process(['pg_dump', '--version']);
        $version->run();
        preg_match('/(\d+)/', $version->getOutput(), $client);
        $server = explode('.', (string) DB::connection('pgsql')->scalar('show server_version'));

        if (($client[1] ?? null) !== $server[0]) {
            $this->markTestSkipped('pg_dump '.trim($version->getOutput())." cannot dump a PostgreSQL {$server[0]} server.");
        }
    }
}
