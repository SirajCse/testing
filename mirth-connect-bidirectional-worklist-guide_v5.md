# Mirth Connect v5 — Full Worklist + LIS Result Integration (Online + Offline)

> **Scope:** everything A-to-Z in ONE document — worklist AND results, pull AND push, online Laravel server AND offline Mirth server, Bidirectional + Unidirectional + Manual, HL7 + ASTM + other protocols.
> **Base:** consolidates v3/v4 guide + unified-result-ingest design + simplified schema (column reduction) into one reference. Patterns are the same as v4 — reorganized by analyzer channel.
> **v5.1 (2026-11-18):** re-checked against the finalized migration `2026_11_18_000001_column_reduction_worklist_chain.php` (guarded, re-runnable) and the live phpMyAdmin dump of `worklists` (35 cols). Fixes: migration filename (§7), drop list names `uuid_new` only (no bare `uuid` in your live table), `result_count` UNSIGNED, `(barcode, status)` index already exists live, `worklists.suffix` KEPT for the OML payload (§7/§8), legacy payload keys annotated (§18). No flow, channel or endpoint changes.
> **v5.2 (2026-11-18):** `test_sample_items.suffix` is KEPT (needed — tube identity); `test_sample_items` drops `sample_number` ONLY. The migration re-adds `test_sample_items.suffix` if the older 2026-11-17 draft already dropped it (§7).

---

## What's new in v5

| # | Change |
|---|---|
| 1 | **One channel per analyzer does BOTH worklist and result** — a single TCP Listener channel routes QRY (query) and ORU/ASTM-R (result) inside one transformer. No separate query/result channels. |
| 2 | **ASTM (LIS2-A2) support** — frame parser, checksum validation, Q-record worklist reply, R-record result ingest. |
| 3 | **Sync channels pull/push for ALL or SELECTED analyzers** — `MACHINE_KEYS` list or dynamic machine-config fetch. |
| 4 | **Manual mode fully integrated** — `ingestResult()` auto-heals missing worklists; nothing is rejected for "data version not found". |
| 5 | **Simplified schema everywhere** — `worklists` has no `parameters`/`result_data`/`reassignment_history`/`order_date` JSON/date columns; version handling = `MAX(version)+1` at write time, `latest_result_id` pointer. |
| 6 | **lis_cache local schema updated** to match (no `order_id`/`sample_number`; `source` column added). |
| 7 | `ingestResult()` is the ONLY result door; direction gates PULL only, never result POST. |

---

## Table of Contents

```
PART 0   Channel inventory at a glance
PART I   Architecture (2 servers, 3 modes, 3 protocols, data flows)
PART II  Database schemas (Laravel + Mirth local lis_cache)
PART III Laravel side (endpoints, ingestResult, machine-config)
PART IV  Sync channels — lis_sync_pull (PULL) + lis_sync_push (PUSH)
PART V   Per-analyzer channels — HL7 bidirectional, HL7 unidirectional,
         ASTM bidirectional, other/serial
PART VI  Result ingest per mode + version handling
PART VII Sample messages (HL7 + ASTM)
PART VIII Deployment, testing, troubleshooting, maintenance
PART IX  Appendices (glossary, quick start)
```

---

# PART 0 — Channel inventory at a glance

Two channel types. Total channels = **2 sync + 1 per analyzer** (5 analyzers → 7 channels).

```
┌─────────────────────────────────────────────────────────────────────────┐
│ CHANNEL GROUP "SYNC" — one instance, serves ALL or SELECTED analyzers   │
│                                                                          │
│  ┌────────────────────┐         ┌──────────────────────┐                │
│  │ lis_sync_pull      │         │ lis_sync_push        │                │
│  │ Timer 30s          │         │ DB Reader 15s        │                │
│  │ Laravel → local    │         │ local → Laravel      │                │
│  │ worklists DOWN     │         │ results UP           │                │
│  └─────────┬──────────┘         └──────────▲───────────┘                │
│            │                                │                            │
└────────────┼────────────────────────────────┼────────────────────────────┘
             ▼                                │
  ┌──────────────────────────────────────────────────────────┐
  │  MIRTH LOCAL DB (PostgreSQL, schema lis_cache)           │
  │  lis_worklists (mirror of Laravel pending worklists)     │
  │  lis_results   (result queue waiting to push)            │
  │  lis_sync_state (per-machine sync bookkeeping)           │
  └──────────────────────────────────────────────────────────┘
             ▲                                ▲
             │ local SELECT (no Laravel)      │ local INSERT (no Laravel wait)
             │                                │
┌────────────┴────────────────────────────────┴───────────────────────────┐
│ CHANNEL GROUP "ANALYZERS" — ONE channel per analyzer, does BOTH sides    │
│                                                                          │
│  SYSMEX-XN1000   (HL7 MLLP, bidirectional)                              │
│  URIT-BH-5390    (HL7 MLLP, unidirectional)                             │
│  MINDRAY-BS240   (ASTM LIS2-A2, bidirectional)                          │
│  GENRUI-3XXX     (serial/CSV, unidirectional)                           │
│  ...one channel each: TCP Listener → transformer routes                 │
│     QRY/Q-record → OML/ASTM-P+O reply  (worklist DOWN, local DB)        │
│     ORU/R-record → INSERT lis_results  (result UP, queued for push)     │
└──────────────────────────────────────────────────────────────────────────┘
```

| Channel | Type | Direction of data | Talks to |
|---|---|---|---|
| `lis_sync_pull` | Timer 30 s → HTTP GET → DB Writer | Laravel **→ Mirth** (worklists down) | Laravel `/api/mirth/worklist` |
| `lis_sync_push` | DB Reader 15 s → HTTP POST | Mirth **→ Laravel** (results up) | Laravel `/api/legacy/result` or `/api/mirth/results` |
| `<MACHINE>-wl-rs` | TCP Listener (MLLP/raw) | Analyzer ↔ Mirth (both ways) | local `lis_cache` only |

**Offline rule:** when the internet is down, analyzer channels keep working — they never touch Laravel. Only the 2 sync channels stop, and they resume automatically.

---

# PART I — Architecture

## 1. Executive summary

- Laravel = the online authority (patients, receipts, tests, worklists, reports).
- Mirth = the local integration server. Holds a **read cache of worklists** and a **write queue of results**.
- Analyzers connect ONLY to Mirth (TCP). Laravel connects only to the 2 sync channels (HTTP).
- Every analyzer is ONE channel that handles its own protocol (HL7 or ASTM or serial) and its own mode (bidirectional or unidirectional). Manual entry needs no channel at all.
- Results are accepted from ANY mode through ONE Laravel function (`ingestResult`). Versions are computed at write time — "data version not found" cannot happen.

## 2. The two servers

| | Online server (Laravel) | Offline server (Mirth) |
|---|---|---|
| Holds | patients, receipts, lab_tests, worklists (authority), lis_results (authority), test_reports | `lis_cache.lis_worklists` (cache), `lis_cache.lis_results` (queue) |
| Worklist writes | fan-out at collection, auto-heal at result time | none (sync-pull copies) |
| Result writes | `ingestResult()` — versions, reports | INSERT on every analyzer message |
| Internet down | unaffected | analyzer channels unaffected; sync stops and resumes |

## 3. The three modes

| Mode | Worklist before run? | Who starts? | Result path | Worklist completion |
|---|---|---|---|---|
| **Bidirectional** | Yes (fan-out → sync-pull → local cache) | Analyzer queries; Mirth answers from local cache | ORU / ASTM-R → local INSERT → sync-push → `ingestResult` | found row → completed |
| **Unidirectional** | Maybe (fan-out still runs) | Analyzer just sends | same as bidirectional | found row → completed, **or auto-heal** |
| **Manual** (operator types barcode on analyzer or in LIS) | Maybe | Operator | same as above (barcode must exist as tube) | found row → completed, **or auto-heal** |

Manual = "result arrived first, worklist maybe not created". `ingestResult` handles it identically — no special code path.

## 4. The three protocols

| Protocol | Framing | Worklist query | Result upload | Used by |
|---|---|---|---|---|
| **HL7 v2.3.1 MLLP** | `0x0B … 0x1C 0x0D` | `QRY^Q02` → `OML^O21` reply | `ORU^R01` + ACK | Sysmex, Mindray (HL7), Snibe, most modern |
| **ASTM LIS2-A2** | `STX … ETX/ETB + checksum CR` | `Q` record → `H`,`P`,`O` records reply | `H`,`P`,`O`,`R…`,`L` frames | Mindray (ASTM mode), many hematology/chemistry |
| **Other / serial** | CSV or vendor flat file (File/Directory Reader) | none | parse per vendor doc | semi-auto devices, old machines |

## 5. Which analyzers sync? (all vs selected)

The 2 sync channels carry a `MACHINE_KEYS` list:

