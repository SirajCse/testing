# Worklist Column Reduction — Laravel Implementation Guide

> All-in-one file. Each section shows the file path + the exact code change. No explanations — only what to drop, what to keep, what to add.

---

## File paths touched

```
database/migrations/2026_11_17_000001_simplify_worklist_chain_columns.php   (NEW)
app/Models/Worklist.php
app/Models/TestSample.php
app/Models/TestSampleItem.php
app/Services/WorklistService.php
app/Http/Controllers/MirthController.php
app/Http/Controllers/WorklistController.php
app/Http/Controllers/SampleController.php
app/Http/Controllers/MachineConfigController.php                            (NEW)
routes/api.php
```

---

## 1. Migration

**`database/migrations/2026_11_17_000001_simplify_worklist_chain_columns.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1) worklists: drop JSON blobs + redundant date (§1.7.4 + §1.7.8) ──
        Schema::table('worklists', function (Blueprint $table) {
            $table->dropColumn([
                'parameters',
                'result_data',
                'reassignment_history',
                'order_date',
                // Add if not dropped earlier:
                // 'uuid', 'uuid_new', 'sample_number',
            ]);
        });

        Schema::table('worklists', function (Blueprint $table) {
            $table->char('machine_mapping_id', 36)->nullable()->after('machine_id');
            $table->string('cancelled_reason', 255)->nullable()->after('status');

            // Multi-version result pointer
            $table->char('latest_result_id', 36)->nullable()->after('cancelled_reason');
            $table->integer('result_count')->default(0)->after('latest_result_id');
            $table->string('source', 20)->default('fanout')->after('result_count');

            $table->index('latest_result_id', 'idx_worklist_latest_result');
        });

        Schema::table('worklists', function (Blueprint $table) {
            $table->unique(['test_sample_item_id', 'machine_id'], 'uq_worklist_item_machine');
        });

        Schema::table('worklists', function (Blueprint $table) {
            $table->index(['machine_id', 'status', 'pushed_at'], 'idx_worklist_machine_status_pushed');
            $table->index(['barcode', 'status'], 'idx_worklist_barcode_status');
        });

        // ── 2) test_samples: drop sl_no (§1.7.9) ──
        Schema::table('test_samples', function (Blueprint $table) {
            $table->dropColumn('sl_no');
        });

        // ── 3) test_sample_items: drop suffix + sample_number (§1.7.9) ──
        Schema::table('test_sample_items', function (Blueprint $table) {
            $table->dropColumn(['suffix', 'sample_number']);
        });

        // ── 4) worklist_reassignments audit table (§1.7.3 — optional) ──
        Schema::create('worklist_reassignments', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('worklist_id', 36);
            $table->char('from_machine_id', 36);
            $table->char('to_machine_id', 36);
            $table->string('reason', 255)->nullable();
            $table->timestamps();
            $table->index('worklist_id', 'idx_reassign_worklist');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worklist_reassignments');

        Schema::table('test_sample_items', function (Blueprint $table) {
            $table->string('suffix', 20)->nullable()->after('status');
            $table->integer('sample_number')->nullable()->after('suffix');
        });

        Schema::table('test_samples', function (Blueprint $table) {
            $table->string('sl_no', 100)->nullable()->after('barcode');
        });

        Schema::table('worklists', function (Blueprint $table) {
            $table->dropIndex('idx_worklist_barcode_status');
            $table->dropIndex('idx_worklist_machine_status_pushed');
            $table->dropUnique('uq_worklist_item_machine');
            $table->dropIndex('idx_worklist_latest_result');
            $table->dropColumn([
                'source', 'result_count', 'latest_result_id',
                'cancelled_reason', 'machine_mapping_id',
            ]);
            $table->timestamp('order_date')->nullable()->after('status');
            $table->json('reassignment_history')->nullable();
            $table->json('result_data')->nullable();
            $table->json('parameters')->nullable()->after('machine_id');
        });
    }
};
```

Run:

```bash
php artisan migrate
```

---

## 2. `app/Models/Worklist.php`

### `$fillable` — REMOVE 4 lines, ADD 5

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
// REMOVED: order_id, sample_number, suffix, parameters, result_data, reassignment_history
```

### `$casts` — REMOVE 3 lines

```php
protected $casts = [
    'collection_date' => 'datetime',
    'started_at'      => 'datetime',
    'completed_at'    => 'datetime',
    'pushed_at'       => 'datetime',
    'patient_age'     => 'integer',
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
```

### `toApiResponse()` — REMOVE 4 keys

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
    // REMOVED: order_id, order_date, parameters, result_data, reassignment_history
}
```

### `boot()` — drop `order_id` generation

```php
protected static function boot()
{
    parent::boot();

    static::creating(function ($model) {
        if (empty($model->id)) {
            $model->id = (string) Str::uuid7();
        }
        // REMOVED: order_id generation (column dropped)
    });
}
```

### DELETE these methods (referenced dropped columns)

```php
public function generateOrderId(): string  // DELETE (no more order_id)
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
        // REMOVED: order_date, parameters, sample_number, suffix
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

### Lines 540-549 — `legacyResult` — drop `result_data` block

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

### Line 1113-1114 — `getWorklistForMirth` response — drop `sample_number`, `suffix`

```php
'barcode'         => $worklist->barcode,
'receipt_barcode' => $worklist->receipt_barcode,
'timing'          => $worklist->timing,
// REMOVED: 'sample_number' => $worklist->sample_number,
// REMOVED: 'suffix'        => $worklist->suffix,
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

## 12. After migration — verify

```bash
# Run migration
php artisan migrate

# Clear caches
php artisan config:clear
php artisan route:clear
php artisan cache:clear

# Check worklists table structure
php artisan tinker
>>> Schema::getColumnListing('worklists');
>>> Schema::getColumnListing('test_samples');
>>> Schema::getColumnListing('test_sample_items');
```

---

## 13. Final column counts

| Table | Before | After | Removed |
|---|---|---|---|
| `worklists` | 30+ | 24 | `order_id`, `sample_number`, `uuid`, `uuid_new`, `parameters`, `result_data`, `reassignment_history`, `order_date` |
| `test_samples` | 16 | 15 | `sl_no` |
| `test_sample_items` | 11 | 9 | `suffix`, `sample_number` |

**Added:**
- `worklists`: `machine_mapping_id`, `cancelled_reason`, `latest_result_id`, `result_count`, `source`
- New table: `worklist_reassignments` (optional audit)
- New endpoint: `GET /api/mirth/machine-config/{device_model}`

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
