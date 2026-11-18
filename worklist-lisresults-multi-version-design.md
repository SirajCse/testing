# Worklist + Multi-Version `lis_results` Design

### Companion to v4 — based on your actual `worklists` table dump

> **UPDATE (unified-result-ingest-design.md):** the RESULT path now has its own doc — one `ingestResult()` function for Bidirectional + Unidirectional + Manual, version computed at write time (`run_id` groups one analyzer message), worklist auto-heal. Two revisions to THIS doc: (1) §2.2/§2.4 simplify — `current_version`, `retake_count` and `superseded_by` are DROPPED (keep only `latest_result_id` + `result_count`; "superseded" = `version < MAX(version)`, no pointer). (2) §1.4/§2.5 unique key — the audit of `SampleController::recheckTest()` (lines 1601–1724) shows every recheck creates a NEW LabTest + NEW TestSampleItem, so the recommended key is now **`UNIQUE (test_sample_item_id, machine_id)`** (matches the existing duplicate guard, zero recheck-flow changes); `(test_sample_id, test_id, machine_id)` remains possible but requires `retakeWorklist()` to also update `lab_test_id`/`test_sample_item_id` to the retest rows. Also confirmed: no `uuid`/`uuid_new` column anywhere — `id` is uuid_v7.

> **Document type:** Schema audit + design recommendation
> **Audience:** You (Laravel dev) + DBA
> **Scope:** (1) Audit your real `worklists` table · (2) Add multi-version `lis_results` so one worklist can hold many result versions · (3) Auto-create worklist trigger logic · (4) Mirth pull/push sync for multi-version results · (5) Final refined `worklists` schema (v1.1) · (6) Retake policy · (7) Full code audit: SampleController → WorklistService → WorklistController
> **Version:** 1.1

---

## 1. Audit of your current `worklists` table

### 1.1 Columns visible in your dump

| Column | Sample value | Status |
|---|---|---|
| `id` | `01a09fc6-e18a-7169-ba07-2b23fe6aa95f` | ✅ UUID v7 — correct |
| `tenant_id` | `019fc0ab-ea14-7293-a5b9-171cbed6db9d` | ✅ |
| `order_id` | `WL260914E54CC9` | ✅ Unique — good |
| `barcode` | `1260914000` | ⚠️ 10 digits — no `-F` suffix (see §1.3) |
| `receipt_barcode` | `1260914000` | ⚠️ Same as barcode (see §1.3) |
| `suffix` | `NULL` | ⚠️ Always NULL — see §1.3 |
| `sample_number` | `1` | ✅ |
| `lab_receipt_id` | (UUID) | ✅ |
| `lab_test_id` | (UUID) | ✅ |
| `test_sample_id` | `NULL` | ❌ Always NULL — drop or populate (§1.4) |
| `test_sample_item_id` | (UUID) | ✅ |
| `test_id` | (UUID) | ✅ |
| `machine_id` | (UUID) | ✅ |
| `patient_id` | (UUID) | ✅ |
| `patient_name` | `Md Nakibuzzaman` | ✅ |
| `patient_age` | `0` | ⚠️ Bug — see §1.5 |
| `patient_gender` | `Male` | ⚠️ Full word, not HL7 `M`/`F` — see §1.6 |
| `test_code` | `CBC` | ✅ |
| `test_name` | `Complete Blood Count (CBC)` | ✅ |
| `sample_type` | `Blood` | ✅ |
| `tube_type` | `EDTA Tube` | ✅ |
| `timing` | `NULL` | ❌ Always NULL — drop or populate (§1.4) |
| `priority` | `routine` | ✅ |
| `status` | `pending` | ✅ |
| `order_date` | `2026-09-14 17:10:25` | ✅ |
| `collection_date` | `2026-09-14 17:56:46` | ✅ |
| `started_at` | `NULL` | ✅ (will be set when Mirth pulls) |
| `completed_at` | `NULL` | ✅ |
| `pushed_at` | `NULL` | ✅ |
| `parameters` | `{"machine_mapping_id":"...","tube_barcode":"..."}` | ✅ JSON snapshot |
| `result_data` | `NULL` | ⚠️ Single column can only hold ONE result — see §2 |
| `reassignment_history` | `NULL` | ✅ |
| `created_at` | `2026-09-14 17:56:46` | ✅ |
| `updated_at` | `2026-09-14 17:56:46` | ✅ |
| `uuid_new` | `NULL` | ❌ Unused — drop (§1.4) |

### 1.2 What's working well

- ✅ UUID v7 PKs (sortable, no collision)
- ✅ All required FKs present
- ✅ `parameters` JSON snapshot captures the `machine_mapping_id` + tube metadata at creation time
- ✅ Lifecycle columns (`pending → processing → completed`) match the Mirth flow
- ✅ `pushed_at` column exists (Pattern B sync-pull uses this)
- ✅ `order_id` is unique and human-readable

### 1.3 Barcode format — what your data shows

Your `barcode` is `1260914000` — a 10-digit numeric. From `HasSimpleSampleBarcode` + `SimpleSampleBarcodeService` the rule is:

- Tube **with** timing, 1st occurrence: `{receipt_barcode}-{TIMING}` → e.g. `1260914000-R` (Random Glucose)
- Tube **with** timing, 2nd+ occurrence: `{receipt_barcode}-{TIMING}{N}` → e.g. `1260914000-F2`
- Tube **without** timing, 1st occurrence: `{receipt_barcode}` itself → `1260914000` (this is why your dump shows `barcode == receipt_barcode`)
- Tube **without** timing, 2nd+ occurrence / recheck: `-C`, `-C2`, `-C3`

So the confirmed semantics are (keep these — the code already works this way):

| Worklist column | Source | Example |
|---|---|---|
| `barcode` | `TestSample->barcode` (the TUBE barcode — what the analyzer scans) | `1260914000-R` |
| `receipt_barcode` | `LabReceipt->barcode` | `1260914000` |
| `suffix` | the `-R` / `-F2` / `-C` part (when present) | `R` |

In your dump both were equal only because that tube had no timing. **Keep the code semantics** (`barcode` = tube, `receipt_barcode` = receipt) — MirthController lookups, `getWorklistByBarcode` and the analyzers all scan the TUBE barcode; swapping the meaning would break every query.

The Mirth templates in v3/v4 use `2608281001-F` as the sample barcode. **Change that to your real format** (`1260914000` plain, `1260914000-R` with timing) when you copy the templates into your channels.

### 1.4 FINAL refined `worklists` schema (v1.1 — your confirmed decision)

You confirmed: `id` is already UUID v7 (both `HasUUIDSettings::bootHasUUIDSettings` and `Worklist::boot` set `Str::uuid7()`), so **no extra `uuid`/`uuid_new` column is needed**. You also decided to drop `order_id` and `sample_number`, and to KEEP `test_sample_id` + `timing` (they back the unique constraint and the analyzer payload). Final DDL:

```sql
-- ── DROP: 4 columns ──────────────────────────────────────────────
ALTER TABLE worklists
  DROP COLUMN uuid_new,        -- never populated; id IS the uuid_v7
  DROP COLUMN uuid,            -- same (if present in your migration)
  DROP COLUMN order_id,        -- WL260914E54CC9 redundant; id + created_at suffice
  DROP COLUMN sample_number;   -- derivable: tube_sequence lives on test_samples

-- ── ADD: multi-version result tracking (v1.2 revision — simplified) ──
-- current_version and retake_count are DROPPED from this draft:
--   latest_result_id → lis_results.version answers "current", result_count (= MAX version)
--   answers "how many times", and "superseded" is just version < MAX(version).
ALTER TABLE worklists
  ADD COLUMN latest_result_id  CHAR(36) NULL  AFTER result_data,
  ADD COLUMN result_count      INT NOT NULL DEFAULT 0  AFTER latest_result_id,
  ADD COLUMN source            VARCHAR(20) NOT NULL DEFAULT 'fanout' AFTER result_count,
  ADD INDEX idx_worklist_latest_result (latest_result_id);

-- ── UNIQUE: v1.2 revision — see banner. Matches the existing duplicate
--    guard (WorklistService.php:160-162) and needs ZERO recheck-flow changes.
ALTER TABLE worklists
  ADD UNIQUE KEY uq_worklist_item_machine (test_sample_item_id, machine_id);

-- (Alternative, only if you keep one-row-per-tube+test+machine and rewrite the
--  recheck flow per §2.5 + lab_test_id pointer update:
--  ADD UNIQUE KEY uq_worklist_sample_test_machine (test_sample_id, test_id, machine_id);)

-- ── COVERING INDEXES: this is what makes get-worklist fast ───────
ALTER TABLE worklists
  ADD INDEX idx_worklist_machine_status_pushed (machine_id, status, pushed_at),
  ADD INDEX idx_worklist_barcode_status (barcode, status);
```

**Two cautions for the UNIQUE key:**

1. MySQL allows unlimited rows where `test_sample_id IS NULL`. `createRecheckWorklist()` and `WorklistController::createWorklist()` currently leave `test_sample_id` NULL — those rows silently bypass the constraint. Fix: always populate `test_sample_id` (the recheck flow has a real `$testSample->id` — pass it), and treat NULL as invalid in code review.
2. `WorklistController::createWorklist()` / reassign paths can now hit duplicate-key errors — the required code handling is in §7 (drop-column checklist) and §4B (audit items A3/A11).

**Code columns to clean up when you drop (see §7 for the full checklist):** `order_id` is written by `Worklist::boot`, `WorklistController` (8 places), `MirthController`, the DataTables search/sort; `sample_number` is written by `WorklistService::createWorklistForTest` / `createRecheckWorklist` and read by `MirthController` lookups. Nothing in the code writes `uuid`/`uuid_new` — those two are pure DB drops.

**KEEP (contrary to my v1.0 suggestion):** `test_sample_id` + `timing`. `test_sample_id` is required by the unique constraint; `timing` feeds the `-F/-R` suffix logic and OBR payload. Your decision is correct — v1.0 §1.4 (drop both) is superseded.

**Column role summary after the change:**

| Column | Role |
|---|---|
| `id` | UUID v7 PK — the only identifier (no `uuid`, no `order_id`) |
| `test_sample_item_id` | link to the collected item (audit trail) |
| `test_sample_id` | link to the TUBE — part of the unique key + retake identity |
| `lab_receipt_id`, `lab_test_id`, `test_id`, `machine_id`, `patient_id` | unchanged |
| `barcode` / `receipt_barcode` / `suffix` | tube barcode / receipt barcode / timing suffix |
| `timing` | F / R / 2H … — also feeds `suffix` |
| `latest_result_id`, `result_count` | multi-version result pointer (§2) — v1.2: `current_version` dropped, `result_count` = MAX(version) |
| `source` | how the row was born: `fanout` \| `recheck` \| `manual` \| `auto` (auto = healed by `ingestResult`) |
| `parameters`, `result_data` | creation snapshot / fast UI snapshot |
| `pushed_at`, `started_at`, `completed_at` | Pattern B sync lifecycle |

### 1.5 `patient_age = 0` — bug

Row `01a09fc6...` shows `patient_age = 0` for patient `Md Nakibuzzaman`. Row `01a09ff3...` shows `patient_age = 23` for `dadd`. So the column works for some patients and not others.

**Likely cause:** in `WorklistService::createWorklistForTest`, the age is calculated from `patient.dob` — but if `dob` is NULL or a future date, `Carbon::parse($dob)->age` returns 0.

**Fix (in `WorklistService::createWorklistForTest`):**

```php
'patient_age' => $patient && $patient->dob
    ? Carbon::parse($patient->dob)->age
    : null,    // null is better than 0 — 0 means "newborn", NULL means "unknown"
```

### 1.6 `patient_gender = "Male"` — full word vs HL7 code

HL7 v2.3.1 `PID.8` expects `M`/`F`/`O`/`U`. Your data stores `"Male"`/`"Female"`.

**Fix options:**

- **Option A (preferred):** keep storing `"Male"`/`"Female"` in DB; convert to `M`/`F` in the Mirth OML^O21 builder:

  ```javascript
  var gender = ({ 'Male':'M', 'male':'M', 'Female':'F', 'female':'F' })
      [String(worklistData.patient_gender)] || 'O';
  ```

- **Option B:** standardize DB to `M`/`F`/`O` — more invasive, breaks existing UI display.

Use Option A — one line in the Mirth transformer.

---

## 2. `lis_results` table — multi-version design

### 2.1 The problem with your current setup

Your `worklists.result_data` JSON column can only hold **one result snapshot**. If an analyzer re-runs the same barcode (correction, retest, machine error retry), the new result overwrites the old — audit trail is lost.

You need a separate `lis_results` table where **one worklist can have many result versions**, each preserved for audit.

### 2.2 Recommended schema (v1.2 — simplified, see banner)