```javascript
// Option A — selected analyzers (explicit):
var MACHINE_KEYS = ['SYSMEX-XN1000', 'URIT-BH-5390'];   // bidirectional + active pushers only

// Option B — ALL active machines (fetch once per run):
var cfg = httpGet(API_BASE + '/machine-config/ALL', {});
var MACHINE_KEYS = JSON.parse(cfg.body).machines.map(function (m) { return m.device_model; });
```

Rule of thumb: put **bidirectional** machines in sync-pull (they need local worklists); put **every** machine in sync-push scope (results always flow up). Unidirectional machines may be left out of sync-pull — Laravel fan-out still records their rows but no analyzer query ever reads them.

## 6. End-to-end flows

### 6.1 Bidirectional, offline-capable (Pattern B)

```
collect → Laravel fan-out creates worklist (status=pending, pushed_at=NULL)
  → lis_sync_pull (≤30 s) GET /api/mirth/worklist
      Laravel marks pushed_at=now(), returns rows
      Mirth upserts lis_cache.lis_worklists
analyzer scans tube → sends QRY^Q02 / ASTM Q record
  → analyzer channel transformer SELECTs local cache
  → OML^O21 / ASTM H,P,O reply → analyzer runs sample
analyzer sends ORU^R01 / ASTM R records
  → transformer parses OBX/R → INSERT lis_cache.lis_results (synced_at=NULL)
  → ACK returned immediately
  → local lock: UPDATE lis_worklists status='completed'
lis_sync_push (≤15 s) POSTs result to Laravel
  → ingestResult(): tube→test→worklist (or auto-heal)
  → version = MAX+1 → INSERT lis_results → TestReport → worklist completed
```

### 6.2 Unidirectional

Same as 6.1 minus the query step. Analyzer pushes; if the tube exists but no worklist row was ever created for that machine, `ingestResult` auto-heals it (`source='auto'`).

### 6.3 Manual entry

Operator enters barcode + test in the LIS UI (or recheck flow) → same fan-out. Result comes back through the analyzer normally. If the operator only types the barcode ON the analyzer (no LIS entry ever), the result arrives → tube found → auto-heal → version 1. Unknown barcode → parked `status='unmatched'`, nothing lost.

---

# PART II — Database schemas

## 7. Laravel schema (simplified — this version)

Applied by migration `2026_11_18_000001_column_reduction_worklist_chain.php` (guarded, re-runnable — full code in `worklist-column-reduction-laravel-guide.md` §1):

```sql
-- worklists: DROPPED order_id (+ its UNIQUE index), uuid_new,
--            sample_number (+ its INDEX), order_date,
--            parameters, result_data, reassignment_history
--            (uuid_new only — your live table has no bare `uuid` column;
--             worklists.suffix is KEPT — tube identity for the Mirth OML payload)
-- worklists: ADDED
ALTER TABLE worklists
  ADD COLUMN machine_mapping_id CHAR(36) NULL AFTER machine_id,
  ADD COLUMN cancelled_reason   VARCHAR(255) NULL AFTER status,
  ADD COLUMN latest_result_id   CHAR(36) NULL AFTER cancelled_reason,
  ADD COLUMN result_count       INT UNSIGNED NOT NULL DEFAULT 0 AFTER latest_result_id,
  ADD COLUMN source             VARCHAR(20) NOT NULL DEFAULT 'fanout' AFTER result_count,
  ADD INDEX idx_worklist_latest_result (latest_result_id);

-- duplicate pre-check (column-reduction guide §15) MUST return 0 rows first —
-- the migration itself re-checks and fails with the offending rows listed:
-- SELECT test_sample_item_id, machine_id, COUNT(*) AS n FROM worklists
-- WHERE test_sample_item_id IS NOT NULL GROUP BY 1, 2 HAVING n > 1;
ALTER TABLE worklists
  ADD UNIQUE KEY uq_worklist_item_machine (test_sample_item_id, machine_id);

ALTER TABLE worklists
  ADD INDEX idx_worklist_machine_status_pushed (machine_id, status, pushed_at);
  -- NOTE: (barcode, status) ALREADY EXISTS in your live table — kept as-is, never recreated

-- test_samples:  DROPPED sl_no
-- test_sample_items: DROPPED sample_number ONLY
--                    (test_sample_items.suffix KEPT — needed tube identity;
--                     re-added by the migration if the old draft dropped it)
-- new audit table: worklist_reassignments (optional)
```

### `lis_results` (Laravel — final)

```sql
CREATE TABLE lis_results (
  id CHAR(36) NOT NULL PRIMARY KEY,            -- uuid_v7
  worklist_id CHAR(36) NULL,                   -- NULL only when parked unmatched
  test_sample_item_id CHAR(36) NULL,
  lab_test_id CHAR(36) NULL,
  test_id CHAR(36) NULL,
  component_id CHAR(36) NULL,
  machine_id CHAR(36) NOT NULL,
  barcode VARCHAR(50) NOT NULL,
  test_code VARCHAR(50) NOT NULL,
  version INT NOT NULL DEFAULT 1,
  result_type VARCHAR(20) NOT NULL DEFAULT 'final',  -- final | rerun | unmatched
  result_value VARCHAR(100) NOT NULL,
  result_numeric DECIMAL(18,6) NULL,
  unit VARCHAR(30) NULL,
  reference_range VARCHAR(50) NULL,
  abnormal_flag VARCHAR(10) NULL,
  run_id CHAR(36) NULL,                        -- one per analyzer message
  message_hash CHAR(64) NOT NULL,
  raw_message TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'matched',     -- matched | unmatched
  error_log TEXT NULL,
  received_at DATETIME NULL,
  created_at TIMESTAMP NULL,
  UNIQUE KEY uq_lis_hash (message_hash),
  KEY idx_lis_worklist_version (worklist_id, version),
  KEY idx_lis_barcode (barcode, test_code, machine_id),
  KEY idx_lis_unmatched (status)
);
```

## 8. Mirth local `lis_cache` schema (PostgreSQL)

Matches the simplified Laravel columns — no `order_id`/`sample_number`. (Laravel KEEPS `worklists.suffix` for the OML payload; the cache does not mirror it — the value already rides inside `receipt_barcode`.)

```sql
CREATE SCHEMA IF NOT EXISTS lis_cache AUTHORIZATION mirth;

CREATE TABLE lis_cache.lis_worklists (
  id                  UUID NOT NULL PRIMARY KEY,
  laravel_id          UUID NOT NULL UNIQUE,
  device_model        VARCHAR(100) NOT NULL,
  machine_id          UUID NULL,
  machine_mapping_id  UUID NULL,
  barcode             VARCHAR(100) NOT NULL,
  receipt_barcode     VARCHAR(50)  NULL,
  patient_id          VARCHAR(64)  NULL,
  patient_name        VARCHAR(255) NULL,
  patient_age         INT          NULL,
  patient_gender      VARCHAR(10)  NULL,
  test_code           VARCHAR(50)  NOT NULL,
  test_name           VARCHAR(255) NULL,
  sample_type         VARCHAR(50)  NULL,
  tube_type           VARCHAR(100) NULL,
  timing              VARCHAR(20)  NULL,
  priority            VARCHAR(20)  NOT NULL DEFAULT 'routine',
  status              VARCHAR(20)  NOT NULL DEFAULT 'pending',
  collection_date     TIMESTAMP    NULL,
  test_sample_item_id UUID NULL,
  test_sample_id      UUID NULL,
  lab_receipt_id      UUID NULL,
  lab_test_id         UUID NULL,
  test_id             UUID NULL,
  source              VARCHAR(20) NOT NULL DEFAULT 'laravel',  -- laravel | manual
  locked_by           VARCHAR(100) NULL,
  result_id           UUID NULL,
  last_synced_at      TIMESTAMP NOT NULL DEFAULT NOW(),
  created_at          TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at          TIMESTAMP NOT NULL DEFAULT NOW(),
  UNIQUE INDEX idx_liswl_laravel_id (laravel_id),
  INDEX idx_liswl_device_barcode_status (device_model, barcode, status),
  INDEX idx_liswl_status (status),
  INDEX idx_liswl_test_sample_item (test_sample_item_id),
  INDEX idx_liswl_last_synced (last_synced_at)
);

CREATE TABLE lis_cache.lis_results (
  id                  UUID NOT NULL PRIMARY KEY,
  worklist_id         UUID NULL,          -- local lis_worklists.id
  laravel_worklist_id UUID NULL,          -- Laravel worklists.id (backfilled after push)
  machine_id          VARCHAR(64) NOT NULL,
  device_model        VARCHAR(100) NOT NULL,
  barcode             VARCHAR(100) NOT NULL,
  test_code           VARCHAR(50) NULL,
  run_id              VARCHAR(64) NULL,   -- groups one analyzer message
  result_data         JSONB NOT NULL,     -- payload for /api/legacy/result
  raw_message         TEXT NULL,
  status              VARCHAR(20) NOT NULL DEFAULT 'completed',
  retry_count         INT NOT NULL DEFAULT 0,
  max_retries         INT NOT NULL DEFAULT 5,
  synced_at           TIMESTAMP NULL,
  sync_response       TEXT NULL,
  sync_error          TEXT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at          TIMESTAMP NOT NULL DEFAULT NOW(),
  INDEX idx_lisres_synced (synced_at, retry_count),
  INDEX idx_lisres_worklist (worklist_id),
  INDEX idx_lisres_barcode (barcode),
  INDEX idx_lisres_created (created_at)
);

CREATE TABLE lis_cache.lis_sync_state (
  device_model    VARCHAR(100) NOT NULL PRIMARY KEY,
  last_pull_at    TIMESTAMP NULL,
  last_push_at    TIMESTAMP NULL,
  last_pull_count INT NOT NULL DEFAULT 0,
  last_push_count INT NOT NULL DEFAULT 0,
  last_pull_error TEXT NULL,
  last_push_error TEXT NULL,
  updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
);
```

