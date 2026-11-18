# Worklist Column Reduction — Laravel Implementation Guide

> All-in-one file. Each section shows the file path + the exact code change. No explanations — only what to drop, what to keep, what to add.

> **v2 (2026-11-18) — schema-accurate.** Rewritten against the live phpMyAdmin dump of `worklists` (35 columns, including `order_id` + UNIQUE index, `uuid_new`, `sample_number` + INDEX). The migration is now **guarded and re-runnable** — every step checks the current structure first, so it cannot conflict with your DB no matter which state it is in. New in v2: git test workflow (§12), pre-migration checks SQL (§15), post-migration verification SQL (§16), rollback (§17), optional index cleanup (§18), FAQ (§19).
>
> Package: `column-reduction-laravel-package.zip` — contains every NEW file (migration, schema test, the two SQL scripts, this guide). Nothing in the zip overwrites your existing app code; model/controller changes below are copy-paste patches.

---

## File paths touched

```
database/migrations/2026_11_18_000001_column_reduction_worklist_chain.php    (NEW — replaces the 2026_11_17 draft)
tests/Feature/ColumnReductionSchemaTest.php                                  (NEW)
scripts/pre_migration_checks.sql                                             (NEW — full SQL in §15)
scripts/post_migration_verification.sql                                      (NEW — full SQL in §16)
app/Models/Worklist.php
app/Models/TestSample.php
app/Models/TestSampleItem.php
app/Models/LabTest.php
app/Services/WorklistService.php
app/Http/Controllers/MirthController.php
app/Http/Controllers/WorklistController.php
app/Http/Controllers/SampleController.php
app/Http/Controllers/MachineConfigController.php                             (NEW)
routes/api.php
```

---

## 1. Migration

**`database/migrations/2026_11_18_000001_column_reduction_worklist_chain.php`** (NEW file)

What makes it safe (no conflict with your live DB):