```sql
CREATE TABLE lis_results (
  id              CHAR(36)     NOT NULL PRIMARY KEY,         -- UUID v7 (no extra uuid column)
  worklist_id     CHAR(36)     NULL,                          -- NULL only when parked 'unmatched'
  version         INT          NOT NULL DEFAULT 1,            -- computed at WRITE time: MAX(version)+1
  result_type     VARCHAR(20)  NOT NULL DEFAULT 'final',     -- 'final' | 'rerun' | 'unmatched'
  machine_id      CHAR(36)     NOT NULL,
  device_model    VARCHAR(100) NULL,
  barcode         VARCHAR(100) NOT NULL,
  test_code       VARCHAR(50)  NOT NULL,
  result_data     JSON         NULL,                           -- the full payload Mirth sent
  raw_message     MEDIUMTEXT   NULL,                          -- original HL7 ORU for audit
  message_hash    CHAR(64)     NOT NULL,                      -- SHA-256 idempotency (UNIQUE)
  status          VARCHAR(20)  NOT NULL DEFAULT 'matched',    -- matched | unmatched
  error_log       TEXT         NULL,                          -- unmatched reason
  received_at     DATETIME     NULL,
  verified_at     DATETIME     NULL,
  verified_by     CHAR(36)     NULL,
  source          VARCHAR(20)  NOT NULL DEFAULT 'mirth',     -- 'mirth'|'manual'|'api'
  synced_at       DATETIME     NULL,                          -- Pattern B: when Mirth push completed
  sync_error      TEXT         NULL,
  created_at      DATETIME     NULL,

  INDEX idx_lisres_worklist_version (worklist_id, version DESC),
  INDEX idx_lisres_status (status),
  INDEX idx_lisres_hash (message_hash),
  INDEX idx_lisres_synced (synced_at, status),
  CONSTRAINT fk_lisres_worklist FOREIGN KEY (worklist_id) REFERENCES worklists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**v1.2 simplifications:** `superseded_by` pointer and the `superseded` status are GONE — "current" = `MAX(version)` (or `worklists.latest_result_id`), "superseded" = every row with `version < MAX(version)`. No pointer to maintain, nothing can go stale. `run_id` (one per analyzer message, §4.5) groups the many OBX parameters of one run onto ONE version.

### 2.3 How versions work — concrete example

A patient has CBC test → worklist created (`worklists.id = WL001`).

| Event | Action |
|---|---|
| 1. Urit BH-5390 runs CBC, sends ORU (msg-A1: WBC+RBC+HGB) | 3 INSERTs `lis_results(worklist_id=WL001, version=1, result_type='final', run_id='msg-A1')` · UPDATE `worklists SET latest_result_id=<last insert>, result_count=1, status='completed'` |
| 2. Lab tech notices hemolysis; flags for re-run | Worklist status reset to `pending` (`retakeWorklist()`, §2.5) |
| 3. Sysmex XN-1000 re-runs CBC, sends new ORU (msg-B7) | 3 INSERTs `version=2, result_type='rerun', run_id='msg-B7'` · UPDATE `worklists SET latest_result_id=..., result_count=2, status='completed', result_data=(new JSON)` |
| 4. Same tube run again after correction | `version=3` — the old rows are NEVER touched: version 1 and 2 stay exactly as received |

**No `superseded` write exists.** Old versions are never mutated — history is append-only, which is what makes the audit trail trustworthy.

At any time, to get the **current** result for a worklist:

```sql
SELECT lr.* FROM lis_results lr
JOIN worklists w ON w.latest_result_id = lr.id
WHERE w.id = 'WL001';
```

To get the **full history**:

```sql
SELECT * FROM lis_results
WHERE worklist_id = 'WL001'
ORDER BY version DESC;
```

### 2.4 Keep `worklists.result_data` as a snapshot

Don't remove `worklists.result_data`. Use it as a **denormalized snapshot of the latest version** for fast UI rendering — but the **authoritative source is `lis_results`**. When you insert a new version, also update `result_data`:

```php
// In MirthController::legacyResult, after inserting lis_results row:
$worklist->update([
    'result_data'       => $newResult->result_data,    // snapshot for UI
    'latest_result_id'  => $newResult->id,
    'result_count'      => $newResult->version,        // = MAX(version) after this insert
    'status'            => Worklist::STATUS_COMPLETED, // idempotent — no "already completed" gate
    'completed_at'      => $worklist->completed_at ?? now(),
]);

// NO "mark previous as superseded" step — the previous row keeps its version,
// and MAX(version) always points at the latest. That is the whole mechanism.
```

---

## 2.5 Retake policy — your question: "retake same test — duplicate worklist row needed or not?"

> **v1.2 revision (see banner):** with the recommended key `UNIQUE (test_sample_item_id, machine_id)`, a same-tube recheck gets its OWN row (new TestSampleItem → new worklist → correct `lab_test_id` linkage, its own version chain) — no rewrite needed. The reuse strategy below applies ONLY if you keep `(test_sample_id, test_id, machine_id)`; in that case `retakeWorklist()` must ALSO update `lab_test_id` + `test_sample_item_id` to the retest rows created by `recheckTest()` (otherwise the retest LabTest never gets `has_result=true`).

**Answer: NO duplicate row for the same tube.** With the UNIQUE key `(test_sample_id, test_id, machine_id)` the rule becomes simple and it matches how the analyzers physically work:

| Situation | New worklist row? | What happens |
|---|---|---|
| Analyzer re-run / result correction, **same tube** | **NO** | Reuse the SAME row: `status → pending`, `parameters.retake_count + 1` (JSON counter — the dedicated column was dropped in v1.2), `pushed_at → NULL` (re-sync), new row in `lis_results` with `version+1` |
| Operator re-collects the same tube barcode (`sampleCollect` again) | **NO** | `TestSample::updateOrCreate` finds the same tube → same worklist rows → retake path above |
| `recheckTest` with a **NEW tube** (`-C` / `-R2` barcode) | **YES** | New `test_sample_id` → a new row is correct and allowed by the UNIQUE key |
| `recheckTest` with `use_existing_sample=true` (same tube) | **NO** | Current code creates a NEW item + NEW worklist → violates the UNIQUE key → must switch to the retake path (audit item A3) |
| Same test ordered twice on one receipt, same tube | **ONE row** | UNIQUE collapses them onto the first row (audit item A3 fix) |

Why reuse (not duplicate) is right for a retake:

1. The analyzer only needs ONE entry per tube+test — a second pending row makes some analyzers run the test twice or show duplicates on their worklist screen.
2. The history you want to preserve is RESULT history, and that now lives in `lis_results` (version chain) — the worklist row is just the "current state" pointer.
3. The retake counter on the row gives you the "how many times" answer at a glance (`parameters.retake_count` — v1.2 dropped the dedicated column; `lis_results.result_count`/`MAX(version)` answers the same question), while `lis_results.version` + `result_type='rerun'` gives the full audit trail.

**For the worklist-creation speed question — what the schema change buys you:**

- The UNIQUE key `(test_sample_id, test_id, machine_id)` lets `createWorklistForTest` use a single `firstOrCreate`-style guard instead of two separate existence queries (`existing` + `isCompleted`) per machine — one indexed lookup instead of two.
- `idx_worklist_machine_status_pushed` makes the pull endpoint (`WHERE machine_id=? AND status='pending' AND pushed_at IS NULL`) an index-only range scan — this is the query that runs every 30 s.
- `idx_worklist_barcode_status` makes analyzer barcode queries (`WHERE barcode=? AND status IN (...)`) instant.
- Dropping `order_id`/`sample_number`/`uuid` shrinks every insert and the two hot indexes; `parameters` stays as the only JSON payload.
- Wrap the fan-out in ONE transaction (A4) — one commit for N machines instead of N implicit commits, plus all-or-nothing semantics.

**Code — add `retakeWorklist()` to WorklistService and route every retake through it:**

```php
// WorklistService — NEW method
public function retakeWorklist(string $worklistId, ?string $reason = null): array
{
    $worklist = Worklist::find($worklistId);
    if (! $worklist) {
        return ['success' => false, 'message' => 'Worklist not found'];
    }

    if ($worklist->status === self::STATUS_CANCELLED) {
        return ['success' => false, 'message' => 'Cancelled worklists cannot be retaken — create a new order'];
    }

    DB::transaction(function () use ($worklist, $reason) {
        $worklist->status        = self::STATUS_PENDING;
        $worklist->pushed_at     = null;    // ⬅ CRITICAL: re-sync to Mirth cache (audit A5)
        $worklist->started_at    = null;
        $worklist->completed_at  = null;
        // v1.2: retake_count column dropped — count in parameters JSON instead
        $params                  = $worklist->parameters ?? [];
        $params['retake_count']  = ($params['retake_count'] ?? 0) + 1;
        if ($reason) {
            $params['retake_reason'] = $reason;
        }
        $worklist->parameters    = $params;
        $worklist->save();

        // put the item back to "collected" so the UI shows it runnable again
        if ($worklist->test_sample_item_id) {
            TestSampleItem::where('id', $worklist->test_sample_item_id)
                ->update(['status' => 'collected']);
        }
    });

    return [
        'success' => true,
        'message' => "Worklist reset for retake #{$worklist->parameters['retake_count']}",
        'worklist' => $worklist,
    ];
}
```

Also relax the existing guard — `retryWorklist()` currently refuses completed rows ("Cannot retry completed worklist", `WorklistService.php:613`). For retakes that's exactly the case you need:

```php
public function retryWorklist(string $worklistId): array
{
    $worklist = Worklist::find($worklistId);
    if (! $worklist) {
        return ['success' => false, 'message' => 'Worklist not found'];
    }

    // completed = retake (keep the row, new version in lis_results)
    return $this->retakeWorklist($worklistId);
}
```

And in `Worklist::markAsPending()` (used by retry), mirror the same rule so nothing slips through:

```php
public function markAsPending(): bool
{
    $this->status       = self::STATUS_PENDING;
    $this->started_at   = null;
    $this->completed_at = null;
    $this->pushed_at    = null;   // ⬅ re-sync to Mirth
    return $this->save();
}
```

With this in place, `recheckTest(use_existing_sample=true)` becomes: find the existing worklist row for `(test_sample_id, test_id, machine)` and call `retakeWorklist()` instead of `createRecheckWorklist()`.

---

## 3. Auto-create worklist — easiest approach

### 3.1 Current trigger (from your code)

`SampleController::generateWorklist` (line 1127) is called when the user clicks "Generate Worklist" on the LabReceipt page. It calls `WorklistService::createWorklistForGroup($labReceiptId)`.

### 3.2 Recommended: auto-trigger on tube collection

**Goal:** when the lab tech collects a tube and marks it collected, worklist rows are auto-created for every machine that can run that test — without a separate button click.

**Implementation:** Laravel Event/Observer pattern.

#### Step 1: Create an Observer

```php
// app/Observers/TestSampleItemObserver.php
namespace App\Observers;