---

# PART III — Laravel side

## 9. Endpoints reference

| Endpoint | Method | Used by | Purpose |
|---|---|---|---|
| `/api/mirth/worklist` | GET | `lis_sync_pull` (+ direct Pattern A query) | pending rows for one machine (by `X-Machine-Key`); marks `pushed_at` |
| `/api/mirth/machine-config/{device_model}` | GET | sync channels + deploy script | active `machine_test_mappings` + `direction` + mapping JSON |
| `/api/legacy/result` | POST | `lis_sync_push`, Urit/legacy channels | **door 1** — batch `mapping + results[]` payload → `ingestResult` per row |
| `/api/mirth/results` | POST | any HL7 channel (Pattern A) | **door 2** — single-parameter payload → `ingestResult` |
| `/api/mirth/acknowledge` | POST | bidirectional channels (optional) | mark worklist `processing` |
| `/api/mirth/health` | GET | monitoring | DB + queue check |

> **Direction rule:** `machines.direction` gates only the worklist PULL (`canPullWorklist`). ANY active machine can POST results. Never 403 a result.

## 10. `ingestResult()` — the one result door

Add to `app/Services/WorklistService.php`. Bidirectional + Unidirectional + Manual all call this. Nothing is rejected for a completed worklist — a second result is just version 2.

```php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** ONE door for ALL result paths. $r keys: barcode, test_code, result_value,
 *  unit, reference_range, abnormal_flag, run_id, raw_message, component_ids. */
public function ingestResult(array $r, Machine $machine): array
{
    $code = $r['test_code'];
    $runId = $r['run_id'] ?? (string) Str::uuid7();

    // 1. Resolve the TUBE (what the analyzer scanned)
    $tube = TestSample::where('barcode', $r['barcode'])->first();
    if (! $tube && str_contains($r['barcode'], '-')) {
        $tube = TestSample::where('barcode', explode('-', $r['barcode'])[0])->first();
    }
    if (! $tube) {
        return $this->parkUnmatched($r, $machine, "Tube barcode not found: {$r['barcode']}");
    }
    $receipt = $tube->labReceipt;
    if (! $receipt) {
        return $this->parkUnmatched($r, $machine, "Tube {$tube->barcode} has no receipt");
    }

    // 2. Resolve code → test (+ optional component)
    $testId = null; $componentId = null;

    if (! empty($r['component_ids'])) {                    // explicit payload mapping
        foreach ($r['component_ids'] as $cid) {
            $c = TestComponent::find($cid);
            if ($c && $c->test_id) { $componentId = $cid; $testId = $c->test_id; break; }
        }
    }
    if (! $testId) {                                       // test-level: CBC
        $mapping = MachineTestMapping::where('machine_id', $machine->id)
            ->where('test_code', $code)->where('is_active', true)->first();
        $testId = $mapping?->test_id;
    }
    if (! $testId) {                                       // parameter-level: WBC
        foreach (MachineTestMapping::where('machine_id', $machine->id)
            ->where('is_active', true)->get() as $m) {
            $map = $this->decodeParams($m->parameters);
            $map = $map['mapping'] ?? $map;
            if (is_array($map) && isset($map[$code])) {
                $testId = $m->test_id;
                $componentId = is_array($map[$code]) ? ($map[$code][0] ?? null) : $map[$code];
                break;
            }
        }
    }
    if (! $testId) {
        return $this->parkUnmatched($r, $machine, "No mapping for code {$code} on {$machine->device_model}");
    }

    // 3. Newest LabTest for receipt + test (retake = newest wins)
    $labTest = LabTest::where('lab_id', $receipt->id)->where('test_id', $testId)
        ->orderBy('created_at', 'desc')->first();
    if (! $labTest) {
        return $this->parkUnmatched($r, $machine,
            "Receipt {$receipt->barcode} has no LabTest for test {$testId}");
    }

    // 4. Worklist for (item, machine) — or AUTO-HEAL it
    $item = TestSampleItem::where('lab_test_id', $labTest->id)
        ->where('test_sample_id', $tube->id)->first()
        ?? TestSampleItem::where('lab_test_id', $labTest->id)->first();

    $worklist = null;
    if ($item) {
        $worklist = Worklist::where('test_sample_item_id', $item->id)
            ->where('machine_id', $machine->id)
            ->orderByRaw("FIELD(status,'pending','processing','locked','completed')")
            ->orderBy('created_at', 'desc')->first();
    }

    $autoHealed = false;
    if (! $worklist && $item) {
        $created = $this->createWorklistForTest($item);
        $worklist = collect($created)->firstWhere('machine_id', $machine->id);
        $autoHealed = (bool) $worklist;
    }
    if (! $worklist) {
        return $this->parkUnmatched($r, $machine, 'Worklist could not be resolved or created');
    }

    // 5. Version at WRITE time — kills "data version not found"
    return DB::transaction(function () use ($r, $machine, $worklist, $testId,
        $componentId, $runId, $receipt, $autoHealed) {

        $last = DB::table('lis_results')->where('worklist_id', $worklist->id);
        $sameRun = (clone $last)->where('run_id', $runId)->first();
        $version = $sameRun?->version ?? (($last->max('version') ?? 0) + 1);

        $messageHash = hash('sha256', json_encode([
            'barcode' => $r['barcode'], 'code' => $r['test_code'],
            'value' => $r['result_value'], 'machine' => $machine->id, 'run' => $runId,
        ]));
        if (DB::table('lis_results')->where('message_hash', $messageHash)->exists()) {
            return ['success' => true, 'duplicate' => true,
                'message' => 'Result already processed', 'version' => $version];
        }

        $resultId = (string) Str::uuid7();
        DB::table('lis_results')->insert([
            'id' => $resultId,
            'worklist_id' => $worklist->id,
            'test_sample_item_id' => $worklist->test_sample_item_id,
            'lab_test_id' => $worklist->lab_test_id,
            'test_id' => $testId,
            'component_id' => $componentId,
            'machine_id' => $machine->id,
            'barcode' => $r['barcode'],
            'test_code' => $r['test_code'],
            'version' => $version,
            'result_type' => $version == 1 ? 'final' : 'rerun',
            'result_value' => $r['result_value'],
            'result_numeric' => is_numeric($r['result_value']) ? $r['result_value'] : null,
            'unit' => $r['unit'] ?? null,
            'reference_range' => $r['reference_range'] ?? null,
            'abnormal_flag' => $r['abnormal_flag'] ?? null,
            'run_id' => $runId,
            'message_hash' => $messageHash,
            'raw_message' => $r['raw_message'] ?? null,
            'status' => 'matched',
            'received_at' => $r['received_at'] ?? now(),
            'created_at' => now(),
        ]);

        // 6. Worklist: complete IDEMPOTENTLY (no status gate)
        $worklist->status = 'completed';
        $worklist->completed_at = $worklist->completed_at ?? now();
        $worklist->latest_result_id = $resultId;
        $worklist->result_count = $version;
        if ($autoHealed) $worklist->source = 'auto';
        $worklist->save();

        // 7. Report UI keeps working for every machine
        if ($componentId) {
            TestReport::updateOrCreate(
                ['model_id' => $worklist->lab_test_id,
                 'model_type' => get_class(new LabTest),
                 'component_id' => $componentId],
                ['result' => $r['result_value'], 'unit' => $r['unit'] ?? '',
                 'patient_id' => $receipt->patient_id, 'test_id' => $testId]
            );
        }

        // 8. Item + LabTest status
        if ($worklist->test_sample_item_id) {
            TestSampleItem::where('id', $worklist->test_sample_item_id)
                ->update(['status' => 'completed']);
        }
        if ($worklist->lab_test_id) {
            LabTest::where('id', $worklist->lab_test_id)
                ->update(['lab_status' => 'completed', 'completed_at' => now(), 'has_result' => true]);
        }

        if ($autoHealed) {
            Log::info('WORKLIST AUTO-HEALED by incoming result', [
                'worklist_id' => $worklist->id, 'barcode' => $r['barcode'],
                'machine' => $machine->device_model, 'code' => $r['test_code'],
            ]);
        }

        return ['success' => true, 'duplicate' => false, 'version' => $version,
            'result_id' => $resultId, 'worklist_id' => $worklist->id];
    });
}

/** Park an unmatched result — nothing is ever thrown away. */
private function parkUnmatched(array $r, Machine $machine, string $reason): array
{
    DB::table('lis_results')->insert([
        'id' => (string) Str::uuid7(),
        'worklist_id' => null, 'machine_id' => $machine->id,
        'barcode' => $r['barcode'], 'test_code' => $r['test_code'],
        'version' => 1, 'result_type' => 'unmatched',
        'result_value' => $r['result_value'] ?? '',
        'run_id' => $r['run_id'] ?? (string) Str::uuid7(),
        'message_hash' => hash('sha256', json_encode($r).$machine->id.microtime()),
        'status' => 'unmatched', 'error_log' => $reason,
        'received_at' => now(), 'created_at' => now(),
    ]);
    Log::warning('RESULT PARKED (unmatched)', ['reason' => $reason, 'barcode' => $r['barcode']]);

    return ['success' => false, 'type' => 'unmatched', 'note' => $reason, 'status' => 422];
}
```