- every `dropColumn` / `addColumn` / index change is guarded by `Schema::hasColumn` / `indexExists` → re-runnable, skips what is already done;
- index drops are name-tolerant (tries `worklists_order_id_unique`, `order_id`, `order_id_unique` — and MySQL auto-drops a single-column index with its column anyway);
- before adding `UNIQUE (test_sample_item_id, machine_id)` it runs the duplicate check and **fails with the offending rows listed** instead of crashing on constraint creation;
- works on MySQL/MariaDB (production) and SQLite (test runs).

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Column Reduction — worklists / test_samples / test_sample_items
 *
 * worklists         DROP: order_id (+UNIQUE), uuid_new, sample_number (+INDEX),
 *                         order_date, parameters, result_data, reassignment_history
 *                   ADD : machine_mapping_id, cancelled_reason, latest_result_id,
 *                         result_count, source
 *                   IDX : +uq_worklist_item_machine UNIQUE (test_sample_item_id, machine_id)
 *                         +idx_worklist_machine_status_pushed (machine_id, status, pushed_at)
 *                         +idx_worklist_latest_result
 *                   KEEP: barcode, receipt_barcode, suffix, timing (Mirth OML payload — §1.7.7)
 * test_samples      DROP: sl_no
 * test_sample_items DROP: suffix, sample_number
 * new table         worklist_reassignments (optional audit)
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->reduceWorklists();
        $this->reduceTestSamples();
        $this->reduceTestSampleItems();
        $this->createReassignmentAuditTable();
    }

    public function down(): void
    {
        // Restores STRUCTURE only — data of the dropped columns is NOT recoverable.
        // Full data restore: mysql -u USER -p DBNAME < database/before_column_reduction.sql
        Schema::dropIfExists('worklist_reassignments');
        $this->restoreTestSampleItems();
        $this->restoreTestSamples();
        $this->restoreWorklists();
    }

    /* ───────────────────────────── up ───────────────────────────── */

    private function reduceWorklists(): void
    {
        // 1) indexes tied to columns being dropped.
        //    MySQL auto-drops a single-column index when its column is dropped;
        //    these explicit drops are name-tolerant and keep SHOW INDEX tidy.
        $this->dropIndexIfExact('worklists', ['worklists_order_id_unique', 'order_id', 'order_id_unique']);
        $this->dropIndexIfExact('worklists', ['worklists_sample_number_index', 'sample_number', 'sample_number_index']);

        // 2) drop the dead columns (only the ones that still exist)
        $drop = array_values(array_filter([
            'order_id',
            'uuid_new',
            'sample_number',
            'order_date',
            'parameters',
            'result_data',
            'reassignment_history',
        ], fn (string $c): bool => Schema::hasColumn('worklists', $c)));

        if ($drop !== []) {
            Schema::table('worklists', fn (Blueprint $t) => $t->dropColumn($drop));
        }

        // 3) promoted + multi-version result-pointer columns
        Schema::table('worklists', function (Blueprint $t): void {
            if (! Schema::hasColumn('worklists', 'machine_mapping_id')) {
                $t->char('machine_mapping_id', 36)->nullable()->after('machine_id')
                    ->comment('machine_test_mappings(id) used for this row (was inside parameters JSON)');
            }
            if (! Schema::hasColumn('worklists', 'cancelled_reason')) {
                $t->string('cancelled_reason', 255)->nullable()->after('status');
            }
            if (! Schema::hasColumn('worklists', 'latest_result_id')) {
                $t->char('latest_result_id', 36)->nullable()->after('cancelled_reason')
                    ->comment('lis_results(id) — newest result version');
            }
            if (! Schema::hasColumn('worklists', 'result_count')) {
                $t->unsignedInteger('result_count')->default(0)->after('latest_result_id');
            }
            if (! Schema::hasColumn('worklists', 'source')) {
                $t->string('source', 20)->default('fanout')->after('result_count')
                    ->comment('fanout | manual | mirth | recheck');
            }
        });

        // 4) hot-path indexes
        Schema::table('worklists', function (Blueprint $t): void {
            if (! $this->indexExists('worklists', 'idx_worklist_machine_status_pushed')) {
                // Mirth pull: WHERE machine_id = ? AND status = 'pending' AND pushed_at IS NULL
                $t->index(['machine_id', 'status', 'pushed_at'], 'idx_worklist_machine_status_pushed');
            }
            if (! $this->indexExists('worklists', 'idx_worklist_latest_result')) {
                $t->index('latest_result_id', 'idx_worklist_latest_result');
            }
        });

        // 5) one worklist per sample-item per machine (duplicate guard — §1.4).
        //    Runs the duplicate pre-check first and FAILS with the offending
        //    rows if production data would violate the constraint.
        if (! $this->indexExists('worklists', 'uq_worklist_item_machine')) {
            $this->assertNoItemMachineDuplicates();

            Schema::table('worklists', fn (Blueprint $t) =>
                $t->unique(['test_sample_item_id', 'machine_id'], 'uq_worklist_item_machine')
            );
        }
    }

    private function reduceTestSamples(): void
    {
        if (Schema::hasColumn('test_samples', 'sl_no')) {
            Schema::table('test_samples', fn (Blueprint $t) => $t->dropColumn('sl_no'));
        }
    }

    private function reduceTestSampleItems(): void
    {
        $drop = array_values(array_filter(
            ['suffix', 'sample_number'],
            fn (string $c): bool => Schema::hasColumn('test_sample_items', $c)
        ));

        if ($drop !== []) {
            // MySQL auto-drops the single-column indexes on these columns
            Schema::table('test_sample_items', fn (Blueprint $t) => $t->dropColumn($drop));
        }
    }

    private function createReassignmentAuditTable(): void
    {
        // Optional (§1.7.3) — replaces the dropped reassignment_history JSON.
        // Delete the call in up() if you don't audit machine reassignments.
        if (! Schema::hasTable('worklist_reassignments')) {
            Schema::create('worklist_reassignments', function (Blueprint $t): void {
                $t->char('id', 36)->primary();
                $t->char('worklist_id', 36);
                $t->char('from_machine_id', 36);
                $t->char('to_machine_id', 36);
                $t->string('reason', 255)->nullable();
                $t->timestamps();
                $t->index('worklist_id', 'idx_reassign_worklist');
            });
        }
    }

    /* ──────────────────────────── down ──────────────────────────── */

    private function restoreWorklists(): void
    {
        if ($this->indexExists('worklists', 'uq_worklist_item_machine')) {
            Schema::table('worklists', fn (Blueprint $t) => $t->dropUnique('uq_worklist_item_machine'));
        }
        if ($this->indexExists('worklists', 'idx_worklist_machine_status_pushed')) {
            Schema::table('worklists', fn (Blueprint $t) => $t->dropIndex('idx_worklist_machine_status_pushed'));
        }
        if ($this->indexExists('worklists', 'idx_worklist_latest_result')) {
            Schema::table('worklists', fn (Blueprint $t) => $t->dropIndex('idx_worklist_latest_result'));
        }

        Schema::table('worklists', function (Blueprint $t): void {
            if (! Schema::hasColumn('worklists', 'machine_mapping_id')) {
                $t->char('machine_mapping_id', 36)->nullable();
            }
            if (! Schema::hasColumn('worklists', 'cancelled_reason')) {
                $t->string('cancelled_reason', 255)->nullable();
            }
            if (! Schema::hasColumn('worklists', 'latest_result_id')) {
                $t->char('latest_result_id', 36)->nullable();
            }
            if (! Schema::hasColumn('worklists', 'result_count')) {
                $t->unsignedInteger('result_count')->default(0);
            }
            if (! Schema::hasColumn('worklists', 'source')) {
                $t->string('source', 20)->default('fanout');
            }
        });

        // columns dropped by up() — structure only, old data is gone
        Schema::table('worklists', function (Blueprint $t): void {
            if (! Schema::hasColumn('worklists', 'order_id')) {
                $t->string('order_id', 36)->nullable();
            }
            if (! Schema::hasColumn('worklists', 'uuid_new')) {
                $t->uuid('uuid_new')->nullable();
            }
            if (! Schema::hasColumn('worklists', 'sample_number')) {
                $t->integer('sample_number')->default(1);
            }
            if (! Schema::hasColumn('worklists', 'order_date')) {
                // was NOT NULL originally; nullable here because the table may already hold rows
                $t->timestamp('order_date')->nullable();
            }
            if (! Schema::hasColumn('worklists', 'parameters')) {
                $t->longText('parameters')->nullable();
            }
            if (! Schema::hasColumn('worklists', 'result_data')) {
                $t->longText('result_data')->nullable();
            }
            if (! Schema::hasColumn('worklists', 'reassignment_history')) {
                $t->longText('reassignment_history')->nullable();
            }
        });

        if (Schema::hasColumn('worklists', 'order_id') && ! $this->indexExists('worklists', 'worklists_order_id_unique')) {
            Schema::table('worklists', fn (Blueprint $t) => $t->unique('order_id', 'worklists_order_id_unique'));
        }
    }

    private function restoreTestSamples(): void
    {
        Schema::table('test_samples', function (Blueprint $t): void {
            if (! Schema::hasColumn('test_samples', 'sl_no')) {
                $t->string('sl_no', 100)->nullable();
            }
        });
    }

    private function restoreTestSampleItems(): void
    {
        Schema::table('test_sample_items', function (Blueprint $t): void {
            if (! Schema::hasColumn('test_sample_items', 'suffix')) {
                $t->string('suffix', 20)->nullable();
            }
            if (! Schema::hasColumn('test_sample_items', 'sample_number')) {
                $t->integer('sample_number')->nullable();
            }
        });
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    private function indexExists(string $table, string $index): bool
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return (bool) DB::selectOne(
                "SELECT COUNT(*) AS c FROM sqlite_master WHERE type = 'index' AND name = ?",
                [$index]
            )->c;
        }

        return (int) (DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index]
        )->c ?? 0) > 0;
    }

    private function dropIndexIfExact(string $table, array $candidates): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return; // test runs — dropping the column removes single-column indexes automatically
        }

        foreach ($candidates as $name) {
            if ($this->indexExists($table, $name)) {
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");

                return;
            }
        }
    }

    private function assertNoItemMachineDuplicates(): void
    {
        $dupes = DB::select(
            'SELECT test_sample_item_id, machine_id, COUNT(*) AS n
             FROM worklists
             WHERE test_sample_item_id IS NOT NULL
             GROUP BY test_sample_item_id, machine_id
             HAVING n > 1
             LIMIT 5'
        );

        if ($dupes !== []) {
            $rows = implode('; ', array_map(
                fn ($r) => "item={$r->test_sample_item_id} machine={$r->machine_id} x{$r->n}",
                $dupes
            ));

            throw new RuntimeException(
                'UNIQUE (test_sample_item_id, machine_id) blocked: duplicate worklists found. ' .
                'Cancel the older duplicates (guide §15 step 4), then re-run migrate. Offenders: ' . $rows
            );
        }
    }
};
```

Two notes before running:

- If your project still has the earlier draft `2026_11_17_000001_simplify_worklist_chain_columns.php` **and it was never run** (`php artisan migrate:status` does not list it as Ran) → delete it. This file replaces it.
- If phpMyAdmin shows migrations newer than 2026-11-18, rename this file's timestamp prefix so it sorts **after** your latest migration (Laravel runs migrations in filename order).

---

## 2. `app/Models/Worklist.php`

### `$fillable` — REMOVE 6, ADD 5, KEEP `suffix`

```php
protected $fillable = [
    'id',
    'test_sample_item_id',
    'lab_receipt_id',
    'lab_test_id',
    'test_id',
    'machine_id',
    'machine_mapping_id',      // NEW (was inside parameters JSON)
    'patient_id',

    'barcode',
    'receipt_barcode',
    'suffix',                  // KEEP (§1.7.7 — tube identity for the Mirth OML payload)

    'patient_name',
    'patient_age',
    'patient_gender',

    'test_code',
    'test_name',
    'sample_type',
    'tube_type',
    'timing',

    'priority',
    'status',
    'cancelled_reason',         // NEW (replaces parameters.cancellation_reason)
    'latest_result_id',         // NEW
    'result_count',             // NEW
    'source',                   // NEW

    'collection_date',
    'started_at',
    'completed_at',
    'pushed_at',

    'test_sample_id',
];
// REMOVED: order_id, uuid_new, sample_number, order_date, parameters,
//          result_data, reassignment_history
```

### `$casts` — REMOVE the JSON/date casts of dropped columns

```php
protected $casts = [
    'collection_date' => 'datetime',
    'started_at'      => 'datetime',
    'completed_at'    => 'datetime',
    'pushed_at'       => 'datetime',
    'patient_age'     => 'integer',
    'result_count'    => 'integer',
];
// REMOVED: 'order_date' => 'datetime', 'parameters' => 'array',
//          'result_data' => 'array', 'reassignment_history' => 'array'
```

### `markAsCompleted()` — drop `$resultData` parameter

```php
public function markAsCompleted(): bool
{
    return DB::transaction(function () {
        if (!in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING])) {
            return false;
        }

        $this->status = self::STATUS_COMPLETED;
        $this->completed_at = now();
        $this->save();

        self::where('test_sample_item_id', $this->test_sample_item_id)
            ->where('id', '!=', $this->id)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING])
            ->update(['status' => self::STATUS_LOCKED]);

        if ($this->lab_test_id) {
            LabTest::where('id', $this->lab_test_id)->update([
                'has_result' => true,
                'result_at'  => now(),
            ]);
        }

        return true;
    });
}
```

### `markAsCancelled()` — use `cancelled_reason` column

```php
public function markAsCancelled(?string $reason = null): bool
{
    $this->status = self::STATUS_CANCELLED;
    if ($reason) {
        $this->cancelled_reason = $reason;
    }
    return $this->save();
}
```

### `changeMachine()` — drop `$history` writes

```php
public function changeMachine(string $newMachineId, ?string $reason = null): bool
{
    if ($this->status !== self::STATUS_PENDING) {
        return false;
    }

    $oldMachineId = $this->machine_id;
    $this->machine_id = $newMachineId;
    $this->status = self::STATUS_PENDING;

    // Audit moved to worklist_reassignments table (see WorklistService::reassignWorklist)
    return $this->save();
}
```

### DELETE these methods (no longer needed)

```php
public function getParameter(string $key, $default = null)  // DELETE
public function getResult(string $key, $default = null)      // DELETE
public function hasResult(): bool                            // DELETE
public function generateOrderId(): string                    // DELETE (no more order_id)
```

### `toApiResponse()` — REMOVE the dropped keys

```php
public function toApiResponse(): array
{
    return [
        'worklist_id' => $this->id,

        'receipt_barcode' => $this->receipt_barcode,
        'tube_barcode'    => $this->barcode,
        'sl_no'           => $this->labReceipt?->sl_no,

        'patient' => [
            'id'     => $this->patient_id,
            'name'   => $this->patient_name,
            'age'    => $this->patient_age,
            'gender' => $this->patient_gender,
            'phone'  => $this->patient?->phone,
        ],

        'test' => [
            'id'         => $this->test_id,
            'name'       => $this->test_name,
            'code'       => $this->test_code,
            'sample_type'=> $this->sample_type,
            'tube_type'  => $this->tube_type,
            'timing'     => $this->timing,
        ],

        'machine' => [
            'id'           => $this->machine_id,
            'name'         => $this->machine?->display_name,
            'device_model' => $this->machine?->device_model,
        ],

        'status'         => $this->status,
        'status_label'   => $this->status_label,
        'priority'       => $this->priority,
        'priority_label' => $this->priority_label,

        'collection_date' => $this->collection_date?->toISOString(),
        'started_at'      => $this->started_at?->toISOString(),
        'completed_at'    => $this->completed_at?->toISOString(),
        'pushed_at'       => $this->pushed_at?->toISOString(),

        'machine_mapping_id' => $this->machine_mapping_id,
        'latest_result_id'   => $this->latest_result_id,
        'result_count'       => $this->result_count,
        'source'             => $this->source,
        'cancelled_reason'   => $this->cancelled_reason,
    ];
    // REMOVED: order_id, order_date, uuid_new, parameters, result_data, reassignment_history
}
```

### `boot()` — drop `order_id` / `uuid_new` generation

```php
protected static function boot()
{
    parent::boot();

    static::creating(function ($model) {
        if (empty($model->id)) {
            $model->id = (string) Str::uuid7();
        }
        // REMOVED: order_id / uuid_new generation (columns dropped)
    });
}
```

---

## 3. `app/Models/TestSample.php`

### `$fillable` — REMOVE 1 line

```php
protected $fillable = [
    'id',
    'barcode',
    // REMOVED: 'sl_no',
    'tenant_id',
    'lab_receipt_id',
    'patient_id',
    'tube_type',
    'product_id',
    'timing',
    'tube_sequence',
    'barcode_source',
    'shared_from_tenant_barcode',
    'collected_by',
    'collected_at',
    'note',
];
```

---

## 4. `app/Models/TestSampleItem.php`

### `$fillable` — REMOVE 2 lines

```php
protected $fillable = [
    'id',
    'tenant_id',
    'test_sample_id',
    'lab_test_id',
    'test_id',
    'collected_by',
    'collected_at',
    'notes',
    // REMOVED: 'suffix', 'sample_number',
    'status',
];
```

---

## 5. `app/Models/LabTest.php`

### Line 89 — drop `suffix`, `sample_number` from `withPivot`

```php
public function sampleItems()
{
    return $this->belongsToMany(TestSample::class, 'test_sample_items', 'lab_test_id', 'test_sample_id')
        ->withPivot('status', 'collected_by', 'collected_at');
        // REMOVED: 'suffix', 'sample_number'
}
```

---

## 6. `app/Services/WorklistService.php`

### Line 57 — fix `is_collected` bug

```php
$items = TestSampleItem::with([
    'testSample.labReceipt.patient',
    'labTest.test',
    'test',
])
    ->whereHas('testSample', fn ($q) => $q->where('lab_receipt_id', $labReceiptId))
    ->whereNotNull('collected_by')   // FIXED: was ->where('is_collected', true)
    ->get();