use App\Models\TestSampleItem;
use App\Services\WorklistService;

class TestSampleItemObserver
{
    public function __construct(private WorklistService $worklistService) {}

    public function updated(TestSampleItem $item): void
    {
        // Only when status transitions to 'collected'
        if ($item->isDirty('status') && $item->status === 'collected' && $item->collected_by) {
            try {
                $this->worklistService->createWorklistForTest($item);
            } catch (\Throwable $e) {
                \Log::error('Auto-worklist creation failed', [
                    'item_id' => $item->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }
}
```

#### Step 2: Register the Observer

```php
// app/Providers/AppServiceProvider.php — in boot()
use App\Models\TestSampleItem;
use App\Observers\TestSampleItemObserver;

public function boot(): void
{
    TestSampleItem::observe(TestSampleItemObserver::class);
}
```

#### Step 3: Keep the manual "Generate Worklist" button as fallback

In case the observer missed something (e.g. existing tubes collected before the observer was added), the manual button still works — `WorklistService::createWorklistForGroup` is idempotent (checks for existing worklists on `(test_sample_item_id, machine_id)` and skips if found).

### 3.3 What happens on auto-create (fan-out per `machine_test_mapping`)

```
Tube collected for patient P, test CBC
        │
        ▼
TestSampleItem.status → 'collected'
        │
        ▼
TestSampleItemObserver::updated()
        │
        ▼
WorklistService::createWorklistForTest($item)
        │
        ├── Find active machine_test_mappings for test_id=CBC
        │   → 3 mappings: Urit BH-5390, Sysmex XN-1000, Mindray BC-6800
        │
        ├── For EACH mapping → INSERT worklist row
        │   ├── worklist for Urit BH-5390 (status='pending')
        │   ├── worklist for Sysmex XN-1000 (status='pending')
        │   └── worklist for Mindray BC-6800 (status='pending')
        │
        └── All 3 rows visible in Laravel admin → "Worklists" page
                │
                ▼
        Mirth sync-pull channel (every 30 s)
                │
                ├── GET /api/mirth/worklist (X-Machine-Key: BH-5390)
                │   → returns Urit's pending worklist row, sets pushed_at
                │
                ├── GET /api/mirth/worklist (X-Machine-Key: SYSMEX-XN1000)
                │   → returns Sysmex's pending worklist row, sets pushed_at
                │
                └── GET /api/mirth/worklist (X-Machine-Key: BC-6800)
                    → returns Mindray's pending worklist row, sets pushed_at
```

When Urit BH-5390 scans the tube and queries, it sees only **its own** worklist row (filtered by `device_model`). The sibling rows on Sysmex and Mindray are independent — they'll be locked when Urit claims its row.

---

## 4. Mirth pull/push with multi-version results (Pattern B)

### 4.1 Sync-pull: unchanged

The sync-pull channel polls Laravel for `WHERE pushed_at IS NULL` worklists. It doesn't care about `lis_results` — that's only relevant for sync-push.

### 4.2 Sync-push: now pushes multi-version results

When Mirth receives a new ORU and inserts a row into `lis_cache.lis_results`, it computes the version number locally (v1.2: `MAX(version)+1` at write time, no superseded writes, no `current_version`). When sync-push POSTs to Laravel, Laravel stores the version and updates the worklist's `latest_result_id` / `result_count`. The full end-to-end result logic now lives in `unified-result-ingest-design.md` §3 — this section only adapts the Pattern B cache.

#### Updated `lis_cache.lis_results` schema (Pattern B — v1.2)

```sql
CREATE TABLE lis_cache.lis_results (
  id                UUID         NOT NULL PRIMARY KEY,
  worklist_local_id UUID         NULL,                      -- FK to lis_cache.lis_worklists.id
  worklist_laravel_id UUID      NULL,                       -- Laravel worklists.id (filled after sync-push)
  version           INT          NOT NULL DEFAULT 1,         -- MAX(version)+1 at INSERT; rows of one run share it
  run_id            UUID         NULL,                       -- one per analyzer message (groups CBC params)
  result_type      VARCHAR(20)  NOT NULL DEFAULT 'final',   -- final | rerun | unmatched
  machine_id        VARCHAR(64)  NOT NULL,
  device_model     VARCHAR(100) NOT NULL,
  barcode          VARCHAR(100) NOT NULL,
  test_code        VARCHAR(50)  NULL,
  result_data      JSONB        NOT NULL,
  raw_message      TEXT         NULL,
  message_hash     CHAR(64)     NULL,
  status           VARCHAR(20)  NOT NULL DEFAULT 'completed',   -- completed | unmatched
  retry_count      INT          NOT NULL DEFAULT 0,
  max_retries      INT          NOT NULL DEFAULT 5,
  synced_at        TIMESTAMP    NULL,
  sync_response    TEXT         NULL,
  sync_error       TEXT         NULL,
  laravel_id       UUID         NULL,                       -- Laravel lis_results.id (filled after sync-push)
  created_at       TIMESTAMP    NOT NULL DEFAULT NOW(),

  INDEX idx_lisres_synced (synced_at, retry_count),
  INDEX idx_lisres_worklist (worklist_local_id),
  INDEX idx_lisres_version (worklist_local_id, version),
  INDEX idx_lisres_barcode (barcode)
);
```

(`superseded_by` and `updated_at` dropped — v1.2. History = rows with `version < MAX(version)`; nothing is ever mutated after INSERT.)

#### Updated Mirth destination (§21.B from v4) — computes version

```javascript
// In the destination JavaScript Writer, after finding the local worklist:
// runId = the Mirth message ID (generate ONCE per ORU message in the source transformer,
// e.g. var runId = $('messageId') or java.util.UUID.randomUUID().toString())

var rs = db.executeCachedQuery(
    "SELECT COALESCE(MAX(version), 0) + 1 AS next_version " +
    "FROM lis_cache.lis_results WHERE worklist_local_id = ?",
    [worklistLocalId]
);
var nextVersion = 1;
if (rs.next()) nextVersion = rs.getInt('next_version');
rs.close();

var resultType = 'final';
var rs2 = db.executeCachedQuery(
    "SELECT COUNT(*) AS cnt FROM lis_cache.lis_results " +
    "WHERE worklist_local_id = ? AND status = 'completed' AND run_id IS DISTINCT FROM ?",
    [worklistLocalId, runId]
);
if (rs2.next() && rs2.getInt('cnt') > 0) resultType = 'rerun';
rs2.close();

var resultId = java.util.UUID.randomUUID().toString();
var nowTs = new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss").format(new java.util.Date());

db.executeUpdate(
    "INSERT INTO lis_cache.lis_results " +
    "(id, worklist_local_id, version, run_id, result_type, machine_id, device_model, " +
    " barcode, test_code, result_data, raw_message, status, retry_count, created_at) " +
    "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 'completed', 0, ?)",
    [resultId, worklistLocalId, nextVersion, runId, resultType,
     machineId, deviceModel, barcode, testCode || '',
     payload, nowTs]
);

if (worklistLocalId) {
    // Point at latest — NO "mark previous as superseded" write (v1.2: rows are append-only)
    db.executeUpdate(
        "UPDATE lis_cache.lis_worklists SET status='completed', completed_at=COALESCE(completed_at, ?), " +
        "latest_result_id=?, result_count=?, result_data=? " +
        "WHERE id=?",
        [nowTs, resultId, nextVersion, payload, worklistLocalId]
    );
}
```

#### Updated sync-push payload — includes version metadata

When sync-push POSTs to Laravel, it includes the version + result_type so Laravel knows whether this is a new result, a re-run, or a correction:

```json
{
  "machine_id": "0194abcd-...",
  "api_key": "...",
  "barcode": "1260914000",
  "device_model": "BH-5390",
  "date": "20261114103500",
  "worklist_id": "01a09fc6-e18a-7169-ba07-2b23fe6aa95f",
  "version": 2,
  "result_type": "rerun",
  "run_id": "<mirth-message-id>",
  "mapping": { "WBC": ["<component-uuid>"] },
  "results": [
    { "code": "WBC", "name": "White Blood Cell Count", "value": "6.20", "unit": "10^9/L" }
  ]
}
```

### 4.3 Updated `MirthController::legacyResult` (Laravel side)

> **v1.2 revision:** the full, final body of `legacyResult` (and `receiveResults`) now lives in `unified-result-ingest-design.md` §3–§4 — the recommended path is to replace both method bodies with calls to `WorklistService::ingestResult()`, which handles version, auto-heal, TestReport and worklist completion in one place. If you prefer the minimal in-place patch instead, the deltas below are all you need:

```php
public function legacyResult(Request $request)
{
    $validator = Validator::make($request->all(), [
        'machine_id'   => 'required|string',
        'barcode'      => 'required|string',
        'api_key'      => 'nullable|string',
        'device_model' => 'nullable|string',
        'date'         => 'nullable|string',
        'run_id'       => 'nullable|string',            // NEW: one per analyzer message
        'worklist_id'  => 'nullable|string',
        'mapping'      => 'required|array',
        'results'      => 'required|array',
        'results.*.code'  => 'required|string',
        'results.*.value' => 'required|string',
        'results.*.unit'  => 'nullable|string',
        'results.*.name'  => 'nullable|string',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'type' => 'invalid',
            'note' => $validator->messages(),
            'status' => 400,
        ], 200);
    }

    $input = $validator->validated();

    // ... existing mapping normalization, date parsing, LabReceipt lookup ...

    // Find the worklist — v1.2: completed/locked rows are ACCEPTED too (that's version 2)
    $worklist = null;
    if (!empty($input['worklist_id'])) {
        $worklist = Worklist::find($input['worklist_id']);
    }
    if (!$worklist) {
        $worklist = Worklist::where('barcode', $input['barcode'])
            ->where('machine_id', $machine->id ?? null)
            ->orderByRaw("FIELD(status, 'pending', 'processing', 'locked', 'completed')")
            ->latest()->first();
    }

    // v1.2: version at WRITE time — same run_id shares one version (20 CBC params = version 1)
    $runId = $input['run_id'] ?? (string) Str::uuid7();
    $version = null;
    if ($worklist) {
        $sameRun = LisResult::where('worklist_id', $worklist->id)->where('run_id', $runId)->first();
        $version = $sameRun?->version
            ?? (LisResult::where('worklist_id', $worklist->id)->max('version') ?? 0) + 1;
    }

    $newResult = LisResult::create([
        'id'           => (string) Str::uuid7(),
        'worklist_id'  => $worklist?->id,
        'version'      => $version ?? 1,
        'result_type'  => $version > 1 ? 'rerun' : 'final',
        'machine_id'   => $input['machine_id'],
        'device_model' => $input['device_model'] ?? null,
        'barcode'      => $input['barcode'],
        'test_code'    => $worklist?->test_code ?? '',
        'result_data'  => json_encode([
            'mapping' => $input['mapping'],
            'results' => $input['results'],
        ]),
        'raw_message'  => $input['raw_message'] ?? null,
        'message_hash' => hash('sha256', json_encode([
            $input['barcode'], $input['machine_id'], $runId, $input['results'],
        ])),
        'status'       => 'matched',
        'received_at'  => now(),
        'source'       => 'mirth',
    ]);

    // v1.2: NO "mark previous as superseded" write — rows are append-only.

    // Process each result → TestReport::updateOrCreate (existing logic)
    // ... [existing foreach loop from legacyResult] ...

    // Update worklist to point at latest version (idempotent — no completed gate)
    if ($worklist) {
        $worklist->update([
            'result_data'       => json_encode(['mapping' => $input['mapping'], 'results' => $input['results']]),
            'latest_result_id' => $newResult->id,
            'result_count'      => $version,
            'status'            => Worklist::STATUS_COMPLETED,
            'completed_at'      => $worklist->completed_at ?? now(),
        ]);

        // Also call completeWorklists() (v3 fix §34.3)
        $this->completeWorklists($input['machine_id'], $input['barcode'],
            array_keys($touchedTestIds ?? []), $parsedDate);
    }

    return response()->json([
        'type'   => 'success',
        'note'   => 'Results processed successfully',
        'status' => 200,
        'result_id' => $newResult->id,
        'version' => $version,
    ], 200);
}
```

### 4.4 Sync-push retry strategy

| Result type | Sync-push behavior on failure |
|---|---|
| `final` (version 1) | Retry up to 5×; if still failing, mark `synced_at=now()`, `sync_error='transient fail'`; operator inspects |
| `correction` (version N+1) | Same as above — Laravel needs to know there's a correction |
| `re-run` (version N+1) | Same as above |
| `preliminary` (will be replaced by `final`) | Best-effort retry; if it fails, the final version supersedes it |

### 4.5 Pattern B sync-push channel update

The sync-push channel now needs to send the `worklist_id` and `version` fields from the local DB to Laravel. Update the sync-push destination code:

```javascript
// In sync-push channel destination (after getting the row from DB Reader)
var payload = {
    machine_id:   $('machine_id'),
    api_key:      '<API_KEY>',          // from Configuration Map
    barcode:      $('barcode'),
    device_model: $('device_model'),
    date:         $('created_at').substring(0, 14).replace(/[-: ]/g, ''),  // YYYYMMDDHHMMSS
    worklist_id:  $('worklist_laravel_id'),   // NEW
    version:      $('version'),                // NEW
    result_type:  $('result_type'),            // NEW
    mapping:      JSON.parse($('result_data')).mapping,
    results:      JSON.parse($('result_data')).results
};
// POST to /mirth/legacy-result
// On type='success': UPDATE lis_cache.lis_results SET synced_at=now(), laravel_id=<response.result_id>
```

---

## 4B. Code audit — SampleController → WorklistService → WorklistController

Full read-through of your actual code (SampleController, WorklistService, Worklist model, WorklistController, TestSampleItem model). What your flow does today:

1. `SampleController::sample()` → `prepareExpandedTests()` groups `labTest.test.consumptions` by **product_id + timing** → `autoCreateTestSamples()` creates `TestSample` (tube) + `TestSampleItem` rows (status `pending`, no collector).
2. `SampleController::sampleCollect()` → for each checked tube: `TestSample::updateOrCreate(barcode+product+timing+receipt)` with `collected_by/at`; each item gets `status='collected'` + `collected_by/at`; `LabTest` gets `collected_by/at`; **after** the DB transaction: `createWorklistForGroup($receiptId)`.
3. `WorklistService::createWorklistForGroup()` → all collected items of the receipt → per item `createWorklistForTest()` → active `machine_test_mappings` → **fan-out**: one `Worklist` row per (item × machine), duplicate guard per (item, machine), status `locked` if the test is already completed on another machine.
4. `WorklistService::createRecheckWorklist()` (via `SampleController::recheckTest`) → single machine (first mapping), same tube or new `-C` tube.
5. `WorklistController` → cancel/retry/update/delete/reassign UI + machine-facing APIs (`/api/mirth/*`, `/api/worklist/*`).

The fan-out + sibling-lock design is correct. These are the gaps found, in priority order:

### Critical — fix before anything else

**A1 · `where('is_collected', true)` filters a column that is not a DB column** — `WorklistService.php:57`

```php
->whereHas('testSample', fn ($q) => $q->where('lab_receipt_id', $labReceiptId))
->where('is_collected', true)          // ⬅ is_collected is a PHP ACCESSOR, not a column
```

`TestSampleItem::getIsCollectedAttribute()` is computed from `collected_by` + `collected_at` — there is no `is_collected` column in the model's fillable, and the model's own scope says `scopeCollected() { return $query->whereNotNull('collected_by'); }`. Two possibilities:

- If your real `test_sample_items` table has **no** `is_collected` column → SQLSTATE 42S22 on EVERY `createWorklistForGroup`. You would never notice from `sampleCollect` because it catches the exception and still flashes "collected successfully" (audit A4); only the manual "Generate Worklist" button shows the 500.
- If the column **exists** but nothing in the code writes it → the query returns 0 rows and worklists are silently never created.

**Verify first:** `SHOW COLUMNS FROM test_sample_items LIKE 'is_collected';` — then apply the safe fix either way:

```php
->whereNotNull('collected_by')
->whereNotNull('collected_at')
```

**A2 · `TestSampleItem::insert()` without `id`** — `SampleController.php:836-850` (`autoCreateTestSamples`)

Raw `DB::insert` bypasses Eloquent events → `HasUUIDSettings` never fires → `id` is NULL on a `CHAR(36) NOT NULL` PK. In MySQL strict mode: *"Field 'id' doesn't have a default value"* → the whole tube creation rolls back. In non-strict mode: every item gets `id=''` and the second item of the same tube collides on the PK. Fix:

```php
$rows[] = [
    'id'            => (string) Str::uuid7(),   // ⬅ ADD THIS
    'test_sample_id'=> $testSample->id,
    ...
];
```

**A3 · Retake / duplicate order breaks the new UNIQUE key** — ties to §2.5

- `recheckTest(use_existing_sample=true)` creates a NEW `TestSampleItem` on the SAME tube, then `createRecheckWorklist()` inserts a worklist with the same `(test_sample_id, test_id, machine_id)` → duplicate-key 500 after you add the UNIQUE constraint (today: two worklist rows for one tube+test+machine — the analyzer runs the test twice).
- `createRecheckWorklist()` never sets `test_sample_id` on the worklist (NULL bypasses the constraint and breaks sync joins) and its duplicate guard misses `locked`/`completed` rows.
- Two orders of the same test on one receipt (same tube) → `createWorklistForTest()`'s guard is per-item, so the second item's INSERT collides; and because it `throw $e`, the whole receipt's fan-out aborts midway.

Fixes: (1) route `use_existing_sample=true` through `retakeWorklist()` (§2.5); (2) pass `'test_sample_id' => $testSample->id` in `createRecheckWorklist`; (3) key the duplicate guard on `(test_sample_id, test_id, machine_id)` and reuse the existing row:

```php
// createWorklistForTest — replace the per-item guard
$existing = Worklist::where('test_sample_id', $testSample->id)
    ->where('test_id', $testId)
    ->where('machine_id', $machine->id)
    ->first();

if ($existing) {
    $worklists[] = $existing;   // reuse instead of throwing on INSERT
    continue;
}
```

**A4 · Fan-out is not transactional and failures are silent** — `WorklistService.php:67-70` / `SampleController.php:1096-1105`

Each `Worklist::create()` is its own implicit transaction. One mapping failure → rows created so far stay, remaining machines never get rows, the exception is caught in `sampleCollect` and the phlebotomist still sees *"Test Sample collected successfully"*. Fix:

```php
public function createWorklistForGroup(string $labReceiptId, $collectedAt = null): array
{
    ...
    $allWorklists = DB::transaction(function () use ($items) {   // ⬅ all-or-nothing
        $created = [];
        foreach ($items as $item) {
            $created = array_merge($created, $this->createWorklistForTest($item));
        }
        return $created;
    });
    ...
}
```

and in `sampleCollect`, show the real outcome:

```php
foreach ($processed as $labReceiptId) {
    try {
        $created = $this->worklistService->createWorklistForGroup($labReceiptId);
        if (empty($created)) {
            session()->flash('warning', "No worklists created for receipt {$labReceiptId} — check machine mappings");
        }
    } catch (\Exception $e) {
        Log::warning('Worklist creation failed', [...]);
        session()->flash('warning', 'Sample collected, but worklist creation failed: '.$e->getMessage());
    }
}
```

### High — wrong sync behaviour

**A5 · `markAsPending()` does not clear `pushed_at`** — `Worklist.php:320-326`. Pattern B pull-sync is `WHERE pushed_at IS NULL`; a retried/retaken worklist is never re-pushed to the Mirth cache. Fix shown in §2.5.

**A6 · `getWorklistForMirth()` never sets `pushed_at`** — `WorklistController.php:1088-1136`. It selects `pending + pushed_at IS NULL` but doesn't reserve them, so **every 30 s poll re-returns the same rows** → duplicate `lis_worklists` in the Mirth cache. (Your other endpoint `MachineController::getWorklist` does reserve — this one doesn't.) Fix: bulk-reserve right after building the payload:

```php
$ids = $worklists->pluck('id')->all();
// ... build $data ...
if ($ids) {
    Worklist::whereIn('id', $ids)->update(['pushed_at' => now()]);   // reservation
}
```

**A7 · `TestSampleItem::getStatusAttribute()` masks the real DB status** — `TestSampleItem.php:125-139`. The `worklist()` relation filters `status IN (pending, processing)`, so once the worklist completes, `$this->worklist` is NULL → the accessor returns `'collected'` forever, ignoring the `'completed'` you correctly wrote to the DB in `submitResult()`. Any UI reading `$item->status` shows the wrong state. Fix: delete the accessor override (the DB column is already maintained by sampleCollect/submitResult), or include `'completed'` in the relation.

**A8 · Acknowledge race** — `Worklist::markAsProcessing()` (259-277) checks `canProcess()` then saves with no row lock; two machines acknowledging the same test in the same instant can both win. Rare (Mirth runs one channel thread per analyzer) but possible with two channels. Fix:

```php
public function markAsProcessing(): bool
{
    return DB::transaction(function () {
        $fresh = self::where('id', $this->id)->lockForUpdate()->first();
        if (! $fresh || ! $fresh->canProcess()) {
            return false;
        }
        ...
    });
}
```

### Medium — edge cases / hardening

**A9 · `WorklistController::createWorklist()` creates orphan rows** (1042-1078) — accepts any `barcode` from the request, no `test_sample_item_id`, no `test_sample_id` (NULL bypasses the UNIQUE key), no machine-mapping validation. Either require `test_sample_id` + validate a mapping exists, or disable this admin endpoint — the observer + `createWorklistForGroup` path already covers every real case.

**A10 · `deleteWorklist()` hard-deletes → Mirth cache orphan.** Pull cancel-detection only sees `status='cancelled'`; a deleted row disappears from the pull result and stays `pending` in `lis_cache.lis_worklists` forever (the analyzer keeps seeing it). Fix: add `SoftDeletes` to Worklist and treat `trashed` rows as `cancelled` in the pull payload, or mark cancelled before delete.

**A11 · `clearOldWorklists(30)` has the same orphan effect** for Pattern B — add a matching 30-day purge in `lis_cache` (or keep completed rows; they're cheap).

**A12 · `updateWorklistStatus()` has no validation** — any status string, bypasses sibling locking. Check callers and restrict to the five status constants.

**A13 · Machine APIs don't check `api_key`** — `machines.api_key` exists (`MachineController.php:96` generates it) but `/api/mirth/*` and `/api/worklist/*` authenticate on `device_model` / `X-Machine-Key` alone, which is guessable. Fix: also require `X-Api-Key` and compare with `hash_equals($machine->api_key, ...)`.

**A14 · `patient_age` uses `$patient?->age`** — apply the §1.5 fix (`Carbon::parse($patient->dob)->age`, NULL when dob missing) so you never store 0.

**A15 · `createRecheckWorklist()` recheck-duplicate guard misses `locked`/`completed`** — a second recheck click after completion creates another row for the same combo. The §2.5 retake path makes this moot for same-tube rechecks; for new-tube rechecks the UNIQUE key doesn't help (new `test_sample_id`) so keep the guard but include all non-cancelled statuses.

### What is already correct (no change needed)

- UUID v7 PKs via `HasUUIDSettings` (+ `Worklist::boot` double-set is harmless)
- Fan-out per active `machine_test_mapping` with `parameters` JSON self-describing payload
- Sibling locking (`locked` when completed elsewhere) — `canProcess()` / `markAsCompleted()`
- `pushed_at` reservation pattern in `MachineController::getWorklist`
- Priority logic (receipt urgent → high), FIELD() ordering, tenant scoping
- Barcode generation rules (`-R`, `-F2`, `-C` suffixes) in `HasSimpleSampleBarcode`

---

## 5. Implementation order — what to do first

### Phase 1 — Laravel schema changes (1 day)

1. Backup your DB: `mysqldump lis_db > backup_$(date +%Y%m%d).sql`
2. Run the ALTER TABLE statements from §1.4 (drop `uuid_new`/`uuid`/`order_id`/`sample_number`, add 4 result/retake columns, UNIQUE key, covering indexes)
3. Run the CREATE TABLE for `lis_results` from §2.2
4. Create the `LisResult` Eloquent model
5. Apply the audit fixes A1–A5 (§4B) — `is_collected` query, `insert()` id, retake/UNIQUE guard, transaction + honest flash, `pushed_at` on pending
6. Apply the v3 fixes (`direction` in exportMachineConfig §34.4, `completeWorklists()` in legacyResult §34.3) — these are prerequisites

### Phase 2 — Laravel controller update (1 day)

1. Update `MirthController::legacyResult` to handle `worklist_id`, `version`, `result_type` (§4.3)
2. Update `MirthController::legacyResult` to insert into `lis_results` and update worklist columns (§4.3)
3. Update `WorklistService::createWorklistForTest` to fix `patient_age` bug (§1.5)
4. Apply audit fixes A6–A13 (§4B): `pushed_at` reservation in `getWorklistForMirth`, status accessor, acknowledge row-lock, orphan `createWorklist`, soft deletes, `api_key` check
5. Execute the §7 drop-column checklist (`order_id`, `sample_number` code cleanup) — same migration as Phase 1

### Phase 3 — Auto-create worklist trigger (1 day)

1. Create `TestSampleItemObserver` (§3.2)
2. Register in `AppServiceProvider`
3. Test: collect a tube → check `worklists` table auto-populates with rows for every machine mapping
4. Keep the manual "Generate Worklist" button as fallback

### Phase 4 — Mirth Pattern B migration (3–5 days)

1. Install PostgreSQL JDBC driver in Mirth
2. Create `lis_cache` schema (v4 §42A)
3. Deploy `lis_sync_pull` channel (v4 §21C)
4. Deploy `lis_sync_push` channel (v4 §21D, updated with `version` + `result_type` per §4.5)
5. Deploy one analyzer channel with Pattern B source transformer (v4 §18.2)
6. Test end-to-end with a real tube
7. Migrate other analyzer channels one at a time

### Phase 5 — Multi-version testing (1 day)

1. Run a CBC on Urit → verify version=1 in `lis_results`
2. Re-run the same barcode on Sysmex → verify version=2 in `lis_results`, version=1 marked `superseded`
3. Verify Laravel UI shows the latest version, with a "History" tab showing all versions
4. Verify sync-push delivered both versions to Laravel

---

## 6. Summary of changes

### `worklists` table — FINAL (§1.4): drop 4, add 4, 1 unique + 2 indexes

```sql
-- v1.2 (see banner): simplified — no current_version, no retake_count
ALTER TABLE worklists
  DROP COLUMN uuid_new,
  DROP COLUMN uuid,             -- if present
  DROP COLUMN order_id,
  DROP COLUMN sample_number,
  ADD COLUMN latest_result_id   CHAR(36) NULL AFTER result_data,
  ADD COLUMN result_count       INT      NOT NULL DEFAULT 0 AFTER latest_result_id,
  ADD COLUMN source             VARCHAR(20) NOT NULL DEFAULT 'fanout' AFTER result_count,
  ADD UNIQUE KEY uq_worklist_item_machine (test_sample_item_id, machine_id),
  ADD INDEX idx_worklist_latest_result (latest_result_id),
  ADD INDEX idx_worklist_machine_status_pushed (machine_id, status, pushed_at),
  ADD INDEX idx_worklist_barcode_status (barcode, status);
```

(`test_sample_id` + `timing` are KEPT per your decision — see §1.4.)

### New table: `lis_results` (multi-version) — §2.2 DDL

### Laravel code changes

| File | Change |
|---|---|
| `app/Models/LisResult.php` | NEW — Eloquent model for `lis_results` |
| `app/Models/Worklist.php` | Add `latestResult` relationship, casts for new columns; remove `order_id` from fillable + `boot()`; `markAsPending()` clears `pushed_at` (A5); `markAsProcessing()` row-lock (A8); add SoftDeletes (A10) |
| `app/Http/Controllers/Lab/Api/MirthController.php` | `legacyResult`: insert into `lis_results`, update worklist columns, handle `version`/`result_type` (§4.3); remove `sample_number`/`order_id` from lookups and responses (§7) |
| `app/Services/WorklistService.php` | Fix `patient_age` (§1.5); `is_collected` → `whereNotNull collected_by/at` (A1); UNIQUE-keyed duplicate guard (A3); wrap fan-out in `DB::transaction` (A4); add `retakeWorklist()` (§2.5); remove `sample_number`/`order_id` writes (§7) |
| `app/Http/Controllers/Lab/WorklistController.php` | `getWorklistForMirth`: bulk `pushed_at` reservation (A6); `createWorklist`: require `test_sample_id` (A9); remove `order_id` references (§7) |
| `app/Http/Controllers/Lab/SampleController.php` | `autoCreateTestSamples`: add `id` to `insert()` rows (A2); honest worklist flash message (A4); `recheckTest(use_existing_sample=true)` → retake path (A3) |
| `app/Models/TestSampleItem.php` | Remove `getStatusAttribute` override (A7) |
| `app/Observers/TestSampleItemObserver.php` | NEW — auto-create worklist on tube collection (§3.2) |
| `app/Providers/AppServiceProvider.php` | Register observer |

### Mirth changes

| Channel | Change |
|---|---|
| `lis_cache.lis_results` schema | Add `version`, `result_type`, `run_id`, `laravel_id` columns (§4.2; `superseded_by` dropped — v1.2) |
| Analyzer channel destination (§21.B) | INSERT with `version` + `result_type` + `run_id` (one per analyzer message); NO "mark previous as superseded" write — latest = `MAX(version)` (§4.2, v1.2) |
| `lis_sync_push` channel (§21D) | Include `worklist_id`, `version`, `result_type` in POST body (§4.5) |

### Mirth templates

Replace `2608281001-F` with your real barcode format `1260914000` (plain) or `1260914000-R` (timing tube) in all Mirth templates and sample HL7 messages. Adjust gender conversion (`"Male"` → `M`) in the OML^O21 builder (§1.6).

---

## 7. Drop-column checklist — what code must change when you drop `order_id` and `sample_number`

Both columns are referenced in working code — dropping them in SQL alone breaks inserts. Checklist (all verified against your uploaded files):

### `order_id` — remove from

| File | Place | Change |
|---|---|---|
| `Model/Worklist.php` | `fillable` (line 21), `boot()` order_id assignment (682-684), `generateOrderId()` (534-537), `toApiResponse()` (618) | Remove all four |
| `Service/WorklistService.php` | `generateOrderId()` (1147-1150) | Remove (no callers after controller cleanup) |
| `Controller/WorklistController.php` | DataTables search (140), sort case (199-200), stats search (307), exports (938, 1011), `createWorklist` (1063), machine payloads (1110, 1293, 1487) | Remove `order_id` fields; keep search on `barcode` + `patient_name` |
| `Controller/MirthController.php` | `getWorklist` payload (289) | Remove field |

### `sample_number` — remove from

| File | Place | Change |
|---|---|---|
| `Model/Worklist.php` | `fillable` (64), `getSampleNumberAttribute()` (482 — keep but return `tube_sequence` only) | Remove column references |
| `Model/TestSampleItem.php` | `fillable` (28) + DB column | Remove (tube_sequence on `test_samples` already holds the number) |
| `Service/WorklistService.php` | `$sampleNumber` resolution (141-144), `parameters['sample_number']` (193), worklist create (214), log (247), `createRecheckWorklist` (311-315) | Replace with `tube_sequence` inside `parameters` only if an analyzer needs it; remove column writes |
| `Controller/WorklistController.php` | `getWorklistById` sample_info (527), `getWorklistForMirth` (1113) | Remove field |
| `Controller/MirthController.php` | validation (427), legacy submit (472), GLUE lookup `when(sample_number)` (515-516), batch validation (623), idempotency hash (661-665, 683) | Remove the `sample_number` whens/fields; the UNIQUE key `(test_sample_id, test_id, machine_id)` + `worklist_id` make them redundant |

### `uuid` / `uuid_new` — no code changes

Nothing in any uploaded file reads or writes `uuid`/`uuid_new` on `worklists` (only `LabReceipt`/`LabTest`/`TenantConfig` have their own `uuid` columns — those stay). Pure `ALTER TABLE ... DROP COLUMN`.

---

## 8. What this gives you

| Capability | Before | After |
|---|---|---|
| One worklist per (tube, machine, test) | ✅ Already | ✅ Same |
| Auto-create on tube collection | ❌ Manual button only | ✅ Observer-triggered |
| Multiple result versions per worklist | ❌ Single `result_data` JSON overwritten | ✅ `lis_results` table with version chain |
| Latest version accessible in O(1) | ✅ `result_data` JSON | ✅ `latest_result_id` FK + `result_data` snapshot |
| Full audit trail of re-runs / corrections | ❌ Lost | ✅ `lis_results` history |
| Pattern B sync works with multi-version | ❌ n/a | ✅ Version sent in payload, Laravel stores correctly |
| Pattern B offline tolerance | ✅ Already | ✅ Same — re-runs queued locally, synced when Laravel back |
| Laravel UI "Result History" view | ❌ n/a | ✅ `SELECT * FROM lis_results WHERE worklist_id=? ORDER BY version DESC` |

---

*End of design doc.*