### 10.1 Thin wrappers (URLs and payload shapes unchanged)

`MirthController::receiveResults` (door 2 — single parameter):

```php
public function receiveResults(Request $request)
{
    $machine = Machine::where('device_model', $request->header('X-Machine-Key'))
        ->where('is_active', true)->first();
    if (! $machine) return response()->json(['error' => 'Machine not found'], 404);

    // NO direction gate — direction only gates worklist PULL.

    $result = $this->worklistService->ingestResult([
        'barcode'         => $request->barcode,
        'test_code'       => $request->test_code,
        'result_value'    => $request->result_value,
        'unit'            => $request->unit,
        'reference_range' => $request->reference_range,
        'abnormal_flag'   => $request->abnormal_flag,
        'run_id'          => $request->run_id ?? $request->header('X-Run-Id'),
        'raw_message'     => $request->raw_message,
        'received_at'     => now(),
    ], $machine);

    return response()->json($result, $result['success'] || ($result['duplicate'] ?? false) ? 200 : 422);
}
```

`MirthController::legacyResult` (door 1 — batch; keep validation + `LegacyResultTemp` insert as-is, replace the processing block):

```php
$runId = (string) Str::uuid7();          // one per HTTP call = one analyzer message
$type = 'success';

foreach ($input['results'] as $result) {
    $out = $this->worklistService->ingestResult([
        'barcode'       => $input['barcode'],
        'test_code'     => $result['code'],
        'result_value'  => $result['value'],
        'unit'          => $result['unit'] ?? null,
        'run_id'        => $runId,
        'component_ids' => $normalizedMapping[$result['code']] ?? [],
        'received_at'   => $parsedDate ?? now(),
    ], $machine ?? $this->machineFromPayload($input));

    if (($out['type'] ?? null) === 'unmatched') $type = 'unmatched';
    if (! empty($out['note'])) $errorLog[] = $out['note'];
}

$temp->update(['processed' => true, 'error_log' => $errorLog]);

return response()->json(['type' => $type, 'note' => 'Results processed', 'status' => 200], 200);
```

Redirect the third door: point `WorklistController::receiveHl7Results` at the same `ingestResult` (kill the `status == processing` requirement).

## 11. `GET /api/mirth/machine-config/{device_model}`

`app/Http/Controllers/MachineConfigController.php`:

```php
public function show(string $deviceModel): JsonResponse
{
    $machine = Machine::where('device_model', $deviceModel)
        ->where('is_active', true)->first();
    if (! $machine) {
        return response()->json(['success' => false, 'message' => 'Machine not found'], 404);
    }

    $mappings = MachineTestMapping::where('machine_id', $machine->id)
        ->where('is_active', true)
        ->with('test:id,code,name,short_name,sample_type')
        ->get()
        ->map(fn ($m) => [
            'id'          => $m->id,
            'test_id'     => $m->test_id,
            'test_code'   => $m->test_code,
            'test_name'   => $m->test?->name,
            'sample_type' => $m->sample_type,
            'parameters'  => is_string($m->parameters)
                ? json_decode($m->parameters, true) : ($m->parameters ?? []),
        ]);

    return response()->json([
        'success'          => true,
        'machine_id'       => $machine->id,
        'device_model'     => $machine->device_model,
        'direction'        => $machine->direction ?? 'unidirectional',
        'protocol'         => $machine->protocol ?? 'hl7',      // hl7 | astm | serial
        'machine_mappings' => $mappings,
    ]);
}
```

Route:

```php
Route::get('/mirth/machine-config/{device_model}',
    [\App\Http\Controllers\MachineConfigController::class, 'show']);
```

This replaces the per-row `parameters` JSON that used to be embedded in worklists — each Mirth channel fetches + caches the config ONCE (deploy script or Configuration Map). For the `ALL` variant used by §5 Option B and §14, add the `show('ALL')` branch shown in §14 to this controller.

---

# PART IV — Sync channels (PULL + PUSH)

Two global channels. They serve ALL analyzers or a selected list. When the internet is down they simply stop; analyzer channels keep running locally.

## 12. Channel: `lis_sync_pull` — worklists Laravel → Mirth (PULL)

| Setting | Value |
|---|---|
| Source | Timer, 30000 ms |
| Destination 1 | JavaScript Writer (upsert local cache) |
| Scope | all active machines, or the `MACHINE_KEYS` selection |

Full transformer (JavaScript — one step on the source; no separate destination needed):

```javascript
// ================================================================
//  lis_sync_pull — Laravel worklists → lis_cache.lis_worklists
// ================================================================
var API_BASE = 'https://lab.example.com/api/mirth';

// ── SELECTED analyzers (or fetch ALL via machine-config) ──
var MACHINE_KEYS = ['SYSMEX-XN1000', 'URIT-BH-5390', 'MINDRAY-BS240'];

function httpGet(url, headers) {
    var c = new java.net.URL(url).openConnection();
    c.setRequestMethod('GET'); c.setConnectTimeout(5000); c.setReadTimeout(10000);
    for (var h in headers) c.setRequestProperty(h, headers[h]);
    var code = c.getResponseCode();
    var st = code >= 400 ? c.getErrorStream() : c.getInputStream();
    var body = st ? String(org.apache.commons.io.IOUtils.toString(st, 'UTF-8')) : '';
    c.disconnect();
    return { code: code, body: body };
}

var db = DatabaseConnectionFactory.createDatabaseConnection(
    'org.postgresql.Driver',
    'jdbc:postgresql://localhost:5432/mirth_db',
    'mirth', '<MIRTH_DB_PASSWORD>');

var totalUpserted = 0;
var seenIds = {};

for (var i = 0; i < MACHINE_KEYS.length; i++) {
    var key = MACHINE_KEYS[i];
    try {
        var r = httpGet(API_BASE + '/worklist', {
            'X-Machine-Key': key, 'Accept': 'application/json'
        });
        if (r.code != 200) { logger.warn('sync-pull ' + key + ' HTTP ' + r.code); continue; }

        var worklists = JSON.parse(r.body).worklist || [];
        var fmt = new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss");
        var nowTs = fmt.format(new java.util.Date());

        for (var w = 0; w < worklists.length; w++) {
            var row = worklists[w];
            seenIds[row.worklist_id] = true;

            db.executeUpdate(
                "INSERT INTO lis_cache.lis_worklists " +
                "(id, laravel_id, device_model, machine_id, machine_mapping_id, " +
                " barcode, receipt_barcode, patient_id, patient_name, patient_age, patient_gender, " +
                " test_code, test_name, sample_type, tube_type, timing, priority, status, " +
                " collection_date, test_sample_item_id, test_sample_id, " +
                " lab_receipt_id, lab_test_id, test_id, source, last_synced_at, created_at, updated_at) " +
                "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, 'laravel', ?, ?, ?) " +
                "ON CONFLICT (laravel_id) DO UPDATE SET " +
                "  status = CASE WHEN lis_cache.lis_worklists.status IN ('processing','completed') " +
                "                THEN lis_cache.lis_worklists.status ELSE 'pending' END, " +
                "  patient_name = EXCLUDED.patient_name, test_name = EXCLUDED.test_name, " +
                "  priority = EXCLUDED.priority, last_synced_at = EXCLUDED.last_synced_at, " +
                "  updated_at = EXCLUDED.updated_at",
                [
                    String(java.util.UUID.randomUUID()),
                    row.worklist_id, key,
                    row.machine_id || null,
                    row.machine_mapping_id || null,
                    row.barcode,
                    row.receipt_barcode || null,
                    row.patient_id || null,
                    row.patient_name || null,
                    row.patient_age || null,
                    row.patient_gender || null,
                    row.test_code,
                    row.test_name || null,
                    row.sample_type || null,
                    row.tube_type || null,
                    row.timing || null,
                    row.priority || 'routine',
                    row.collection_date || null,
                    row.test_sample_item_id || null,
                    row.test_sample_id || null,
                    row.lab_receipt_id || null,
                    row.lab_test_id || null,
                    row.test_id || null,
                    nowTs, nowTs, nowTs
                ]);
            totalUpserted++;
        }
        logger.info('sync-pull ' + key + ': ' + worklists.length + ' worklists');
    } catch (e) { logger.error('sync-pull failed for ' + key + ': ' + e); }
}

// Cancel detection: local pending rows not seen this cycle (>2 min old) → cancelled
try {
    var fmt2 = new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss");
    var rs = db.executeCachedQuery(
        "SELECT laravel_id FROM lis_cache.lis_worklists " +
        "WHERE status = 'pending' AND last_synced_at < ?",
        [fmt2.format(new java.util.Date(System.currentTimeMillis() - 120000))]);
    var toCancel = [];
    while (rs.next()) {
        var lid = rs.getString('laravel_id');
        if (!seenIds[lid]) toCancel.push(lid);
    }
    rs.close();
    if (toCancel.length > 0) {
        db.executeUpdate(
            "UPDATE lis_cache.lis_worklists SET status='cancelled', updated_at=? " +
            "WHERE laravel_id IN ('" + toCancel.join("','") + "') AND status='pending'",
            [fmt2.format(new java.util.Date())]);
        logger.info('sync-pull cancelled ' + toCancel.length + ' stale local rows');
    }
} catch (e) { logger.error('sync-pull cancel detection failed: ' + e); }

db.close();
logger.info('sync-pull done: ' + totalUpserted + ' upserted');
```