```

### Lines 135-200 — simplify `$parameters` build (drop entire array, set `machine_mapping_id` directly)

```php
$tubeSequence = $testSample->tube_sequence;
$sampleNumber = $tubeSequence;   // simple — was a long ?? chain through $item->sample_number
$suffix       = $this->parseSuffixFromBarcode($barcode) ?? $testSample->timing;
// DELETE the two locals above if nothing else uses them — the sample_number /
// suffix worklist writes are gone (suffix value rides inside receipt_barcode).

try {
    $worklist = Worklist::create([
        'test_sample_item_id' => $item->id,
        'lab_receipt_id'      => $labReceipt?->id,
        'lab_test_id'         => $labTest->id,
        'test_id'             => $testId,
        'machine_id'          => $machine->id,
        'machine_mapping_id'  => $mapping->id,     // NEW (was inside parameters)
        'patient_id'          => $patient?->id,

        'barcode'         => $barcode,
        'receipt_barcode' => $labReceipt?->barcode,

        'test_sample_id'  => $testSample->id,

        'patient_name'   => $patient?->name,
        'patient_age'    => $patient && $patient->dob
            ? \Carbon\Carbon::parse($patient->dob)->age
            : null,
        'patient_gender' => $patient?->gender,

        'test_code'   => $mapping->test_code ?? $labTest->test?->code ?? (string) $testId,
        'test_name'   => $labTest->test?->name ?? 'Unknown',
        'sample_type' => $mapping->sample_type ?? $labTest->test?->sample_type ?? 'Serum',
        'tube_type'   => $tubeType,
        'timing'      => $testSample->timing,

        'priority'        => $this->determinePriority($labReceipt, $machine),
        'status'          => $status,
        'collection_date' => $testSample->collected_at ?? now(),
        'source'          => 'fanout',
        // REMOVED: order_date, parameters, sample_number (suffix column kept, not written here)
    ]);

    Log::info('WORKLIST CREATED', [
        'worklist_id'   => $worklist->id,
        'item_id'       => $item->id,
        'machine_id'    => $machine->id,
        'barcode'       => $barcode,
        'status'        => $status,
    ]);

    $worklists[] = $worklist;

} catch (\Exception $e) {
    Log::error('WORKLIST CREATE FAILED', [
        'item_id'    => $item->id,
        'machine_id' => $machine->id,
        'error'      => $e->getMessage(),
    ]);
}
```

### Lines 370-371 — `createRecheckWorklist` — drop `order_date`

```php
$worklist = Worklist::create([
    // ... existing fields ...
    'collection_date' => now(),
    'source'          => 'recheck',
    // REMOVED: 'order_date' => $labTest->receipt->created_at ?? now(),
]);
```

### `submitResult()` — drop `$resultData` arg

```php
public function submitResult(string $worklistId): array
{
    $worklist = Worklist::find($worklistId);
    if (! $worklist) {
        return ['success' => false, 'message' => 'Worklist not found'];
    }

    if ($worklist->status === Worklist::STATUS_COMPLETED) {
        return ['success' => false, 'message' => 'Test already completed'];
    }

    if ($worklist->markAsCompleted()) {   // no $resultData arg
        if ($worklist->test_sample_item_id) {
            TestSampleItem::where('id', $worklist->test_sample_item_id)
                ->update(['status' => 'completed']);
        }

        if ($worklist->lab_test_id) {
            LabTest::where('id', $worklist->lab_test_id)
                ->update([
                    'lab_status'   => 'completed',
                    'completed_at' => now(),
                    'has_result'   => true,
                ]);
        }

        return [
            'success'  => true,
            'message'  => 'Result submitted successfully',
            'worklist' => $worklist,
        ];
    }

    return ['success' => false, 'message' => 'Failed to submit result'];
}
```

> **Note:** the `lis_results` INSERT happens in `MirthController::legacyResult` BEFORE calling `submitResult` — that's the authoritative result store; `worklists.result_data` is no longer used.

### `updateWorklistStatus()` — drop `$resultData` block

```php
public function updateWorklistStatus(string $worklistId, string $status): bool
{
    $worklist = Worklist::find($worklistId);
    if (! $worklist) {
        return false;
    }

    $worklist->status = $status;
    // REMOVED: if ($resultData) { $worklist->result_data = $resultData; }
    if ($status === self::STATUS_COMPLETED) {
        $worklist->completed_at = now();
    }
    if ($status === self::STATUS_PROCESSING) {
        $worklist->started_at = now();
    }

    return $worklist->save();
}
```

### `reassignWorklist()` — drop `reassignment_history`, insert into audit table

```php
public function reassignWorklist(string $worklistId, string $newMachineId, ?string $reason = null): array
{
    try {
        $worklist = Worklist::findOrFail($worklistId);

        if ($worklist->status !== self::STATUS_PENDING) {
            return ['success' => false, 'message' => 'Only pending worklists can be reassigned'];
        }

        $machineConfig = MachineTestMapping::where('machine_id', $newMachineId)
            ->where('test_id', $worklist->test_id)
            ->where('is_active', true)
            ->first();

        if (! $machineConfig) {
            return ['success' => false, 'message' => 'New machine cannot run this test'];
        }

        $oldMachineId = $worklist->machine_id;

        $worklist->machine_id        = $newMachineId;
        $worklist->machine_mapping_id = $machineConfig->id;
        $worklist->test_code         = $machineConfig->test_code ?? $worklist->test_code;
        $worklist->save();

        // Audit moved to dedicated table (§1.7.3)
        \DB::table('worklist_reassignments')->insert([
            'id'              => (string) \Illuminate\Support\Str::uuid7(),
            'worklist_id'     => $worklistId,
            'from_machine_id' => $oldMachineId,
            'to_machine_id'   => $newMachineId,
            'reason'          => $reason,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        Log::info('Worklist reassigned', [
            'worklist_id'   => $worklistId,
            'from_machine'  => $oldMachineId,
            'to_machine'    => $newMachineId,
        ]);

        return ['success' => true, 'message' => 'Worklist reassigned'];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
```

---

## 7. `app/Http/Controllers/MirthController.php`

### Line 317 — remove `order_date` from OML payload

```php
'collection_date' => $worklist->collection_date?->toISOString(),
// REMOVED: 'order_date' => $worklist->order_date?->toISOString(),
```

### Line 321 — remove `parameters` from response

```php
'patient_gender' => $worklist->patient_gender,

// Dates
'collection_date' => $worklist->collection_date?->toISOString(),

// Machine mapping
'machine_mapping_id' => $worklist->machine_mapping_id,
// REMOVED: 'parameters' => $worklist->parameters,
```

### Lines 540-549 — `legacyResult` — drop `result_data` block, keep the pointer update

```php
if ($worklist) {
    $worklist->status = 'completed';
    $worklist->completed_at = now();
    // REMOVED: $worklist->result_data = array_merge($worklist->result_data ?? [], [...]);
    $worklist->latest_result_id = $resultId;   // NEW — point to lis_results row
    $worklist->result_count     = ($worklist->result_count ?? 0) + 1;
    $worklist->save();

    if ($worklist->test_sample_item_id) {
        TestSampleItem::where('id', $worklist->test_sample_item_id)
            ->update(['status' => 'completed']);
    }

    if ($worklist->lab_test_id) {
        LabTest::where('id', $worklist->lab_test_id)
            ->update([
                'lab_status'   => 'completed',
                'completed_at' => now(),
                'has_result'   => true,
            ]);
    }
}
```

---

## 8. `app/Http/Controllers/WorklistController.php`

### Lines 165, 168, 171, 174, 181, 184 — DataTables date filter (no change, uses `created_at`)

Already correct. No edits.

### Lines 148, 178-182, 200-204, 330 — `sl_no` queries (remove or redirect through `labReceipt`)

```php
// Line 148 — change:
->orWhere('sl_no', 'like', "%{$search}%")
// to:
->orWhereHas('labReceipt', fn($q) => $q->where('sl_no', 'like', "%{$search}%"))

// Line 178-179 — sort switch — remove case 'sl_no':
// (drop the case entirely, or sort via JOIN with lab_receipts.sl_no)

// Line 182 — remove 'sl_no' from allowed sort columns:
if (in_array($columnName, ['barcode', 'date'])) {

// Lines 200-204 — DataTables column:
->addColumn('sl_no', function ($row) {
    return $row->labReceipt?->sl_no;
})
// And ensure ->with('labReceipt') is on the query
```

> Note: `test_samples.sl_no` is dropped — `lab_receipts.sl_no` is a DIFFERENT column on a different table and is kept.

### Line 1113-1114 — `getWorklistForMirth` response — drop `sample_number`

```php
'barcode'         => $worklist->barcode,
'receipt_barcode' => $worklist->receipt_barcode,
'timing'          => $worklist->timing,
// REMOVED: 'sample_number' => $worklist->sample_number,
```

### Line 1124 — `getWorklistForMirth` response — drop `order_date`

```php
'collection_date' => $worklist->collection_date?->toISOString(),
// REMOVED: 'order_date' => $worklist->order_date?->toISOString(),
```

### Line 1307 — `getWorklistForMachineApi` response — drop `parameters`

```php
'collection_date' => $worklist->collection_date?->toISOString(),
'machine_mapping_id' => $worklist->machine_mapping_id,
// REMOVED: 'parameters' => $worklist->parameters,
```

### Lines 1183-1206 — `receiveHl7Results` — drop `result_data` validation

```php
$validator = Validator::make($request->all(), [
    'barcode'        => 'required|string',
    'test_code'      => 'required|string',
    'machine_key'    => 'required|string',
    'result_value'   => 'required|string',
    // REMOVED: 'result_data' => 'required|array',
    'unit'           => 'nullable|string',
    'reference_range'=> 'nullable|string',
    'abnormal_flag'  => 'nullable|string',
]);
```

### Lines 1363-1414 — `submitResultApi` — drop `result_data` validation

```php
$validator = Validator::make($request->all(), [
    'worklist_id'  => 'required|string',
    'machine_key'  => 'required|string',
    // REMOVED: 'result_data' => 'required|array',
]);

// Line 1391 — change:
$result = $this->worklistService->submitResult($worklist->id);
// (was: ->submitResult($worklist->id, $request->result_data))
```

### Lines 954-956, 1027-1029 — CSV export — keep as-is (these read `collection_date`, `started_at`, `completed_at` which are kept)

No changes.

---

## 9. `app/Http/Controllers/SampleController.php`

### Lines 722-732, 836-846 — `TestSampleItem::insert()` arrays

Already correct (never set `suffix`/`sample_number`/`order_date`). No changes needed.

### Lines 980 — note (`'note' => $validator->messages()`)

Keep — `test_samples.note` column is kept.

### Lines 1626, 1708 — `TestSampleItem::create()` in recheck flow

Already correct. No changes needed.

---

## 10. NEW — `app/Http/Controllers/MachineConfigController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\MachineTestMapping;
use Illuminate\Http\JsonResponse;

class MachineConfigController extends Controller
{
    /**
     * GET /api/mirth/machine-config/{device_model}
     * Returns active machine_test_mappings (with their parameters JSON)
     * so Mirth can fetch + cache once per channel start.
     */
    public function show(string $deviceModel): JsonResponse
    {
        $machine = Machine::where('device_model', $deviceModel)
            ->where('is_active', true)
            ->first();

        if (! $machine) {
            return response()->json(['success' => false, 'message' => 'Machine not found'], 404);
        }

        $mappings = MachineTestMapping::where('machine_id', $machine->id)
            ->where('is_active', true)
            ->with('test:id,code,name,short_name,sample_type')
            ->get()
            ->map(fn ($m) => [
                'id'                => $m->id,
                'test_id'           => $m->test_id,
                'test_code'         => $m->test_code,
                'test_name'         => $m->test?->name,
                'sample_type'       => $m->sample_type,
                'parameters'        => is_string($m->parameters)
                    ? json_decode($m->parameters, true)
                    : ($m->parameters ?? []),
            ]);

        return response()->json([
            'success'          => true,
            'machine_id'       => $machine->id,
            'device_model'     => $machine->device_model,
            'direction'        => $machine->direction ?? 'unidirectional',
            'machine_mappings' => $mappings,
        ]);
    }
}
```

---

## 11. `routes/api.php` — add the new route

```php
// Machine config endpoint (Mirth fetches + caches once per channel start)
Route::get('/mirth/machine-config/{device_model}', [\App\Http\Controllers\MachineConfigController::class, 'show']);
```

---

## 12. Git test & update workflow (test first, then update — no conflict)

```bash
## 0) branch + backup
cd /var/www/your-lis                        # your project root
git checkout main && git pull
git checkout -b refactor/worklist-column-reduction

# backup ONLY the 3 touched tables (fast, restorable)
mysqldump -u USER -p DBNAME worklists test_samples test_sample_items \
  > database/before_column_reduction.sql

## 1) copy the NEW files from the zip (nothing overwrites your app code)
#    database/migrations/2026_11_18_000001_column_reduction_worklist_chain.php
#    tests/Feature/ColumnReductionSchemaTest.php
#    scripts/pre_migration_checks.sql / scripts/post_migration_verification.sql
#
# old draft — delete ONLY if it was never run:
php artisan migrate:status | grep 2026_11_17 || \
  git rm -f database/migrations/2026_11_17_000001_simplify_worklist_chain_columns.php

## 2) pre-checks (phpMyAdmin / mysql CLI) — full SQL in §15
#    • step 3 (duplicate check) MUST return 0 rows
#    • note the row counts (step 2) — compare again after migrate

## 3) dry run — prints the SQL, changes nothing
php artisan migrate --pretend

## 4) apply
php artisan migrate
php artisan migrate:status                  # new file shows "Ran"

## 5) schema smoke test — point the test DB at a COPY, never production
#    phpunit.xml:  <env name="DB_DATABASE" value="lab_test_copy"/>
php artisan test --filter=ColumnReductionSchemaTest
php artisan test                            # full suite

## 6) leftover-reference scan — every hit must be fixed or justified
grep -rn --include=*.php -E "order_date|uuid_new|reassignment_history|sl_no" app/ database/ resources/
grep -rn --include=*.php -E "'parameters'|'result_data'" app/
grep -rn --include=*.php "sample_number" app/

## 7) manual smoke on dev
#    receipt → collect → fan-out worklists
#    Mirth pull channel returns OML for one analyzer
#    push one ORU/result → worklist shows result_count=1, latest_result_id set, status completed
php artisan tinker
>>> Schema::getColumnListing('worklists');        // 33 columns
>>> Schema::getColumnListing('test_samples');     // 15 columns
>>> Schema::getColumnListing('test_sample_items');// 9 columns

## 8) commit + merge
git add -A
git commit -m "refactor(worklists): column reduction — drop dead cols, promote machine_mapping_id/cancelled_reason, add result pointer + covering indexes"
git checkout main && git merge --no-ff refactor/worklist-column-reduction

## 9) production deploy (same order: pre-checks → migrate → verify)
#    §15 pre_migration_checks.sql  →  php artisan migrate  →  §16 post_migration_verification.sql
php artisan config:clear && php artisan route:clear && php artisan cache:clear
```

---

## 13. Final column counts

| Table | Before (live dump 2026-11-18) | After | Removed |
|---|---|---|---|
| `worklists` | **35** | **33** | `order_id`, `uuid_new`, `sample_number`, `order_date`, `parameters`, `result_data`, `reassignment_history` |
| `test_samples` | 16 | 15 | `sl_no` |
| `test_sample_items` | 11 | 9 | `suffix`, `sample_number` |

**Added:**
- `worklists`: `machine_mapping_id`, `cancelled_reason`, `latest_result_id`, `result_count`, `source`
- Indexes: `uq_worklist_item_machine` (UNIQUE), `idx_worklist_machine_status_pushed`, `idx_worklist_latest_result` — `(barcode, status)` already exists in your table and is kept as-is
- New table: `worklist_reassignments` (optional audit)
- New endpoint: `GET /api/mirth/machine-config/{device_model}`

Row size effect (§1.7.5 of the design doc): dropping the 3 LONGTEXT JSON blobs + 4 dead columns and adding 5 compact ones takes the typical row from ~600-800 B to ~280-320 B → ~2× more rows per InnoDB page → faster Mirth pull and DataTables paths.

---

## 14. Mirth side — one-time fetch

In each Mirth channel's Source Transformer (or a deploy script), add a JavaScript Reader step that calls the new endpoint and stores the result in the channel's Configuration Map:

```javascript
// Mirth Connect — JavaScript Reader (deploy step)
var deviceModel = $('deviceModel'); // e.g. 'Sysmex XN-1000'
var response = $wc('GET', '/api/mirth/machine-config/' + encodeURIComponent(deviceModel));
var json     = JSON.parse(response.getResponseData());

if (json.success) {
    $cfg('machineId',   json.machine_id);
    $cfg('direction',   json.direction);
    $cfg('mappings',    JSON.stringify(json.machine_mappings));
    logger.info('Loaded ' + json.machine_mappings.length + ' mappings for ' + deviceModel);
} else {
    logger.error('Failed to load machine config: ' + response.getStatus());
}
```

This replaces the per-row `parameters` JSON payload — Mirth now has the mapping config in its Configuration Map and can look up by `test_code` or `test_id` directly.

---

## 15. Pre-migration checks SQL (`scripts/pre_migration_checks.sql`)

```sql
-- 0) BACKUP FIRST (server shell):
--    mysqldump -u USER -p DBNAME worklists test_samples test_sample_items \
--      > database/before_column_reduction.sql

-- 1) Actual index names on worklists (migration is name-tolerant, note them anyway)
SHOW INDEX FROM worklists;

-- 2) Row counts BEFORE — compare after migrate
SELECT
  (SELECT COUNT(*) FROM worklists)         AS worklists_rows,
  (SELECT COUNT(*) FROM test_samples)      AS test_samples_rows,
  (SELECT COUNT(*) FROM test_sample_items) AS test_sample_items_rows;

-- 3) DUPLICATE CHECK for the new UNIQUE (test_sample_item_id, machine_id)
--    MUST return 0 rows — otherwise the migration stops with an error.
SELECT test_sample_item_id, machine_id, COUNT(*) AS n
FROM worklists
WHERE test_sample_item_id IS NOT NULL
GROUP BY test_sample_item_id, machine_id
HAVING n > 1;

-- 4) If step 3 returned rows — inspect the offenders:
SELECT w.id, w.barcode, w.receipt_barcode, w.test_id, w.test_name,
       w.status, w.test_sample_item_id, w.machine_id, w.created_at
FROM worklists w
JOIN (
    SELECT test_sample_item_id, machine_id
    FROM worklists
    WHERE test_sample_item_id IS NOT NULL
    GROUP BY test_sample_item_id, machine_id
    HAVING COUNT(*) > 1
) d ON w.test_sample_item_id = d.test_sample_item_id
   AND w.machine_id = d.machine_id
ORDER BY w.test_sample_item_id, w.machine_id, w.created_at;

-- 4b) Fix: keep the newest PENDING/PROCESSING row, CANCEL the older
--     duplicates (never hard-delete in production). Replace the ids:
-- UPDATE worklists
-- SET status = 'cancelled', cancelled_reason = 'duplicate worklist cleanup'
-- WHERE id IN ('OLDER-DUPLICATE-ID-1', 'OLDER-DUPLICATE-ID-2');

-- 5) Evidence check — are the doomed columns really dead?
SELECT
  SUM(order_id IS NOT NULL)  AS order_id_used,
  SUM(uuid_new IS NOT NULL)  AS uuid_new_used,
  SUM(parameters IS NOT NULL)  AS parameters_used,
  SUM(result_data IS NOT NULL) AS result_data_used,
  SUM(reassignment_history IS NOT NULL) AS reassignment_used,
  SUM(suffix IS NOT NULL AND suffix <> '') AS suffix_used