Laravel side note: `MirthController::getWorklist` sets `pushed_at = now()` on every returned row — that is the reservation, so the next pull gets only fresh rows. Response fields used above (`worklist_id`, `barcode`, `receipt_barcode`, `patient_*`, `test_code`, `test_name`, `sample_type`, `tube_type`, `timing`, `priority`, `collection_date`, ids) all exist on the simplified `worklists` table.

## 13. Channel: `lis_sync_push` — results Mirth → Laravel (PUSH)

| Setting | Value |
|---|---|
| Source | Database Reader, 15000 ms |
| Query | `SELECT * FROM lis_cache.lis_results WHERE synced_at IS NULL AND retry_count < 5 ORDER BY created_at LIMIT 50` |
| Destination 1 | HTTP Sender `POST https://lab.example.com/api/legacy/result`, body `${result_data}`, header `Content-Type: application/json` |
| Destination 2 | JavaScript Writer (process response, mark synced/retry) |

Destination 2 (JavaScript Writer):

```javascript
var rowId = $('id');
var response = responseMap.get('Response 1') || '';
var statusCode = $('responseStatus') || 0;

var db = DatabaseConnectionFactory.createDatabaseConnection(
    'org.postgresql.Driver',
    'jdbc:postgresql://localhost:5432/mirth_db',
    'mirth', '<MIRTH_DB_PASSWORD>');

try {
    var type = '';
    try { type = JSON.parse(response).type; } catch (e) {}
    var fmt = new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss");
    var nowTs = fmt.format(new java.util.Date());

    if (statusCode >= 200 && statusCode < 300 && type === 'success') {
        db.executeUpdate(
            "UPDATE lis_cache.lis_results SET synced_at=?, sync_response=?, updated_at=? WHERE id=?",
            [nowTs, response, nowTs, rowId]);
        logger.info('sync-push OK: ' + rowId);
    } else if (statusCode >= 500 || statusCode == 429 || statusCode == 0) {
        db.executeUpdate(
            "UPDATE lis_cache.lis_results SET retry_count=retry_count+1, sync_response=?, updated_at=? WHERE id=?",
            [response, nowTs, rowId]);
        logger.warn('sync-push TRANSIENT: ' + rowId + ' HTTP ' + statusCode);
    } else {
        db.executeUpdate(
            "UPDATE lis_cache.lis_results SET synced_at=?, sync_response=?, sync_error=?, retry_count=retry_count+1, updated_at=? WHERE id=?",
            [nowTs, response, 'type=' + type + ' HTTP=' + statusCode, nowTs, rowId]);
        logger.error('sync-push FAILED (data issue): ' + rowId);
    }
} catch (e) {
    logger.error('sync-push error: ' + e);
} finally { db.close(); }
```

Retry contract:

| Laravel answer | retry_count | re-polled? |
|---|---|---|
| `type=success` | — | no (done) |
| network/5xx/429/timeout | +1, no `synced_at` | yes next cycle (≤5) |
| 4xx / `type=fail|invalid|unmatched` | +1, `synced_at=now()` + `sync_error` | no — operator fix needed |

## 14. All-or-selected config

```javascript
// All active machines (one extra GET per cycle):
var cfg = httpGet(API_BASE + '/machine-config/ALL', {});
// Requires MachineConfigController::show to accept 'ALL' → list every active device_model:
// if ($deviceModel === 'ALL') return all active machines (summary only).

// Selected (recommended):
var MACHINE_KEYS = ['SYSMEX-XN1000', 'MINDRAY-BS240'];
```

`show('ALL')` addition:

```php
if ($deviceModel === 'ALL') {
    return response()->json([
        'success' => true,
        'machines' => Machine::where('is_active', true)
            ->get(['device_model', 'direction', 'protocol']),
    ]);
}
```

---

# PART V — Per-analyzer channels (ONE channel = worklist + result)

Each analyzer gets ONE channel. The source transformer routes by message type:
- **Query arrives** (HL7 `QRY^Q02` / ASTM `Q` record) → build reply from **local** `lis_cache.lis_worklists` (no Laravel on this path).
- **Result arrives** (HL7 `ORU^R01` / ASTM `R` records / CSV line) → INSERT into `lis_cache.lis_results`, ACK immediately. Push happens later via `lis_sync_push`.

## 15. HL7 bidirectional analyzer (e.g. Sysmex XN-1000) — one channel

### 15.1 Channel settings

| Setting | Value |
|---|---|
| Source | TCP Listener, MLLP (`0x0B … 0x1C 0x0D`), port e.g. 6661 |
| Data type | HL7 v2.x |
| Response | Auto-generate (After source transformer) — we override via `responseMap` |
| Destination 1 | JavaScript Writer — local DB INSERT (results) |
| Deploy script | fetch `/api/mirth/machine-config/<device_model>` → store mapping in Configuration Map |

### 15.2 Source transformer — router + both modes