FROM worklists;

SELECT SUM(sl_no IS NOT NULL) AS samples_sl_no_used FROM test_samples;

SELECT
  SUM(suffix IS NOT NULL)        AS items_suffix_used,
  SUM(sample_number IS NOT NULL) AS items_sample_number_used
FROM test_sample_items;

-- 6) PHP-side leftover scan (project root):
--    grep -rn --include=*.php -E "order_date|uuid_new|reassignment_history|sl_no" app/ database/ resources/
--    grep -rn --include=*.php -E "'parameters'|'result_data'" app/
--    grep -rn --include=*.php "sample_number" app/
```

---

## 16. Post-migration verification SQL (`scripts/post_migration_verification.sql`)

```sql
-- 1) Dropped columns must be GONE (expect 0 rows)
SELECT COLUMN_NAME
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'worklists'
  AND COLUMN_NAME IN ('order_id','uuid_new','sample_number','order_date',
                      'parameters','result_data','reassignment_history');

-- 2) New columns must EXIST (expect 5 rows)
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'worklists'
  AND COLUMN_NAME IN ('machine_mapping_id','cancelled_reason','latest_result_id',
                      'result_count','source')
ORDER BY ORDINAL_POSITION;

-- 3) Chain-table drops must be GONE (expect 0 rows total)
SELECT table_name, COLUMN_NAME
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND ((table_name = 'test_samples'      AND COLUMN_NAME = 'sl_no')
    OR (table_name = 'test_sample_items' AND COLUMN_NAME IN ('suffix','sample_number')));

-- 4) New indexes exist
SHOW INDEX FROM worklists
WHERE Key_name IN ('uq_worklist_item_machine',
                   'idx_worklist_machine_status_pushed',
                   'idx_worklist_latest_result');

-- 5) Old UNIQUE(order_id) must be gone (expect 0 rows)
SHOW INDEX FROM worklists WHERE Column_name = 'order_id';

-- 6) Kept columns still present (expect 3 rows)
SELECT COLUMN_NAME
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'worklists'
  AND COLUMN_NAME IN ('suffix','barcode','receipt_barcode');

-- 7) Row counts identical to pre-check step 2
SELECT
  (SELECT COUNT(*) FROM worklists)         AS worklists_rows,
  (SELECT COUNT(*) FROM test_samples)      AS test_samples_rows,
  (SELECT COUNT(*) FROM test_sample_items) AS test_sample_items_rows;

-- 8) worklist_reassignments exists (expect 1)
SELECT COUNT(*) AS audit_table_exists
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'worklist_reassignments';

-- 9) machine_mapping_id backfill state (NULL is OK — backfill when convenient)
SELECT
  (machine_mapping_id IS NULL) AS still_null,
  COUNT(*) AS rows_count
FROM worklists
GROUP BY (machine_mapping_id IS NULL);
```

---

## 17. Rollback

```bash
# structure only — data of the dropped columns is NOT restored
php artisan migrate:rollback --step=1