```javascript
// ================================================================
//  <MACHINE>-wl-rs source transformer — HL7 bidirectional
//  Routes: QRY^Q02 → OML^O21 reply (from LOCAL cache)
//          ORU^R01 → INSERT lis_results (queue for sync-push)
// ================================================================

var db = DatabaseConnectionFactory.createDatabaseConnection(
    'org.postgresql.Driver',
    'jdbc:postgresql://localhost:5432/mirth_db',
    'mirth', '<MIRTH_DB_PASSWORD>');

var fmt = new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss");
var nowTs = fmt.format(new java.util.Date());
var msgType = msg['MSH']['MSH.9']['MSH.9.1'].toString() +
              '^' + msg['MSH']['MSH.9']['MSH.9.2'].toString();
var DEVICE_MODEL = 'SYSMEX-XN1000';   // == machines.device_model == X-Machine-Key

try {

// ────────────────────────────────────────────────────────────────
//  MODE A — WORKLIST QUERY (QRY^Q02)
// ────────────────────────────────────────────────────────────────
if (msgType == 'QRY^Q02') {
    var barcode = msg['QRD']['QRD.3']['QRD.3.1'].toString();

    // local lock query — sibling lock, no Laravel
    var rs = db.executeCachedQuery(
        "SELECT * FROM lis_cache.lis_worklists " +
        "WHERE device_model = ? AND barcode = ? AND status = 'pending' " +
        "ORDER BY priority DESC, created_at ASC LIMIT 1",
        [DEVICE_MODEL, barcode]);

    var oml = '';   // OML^O21 or 'no data' OML
    if (!rs.next()) {
        oml = buildEmptyOml(barcode);      // helper: valid OML, no OBR — analyzer moves on
    } else {
        // lock locally
        db.executeUpdate(
            "UPDATE lis_cache.lis_worklists SET status='processing', locked_by=?, updated_at=? WHERE id=?",
            [DEVICE_MODEL, nowTs, rs.getString('id')]);

        oml = buildOmlFromLocalRow(rs);    // helper: H, PID, OBR, OBX from cached row
    }
    rs.close();

    responseMap.put('Auto-generate (After source transformer)', oml);
    logger.info('QRY served from local cache: ' + barcode);
}

// ────────────────────────────────────────────────────────────────
//  MODE B — RESULT UPLOAD (ORU^R01)
// ────────────────────────────────────────────────────────────────
else if (msgType == 'ORU^R01') {
    var runId = $('messageId');            // one per analyzer message
    var barcode = msg['OBR']['OBR.2']['OBR.2.1'].toString() ||
                  msg['OBR']['OBR.3']['OBR.3.1'].toString();
    var obrDate = msg['OBR']['OBR.7']['OBR.7.1'].toString();

    // gender fix — HL7 wants M/F; Laravel may store "Male"
    var genderRaw = msg['PID']['PID.8']['PID.8.1'].toString();

    var results = [];
    for each (var obx in msg['OBX']) {
        var code = obx['OBX.3']['OBX.3.2'].toString() ||      // '1^WBC' → WBC
                   obx['OBX.3']['OBX.3.1'].toString();
        code = String(code).split('^').pop();
        var value = obx['OBX.5']['OBX.5.1'].toString();
        var unit = obx['OBX.6']['OBX.6.1'].toString();
        var refRange = obx['OBX.7']['OBX.7.1'].toString();
        var flag = obx['OBX.8']['OBX.8.1'].toString();
        if (code && value !== '') {
            results.push({ code: code, value: String(value), unit: unit,
                           reference_range: refRange, abnormal_flag: flag });
        }
    }

    // queue for sync-push — Laravel is NEVER called from this channel
    var payload = JSON.stringify({
        barcode: barcode,
        sample_number: null, suffix: null,   // legacy payload keys — ignored; Laravel resolves the tube by barcode (test_sample_items.suffix kept server-side)
        results: results,
        device_model: DEVICE_MODEL,
        run_id: runId,
        patient_gender: genderRaw,
        raw_message: connectorMessage.getRawData(),
        parsed_date: obrDate
    });

    var resultId = String(java.util.UUID.randomUUID());
    db.executeUpdate(
        "INSERT INTO lis_cache.lis_results " +
        "(id, worklist_id, machine_id, device_model, barcode, test_code, run_id, " +
        " result_data, raw_message, status, retry_count, created_at, updated_at) " +
        "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', 0, ?, ?)",
        [resultId, null, DEVICE_MODEL, DEVICE_MODEL, barcode, null, runId,
         payload, connectorMessage.getRawData(), nowTs, nowTs]);

    // local lock completion
    db.executeUpdate(
        "UPDATE lis_cache.lis_worklists SET status='completed', " +
        " result_id=?, updated_at=? WHERE device_model=? AND barcode=? AND status IN ('pending','processing')",
        [resultId, nowTs, DEVICE_MODEL, barcode]);

    logger.info('ORU queued: ' + barcode + ' params=' + results.length);
}

// ────────────────────────────────────────────────────────────────
//  ACK (MSA) — analyzer acking our OML; nothing to do
// ────────────────────────────────────────────────────────────────

} catch (e) {
    logger.error('transformer error: ' + e);
    throw e;     // Mirth errors the message — visible in Dashboard, no silent loss
} finally {
    db.close();
}
```

### 15.3 Helpers (put in the transformer's `Code Template` or deploy script)

```javascript
function buildOmlFromLocalRow(rs) {
    // OBR-7 needs YYYYMMDDHHMMSS
    function hl7ts(v) {
        if (!v) return '';
        return String(v).replace(/[^0-9]/g, '').substring(0, 14);
    }
    var barcode  = rs.getString('barcode');
    var collTs   = hl7ts(rs.getTimestamp('collection_date'));
    var priority = String(rs.getString('priority')) === 'high' ? 'S' : 'R';
    var gender   = ({ 'Male':'M','male':'M','Female':'F','female':'F' })
                     [String(rs.getString('patient_gender'))] || 'O';

    return 'MSH|^~\\&|LIS|MIRTH|' + DEVICE_MODEL + '|' + DEVICE_MODEL +
           '|' + hl7ts(nowTs) + '||OML^O21|' + $('messageId') + '|P|2.3.1\r' +
           'PID|1||' + (rs.getString('patient_id') || '') + '|||' +
           (rs.getString('patient_name') || '') + '|||' + gender + '\r' +
           'OBR|1||' + barcode + '|' + rs.getString('test_code') + '^' +
           (rs.getString('test_name') || '') + '||||' + collTs + '|||' +
           (rs.getString('sample_type') || '') + '|||||||' + priority + '\r' +
           'OBX|1|NM||' + rs.getString('test_code') + '|||||||X\r';
}

function buildEmptyOml(barcode) {
    return 'MSH|^~\\&|LIS|MIRTH|' + DEVICE_MODEL + '|' + DEVICE_MODEL +
           '|' + hl7ts(nowTs) + '||OML^O21|' + $('messageId') + '|P|2.3.1\r' +
           'OBR|1||' + barcode + '||||||||||||||||||X\r';
}
```

## 16. HL7 unidirectional analyzer — one channel, result-only

Same channel shape as §15 with the MODE A block deleted. `direction = unidirectional` in Laravel; `MACHINE_KEYS` may omit it from sync-pull. Everything else (local INSERT, ACK, sync-push) is identical.

## 17. ASTM bidirectional analyzer (LIS2-A2) — one channel

### 17.1 Channel settings

| Setting | Value |
|---|---|
| Source | TCP Listener, **Raw** framing (ASTM has no MLLP), port e.g. 5100 |
| Data type | Raw / HL7 v2.x (treat as text) |
| Response | Auto-generate (After source transformer) — we put ASTM reply in responseMap |

### 17.2 Frame format (what you must parse)

```
<STX> frame-text <ETX> cc1 cc2 <CR><LF>      (last frame: <ETB> instead of ETX when batched)
  STX = 0x02, ETX = 0x03, ETB = 0x17, ENQ = 0x05, ACK = 0x06, NAK = 0x15, EOT = 0x04
  checksum = 2-hex-digit sum of all bytes between STX and ETX/ETB (inclusive), mod 256

Records:
  H|<sender>||<receiver>|||timestamp||P|E1394-97|97|||<process_id>|<machine>
  P|1||<patient_id>||<name>||<dob>|<sex>
  O|1|<sample_id(barcode)>||^^^<test_code>|R|<collected_ts>
  Q|1||^<barcode>^1||^^^<test>|||all          ← worklist QUERY
  R|1|^^^<param_code>|<value>|<unit>|<ref_range>||F||operator||<ts>
  L|1|N
```

### 17.3 Source transformer — frame splitter + router