# rollback the CODE first if models are already patched (they reference new columns):
git checkout main -- app/Models/Worklist.php app/Services/WorklistService.php

# full data restore (3 tables, from the step-0 backup):
mysql -u USER -p DBNAME < database/before_column_reduction.sql
```

Notes:
- `down()` recreates every dropped column with its original type; `order_date` comes back as nullable (it was NOT NULL, but the table already holds rows).
- `worklist_reassignments` is dropped by `down()` as well.
- If you already inserted rows after the migration, the new columns (`machine_mapping_id`, `cancelled_reason`, `latest_result_id`, `result_count`, `source`) are removed by `down()` — those values are lost.

---

## 18. Optional — redundant single-column index cleanup

These indexes are left-covered by composite indexes (existing + new). Dropping them saves write time on the hot insert path. **Run manually after the migration, in a quiet window; check real index names with `SHOW INDEX FROM worklists` first.**

| Redundant index | Covered by (leftmost) | Statement |
|---|---|---|
| `machine_id` | `idx_worklist_machine_status_pushed` | `ALTER TABLE worklists DROP INDEX `machine_id`;` |
| `pushed_at` | `idx_worklist_machine_status_pushed` | `ALTER TABLE worklists DROP INDEX `pushed_at`;` |
| `barcode` | `(barcode, status)` | `ALTER TABLE worklists DROP INDEX `barcode`;` |
| `test_sample_item_id` | `uq_worklist_item_machine` | `ALTER TABLE worklists DROP INDEX `test_sample_item_id`;` |
| `lab_receipt_id` | `(lab_receipt_id, status)` | `ALTER TABLE worklists DROP INDEX `lab_receipt_id`;` |

Also assess (low value, your call): `patient_name`, `tube_type`, `timing`, `receipt_barcode`. Keep `test_sample_id`, `test_id`, `patient_id`, `created_at`, `(status, priority)`.

---

## 19. FAQ

**Q: The old draft `2026_11_17_000001_simplify_worklist_chain_columns.php` was already run — do I delete it?**
A: `php artisan migrate:status` — if it shows "Ran", keep the file (Laravel needs it for rollback bookkeeping) and just add the new migration; the new one detects what already exists and only does the missing parts. If it was never run, delete the file.

**Q: Why is `worklists.suffix` kept when `test_sample_items.suffix` is dropped?**
A: Design doc §1.7.7 keeps it as tube identity for the Mirth OML payload. Pre-check §15 step 5 gives you the evidence: if `suffix_used = 0` AND `grep -rn --include=*.php -- "->suffix" app/` shows no worklist usage, you may add `'suffix'` to the migration's drop list yourself — one line.

**Q: Why `UNIQUE (test_sample_item_id, machine_id)` and not `(test_sample_id, test_id, machine_id)`?**
A: The audit of `SampleController::recheckTest()` showed every recheck creates a NEW LabTest + NEW TestSampleItem, so `(test_sample_item_id, machine_id)` matches the existing duplicate guard with zero recheck-flow changes. If one tube item ever runs two different tests on the same analyzer, widen it to `['test_sample_item_id','test_id','machine_id']` — one line in the migration.

**Q: The migration timestamp is older than my newest migration.**
A: Rename the file prefix (e.g. `2026_12_01_000001_...php`) so it sorts after your latest migration — Laravel runs them in filename order.

**Q: Does `down()` restore the dropped data?**
A: No — structure only. Restore `database/before_column_reduction.sql` for data.

**Q: Do tests break on SQLite?**
A: The migration detects the driver (`sqlite` branch in the helpers) and the schema smoke test only asserts column existence — both work on MySQL and SQLite.

---

## Related docs

- `worklist-lisresults-multi-version-design.md` — §1.4 (result pointer columns), §1.7 (this reduction's rationale), §2 (multi-version `lis_results`)
- `unified-result-ingest-design.md` — one `ingestResult()` for Bidirectional + Unidirectional + Manual results, version at write time
- `mirth-connect-bidirectional-worklist-guide_v4.md` — Pattern A (direct) and Pattern B (local cache) channel setup