```javascript
// ================================================================
//  MINDRAY-BS240 (ASTM) source transformer
// ================================================================
var DEVICE_MODEL = 'MINDRAY-BS240';
var db = DatabaseConnectionFactory.createDatabaseConnection(
    'org.postgresql.Driver', 'jdbc:postgresql://localhost:5432/mirth_db',
    'mirth', '<MIRTH_DB_PASSWORD>');
var fmt = new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss");
var nowTs = fmt.format(new java.util.Date());

// ── 1. Split the raw stream into ASTM frames + validate checksum ──
var raw = connectorMessage.getRawData();
var frames = [];
var buf = '';
var chkSum = 0, inFrame = false;

for (var i = 0; i < raw.length(); i++) {
    var ch = raw.charAt(i);
    var code = raw.charCodeAt(i);
    if (code == 2) { inFrame = true; chkSum = 0; buf = ''; continue; }      // STX
    if (code == 3 || code == 23) {                                          // ETX/ETB
        var expect = parseInt(raw.substring(i + 1, i + 3), 16);
        if (expect === chkSum % 256) frames.push(buf);
        else logger.warn('ASTM checksum mismatch, frame dropped');
        i += 3; inFrame = false; continue;
    }
    if (inFrame) { buf += ch; chkSum += code; }
}

// low-level ACK for the analyzer session
responseMap.put('Auto-generate (After source transformer)', '\u0006');   // ACK byte

// ── 2. Route by record type ──
try {
    var qBarcode = null;
    var patient = null, order = null, results = [];

    for (var f = 0; f < frames.length; f++) {
        var rec = frames[f].split('|');
        switch (frames[f].charAt(0)) {
            case 'Q':                                       // worklist query
                qBarcode = (rec[2] || '').replace(/\^/g, '') || null;
                break;
            case 'P': patient = rec; break;                 // patient record
            case 'O': order = rec; break;                   // order record
            case 'R':                                       // result record
                results.push({
                    code: (rec[2] || '').replace(/\^\^\^/g, '').split('^').pop(),
                    value: rec[3] || '', unit: rec[4] || '',
                    reference_range: rec[5] || '', abnormal_flag: rec[8] || ''
                });
                break;
            case 'L': break;                                // terminator
        }
    }

    // ── 2a. QUERY → reply with H, P, O records from LOCAL cache ──
    if (qBarcode) {
        var rs = db.executeCachedQuery(
            "SELECT * FROM lis_cache.lis_worklists " +
            "WHERE device_model = ? AND barcode = ? AND status = 'pending' " +
            "ORDER BY priority DESC, created_at ASC LIMIT 1",
            [DEVICE_MODEL, qBarcode]);

        var reply = '';
        if (rs.next()) {
            db.executeUpdate(
                "UPDATE lis_cache.lis_worklists SET status='processing', locked_by=?, updated_at=? WHERE id=?",
                [DEVICE_MODEL, nowTs, rs.getString('id')]);

            reply = buildAstmWorklistReply(rs);   // helper below
        } else {
            // empty L-only reply → analyzer moves on
            reply = frame('L|1|N');
        }
        rs.close();

        // overwrite the plain ACK with the full reply
        responseMap.put('Auto-generate (After source transformer)', reply);
        logger.info('ASTM query served: ' + qBarcode);
    }

    // ── 2b. RESULTS → queue for sync-push ──
    if (results.length > 0 && order) {
        var barcode = (order[2] || '').replace(/\^/g, '');
        var testCode = (order[4] || '').replace(/\^\^\^/g, '').split('^').pop();
        var runId = String(java.util.UUID.randomUUID());

        var payload = JSON.stringify({
            barcode: barcode, test_code: testCode, results: results,
            device_model: DEVICE_MODEL, run_id: runId,
            raw_message: String(raw)
        });

        db.executeUpdate(
            "INSERT INTO lis_cache.lis_results " +
            "(id, worklist_id, machine_id, device_model, barcode, test_code, run_id, " +
            " result_data, raw_message, status, retry_count, created_at, updated_at) " +
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', 0, ?, ?)",
            [String(java.util.UUID.randomUUID()), null, DEVICE_MODEL, DEVICE_MODEL,
             barcode, testCode, runId, payload, String(raw), nowTs, nowTs]);

        db.executeUpdate(
            "UPDATE lis_cache.lis_worklists SET status='completed', updated_at=? " +
            "WHERE device_model=? AND barcode=? AND status IN ('pending','processing')",
            [nowTs, DEVICE_MODEL, barcode]);

        logger.info('ASTM results queued: ' + barcode + ' params=' + results.length);
    }
} catch (e) {
    logger.error('ASTM transformer error: ' + e);
    throw e;
} finally { db.close(); }
```

### 17.4 ASTM frame + reply helpers

```javascript
function frame(text) {                       // wrap + checksum
    var sum = 0;
    for (var i = 0; i < text.length(); i++) sum += text.charCodeAt(i);
    return '\u0002' + text + '\u0003' +
           java.lang.Integer.toHexString(sum % 256).toUpperCase() + '\r\n';
}

function buildAstmWorklistReply(rs) {
    var collTs = String(rs.getTimestamp('collection_date') || '')
        .replace(/[^0-9]/g, '').substring(0, 14);
    var gender = ({ 'Male':'M','male':'M','Female':'F','female':'F' })
        [String(rs.getString('patient_gender'))] || 'U';

    var h = frame('H|\\^&||LIS^MIRTH||' + DEVICE_MODEL + '||||||P|E1394-97|97');
    var p = frame('P|1||' + (rs.getString('patient_id') || '') + '||' +
        (rs.getString('patient_name') || '') + '|||' + gender);
    var o = frame('O|1|' + rs.getString('barcode') + '||^^^' +
        rs.getString('test_code') + '|R|||N|||' + collTs + '|||||||||||' +
        (String(rs.getString('priority')) === 'high' ? 'S' : 'R'));
    var l = frame('L|1|N');
    return h + p + o + l;
}
```

> Some Mindray/analyser builds expect the host to answer `ENQ` with `ACK` first, then frames. If you see "no reply" in analyzer logs: put `responseMap.put(..., '\u0006')` when the raw starts with `\u0005` (ENQ) and the frame reply afterwards. Test with the vendor simulator first.

## 18. Other / serial CSV analyzers — one channel

For machines that dump flat files or talk CSV over serial→COM→TCP:

| Setting | Value |
|---|---|
| Source | **File Reader / Directory Reader** (watch a drop-folder) or TCP Listener Raw |
| Transformer | vendor-specific line parser → same `lis_results` INSERT as §15 MODE B |
| Mode | effectively unidirectional |

Skeleton (replace the parse block with the vendor spec):

```javascript
var lines = connectorMessage.getRawData().split('\n');
var results = [];
for each (var line in lines) {
    // EXAMPLE: "1260914000|WBC|6.2|10^3/uL|4.0-10.0|N"
    var p = line.trim().split('|');
    if (p.length >= 4) {
        results.push({ code: p[1], value: p[2], unit: p[3],
                       reference_range: p[4] || '', abnormal_flag: p[5] || '',
                       barcode: p[0] });
    }
}
// then: identical INSERT into lis_cache.lis_results as §15 MODE B
```

Manual entry mode (operator + LIS UI) needs **no channel** — results flow in via `/api/legacy/result` or `/api/mirth/results` when the operator/analyzer pushes them; `ingestResult` auto-heals the worklist.

---

# PART VI — Result ingest per mode + version handling

## 19. Mode matrix — what happens on the Laravel side

| | Bidirectional | Unidirectional | Manual |
|---|---|---|---|
| Worklist before run | yes (fan-out + pull) | maybe | maybe |
| Result enters via | `lis_sync_push` → `/api/legacy/result` | same | same (or direct POST) |
| Laravel function | `ingestResult()` | `ingestResult()` | `ingestResult()` |
| Worklist found? | yes → complete | yes → complete / **auto-heal** | yes → complete / **auto-heal** |
| `worklists.source` | `fanout` | `fanout` or `auto` | `fanout` / `manual` / `auto` |
| Version | `MAX+1` (run_id groups one message) | same | same |
| Re-run / correction | version N+1 — never rejected | same | same |

## 20. Version contract (the whole story in one table)

| Question | Answer |
|---|---|
| Which version did this write get? | `version = MAX(version)+1` computed in the INSERT transaction; rows of one `run_id` share the version |
| Latest result? | `worklists.latest_result_id` or `ORDER BY version DESC` |
| Was it corrected? | `version > 1` (`result_type='rerun'`) |
| History? | `SELECT * FROM lis_results WHERE worklist_id=? ORDER BY version` |
| "data version not found"? | cannot happen — version never looked up, always computed |

Example — one Sysmex run then a re-run:

| run_id | barcode | test_code | version | result_type | value |
|---|---|---|---|---|---|
| msg-A1 | 1260914000 | WBC | 1 | final | 6.2 |
| msg-A1 | 1260914000 | RBC | 1 | final | 4.8 |
| msg-A1 | 1260914000 | HGB | 1 | final | 13.5 |
| msg-B7 | 1260914000 | WBC | 2 | rerun | 6.4 |
| msg-B7 | 1260914000 | HGB | 2 | rerun | 13.1 |

## 21. Unmatched results — the safety net

Unknown barcode / no mapping → row parked in `lis_results` (`status='unmatched'`, reason in `error_log`). Nothing lost. Re-link after fixing data:

```php
// app/Console/Commands/ReprocessUnmatchedResults.php
public function handle(WorklistService $svc)
{
    $rows = DB::table('lis_results')->where('status', 'unmatched')->orderBy('created_at')->get();
    foreach ($rows as $row) {
        $machine = Machine::find($row->machine_id);
        $out = $svc->ingestResult([
            'barcode' => $row->barcode, 'test_code' => $row->test_code,
            'result_value' => $row->result_value, 'unit' => $row->unit,
            'run_id' => $row->run_id, 'received_at' => $row->received_at,
        ], $machine);
        if ($out['success'] ?? false) {
            DB::table('lis_results')->where('id', $row->id)
                ->update(['status' => 'matched', 'error_log' => null]);
        }
    }
}
```

Optional nightly repair (sample collected but fan-out silently failed): `TestSampleItem::whereNotNull('collected_at')->whereDoesntHave('worklists')->get()` → `createWorklistForTest()` each.

---

# PART VII — Sample messages

## 22. HL7 worklist query (analyzer → Mirth)

```
MSH|^~\&|SYSMEX|XN1000|MIRTH|LIS|20261117103000||QRY^Q02|MSG00001|P|2.3.1
QRD|20261117103000|R|I|||^1260914000^1|REQ|1
```

## 23. OML^O21 reply (Mirth → analyzer, from LOCAL cache)

```
MSH|^~\&|LIS|MIRTH|SYSMEX|XN1000|20261117103001||OML^O21|MSG00001|P|2.3.1
PID|1||019f...|||Md Nakibuzzaman|||M
OBR|1||1260914000|CBC^Complete Blood Count||||20261117100000|||Whole Blood|||||||R
OBX|1|NM||CBC|||||||X
```

## 24. ORU^R01 result upload (analyzer → Mirth)

```
MSH|^~\&|SYSMEX|XN1000|MIRTH|LIS|20261117103200||ORU^R01|MSG00002|P|2.3.1
PID|1||019f...|||Md Nakibuzzaman|||M
OBR|1||1260914000|CBC^Complete Blood Count|||20261117103200
OBX|1|NM|1^WBC||6.2|10^3/uL|4.0-10.0|N|||F
OBX|2|NM|2^RBC||4.8|10^6/uL|4.2-5.8|N|||F
OBX|3|NM|3^HGB||13.5|g/dL|13.0-17.0|N|||F
```

> OBX.3.2 carries the parameter code (`1^WBC` → parse `.3.2`); OBX.8 is the abnormal flag (`N`, `L`, `H`, `HH`, `LL`).

## 25. ACK variants (Mirth → analyzer)

```
MSH|^~\&|LIS|MIRTH|SYSMEX|XN1000|20261117103201||ACK|MSG00002|P|2.3.1
MSA|AA|MSG00002
```

`AA` = accepted. `AE`/`AR` = error/rejected (analyzer will retry or hold).

## 26. ASTM session examples

Host query (bidirectional ASTM):

```
→ (from analyzer)  <STX>Q|1||^1260914000^1||^^^ALL|||all<ETX>3F<CR>
← (from Mirth)     ACK byte
← (from Mirth)     <STX>H|\^&||LIS^MIRTH||MINDRAY-BS240||||||P|E1394-97|97<ETX>xx<CR>
                   <STX>P|1||019f...||Md Nakibuzzaman|||M<ETX>xx<CR>
                   <STX>O|1|1260914000||^^^CBC|R|||N|||20261117100000|||||||||||R<ETX>xx<CR>
                   <STX>L|1|N<ETX>xx<CR>
```

Result upload:

```
→ H|\^&||MINDRAY^BS240||LIS||||||P|E1394-97|97
→ P|1||019f...||Md Nakibuzzaman|||M
→ O|1|1260914000||^^^CBC|R|20261117103200
→ R|1|^^^WBC|6.2|10^3/uL|4.0-10.0||F
→ R|1|^^^RBC|4.8|10^6/uL|4.2-5.8||F
→ L|1|N
← ACK byte after each frame
```

---

# PART VIII — Deployment, testing, troubleshooting

## 27. Adding a new analyzer (cookbook)

1. Add the machine in Laravel: `machines` row with `device_model`, `direction`, `protocol`, `is_active=1`.
2. Add `machine_test_mappings` rows (test_code ↔ test, `parameters` JSON if parameter-level).
3. Clone the closest template channel (§15 HL7-bi / §16 HL7-uni / §17 ASTM / §18 serial).
4. Change `DEVICE_MODEL`, port, and any vendor-specific parsing.
5. Deploy script: fetch `/api/mirth/machine-config/<device_model>` → Configuration Map.
6. Add the `device_model` to `MACHINE_KEYS` in `lis_sync_pull` (if bidirectional).
7. Test: simulator → QRY → OML; ORU → dashboard → `lis_results` row → sync-push → Laravel.

## 28. Testing checklist (end-to-end)

| # | Test | Expected |
|---|---|---|
| 1 | Collect sample → fan-out | worklist row `pending`, `pushed_at NULL` |
| 2 | sync-pull cycle (≤30 s) | local `lis_worklists` row appears; Laravel `pushed_at` set |
| 3 | Send QRY (HL7 tool / simulator) | OML reply with patient + test from LOCAL cache |
| 4 | Send ORU (3 params) | local `lis_results` row (synced_at NULL); ACK `AA` returned fast |
| 5 | sync-push cycle (≤15 s) | Laravel `lis_results` 3 rows version 1; worklist `completed` |
| 6 | Send same ORU again | version 2 (`rerun`), no duplicate (hash identical → skipped) |
| 7 | Unplug internet 5 min | analyzer flow works; sync-push queues; on reconnect drains |
| 8 | Unknown barcode ORU | Laravel parks `unmatched` with reason; Mirth keeps `synced_at` after `type=unmatched` (operator fix) |
| 9 | Cancel order in Laravel | next sync-pull marks local row `cancelled`; QRY returns empty OML |
| 10 | Two machines same tube | first completes; second machine's row stays `locked`; result still ingested (version++) |

## 29. Troubleshooting matrix

| Symptom | Cause | Fix |
|---|---|---|
| QRY gets empty reply though worklists exist | sync-pull not running / wrong `MACHINE_KEYS` / `device_model` mismatch | check `lis_sync_state.last_pull_*`; verify key == `machines.device_model` |
| ORU ACKed but no `lis_results` row | transformer exception before INSERT | check Channel Map/Dashboard error; usually checksum (ASTM) or OBX path |
| `type=unmatched` from Laravel | barcode not in `test_samples` or no mapping for code | fix data or add `machine_test_mappings`; run `ReprocessUnmatchedResults` |
| sync-push stuck, retry_count grows | Laravel 5xx / network | check Laravel URL/SSL; queue drains when back |
| `result_count` not incrementing | `run_id` same across different runs | ensure `run_id = messageId` per message |
| Gender shows `O` | DB stores "Male", conversion missing | keep the `{'Male':'M',...}` map in OML/ASTM builders |
| Duplicate worklists per machine | fan-out ran twice + missing unique key | run §7 migration (`uq_worklist_item_machine`) |
| Two CBC OBXs for one barcode merged wrongly | OBX.3.1 not unique | use OBX.3.2 code convention `1^WBC` |
| Local row `processing` forever | analyzer crashed mid-run | maintenance job resets >1 h rows to `pending` |

## 30. Maintenance jobs (Mirth PostgreSQL)

```sql
-- prune synced results older than 90 days (weekly)
DELETE FROM lis_cache.lis_results
WHERE synced_at IS NOT NULL AND created_at < NOW() - INTERVAL '90 days';

-- prune completed/cancelled worklists older than 30 days
DELETE FROM lis_cache.lis_worklists
WHERE status IN ('completed','cancelled') AND updated_at < NOW() - INTERVAL '30 days';
```

```sql
-- stuck processing rows (alert if any return)
SELECT id, barcode, device_model, started_at FROM lis_cache.lis_worklists
WHERE status = 'processing' AND started_at < NOW() - INTERVAL '1 hour';
```

## 31. Monitoring one-liners

```sql
-- Laravel: results waiting to sync (Mirth side)
SELECT device_model, count(*) FROM lis_cache.lis_results WHERE synced_at IS NULL GROUP BY 1;

-- Laravel: unmatched backlog
SELECT * FROM lis_results WHERE status='unmatched' ORDER BY created_at DESC LIMIT 20;

-- Laravel: today's completed worklists per machine
SELECT machine_id, count(*) FROM worklists
WHERE status='completed' AND DATE(completed_at)=CURDATE() GROUP BY 1;
```

---

# PART IX — Appendices

## A. Glossary

| Term | Meaning |
|---|---|
| MLLP | HL7 framing: `0x0B … payload … 0x1C 0x0D` |
| LIS2-A2 | ASTM E1394 lab record layout (H/P/O/Q/R/L records) |
| `run_id` | one identifier per analyzer message — all its parameters share one version |
| auto-heal | `ingestResult` creating a missing worklist via existing fan-out |
| sibling lock | other machines' rows for the same tube+test are `locked` after one completes |
| `pushed_at` | Laravel-side reservation: row handed to sync-pull once |
| parked / unmatched | result stored with `status='unmatched'` + reason, never discarded |

## B. Quick start (TL;DR)

1. Run the Laravel migration (simplified schema + `lis_results`).
2. Add `ingestResult()` + `parkUnmatched()` to `WorklistService`; rewire `receiveResults` + `legacyResult` to call it. Remove the direction 403 on result POST.
3. Create `lis_cache` schema on Mirth PostgreSQL (§8).
4. Create `lis_sync_pull` (§12) and `lis_sync_push` (§13); set `MACHINE_KEYS`.
5. Create ONE channel per analyzer (§15/§16/§17/§18).
6. Test with the §28 checklist.

## C. Document map

| Topic | Doc |
|---|---|
| HL7 field-level details, ACK deep-dive, Mirth install | v4 guide (companion) |
| Result-path audit + ingest rationale | unified-result-ingest-design.md |
| Schema audit, column reduction line-by-line | worklist-lisresults-multi-version-design.md §1.7 |
| Laravel migration + code diffs | worklist-column-reduction-laravel-guide.md |

**This document is self-sufficient for the full worklist + result cycle, offline, in all three modes and all three protocols.**




