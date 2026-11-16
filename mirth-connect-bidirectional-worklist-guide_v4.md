# Mirth Connect — Bidirectional Worklist Integration Guide

### For LIS (Laravel) ⇄ Analyzers (Urit BH-5390, Snibe, and other HL7/ASTM machines)

> **Document type:** Reference / Operations Manual
> **Audience:** Integration engineers, LIS administrators, lab IT staff
> **Scope:** Bidirectional **and** Unidirectional, dynamic multi-analyzer deployment, end-to-end
> **Pattern:** Two patterns documented — **Pattern A: Direct** (Mirth calls Laravel per-message) and **Pattern B: Cached** (Mirth holds a local mirror of worklists and a results queue)
> **Version:** 4.0

---

## What's new in v4.0

v4.0 = v3.0 + **Pattern B: Local Cache Layer**. v3's direct pattern is preserved as Pattern A (still valid for small labs or single-analyzer setups with always-reachable Laravel).

### Two patterns, your choice

| Pattern | When to use | Pros | Cons |
|---|---|---|---|
| **A · Direct** (v3) | Single analyzer, Laravel always reachable, simple | No local DB to manage; Laravel is the single source of truth | Every QRY = HTTP round-trip; analyzer may time out if Laravel slow; no offline operation |
| **B · Cached** (v4 new) | Multi-analyzer, need offline tolerance, want fast QRY replies | Local queries (1–2 ms); Laravel can be briefly down; lower Laravel load | Extra Mirth channels + local DB to manage; cache must stay consistent with Laravel |

### Design defaults chosen for Pattern B (you can change later)

| Decision | Default | Why | Alternative |
|---|---|---|---|
| **Locking model** | Lock in Mirth local DB only; sync the lock state to Laravel in the next push cycle (≤15 s) | Fast QRY response; all analyzers reading same Mirth local DB see the lock immediately | Strict mode: also call `POST /api/mirth/acknowledge` immediately (slower but Laravel is authoritative within 100 ms) |
| **Sync direction** | Sync-pull always pulls `WHERE pushed_at IS NULL`; cancel detection = "row in local DB not in Laravel's response → mark cancelled" | No new Laravel endpoint needed; reuses existing `MirthController::getWorklist` | Delta sync (needs new endpoint); event-based (needs webhook setup) |
| **Sync interval** | Pull = 30 s, Push = 15 s | Tight enough that Laravel's view of in-flight tests is ≤45 s stale | Tighter for busier labs (10 s / 5 s); looser for quieter labs (60 s / 30 s) |
| **Local DB** | PostgreSQL (same DB instance as Mirth's internal DB, separate schema `lis_cache`) | Mirth already needs PostgreSQL; no second DB to install | MySQL if Mirth is configured for MySQL internally |
| **Result queue retention** | 90 days after `synced=true` | Audit trail; reasonable disk footprint | Adjust per compliance requirements |

### v3 fixes inherited (14 — all kept)

All 14 verified bug fixes from v3.0 are still applied in v4.0. The 5 reverted questionable changes (kept your v1 working pattern) are also still applied. See v3.0 changelog at the end of this document for the full list.

---

## Table of Contents

**PART I — Foundations** — 1 Executive Summary · 2 Concepts · 3 Architecture (A & B) · 4 Communication Styles
**PART II — Multi-Analyzer Strategy** — 5 One Channel Per Analyzer · 6 Naming · 7 Dynamic Config
**PART III — Mirth Setup** — 8 Prerequisites · 9 Install · 10 Channel Config · 11 MLLP & ACK
**PART IV — Source Transformer** — 12 Strategy · 13 Router · 14 Mode A · 15 Mode B · 16 Dynamic Loader · 17 Helpers · 18 Full Source Transformer
**PART V — Destination** — 19 Logic · 20 HTTP/DB · 21 Full Destination Code
**PART VI — Unidirectional** — 22 When to Use · 23 Source · 24 Destination · 25 Comparison
**PART VII — Laravel Backend** — 26 Endpoints · 27 GET /api/get-worklist · 28 POST /mirth/legacy-result · 29 POST /api/mirth/results · 30 ACK · 31 Health · 32 DLQ · 33 Export Config · 34 Snippets
**PART VIII — DB Schema (Laravel)** — 35 ER · 36 machines · 37 mappings · 38 worklists · 39 receipts/tests/samples · 40 components/reports · 41 results/DLQ/temp · 42 DDL
**PART IX — Sample HL7** — 43 QRY^Q02 · 44 OML^O21 · 45 ORU^R01 · 46 ACK
**PART X — Multi-Analyzer** — 47 Inventory · 48 New Analyzer · 49 Channel Groups
**PART XI — Testing** — 50 Strategy · 51 HL7 Send · 52 Channel Reader · 53 Postman · 54 Checklist
**PART XII — Troubleshooting** — 55 Matrix · 56 Logging · 57 Mirth Errors · 58 Laravel Errors
**PART XIII — Operations** — 59 Checklist · 60 Rollout · 61 Monitoring · 62 Backup
**PART XIV — Appendices** — A Glossary · B HL7 Reference · C Mirth Glossary · D Files · E Quick Start

---

# PART I — Foundations

## 1. Executive Summary

This guide documents two production-grade integration patterns between Mirth Connect and a Laravel-based LIS:

- **Pattern A · Direct** — Each analyzer QRY/ORU triggers an immediate HTTP call to Laravel. Simple, no local state. Best for single-analyzer setups.
- **Pattern B · Cached** — Mirth maintains a local PostgreSQL mirror of pending worklists and a queue of results to push back. QRYs are answered from local cache in 1–2 ms; Laravel is called only by two timer channels on a 15–30 s cycle. Best for multi-analyzer labs that need fast QRY response and offline tolerance.

Both patterns support bidirectional (worklist query + result upload) and unidirectional (result only) flows, and work for any HL7 v2.x analyzer (Urit BH-5390, Snibe, Sysmex, Mindray, Beckman, Roche, Abbott) by:

1. Cloning the channel
2. Changing the MLLP port
3. Loading the new analyzer's `machineConfig` JSON

Analyzer-specific knowledge lives in two places:
- The `machineConfig` JSON (mapping OBX codes → LIS component IDs, units, functions)
- The Laravel `machines` row (direction, port, IP, protocol, priority)

Laravel never sees raw HL7. **All HL7 parsing and OML^O21 building happens inside Mirth.** Laravel exchanges JSON only.

---

## 2. Concepts: Worklist, Unidirectional vs Bidirectional

### 2.1 What is a Worklist?

A **worklist** (order list / task list) is the set of pending tests the LIS has assigned to a specific analyzer for a specific sample tube. The analyzer scans the tube's barcode (e.g. `2608281001-F`) and must know which tests to run, what sample type it is, what priority, and patient demographics.

A worklist entry is created by the LIS as soon as:

1. A `LabReceipt` is registered (patient + tests requested)
2. Tubes are collected (`TestSample` + `TestSampleItem`)
3. The user clicks "Generate Worklist" — `WorklistService::createWorklistForGroup()` inserts one `Worklist` row **per (tube, machine, test) tuple** — fan-out across all machines whose `machine_test_mappings` accept that test

So if a CBC test is supported by Urit BH-5390 + Sysmex XN-1000 + Mindray BC-6800, three `worklists` rows are created, each tracking the test status on that specific machine.

### 2.2 Unidirectional vs Bidirectional

| Aspect | Unidirectional | Bidirectional |
|---|---|---|
| Analyzer asks LIS for tests? | No | Yes (QRY^Q02) |
| Analyzer sends results? | Yes (ORU^R01) | Yes (ORU^R01) |
| LIS sends worklist reply? | No | Yes (OML^O21) |
| Operator must key tests into analyzer? | Yes | No — fully automatic |
| Laravel `machines.direction` value | `'unidirectional'` | `'bidirectional'` |

### 2.3 Sibling Lock Rule (already in your Laravel code)

When one analyzer claims a worklist (status → `processing`), the **same test on other machines** is automatically locked (`status → 'locked'`). This prevents two analyzers from running the same test simultaneously. If the first analyzer fails or retries, `Worklist::markAsPending()` unlocks the siblings so another machine can pick it up.

In Pattern B, the same rule applies to the **Mirth local DB** — when an analyzer claims a worklist, Mirth locally locks the sibling rows. Laravel learns about the lock in the next sync-push cycle.

### 2.4 HL7 Messages Used

| Direction | Message | Trigger | Sender | Receiver |
|---|---|---|---|---|
| Analyzer → LIS | `QRY^Q02` | Q02 | Analyzer | Mirth (Source) |
| LIS → Analyzer | `OML^O21` | O21 | Mirth (responseMap) | Analyzer |
| Analyzer → LIS | `ORU^R01` | R01 | Analyzer | Mirth (Source) |
| LIS → Analyzer | `ACK^*` | Application ACK | Mirth (Source ACK) | Analyzer |

---

## 3. Architecture Overview

### 3.1 Pattern A · Direct (v3 pattern — single-analyzer, simple)

```
┌─────────────────────────────────────────────────────────────────────────┐
│                              MIRTH CONNECT                               │
│   ┌─────────────────────────────┐    ┌─────────────────────────────┐    │
│   │  Channel: urit_bh5390_bi    │    │  Channel: snibe_xxx_bi      │    │
│   │  (MLLP :5700)                │    │  (MLLP :5701)                │    │
│   │  Source: QRY/ORU             │    │                              │    │
│   │  Dest: HTTP POST Laravel     │    │                              │    │
│   └─────────────────────────────┘    └─────────────────────────────┘    │
└──────────────────┬──────────────────────────────────┬──────────────────┘
                   │ HTTPS per-message                │ HTTPS per-message
                   ▼                                   ▼
┌──────────────────────────────────────────────────────────────────────────┐
│                              LARAVEL LIS                                 │
│  /api/mirth/* endpoints                                                  │
└──────────────────────────────────────────────────────────────────────────┘
```

### 3.2 Pattern B · Cached (v4 new — multi-analyzer, offline-tolerant)

```
┌─────────────────────────────────────────────────────────────────────────┐
│                              MIRTH CONNECT                               │
│                                                                         │
│  ┌─────────────────────────────────────────────────────────────────┐    │
│  │  Channel: lis_sync_pull  (Timer, every 30 s)                    │    │
│  │   GET /api/mirth/worklist ──▶ Laravel                            │    │
│  │   ← worklists (pushed_at set in Laravel)                         │    │
│  │   UPSERT INTO lis_cache.lis_worklists (local PostgreSQL)         │    │
│  │   DELETE local rows whose Laravel ID is no longer in response    │    │
│  │     (cancel detection)                                          │    │
│  └─────────────────────────────────────────────────────────────────┘    │
│                                                                         │
│  ┌─────────────────────────────┐    ┌─────────────────────────────┐    │
│  │  Channel: urit_bh5390_bi    │    │  Channel: snibe_xxx_bi      │    │
│  │  (MLLP :5700)                │    │  (MLLP :5701)                │    │
│  │                               │    │                              │    │
│  │  QRY^Q02 arrives             │    │  QRY^Q02 arrives             │    │
│  │  → SELECT FROM lis_worklists  │    │  → SELECT FROM lis_worklists │    │
│  │     WHERE device_model=...    │    │     WHERE device_model=...   │    │
│  │     AND barcode=X             │    │     AND barcode=X            │    │
│  │     AND status='pending'      │    │     AND status='pending'     │    │
│  │  → build OML^O21 (1–2 ms)     │    │                              │    │
│  │  → UPDATE lis_worklists SET    │    │                              │    │
│  │     status='processing'        │    │                              │    │
│  │     siblings → 'locked'        │    │                              │    │
│  │                               │    │                              │    │
│  │  ORU^R01 arrives             │    │  ORU^R01 arrives             │    │
│  │  → parse OBX                  │    │  → parse OBX                 │    │
│  │  → INSERT INTO lis_results    │    │  → INSERT INTO lis_results   │    │
│  │     (status='completed',      │    │                              │    │
│  │      synced_at=NULL)          │    │                              │    │
│  └─────────────────────────────┘    └─────────────────────────────┘    │
│                                                                         │
│  ┌─────────────────────────────────────────────────────────────────┐    │
│  │  Channel: lis_sync_push  (Timer, every 15 s)                    │    │
│  │   SELECT FROM lis_results WHERE synced_at IS NULL LIMIT 50       │    │
│  │   POST /mirth/legacy-result ──▶ Laravel                          │    │
│  │   on body.type='success' → UPDATE lis_results SET synced_at=now  │    │
│  └─────────────────────────────────────────────────────────────────┘    │
│                                                                         │
│  Mirth local DB (PostgreSQL, schema "lis_cache"):                       │
│    lis_worklists  ·  lis_results  ·  lis_sync_state                      │
└──────────────────────────────────────────────────────────────────────────┘
                                   ▲
                                   │ HTTPS (sync only, no per-message)
                                   ▼
┌──────────────────────────────────────────────────────────────────────────┐
│                              LARAVEL LIS                                 │
│  GET /api/mirth/worklist (sync-pull) · POST /mirth/legacy-result (push)  │
└──────────────────────────────────────────────────────────────────────────┘
```

### 3.3 Pattern B Data Flow — Bidirectional (Urit BH-5390)

```
Phase 1 — Sync pull (every 30 s, async)
───────────────────────────────────────
1. lis_sync_pull channel: GET https://lab.htncr.org/api/mirth/worklist
   Header: X-Machine-Key: BH-5390   (one pull per machine, or per active bidirectional machine)
2. Laravel returns worklists WHERE machine_id=? AND status='pending' AND pushed_at IS NULL
   Sets pushed_at = now() on each returned row (reservation)
3. Mirth UPSERTs each row into lis_cache.lis_worklists
4. Mirth marks local rows whose Laravel ID is no longer in response as 'cancelled'

Phase 2 — Worklist query (analyzer scans tube)
─────────────────────────────────────────────
5. Urit BH-5390 sends QRY^Q02 to Mirth port 5700
6. Mirth source transformer: SELECT * FROM lis_worklists
   WHERE device_model='BH-5390' AND barcode='2608281001-F' AND status='pending'
   Returns in 1–2 ms (local PostgreSQL)
7. Mirth builds OML^O21 reply, returns via responseMap
8. Mirth UPDATE lis_worklists SET status='processing', started_at=now(), locked_by='BH-5390'
   WHERE id=<row_id>
   UPDATE lis_worklists SET status='locked'
   WHERE test_sample_item_id=<same> AND machine_id != <claimed_machine>
   (mirrors Laravel's sibling-lock rule, applied locally)

Phase 3 — Result upload (analyzer sends ORU^R01)
─────────────────────────────────────────────────
9. Mirth source transformer: parse OBX → rawValues, applyFunctions (String cast), EOS# derivation
10. Mirth INSERT INTO lis_results
    (id, worklist_id, machine_id, barcode, test_code, result_data, status='completed',
     synced_at=NULL, created_at=now())
11. Mirth UPDATE lis_worklists SET status='completed', completed_at=now(), result_id=<new row>
    WHERE id=<row_id>
12. Mirth returns ACK to analyzer (immediate, no Laravel wait)

Phase 4 — Sync push (every 15 s, async)
───────────────────────────────────────
13. lis_sync_push channel: SELECT * FROM lis_results WHERE synced_at IS NULL LIMIT 50
14. For each row: POST /mirth/legacy-result with payload
15. On body.type='success':
    UPDATE lis_results SET synced_at=now(), sync_response=<body>
    UPDATE lis_worklists SET laravel_status='completed' WHERE id=<row_id>
16. Laravel's legacyResult writes TestReport, calls completeWorklists()
    (which sets Laravel's worklists.status='completed', TestSampleItem='completed',
    LabTest.has_result=true)
17. On body.type='fail' or 'invalid': log to lis_results.sync_error, leave synced_at=NULL
    (next cycle will retry; or operator inspects and marks synced=true manually)
```

### 3.4 Pattern B Data Flow — Unidirectional

Phases 1, 2 skipped. The analyzer just sends ORU^R01 (Phase 3). The lis_sync_push channel still syncs results back. No worklist rows exist in local DB for unidirectional machines (since `canPullWorklist()` returns false).

### 3.5 Pattern B — Offline Tolerance

If Laravel is briefly unreachable:

| Phase | What happens during outage |
|---|---|
| Sync pull | lis_sync_pull logs error, skips this cycle. Local `lis_worklists` keeps serving cached rows. New worklists created in Laravel during outage will appear after Laravel is back. |
| Worklist query | Mirth answers from local cache. Analyzer runs normally. |
| Result upload | Mirth writes to `lis_results` with `synced_at=NULL`. Results queue locally. |
| Sync push | lis_sync_push logs error, skips. Queue grows; will drain when Laravel is back. |

Analyzer operations are not affected by Laravel downtime, as long as Mirth itself is up.

---

## 4. The Two Communication Styles

### 4.1 Style A — Query / Response (Bidirectional)

```
Analyzer ──QRY^Q02──▶ Mirth ──[Pattern A: HTTP GET to Laravel | Pattern B: local DB query]──▶ data
                                              │
Analyzer ◀──OML^O21── Mirth ◀──JSON / SQL result──┘
```

### 4.2 Style B — Push (Unidirectional)

```
Analyzer ──ORU^R01──▶ Mirth ──[Pattern A: HTTP POST Laravel | Pattern B: INSERT into lis_results]
```

---

# PART II — Multi-Analyzer Strategy

## 5. Why One Channel Per Analyzer

Each analyzer speaks HL7 with subtle dialect differences: MLLP framing variants, field separators, MSH.9 shapes (`QRY^Q02`, `QBP^Q11`, `TSREQ`), OBX-3 conventions, ACK expectations. A single mega-channel becomes unmaintainable.

Clean pattern: **one channel per physical analyzer**, unique MLLP port, own `machineConfig`.

Pattern B advantage: the sync channels (`lis_sync_pull`, `lis_sync_push`) are **shared** across all analyzer channels — they handle Laravel I/O once for the whole lab.

## 6. Channel Naming Convention

```
<vendor>_<model>_<direction>[_<sample_type>]
```

| Channel | Vendor | Model | Direction | Port |
|---|---|---|---|---|
| `urit_bh5390_bi` | Urit | BH-5390 | Bidirectional | 5700 |
| `snibe_xxx_bi` | Snibe | XXX | Bidirectional | 5701 |
| `sysmex_xn1000_bi` | Sysmex | XN-1000 | Bidirectional | 5702 |
| `mindray_bc6800_bi` | Mindray | BC-6800 | Bidirectional | 5703 |
| `beckman_au680_uni` | Beckman | AU680 | Unidirectional | 5710 |
| `roche_cobas_uni` | Roche | Cobas | Unidirectional | 5711 |

The `machines.device_model` column matches the middle component. The `X-Machine-Key` header uses this value — must be **unique** (Laravel uses `first()`).

**Pattern B additional channels (shared):**

| Channel | Type | Port | Purpose |
|---|---|---|---|
| `lis_sync_pull` | Timer + DB Writer | n/a | Pull worklists from Laravel → Mirth local DB |
| `lis_sync_push` | Timer + HTTP Sender | n/a | Push results from Mirth local DB → Laravel |

## 7. Dynamic Machine Config Pattern

### 7.1 The Problem with Hardcoded Configs

Each analyzer has different OBX codes, reference ranges, unit conventions, decimal places, transformation functions. Hardcoding in JS works but every change requires a Mirth channel redeploy.

### 7.2 The Dynamic Pattern

`machineConfig` is stored in the Laravel `machine_test_mappings.parameters` JSON column. Laravel exposes it via `GET /lab/machines/{machine_id}/export-config` (web-session auth — Mirth cannot call directly).

To use dynamic loading: add a token-authenticated export route first, then use the §16 dynamic loader. Until then, **embed the JSON in the source transformer** (your current pattern).

---

# PART III — Mirth Setup

## 8. Prerequisites

### 8.1 Software Stack

| Component | Version | Notes |
|---|---|---|
| Java JDK | 8 or 11 | Mirth 4.x requires Java 8+ |
| Mirth Connect / NextGen / BridgeLink | 4.1.x or 4.4.x | |
| Mirth internal DB | PostgreSQL 12+ recommended | **Required for Pattern B** (MySQL works for Pattern A) |
| OS | Linux x86_64 (Ubuntu 22.04 LTS preferred) | |
| Laravel | 10.x / 11.x | Already in production |

### 8.2 Network

- Mirth server must reach Laravel HTTPS on port 443
- Analyzers must reach Mirth on their assigned MLLP ports (5700, 5701, …)
- Firewall: inbound TCP per analyzer port, outbound HTTPS to Laravel
- `lab.htncr.org` / `admcr.advmedi.com` are behind Cloudflare with public CAs — **no truststore needed**

### 8.3 Lab-side Configuration

For each analyzer: set LIS-IP = Mirth server IP, LIS-Port = channel MLLP port, protocol = HL7 v2.3.1 (match `machines.protocol_version`), mode = bidirectional/unidirectional (match `machines.direction`).

### 8.4 TLS Truststore (only for self-signed / internal CA)

Skip for public-CA hosts. For internal hosts:

```bash
openssl s_client -connect <host>:443 -showcerts </dev/null 2>/dev/null \
  | openssl x509 -outform PEM > /tmp/host.pem
keytool -import -alias <host> -file /tmp/host.pem \
  -keystore /opt/mirth/conf/truststore.jks \
  -storepass '<STRONG_PASSWORD>' -noprompt
chown mirth:mirth /opt/mirth/conf/truststore.jks && chmod 600 /opt/mirth/conf/truststore.jks
```

Keep secrets in Mirth's Configuration Map / Settings, not in channel scripts or documents.

## 9. Mirth Installation Quick Reference

> Generic example — installer URLs/versions change, BridgeLink on Windows uses different ports/paths. Follow your own installation.

```bash
sudo apt-get update && sudo apt-get install -y openjdk-11-jdk-headless postgresql-14
sudo useradd -r -m -d /opt/mirth -s /bin/bash mirth
cd /tmp && wget <mirth-installer-url>
sudo chmod +x mirthconnect-*.sh && sudo ./mirthconnect-*.sh
sudo /opt/mirthconnect/mcservice start
curl -k https://localhost:8443/api/system/information
```

Default login: `admin / admin` — **change immediately**. Configure internal DB as PostgreSQL (required for Pattern B).

### 9.1 Create the lis_cache schema (Pattern B only)

After Mirth is installed and its PostgreSQL is running:

```bash
sudo -u postgres psql
CREATE SCHEMA lis_cache AUTHORIZATION mirth;
\q
```

Then run the DDL from §42A to create `lis_cache.lis_worklists`, `lis_cache.lis_results`, `lis_cache.lis_sync_state`.

## 10. Channel Configuration Step-by-Step

### 10.1 General Tab

Name: `urit_bh5390_bi` · Description: `Urit BH-5390 Bidirectional — worklist query + result upload` · Tags: `hematology`, `bidirectional`, `urit` · Pruning: keep 30 days · Initial State: Started · Store message payload: true.

### 10.2 Source Connector — TCP Listener (MLLP)

| Field | Value | Notes |
|---|---|---|
| Connector | TCP Listener | Transmission mode: MLLP |
| Port | 5700 | Unique per channel |
| Receive Timeout | 60000 ms | |
| Send Timeout | 30000 ms | |
| Buffer Size | 65536 | |
| Response | None — reply via responseMap | OML/ACK sent via `responseMap.put('Auto-generate (After source transformer)', oml)` |
| Socket Timeout | 0 (infinite) | |

### 10.3 MLLP Settings

| Field | Value | Meaning |
|---|---|---|
| Start byte | `0x0B` (`\u000b`) | VT — start of HL7 frame |
| End byte 1 | `0x1C` (`\u001c`) | FS — end of HL7 frame |
| End byte 2 | `0x0D` (`\u000d`) | CR — terminates frame |
| Send ACK | Yes (use response) | We send OML^O21 (worklist) or simple AA ACK (results) via responseMap |

### 10.4 Source Transformer

Transformer type: JavaScript · Message data type: HL7 v2.3.1. Paste the Full Source Transformer from §18.

### 10.5 Filter

Leave empty.

### 10.6 Destination — JavaScript Writer

Single JavaScript Writer destination. Pattern A: HTTP POST to Laravel (§21A). Pattern B: INSERT into Mirth local DB `lis_results` (§21B).

## 11. MLLP & ACK Settings Deep Dive

### 11.1 The MLLP Frame

```
[0x0B] MSH|...|... [0x1C][0x0D]
```

### 11.2 ACK Types

| Type | When | Example |
|---|---|---|
| Commit ACK (transport) | Sent automatically by Mirth after a complete frame | minimal MSA\|AA |
| Application ACK (semantic) | Sent by your Source Transformer via responseMap | OML^O21 (for QRY) or MSA\|AA (for ORU) |

### 11.3 How responseMap.put Works

When the Source Transformer writes `responseMap.put('Auto-generate (After source transformer)', oml)`, Mirth:

1. Uses `oml` as the Source Connector's response to the analyzer
2. Sends it over the same TCP socket the request came in on
3. Wraps it with MLLP framing bytes automatically

The string `'Auto-generate (After source transformer)'` is a documented Mirth 3.x/4.x responseMap key (it appears as a dropdown choice in Source Response settings). Works reliably when the string matches exactly.

---

# PART IV — Source Transformer (Core)

## 12. File Header & Strategy

The Source Transformer receives raw HL7 and decides:

1. **Message type** — QRY (worklist query) vs ORU (result upload)
2. **Extract fields** — barcode from QRD or OBR, OBX values
3. **Generate response**:
   - **Pattern A (direct):** for QRY, HTTP GET Laravel, build OML, put in responseMap. For ORU, build JSON, put in channelMap for destination to POST.
   - **Pattern B (cached):** for QRY, query local `lis_worklists`, build OML, put in responseMap, lock locally. For ORU, build JSON, put in channelMap for destination to INSERT into local `lis_results`.

## 13. Router Logic — Detect QRY vs ORU

```javascript
var msgType    = msg['MSH']['MSH.9']['MSH.9.1'].toString() || '';
var msgTrigger = msg['MSH']['MSH.9']['MSH.9.2'].toString() || '';
logger.info('HL7 type: ' + msgType + '^' + msgTrigger);

if (msgType == 'QRY' || msgTrigger == 'Q02' || msgTrigger == 'TSREQ') {
    interface_mode = 'WORKLIST_QUERY';
}
```

`TSREQ` covers some Urit firmware revisions. Default to `RESULT_UPLOAD` (safer than `IGNORE` in your case — only QRY and ORU are expected).

## 14. Mode A — Worklist Query (QRY → OML^O21)

### 14.1 Pattern A — Direct HTTP GET to Laravel

```javascript
// 1. Extract barcode
var barcode = '';
try { barcode = msg['QRD']['QRD.8']['QRD.8.1'].toString(); } catch(e) {}
if (!barcode) { try { barcode = msg['SPM']['SPM.2']['SPM.2.1'].toString(); } catch(e) {} }

// 2. HTTP GET Laravel
var apiUrl = 'https://lab.htncr.org/api/get-worklist?barcode=' +
             java.net.URLEncoder.encode(barcode, 'UTF-8') + '&api=<API_KEY>';
var conn = new java.net.URL(apiUrl).openConnection();
conn.setRequestMethod('GET');
conn.setConnectTimeout(10000); conn.setReadTimeout(10000);
conn.setRequestProperty('Accept', 'application/json');
var respCode = conn.getResponseCode();
// ... read body, parse JSON, build OML^O21, responseMap.put(...)
```

### 14.2 Pattern B — Query Local DB + Local Lock (v4 new)

Pattern B replaces the HTTP GET with a local SQL query and adds an immediate local lock + sibling lock. The OML^O21 builder is identical.

```javascript
// 1. Extract barcode (same as Pattern A)
var barcode = '';
try { barcode = msg['QRD']['QRD.8']['QRD.8.1'].toString(); } catch(e) {}
if (!barcode) { try { barcode = msg['SPM']['SPM.2']['SPM.2.1'].toString(); } catch(e) {} }
logger.info('Worklist query barcode: ' + barcode);

// 2. Query local lis_worklists (Mirth local DB via dbConn or router)
var dbConn = DatabaseConnectionFactory.createDatabaseConnection(
    'org.postgresql.Driver',
    'jdbc:postgresql://localhost:5432/mirth_db',
    'mirth', '<mirth_db_password>'
);

// Find pending worklists for THIS machine + barcode
var rows = dbConn.executeCachedQuery(
    "SELECT id, test_code, test_name, patient_id, patient_name, patient_age, patient_gender, " +
    "       sample_type, priority, test_sample_item_id " +
    "FROM lis_cache.lis_worklists " +
    "WHERE device_model = ? AND barcode = ? AND status = 'pending' " +
    "ORDER BY FIELD(priority,'high','routine','low'), created_at",
    [machineConfig.device_model, barcode]
);

var worklistData = { patient_id: '', patient_name: '', tests: [] };
var claimedWorklistIds = [];
var claimedTestSampleItemIds = [];

while (rows.next()) {
    if (!worklistData.patient_id) {
        worklistData.patient_id   = rows.getString('patient_id') || '';
        worklistData.patient_name = rows.getString('patient_name') || '';
    }
    worklistData.tests.push({
        test_id:   rows.getString('test_code'),
        test_name: rows.getString('test_name') || ''
    });
    claimedWorklistIds.push(rows.getString('id'));
    var tsii = rows.getString('test_sample_item_id');
    if (tsii) claimedTestSampleItemIds.push(tsii);
}
rows.close();

// 3. Lock claimed rows + sibling rows locally (mirrors Laravel's Worklist::markAsProcessing)
if (claimedWorklistIds.length > 0) {
    var now = new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss").format(new java.util.Date());

    // Mark claimed rows as processing
    var idList = "'" + claimedWorklistIds.join("','") + "'";
    dbConn.executeUpdate(
        "UPDATE lis_cache.lis_worklists SET status='processing', started_at=?, " +
        "locked_by=? WHERE id IN (" + idList + ")",
        [now, machineConfig.device_model]
    );

    // Lock siblings: same test_sample_item_id, different machine, still pending
    if (claimedTestSampleItemIds.length > 0) {
        var tsiiList = "'" + claimedTestSampleItemIds.join("','") + "'";
        dbConn.executeUpdate(
            "UPDATE lis_cache.lis_worklists SET status='locked' " +
            "WHERE test_sample_item_id IN (" + tsiiList + ") " +
            "AND device_model != ? AND status='pending'",
            [machineConfig.device_model]
        );
    }
}
dbConn.close();

// 4. Build OML^O21 (identical to Pattern A)
var msgCtrlId = msg['MSH']['MSH.10'].toString() || '';
var newCtrlId = 'OML' + DateUtil.getCurrentDate('yyyyMMddHHmmss');
var msh = 'MSH|^~\\&|LIS|LAB|ANALYZER|MODEL|' +
          DateUtil.getCurrentDate('yyyyMMddHHmmss') + '||OML^O21|' + newCtrlId + '|P|2.3.1';
var msa = 'MSA|AA|' + msgCtrlId + '||Worklist sent successfully';
var orc = 'ORC|NW|' + barcode + '||||CM';
var obr = 'OBR|1||' + barcode + '|||F|||' +
          (worklistData.patient_id || '') + '|' + (worklistData.patient_name || '');

var obxLines = '';
var testCounter = 0;
for each (var t in worklistData.tests) {
    testCounter++;
    obxLines += '\rOBX|' + testCounter + '|NM|' + t.test_id + '^' + t.test_name + '||||||F';
}
if (testCounter === 0) {
    obxLines = '\rOBX|1|NM|NO_TEST^No Test Ordered||||||F';
}
var omlResponse = msh + '\r' + msa + '\r' + orc + '\r' + obr + obxLines;

responseMap.put('Auto-generate (After source transformer)', omlResponse);
channelMap.put('barcode', barcode);
channelMap.put('device_model', machineConfig.device_model);
logger.info('OML^O21 built with ' + testCounter + ' tests (Pattern B local query)');
```

### 14.3 Pattern B — Locking Details

When the analyzer claims a worklist in Pattern B:

| Row type | What happens |
|---|---|
| Claimed row | `status='processing'`, `started_at=now()`, `locked_by='<device_model>'` |
| Sibling rows (same `test_sample_item_id`, different machine) | `status='locked'` |
| Locked rows | Not returned by future QRYs; analyzers for those machines see empty worklist |
| If first analyzer fails/retries | Sync-push channel reports the failure; Laravel's `Worklist::markAsPending()` unlocks siblings; next sync-pull cycle reflects the unlock in local DB |

### 14.4 Error Path (MSA|AE)

If the local query fails (DB connection error, schema missing, etc.):

```javascript
} catch (e) {
    logger.error('Pattern B worklist query failed: ' + e);
    var mcid = msg['MSH']['MSH.10'].toString() || '';
    var errorReply = 'MSH|^~\\&|LIS|LAB|ANALYZER|MODEL|' +
        DateUtil.getCurrentDate('yyyyMMddHHmmss') +
        '||OML^O21|OML' + DateUtil.getCurrentDate('yyyyMMddHHmmss') + '|P|2.3.1\r' +
        'MSA|AE|' + mcid + '||Local DB error: ' + e.message;
    responseMap.put('Auto-generate (After source transformer)', errorReply);
}
```

## 15. Mode B — Result Upload (ORU → JSON)

Same for both Pattern A and Pattern B up to building the payload. The difference is in §21 (where the payload goes).

### 15.1 Extract Common Fields

```javascript
var barcode = msg['OBR']['OBR.2']['OBR.2.1'].toString() ||
              msg['OBR']['OBR.3']['OBR.3.1'].toString() || null;
var date = msg['OBR']['OBR.7']['OBR.7.1'].toString() || null;
var deviceModel = msg['MSH']['MSH.4']['MSH.4.1'].toString() || 'ANALYZER';
```

### 15.2 Parse OBX Segments

```javascript
var rawValues = {};
if (msg['OBX']) {
    for each (var obx in msg['OBX']) {
        var code = obx['OBX.3']['OBX.3.2'].toString() || null;
        if (!code) continue;
        if (code.indexOf('.PNG') !== -1) continue;
        if (['MODE','MODE_EX','Ref','Note'].indexOf(code) !== -1) continue;
        var raw = obx['OBX.5']['OBX.5.1'].toString() || null;
        if (raw === null) continue;
        raw = raw.replace(/^\*/, '');
        var num = parseFloat(raw);
        if (!isNaN(num)) rawValues[code] = num;
    }
}
```

> ⚠️ **OBX.3 convention:** parser reads `OBX.3.2`. If your analyzer puts code in `OBX.3.1`, change one line:
> ```javascript
> var code = obx['OBX.3']['OBX.3.1'].toString() || obx['OBX.3']['OBX.3.2'].toString() || null;
> ```

### 15.3 Apply Machine Config Functions (v3 fix: cast to String)

```javascript
function applyFunctions(value, functions) {
    if (typeof value !== 'number') return String(value);
    var result = value;
    for each (var fn in functions) {
        switch (fn.name) {
            case 'convert_unit': result = result * (fn.params.factor || 1); break;
            case 'round':        result = result.toFixed(fn.params.decimal || 0); break;
            case 'format':
                if (fn.params.type === 'comma') {
                    var parts = result.toString().split('.');
                    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                    result = parts.length > 1 ? parts[0] + '.' + parts[1] : parts[0];
                }
                break;
        }
    }
    return String(result);    // Laravel validates results.*.value as string
}
```

### 15.4 Compute Derived Values (EOS#)

```javascript
if (rawValues['EOS#'] === undefined &&
    rawValues['WBC'] !== undefined &&
    rawValues['EOS%'] !== undefined) {
    rawValues['EOS#'] = (rawValues['EOS%'] / 100) * rawValues['WBC'];
}
```

### 15.5 Build the JSON Payload

```javascript
var results = [];
for each (var compDef in machineConfig.components) {
    var code = compDef.code;
    if (!code || rawValues[code] === undefined) continue;
    var formatted = applyFunctions(rawValues[code], compDef.functions);
    results.push({
        code: code, name: compDef.name,
        value: formatted, unit: compDef.unit || ''
    });
}

var payload = {
    machine_id: machineConfig.machine_id,
    api_key: machineConfig.api_key,
    barcode: barcode,
    device_model: deviceModel,
    date: date,
    mapping: machineConfig.mapping,
    results: results
};

channelMap.put('barcode', barcode);
channelMap.put('machine_id', machineConfig.machine_id);
channelMap.put('device_model', deviceModel);
channelMap.put('date', date);
channelMap.put('output', JSON.stringify(payload));
channelMap.put('result_count', results.length);
logger.info('Result upload: ' + results.length + ' results for barcode ' + barcode);
```

## 16. Dynamic Machine Config Loader (optional)

Same as v3 — see the `loadMachineConfig` sketch in v3.0. Requires a token-auth export route first; until then use embedded JSON.

## 17. Helper Functions Reference

### 17.1 applyFunctions — see §15.3

### 17.2 extractBarcode (null-safe)

```javascript
function extractBarcode(msg, preferQrd) {
    var barcode = '';
    if (preferQrd) { try { barcode = msg['QRD']['QRD.8']['QRD.8.1'].toString(); } catch(e) {} }
    if (!barcode)  { try { barcode = msg['OBR']['OBR.2']['OBR.2.1'].toString(); } catch(e) {} }
    if (!barcode)  { try { barcode = msg['OBR']['OBR.3']['OBR.3.1'].toString(); } catch(e) {} }
    if (!barcode)  { try { barcode = msg['SPM']['SPM.2']['SPM.2.1'].toString(); } catch(e) {} }
    return barcode;
}
```

### 17.3 safeHL7Field

```javascript
function safeHL7Field(msg, seg, field, sub) {
    try {
        if (sub) return msg[seg][field][sub].toString() || '';
        return msg[seg][field].toString() || '';
    } catch (e) { return ''; }
}
```

### 17.4 hl7Escape — escape `| ^ ~ & \` in free text

```javascript
function hl7Escape(str) {
    if (!str) return '';
    return String(str)
        .replace(/\\/g, '\\E\\').replace(/\|/g, '\\F\\').replace(/\^/g, '\\S\\')
        .replace(/~/g,  '\\R\\').replace(/&/g,  '\\T\\')
        .replace(/\r/g, '\\X0D\\').replace(/\n/g, '\\X0A\\');
}
```

## 18. Full Source Transformer (Bidirectional)

Choose based on pattern. The shared parts are omitted for brevity — they're identical to v3.0 §18 (machineConfig block, applyFunctions, router detection, OBX parser, EOS# derivation, payload builder).

### 18.1 Pattern A · Direct (unchanged from v3.0)

Mode A = HTTP GET to Laravel, build OML, responseMap.put. Mode B = build payload, channelMap.put('output', ...). Destination = HTTP POST.

→ Use the v3.0 §18 script verbatim.

### 18.2 Pattern B · Cached (v4 new)

```javascript
// ================================================================
//  PATTERN B · CACHED — SOURCE TRANSFORMER (Bidirectional)
//  Mode A: query local lis_worklists + local lock
//  Mode B: build payload, INSERT into local lis_results (in destination)
// ================================================================

var interface_mode = 'RESULT_UPLOAD';

try {
    var msgType    = msg['MSH']['MSH.9']['MSH.9.1'].toString() || '';
    var msgTrigger = msg['MSH']['MSH.9']['MSH.9.2'].toString() || '';
    logger.info('HL7 type: ' + msgType + '^' + msgTrigger);
    if (msgType == 'QRY' || msgTrigger == 'Q02' || msgTrigger == 'TSREQ') {
        interface_mode = 'WORKLIST_QUERY';
    }
} catch (e) { logger.error('Router error: ' + e); }
channelMap.put('interface_mode', interface_mode);

// ---------- Machine config (paste exported JSON; add device_model) ----------
var machineConfig = {
    "machine_id": "YOUR_MACHINE_ID",
    "device_model": "BH-5390",             // = machines.device_model (used as X-Machine-Key + local DB filter)
    "api_key": "YOUR_API_KEY",
    "directional": "bidirectional",
    "sample_type": "serum",
    "mapping": { /* from export */ },
    "components": [ /* from export */ ]
};

// ---------- Helper: apply functions (v3 fix — returns String) ----------
function applyFunctions(value, functions) {
    if (typeof value !== 'number') return String(value);
    var result = value;
    for each (var fn in (functions || [])) {
        switch (fn.name) {
            case 'convert_unit': result = result * (fn.params.factor || 1); break;
            case 'round':        result = result.toFixed(fn.params.decimal || 0); break;
            case 'format':
                if (fn.params.type === 'comma') {
                    var parts = result.toString().split('.');
                    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                    result = parts.length > 1 ? parts[0] + '.' + parts[1] : parts[0];
                }
                break;
        }
    }
    return String(result);
}

// ---------- DB connection helper (Pattern B) ----------
function getDb() {
    return DatabaseConnectionFactory.createDatabaseConnection(
        'org.postgresql.Driver',
        'jdbc:postgresql://localhost:5432/mirth_db',
        'mirth', '<MIRTH_DB_PASSWORD>'   // store in Mirth Configuration Map, not hardcoded
    );
}

// ================================================================
//  MODE A: WORKLIST QUERY (Pattern B — local DB)
// ================================================================
if (interface_mode === 'WORKLIST_QUERY') {
    try {
        var barcode = '';
        try { barcode = msg['QRD']['QRD.8']['QRD.8.1'].toString(); } catch(e) {}
        if (!barcode) { try { barcode = msg['SPM']['SPM.2']['SPM.2.1'].toString(); } catch(e) {} }
        logger.info('Worklist query barcode: ' + barcode);

        var db = getDb();

        // 1. Query local lis_worklists for THIS machine + barcode, status pending
        var rs = db.executeCachedQuery(
            "SELECT id, test_code, test_name, patient_id, patient_name, " +
            "       test_sample_item_id, priority " +
            "FROM lis_cache.lis_worklists " +
            "WHERE device_model = ? AND barcode = ? AND status = 'pending' " +
            "ORDER BY CASE priority WHEN 'high' THEN 1 WHEN 'routine' THEN 2 ELSE 3 END, created_at",
            [machineConfig.device_model, barcode]
        );

        var tests = [];
        var claimedIds = [];
        var claimedTsii = [];
        var patient_id = '', patient_name = '';

        while (rs.next()) {
            if (!patient_id) {
                patient_id   = rs.getString('patient_id') || '';
                patient_name = rs.getString('patient_name') || '';
            }
            tests.push({
                test_id:   rs.getString('test_code'),
                test_name: rs.getString('test_name') || ''
            });
            claimedIds.push(rs.getString('id'));
            var tsii = rs.getString('test_sample_item_id');
            if (tsii) claimedTsii.push(tsii);
        }
        rs.close();

        // 2. Local lock: claimed → processing; siblings → locked
        if (claimedIds.length > 0) {
            var nowTs = new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss").format(new java.util.Date());
            var idsIn = "'" + claimedIds.join("','") + "'";
            db.executeUpdate(
                "UPDATE lis_cache.lis_worklists SET status='processing', started_at=?, locked_by=? " +
                "WHERE id IN (" + idsIn + ")",
                [nowTs, machineConfig.device_model]
            );
            if (claimedTsii.length > 0) {
                var tsiiIn = "'" + claimedTsii.join("','") + "'";
                db.executeUpdate(
                    "UPDATE lis_cache.lis_worklists SET status='locked' " +
                    "WHERE test_sample_item_id IN (" + tsiiIn + ") " +
                    "AND device_model != ? AND status='pending'",
                    [machineConfig.device_model]
                );
            }
        }
        db.close();

        // 3. Build OML^O21 (same as Pattern A)
        var msgCtrlId = msg['MSH']['MSH.10'].toString() || '';
        var newCtrlId = 'OML' + DateUtil.getCurrentDate('yyyyMMddHHmmss');
        var msh = 'MSH|^~\\&|LIS|LAB|ANALYZER|MODEL|' +
                  DateUtil.getCurrentDate('yyyyMMddHHmmss') + '||OML^O21|' + newCtrlId + '|P|2.3.1';
        var msa = 'MSA|AA|' + msgCtrlId + '||Worklist sent successfully';
        var orc = 'ORC|NW|' + barcode + '||||CM';
        var obr = 'OBR|1||' + barcode + '|||F|||' + patient_id + '|' + patient_name;

        var obxLines = '';
        var testCounter = 0;
        for each (var t in tests) {
            testCounter++;
            obxLines += '\rOBX|' + testCounter + '|NM|' + t.test_id + '^' + t.test_name + '||||||F';
        }
        if (testCounter === 0) {
            obxLines = '\rOBX|1|NM|NO_TEST^No Test Ordered||||||F';
        }
        var omlResponse = msh + '\r' + msa + '\r' + orc + '\r' + obr + obxLines;
        logger.info('OML^O21 built with ' + testCounter + ' tests (Pattern B local query)');

        responseMap.put('Auto-generate (After source transformer)', omlResponse);
        channelMap.put('barcode', barcode);
        channelMap.put('device_model', machineConfig.device_model);

    } catch (e) {
        logger.error('Pattern B worklist query failed: ' + e);
        var mcid = msg['MSH']['MSH.10'].toString() || '';
        var errorReply = 'MSH|^~\\&|LIS|LAB|ANALYZER|MODEL|' +
            DateUtil.getCurrentDate('yyyyMMddHHmmss') +
            '||OML^O21|OML' + DateUtil.getCurrentDate('yyyyMMddHHmmss') + '|P|2.3.1\r' +
            'MSA|AE|' + mcid + '||Local DB error: ' + e.message;
        responseMap.put('Auto-generate (After source transformer)', errorReply);
    }
}

// ================================================================
//  MODE B: RESULT UPLOAD (builds payload for destination to INSERT)
// ================================================================
if (interface_mode === 'RESULT_UPLOAD') {
    try {
        var barcode = msg['OBR']['OBR.2']['OBR.2.1'].toString() ||
                      msg['OBR']['OBR.3']['OBR.3.1'].toString() || null;
        var date = msg['OBR']['OBR.7']['OBR.7.1'].toString() || null;
        var deviceModel = msg['MSH']['MSH.4']['MSH.4.1'].toString() || machineConfig.device_model;

        // Parse OBX
        var rawValues = {};
        if (msg['OBX']) {
            for each (var obx in msg['OBX']) {
                var code = obx['OBX.3']['OBX.3.2'].toString() || null;
                if (!code) continue;
                if (code.indexOf('.PNG') !== -1) continue;
                if (['MODE','MODE_EX','Ref','Note'].indexOf(code) !== -1) continue;
                var raw = obx['OBX.5']['OBX.5.1'].toString() || null;
                if (raw === null) continue;
                raw = raw.replace(/^\*/, '');
                var num = parseFloat(raw);
                if (!isNaN(num)) rawValues[code] = num;
            }
        }

        // EOS# derivation
        if (rawValues['EOS#'] === undefined &&
            rawValues['WBC'] !== undefined &&
            rawValues['EOS%'] !== undefined) {
            rawValues['EOS#'] = (rawValues['EOS%'] / 100) * rawValues['WBC'];
        }

        // Build results (value as String — v3 fix)
        var results = [];
        for each (var compDef in machineConfig.components) {
            var code = compDef.code;
            if (!code || rawValues[code] === undefined) continue;
            var formatted = applyFunctions(rawValues[code], compDef.functions);
            results.push({
                code: code, name: compDef.name,
                value: formatted, unit: compDef.unit || ''
            });
        }

        var payload = {
            machine_id: machineConfig.machine_id,
            api_key: machineConfig.api_key,
            barcode: barcode,
            device_model: deviceModel,
            date: date,
            mapping: machineConfig.mapping,
            results: results
        };

        channelMap.put('barcode', barcode);
        channelMap.put('machine_id', machineConfig.machine_id);
        channelMap.put('device_model', deviceModel);
        channelMap.put('date', date);
        channelMap.put('output', JSON.stringify(payload));
        channelMap.put('result_count', results.length);

        logger.info('Result upload: ' + results.length + ' results for barcode ' + barcode);

    } catch (error) {
        logger.error('Result transform error: ' + error.message);
    }
}
```

---

# PART V — Destination Script

## 19. Destination Logic

| Pattern | Destination does |
|---|---|
| A · Direct | HTTP POST to Laravel `/mirth/legacy-result`; read `body.type === 'success'` |
| B · Cached | INSERT into Mirth local DB `lis_cache.lis_results`; UPDATE `lis_worklists` to `completed`. The actual sync to Laravel happens in the separate `lis_sync_push` channel. |

Both skip `WORKLIST_QUERY` mode (reply was sent via `responseMap`).

## 20. Where the Payload Goes

### 20.1 Pattern A — HTTP POST

Apache Commons HttpClient → `POST https://admcr.advmedi.com/mirth/legacy-result`. Read `body.type` (Laravel returns HTTP 200 even on failure).

### 20.2 Pattern B — Local DB INSERT (v4 new)

```sql
INSERT INTO lis_cache.lis_results (
    id, worklist_id, machine_id, device_model, barcode, test_code,
    result_data, raw_message, status, synced_at, created_at
) VALUES (
    ?, ?, ?, ?, ?, ?, ?, ?, 'completed', NULL, NOW()
);

UPDATE lis_cache.lis_worklists
SET status='completed', completed_at=NOW(), result_id=?
WHERE id = ?;
```

`result_data` stores the JSON payload (same shape as Pattern A would POST). `raw_message` stores the original ORU HL7 for audit. The `lis_sync_push` channel polls `WHERE synced_at IS NULL` every 15 s and POSTs to Laravel.

## 21. Full Destination Code

### 21.A Pattern A — Direct HTTP POST (v3 unchanged)

```javascript
var interface_mode = channelMap.get('interface_mode') || 'RESULT_UPLOAD';

if (interface_mode === 'RESULT_UPLOAD') {
    try {
        var url = "https://admcr.advmedi.com/mirth/legacy-result";
        var client = new org.apache.commons.httpclient.HttpClient();
        var method = new org.apache.commons.httpclient.methods.PostMethod(url);

        var payload = channelMap.get('output');
        if (!payload) { logger.error("No output payload."); return; }

        var barcode = channelMap.get('barcode') || 'unknown';
        logger.info("Sending results for barcode: " + barcode);

        method.setRequestHeader("Content-Type", "application/json");
        method.setRequestHeader("Accept", "application/json");
        method.setRequestHeader("User-Agent", "AdvMedi-MirthConnector/1.0");

        var requestEntity = new org.apache.commons.httpclient.methods.StringRequestEntity(
            payload, "application/json", "UTF-8");
        method.setRequestEntity(requestEntity);

        client.getHttpConnectionManager().getParams().setConnectionTimeout(10000);
        client.getHttpConnectionManager().getParams().setSoTimeout(15000);

        try {
            var statusCode = client.executeMethod(method);
            var response = method.getResponseBodyAsString();

            // v3 fix: read body.type — Laravel returns HTTP 200 even on failure
            var type = '';
            try { type = JSON.parse(response).type; } catch (e) {}

            if (statusCode >= 200 && statusCode < 300 && type === 'success') {
                logger.info("Upload OK. Barcode: " + barcode);
            } else if (statusCode >= 500 || statusCode == 429) {
                logger.error("Upload TRANSIENT (will retry). Status: " + statusCode + " Barcode: " + barcode);
            } else {
                logger.error("Upload FAILED. Status: " + statusCode + " Type: " + type + " Barcode: " + barcode);
            }
        } catch (error) {
            logger.error("Request failed for " + barcode + ": " + error);
        } finally {
            method.releaseConnection();
        }
    } catch (error) {
        logger.error("Destination fatal error: " + error);
    }
} else {
    logger.info('WORKLIST_QUERY mode – reply sent via responseMap, no HTTP POST.');
}
```

### 21.B Pattern B — Local DB INSERT (v4 new)

```javascript
// ================================================================
//  PATTERN B · CACHED — DESTINATION (JavaScript Writer)
//  Mode A: skip (reply already sent via responseMap)
//  Mode B: INSERT into lis_cache.lis_results + UPDATE lis_worklists
// ================================================================

var interface_mode = channelMap.get('interface_mode') || 'RESULT_UPLOAD';

if (interface_mode === 'RESULT_UPLOAD') {
    var payload = channelMap.get('output');
    if (!payload) {
        logger.error("No output payload in channelMap.");
        return;
    }

    var barcode = channelMap.get('barcode') || 'unknown';
    var machineId = channelMap.get('machine_id');
    var deviceModel = channelMap.get('device_model');
    var resultCount = channelMap.get('result_count') || 0;

    logger.info("Storing " + resultCount + " results locally for barcode: " + barcode);

    var db = DatabaseConnectionFactory.createDatabaseConnection(
        'org.postgresql.Driver',
        'jdbc:postgresql://localhost:5432/mirth_db',
        'mirth', '<MIRTH_DB_PASSWORD>'
    );

    try {
        // 1. Find the matching local worklist row (claimed by this machine, status=processing)
        var rs = db.executeCachedQuery(
            "SELECT id, test_code, test_sample_item_id FROM lis_cache.lis_worklists " +
            "WHERE device_model = ? AND barcode = ? AND status IN ('processing','pending') " +
            "ORDER BY started_at DESC NULLS LAST LIMIT 1",
            [deviceModel, barcode]
        );

        var worklistLocalId = null, testCode = null, testSampleItemId = null;
        if (rs.next()) {
            worklistLocalId = rs.getString('id');
            testCode = rs.getString('test_code');
            testSampleItemId = rs.getString('test_sample_item_id');
        }
        rs.close();

        // 2. INSERT into lis_results
        var resultId = String(new java.util.UUID(Math.random()*1e16, Math.random()*1e16));
        var nowTs = new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss").format(new java.util.Date());

        db.executeUpdate(
            "INSERT INTO lis_cache.lis_results " +
            "(id, worklist_id, machine_id, device_model, barcode, test_code, " +
            " result_data, raw_message, status, retry_count, synced_at, created_at, updated_at) " +
            "VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 'completed', 0, NULL, ?, ?)",
            [resultId, worklistLocalId, machineId, deviceModel, barcode, testCode || '',
             payload, nowTs, nowTs]
        );

        // 3. UPDATE lis_worklists → completed, link result_id
        if (worklistLocalId) {
            db.executeUpdate(
                "UPDATE lis_cache.lis_worklists SET status='completed', completed_at=?, result_id=? " +
                "WHERE id=?",
                [nowTs, resultId, worklistLocalId]
            );

            // 4. Unlock sibling rows (same test_sample_item_id) so they can be cancelled/abandoned
            // Laravel's completeWorklists() will finalize the lock state during sync-push
            if (testSampleItemId) {
                db.executeUpdate(
                    "UPDATE lis_cache.lis_worklists SET status='locked' " +
                    "WHERE test_sample_item_id=? AND device_model != ? AND status='pending'",
                    [testSampleItemId, deviceModel]
                );
            }
        } else {
            logger.warn("No matching local worklist for barcode " + barcode +
                        " — result stored in lis_results without worklist link (unidirectional or sync lag)");
        }

        logger.info("Stored result " + resultId + " for barcode " + barcode +
                    " (worklist_local_id=" + worklistLocalId + "). Sync-push will deliver to Laravel.");

    } catch (e) {
        logger.error("Pattern B destination error for " + barcode + ": " + e);
    } finally {
        db.close();
    }
} else {
    logger.info('WORKLIST_QUERY mode – reply sent via responseMap, no DB write.');
}
```

### 21.1 What Happens in Laravel (Pattern A vs B)

| Step | Pattern A | Pattern B |
|---|---|---|
| Receive payload | Immediate (per-message) | Delayed (15 s by sync-push) |
| `legacyResult` validates | Per message | Per sync-push batch item |
| `TestReport::updateOrCreate` | Immediate | Delayed |
| `completeWorklists()` (v3 fix) | Immediate | Delayed — but local `lis_worklists` already shows `completed` |

Pattern B's lag is acceptable because:
- The analyzer has already received its ACK (immediate, from Mirth local)
- The next analyzer scan sees the local lock (immediate)
- Lab users querying Laravel see the result within 15 s

### 21.2 Idempotency

Pattern A: `updateOrCreate` overwrites same `TestReport` row; retries harmless.
Pattern B: `lis_results` row is unique per ORU. If sync-push POSTs the same row twice (network glitch, retry), Laravel's `updateOrCreate` makes it harmless. After Laravel returns `type: success`, sync-push sets `synced_at = now()` so it won't be re-sent.

---

# PART V-A — Sync Channels (Pattern B only)

## 21C. Channel: lis_sync_pull (Timer → DB Writer)

Pulls worklists from Laravel every 30 s and upserts into local `lis_cache.lis_worklists`. Also detects cancellations (rows in local DB no longer in Laravel's response).

### Source — Timer

| Field | Value |
|---|---|
| Connector | Timer (Polling) |
| Polling frequency | 30000 ms (30 s) |
| Initial state | Started |

### Transformer — JavaScript

For each active bidirectional machine, call `GET /api/mirth/worklist` and upsert rows.

```javascript
// ================================================================
//  CHANNEL: lis_sync_pull  (Timer → DB Writer)
//  Pulls worklists from Laravel → Mirth local lis_cache.lis_worklists
// ================================================================

var API_BASE = 'https://lab.htncr.org/api/mirth';
var USER_AGENT = 'AdvMedi-Mirth/1.0 sync-pull';

// Active bidirectional machines to poll (could be fetched from a config endpoint)
var MACHINE_KEYS = ['BH-5390', 'SNIBE-XXX', 'SYSMEX-XN1000'];

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
    'mirth', '<MIRTH_DB_PASSWORD>'
);

var totalUpserted = 0;
var seenIds = {};

for (var i = 0; i < MACHINE_KEYS.length; i++) {
    var key = MACHINE_KEYS[i];
    try {
        var r = httpGet(API_BASE + '/worklist', {
            'X-Machine-Key': key,
            'Accept': 'application/json',
            'User-Agent': USER_AGENT
        });
        if (r.code != 200) {
            logger.warn('sync-pull ' + key + ' HTTP ' + r.code);
            continue;
        }

        var j = JSON.parse(r.body);
        var worklists = j.worklist || [];
        var nowTs = new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss").format(new java.util.Date());

        for (var w = 0; w < worklists.length; w++) {
            var row = worklists[w];
            var laravelId = row.worklist_id;
            seenIds[laravelId] = true;

            db.executeUpdate(
                "INSERT INTO lis_cache.lis_worklists " +
                "(id, laravel_id, device_model, machine_id, barcode, receipt_barcode, " +
                " patient_id, patient_name, patient_age, patient_gender, " +
                " test_code, test_name, sample_type, tube_type, timing, priority, status, " +
                " order_date, collection_date, test_sample_item_id, test_sample_id, " +
                " lab_receipt_id, lab_test_id, test_id, suffix, sample_number, " +
                " source, laravel_status, last_synced_at, created_at, updated_at) " +
                "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'laravel', 'pending', ?, ?, ?) " +
                "ON CONFLICT (laravel_id) DO UPDATE SET " +
                "  status = CASE WHEN lis_cache.lis_worklists.status IN ('processing','completed') " +
                "                THEN lis_cache.lis_worklists.status ELSE 'pending' END, " +
                "  patient_name = EXCLUDED.patient_name, " +
                "  test_name = EXCLUDED.test_name, " +
                "  priority = EXCLUDED.priority, " +
                "  last_synced_at = EXCLUDED.last_synced_at, " +
                "  updated_at = EXCLUDED.updated_at",
                [
                    String(new java.util.UUID(Math.random()*1e16, Math.random()*1e16)),
                    laravelId,
                    key,
                    row.machine?.id || null,
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
                    'pending',
                    row.order_date || null,
                    row.collection_date || null,
                    row.test_sample_item_id || null,
                    row.test_sample_id || null,
                    row.lab_receipt_id || null,
                    row.lab_test_id || null,
                    row.test_id || null,
                    row.suffix || null,
                    row.sample_number || null,
                    nowTs, nowTs, nowTs
                ]
            );
            totalUpserted++;
        }
        logger.info('sync-pull ' + key + ': ' + worklists.length + ' worklists');
    } catch (e) {
        logger.error('sync-pull failed for ' + key + ': ' + e);
    }
}

// Cancel detection: local rows still 'pending' not seen in this pull → mark cancelled
// (Only for rows recently synced — older rows may belong to a different cycle)
try {
    var rs = db.executeCachedQuery(
        "SELECT laravel_id FROM lis_cache.lis_worklists " +
        "WHERE status = 'pending' AND last_synced_at < ?",
        [new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss")
            .format(new java.util.Date(System.currentTimeMillis() - 120000))]  // 2 min ago
    );
    var toCancel = [];
    while (rs.next()) {
        var lid = rs.getString('laravel_id');
        if (!seenIds[lid]) toCancel.push(lid);
    }
    rs.close();
    if (toCancel.length > 0) {
        var inList = "'" + toCancel.join("','") + "'";
        db.executeUpdate(
            "UPDATE lis_cache.lis_worklists SET status='cancelled', updated_at=? " +
            "WHERE laravel_id IN (" + inList + ") AND status='pending'",
            [new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss").format(new java.util.Date())]
        );
        logger.info('sync-pull cancelled ' + toCancel.length + ' stale local rows');
    }
} catch (e) {
    logger.error('sync-pull cancel detection failed: ' + e);
}

db.close();
logger.info('sync-pull done: ' + totalUpserted + ' upserted across ' + MACHINE_KEYS.length + ' machines');
```

### 21C.1 What `GET /api/mirth/worklist` returns

From your existing `MirthController::getWorklist` (line 221):

```json
{
  "success": true,
  "machine": { "id":"...","name":"Urit BH-5390","device_model":"BH-5390","direction":"bidirectional","protocol":"hl7" },
  "total": 12,
  "worklist": [
    {
      "worklist_id": "0194...",
      "order_id": "WL2611140001",
      "barcode": "2608281001-F",
      "receipt_barcode": "2608281001",
      "sample_number": 1, "suffix": "F",
      "tube_type": "EDTA Tube", "timing": "F",
      "sample_type": "Whole Blood",
      "test_code": "CBC", "test_name": "Complete Blood Count",
      "priority": "high", "status": "pending",
      "patient_id": "...", "patient_name": "JOHN DOE",
      "patient_age": 35, "patient_gender": "M",
      "order_date": "2026-11-14T10:00:00Z",
      "collection_date": "2026-11-14T10:05:00Z"
    }
  ]
}
```

Sets `pushed_at = now()` on each returned row in Laravel (prevents the next sync-pull cycle from re-fetching the same rows).

## 21D. Channel: lis_sync_push (DB Reader → HTTP POST)

Reads unsynced results from `lis_cache.lis_results` every 15 s and POSTs to Laravel.

### Source — Database Reader

| Field | Value |
|---|---|
| Connector | Database Reader |
| Query | `SELECT * FROM lis_cache.lis_results WHERE synced_at IS NULL AND retry_count < 5 ORDER BY created_at LIMIT 50` |
| Polling frequency | 15000 ms (15 s) |

### Transformer — JavaScript

Builds the payload (already stored as `result_data` JSON; just needs to be POSTed).

### Destination — HTTP Sender

`POST https://admcr.advmedi.com/mirth/legacy-result` with body = `result_data` column. On `body.type === 'success'`, run a SQL UPDATE in a JavaScript Writer destination to set `synced_at = now()`.

```javascript
// ================================================================
//  CHANNEL: lis_sync_push  (DB Reader → HTTP Sender + DB Writer)
//  Pushes unsynced results from lis_cache.lis_results → Laravel
// ================================================================

// Source query: SELECT * FROM lis_results WHERE synced_at IS NULL LIMIT 50

// Destination 1: HTTP Sender
// URL: https://admcr.advmedi.com/mirth/legacy-result
// Method: POST
// Headers: Content-Type: application/json, User-Agent: AdvMedi-Mirth/1.0 sync-push
// Body: ${result_data}  (the JSON column from the SELECT)
// Response: stored in responseMap as 'response' for Destination 2 to read

// Destination 2: JavaScript Writer — process response, update lis_results

var rowId = $('id');   // from the DB Reader source columns
var payload = $('result_data');
var response = responseMap.get('Response 1') || '';
var statusCode = $('responseStatus') || 0;

var db = DatabaseConnectionFactory.createDatabaseConnection(
    'org.postgresql.Driver',
    'jdbc:postgresql://localhost:5432/mirth_db',
    'mirth', '<MIRTH_DB_PASSWORD>'
);

try {
    var type = '';
    try { type = JSON.parse(response).type; } catch (e) {}

    var nowTs = new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss").format(new java.util.Date());

    if (statusCode >= 200 && statusCode < 300 && type === 'success') {
        // Mark synced
        db.executeUpdate(
            "UPDATE lis_cache.lis_results SET synced_at=?, sync_response=?, updated_at=? WHERE id=?",
            [nowTs, response, nowTs, rowId]
        );
        logger.info('sync-push OK: ' + rowId);
    } else if (statusCode >= 500 || statusCode == 429) {
        // Transient — increment retry_count, will be retried next cycle
        db.executeUpdate(
            "UPDATE lis_cache.lis_results SET retry_count=retry_count+1, " +
            "sync_response=?, updated_at=? WHERE id=?",
            [response, nowTs, rowId]
        );
        logger.warn('sync-push TRANSIENT (will retry): ' + rowId + ' HTTP ' + statusCode);
    } else {
        // Data problem — won't fix by retrying. Mark synced to stop retry, but flag error.
        db.executeUpdate(
            "UPDATE lis_cache.lis_results SET synced_at=?, sync_response=?, sync_error=?, " +
            "retry_count=retry_count+1, updated_at=? WHERE id=?",
            [nowTs, response, 'type=' + type + ' HTTP=' + statusCode, nowTs, rowId]
        );
        logger.error('sync-push FAILED (data issue): ' + rowId + ' type=' + type + ' body=' + response);
    }
} catch (e) {
    logger.error('sync-push destination error: ' + e);
} finally {
    db.close();
}
```

### 21D.1 Retry behaviour

| Failure type | retry_count behaviour | Will be re-polled? |
|---|---|---|
| Network timeout / connection refused | +1, no `synced_at` set | Yes, next cycle |
| HTTP 5xx, 429 | +1, no `synced_at` set | Yes, next cycle |
| HTTP 4xx (except 429) | +1, `synced_at = now()` (stop retrying) | No — requires manual fix |
| `body.type = 'fail'` or `'invalid'` | +1, `synced_at = now()`, `sync_error` set | No — requires manual fix |
| `body.type = 'success'` | `synced_at = now()` | No — done |

After 5 transient retries (configurable), the row stays unsynced but the sync-push channel skips it (`retry_count < 5` in source query). Operator must inspect and either delete, fix, or manually mark synced.

### 21D.2 Failure recovery flow

```
Analyzer sends ORU
  ↓
Mirth INSERTs into lis_results (synced_at=NULL)
  ↓
Analyzer receives ACK immediately (no Laravel wait)
  ↓
sync-push polls every 15 s
  ↓
POST /mirth/legacy-result
  ↓
  ├─ type=success → synced_at=now(), done
  ├─ type=fail / invalid → synced_at=now(), sync_error set (operator inspects)
  ├─ HTTP 5xx / network → retry next cycle (up to 5 attempts)
  └─ Laravel down → retries forever until Laravel back, then drains queue
```

---

# PART VI — Unidirectional Template

## 22. When to Use Unidirectional

Use when the analyzer: has no worklist query function, has its own internal sample-order entry, or sends only final results.

Laravel `machines.direction = 'unidirectional'` (or NULL). `Machine::canReceiveResults()` returns `true` **only for bidirectional** — so `POST /api/mirth/results` rejects unidirectional with 403. Unidirectional results go through `/mirth/legacy-result` which does not check direction.

### Pattern B note for unidirectional

- **Sync-pull** does NOT pull worklists for unidirectional machines (`canPullWorklist()` returns false → Laravel `/api/mirth/worklist` returns 403)
- **Mode A** never fires (no QRY arrives from a unidirectional analyzer)
- **Mode B** writes to `lis_results` (no `worklist_id` link — set to NULL)
- **Sync-push** POSTs as usual; Laravel's `legacyResult` finds the LabReceipt by barcode only

## 23. Unidirectional Source Transformer

Same as bidirectional Mode B block, without the worklist query branch. Use `"directional": "unidirectional"`. Pattern A or B both apply to Mode B (OBX parse + payload build).

```javascript
// ================================================================
//  UNIDIRECTIONAL TRANSFORMER — Result upload only
//  (Pattern A: builds payload for HTTP POST)
//  (Pattern B: builds payload for local DB INSERT)
// ================================================================

try {
    var machineConfig = {
        "machine_id": "YOUR_MACHINE_ID",
        "device_model": "URIT-BH-5390",
        "api_key": "YOUR_API_KEY",
        "directional": "unidirectional",
        "sample_type": "serum",
        "mapping": { /* from export */ },
        "components": [ /* from export */ ]
    };

    function applyFunctions(value, functions) {
        if (typeof value !== 'number') return String(value);
        var result = value;
        for each (var fn in (functions || [])) {
            switch (fn.name) {
                case 'convert_unit': result = result * (fn.params.factor || 1); break;
                case 'round':        result = result.toFixed(fn.params.decimal || 0); break;
                case 'format':
                    if (fn.params.type === 'comma') {
                        var parts = result.toString().split('.');
                        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                        result = parts.length > 1 ? parts[0] + '.' + parts[1] : parts[0];
                    }
                    break;
            }
        }
        return String(result);
    }

    var barcode = msg['OBR']['OBR.2']['OBR.2.1'].toString() ||
                  msg['OBR']['OBR.3']['OBR.3.1'].toString() || null;
    var date = msg['OBR']['OBR.7']['OBR.7.1'].toString() || null;
    var deviceModel = msg['MSH']['MSH.4']['MSH.4.1'].toString() || 'ANALYZER';

    var rawValues = {};
    if (msg['OBX']) {
        for each (var obx in msg['OBX']) {
            var code = obx['OBX.3']['OBX.3.2'].toString() || null;
            if (!code) continue;
            if (code.indexOf('.PNG') !== -1) continue;
            if (['MODE','MODE_EX','Ref','Note'].indexOf(code) !== -1) continue;
            var raw = obx['OBX.5']['OBX.5.1'].toString() || null;
            if (raw === null) continue;
            raw = raw.replace(/^\*/, '');
            var num = parseFloat(raw);
            if (!isNaN(num)) rawValues[code] = num;
        }
    }

    var results = [];
    for each (var compDef in machineConfig.components) {
        var code = compDef.code;
        if (!code || rawValues[code] === undefined) continue;
        var formatted = applyFunctions(rawValues[code], compDef.functions);
        results.push({
            code: code, name: compDef.name,
            value: formatted, unit: compDef.unit || ''
        });
    }

    var payload = {
        machine_id: machineConfig.machine_id,
        api_key: machineConfig.api_key,
        barcode: barcode,
        device_model: deviceModel,
        date: date,
        mapping: machineConfig.mapping,
        results: results
    };

    channelMap.put('barcode', barcode);
    channelMap.put('machine_id', machineConfig.machine_id);
    channelMap.put('device_model', deviceModel);
    channelMap.put('date', date);
    channelMap.put('output', JSON.stringify(payload));
    channelMap.put('result_count', results.length);

    logger.info('Unidirectional: ' + results.length + ' results for barcode ' + barcode);

} catch (error) {
    logger.error('Unidirectional transform error: ' + error.message);
}
```

## 24. Unidirectional Destination

### 24.1 Pattern A — HTTP POST (same as §21A)

### 24.2 Pattern B — Local DB INSERT (worklist_id will be NULL)

Same as §21.B, but the lookup query will return no worklist (because sync-pull didn't create one for unidirectional machines). The result still gets inserted into `lis_results` with `worklist_id=NULL`, and sync-push will deliver it to Laravel.

## 25. Side-by-Side Comparison

| Feature | Bidirectional | Unidirectional |
|---|---|---|
| Handles QRY/SPM | Yes | No |
| Handles ORU | Yes | Yes |
| Replies with OML^O21 | Yes (via responseMap) | No |
| Pattern A: posts to `/mirth/legacy-result` | Yes | Yes |
| Pattern B: INSERTs to `lis_results` | Yes (with worklist_id link) | Yes (worklist_id=NULL) |
| Laravel `machines.direction` | `'bidirectional'` | `'unidirectional'` |
| `Machine::canPullWorklist()` | true | false (or `auto_push_enabled`) |
| `Machine::canReceiveResults()` | true | **false** |
| Pattern B sync-pull pulls worklists for this machine? | Yes | No (Laravel 403) |
| Pattern B sync-push pushes results? | Yes | Yes |
| Typical use | Urit BH-5390, Sysmex XN, Mindray BC | Beckman AU (legacy), Roche Cobas (legacy) |

---

# PART VII — Laravel Backend

## 26. Endpoints Reference

### 26.1 Mirth-facing endpoints (`MirthController`)

| # | Method | Route | Auth | Purpose |
|---|---|---|---|---|
| 1 | POST | `/mirth/legacy-result` | `api_key` body (advisory) | Multi-component result — writes TestReport + completes worklists (v3 fix); **HTTP 200 even on failure** |
| 2 | GET | `/api/mirth/worklist` | `X-Machine-Key` header | **Bulk pull** — Pattern B sync-pull calls this; sets `pushed_at` on fetch |
| 3 | POST | `/api/mirth/acknowledge` | body | Mark `processing` (controller doesn't lock siblings) |
| 4 | POST | `/api/mirth/results` | `X-Machine-Key` | Single result, SHA-256 idempotent (bidirectional only) |
| 5 | POST | `/api/mirth/submit-batch` | `X-Machine-Key` | Batch results |
| 6 | GET | `/api/mirth/health` | optional `X-Machine-Key` | Health + counts |
| 7 | GET | `/api/mirth/failed-results` | `X-Machine-Key` | DLQ poll |
| 8 | POST | `/api/mirth/retry-failed/{id}` | `X-Machine-Key` | `retry_count + 1`, `status='pending'` |

### 26.2 Worklist endpoints (`WorklistController`)

| # | Method | Route | Auth | Purpose |
|---|---|---|---|---|
| 9 | GET | `/api/get-worklist?barcode=X&api=KEY` | `api` query string | Single-tube worklist (Pattern A Mode A) — **verify this route exists in `routes/api.php`** |

### 26.3 Machine config endpoints (`MachineController`)

| # | Method | Route | Auth | Purpose |
|---|---|---|---|---|
| 10 | GET | `/lab/machines/{id}/export-config` | web session (admin) | Download `machineConfig` JSON |

> ⚠️ The `WorklistController` also has a parallel API set duplicating `MirthController` with different params. Use `MirthController` only and leave the duplicates unrouted.

## 27. GET /api/get-worklist (Pattern A only)

Used by Pattern A Mode A when analyzer sends QRY^Q02. Pattern B does NOT use this endpoint (Pattern B queries the local `lis_cache.lis_worklists` instead).

### Request

```
GET /api/get-worklist?barcode=2608281001-F&api=66ffe8a2-b1b0-800a-802b-ec397f1bcec8
```

### Response (200)

```json
{
  "patient_id": "P0001",
  "patient_name": "JOHN DOE",
  "tests": [{ "test_id": "CBC", "test_name": "Complete Blood Count" }]
}
```

> ⚠️ The routes file is not in the uploaded zip. If this route is not in `routes/api.php`, add it (see §34.2). If your Mirth template currently works against this URL, the route is already there.

## 28. POST /mirth/legacy-result (both patterns)

Used by Pattern A destination (immediate) and Pattern B sync-push channel (every 15 s).

### Request

```json
{
  "machine_id": "0194-...",
  "api_key": "abc123...",
  "barcode": "2608281001-F",
  "device_model": "BH-5390",
  "date": "20261114103500",
  "mapping": { "WBC": ["<TestComponent.id>"] },
  "results": [
    { "code": "WBC", "name": "White Blood Cell Count", "value": "6.2", "unit": "10^9/L" }
  ]
}
```

> **v3 fix:** `value` must be a **string** (`"6.2"` not `6.2`). Laravel validates `results.*.value => 'required|string'`.

### Response (always HTTP 200 — verdict in body)

```json
{ "type": "success", "note": "Results processed successfully", "status": 200 }
```

### Internal flow

1. Validate body → `invalid` if bad
2. Normalize mapping (nested or flat; string → [string])
3. Parse date (YmdHis or any Carbon string)
4. INSERT `legacy_result_temp` (raw audit)
5. Find LabReceipt: `test_samples.barcode` → else `lab_receipts.barcode` → else `fail`
6. DB::transaction — for each result: `mapping[code]` → `TestComponent.id` → `TestReport::updateOrCreate(...)`
7. **v3 fix:** `completeWorklists(machine_id, barcode, touched test_ids)` — bidirectional only
8. `legacy_result_temp.processed = true`
9. Return `{type:'success', note:'…', status:200}`

## 29. POST /api/mirth/results

Newer, idempotent endpoint. **Does not write `TestReport`** — only `lis_results` and updates `Worklist.status`. Not used by either Pattern A or B in this guide (use `/mirth/legacy-result` for multi-component).

## 30. POST /api/mirth/acknowledge

Marks a worklist as `processing`. Body: `{worklist_id, barcode}`. Response: `{success:true}`.

**Side effects:**
- `worklists.status = 'processing'`, `started_at = now()`
- `TestSampleItem.status` and `LabTest.lab_status` set to `processing`
- **Sibling worklists on other machines are NOT locked here.** Locking happens only in `WorklistService::acknowledgeWorklist()` → `Worklist::markAsProcessing()`
- Does not read `X-Machine-Key` (body fields only)

> Pattern B does NOT call this endpoint per-message. The local DB lock (§14.2) is sufficient; Laravel learns about the lock state via `completeWorklists()` during sync-push.

## 31. GET /api/mirth/health

```
GET /api/mirth/health
X-Machine-Key: BH-5390    (optional)
```

Response: `{status:'healthy', timestamp, version, machine:{...}, pending_worklists:N, processing_worklists:N, completed_today:N}`.

## 32. Failed Results & Retry

`GET /api/mirth/failed-results` returns up to 50 rows where `retry_count < max_retries` and `status = 'pending'`.

`POST /api/mirth/retry-failed/{id}` increments `retry_count` and sets `status = 'pending'`.

> ⚠️ **No background worker in the uploaded code.** You must add one or reprocess manually. `max_retries = 5`.

> Pattern B has its OWN retry queue in `lis_cache.lis_results` (rows with `synced_at IS NULL AND retry_count < 5`). Use that for Pattern B's retry needs; only use Laravel's DLQ for Pattern A's per-message failures.

## 33. Machine Config Export Endpoint

`GET /lab/machines/0194.../export-config` (web session auth). Returns JSON of `machineConfig`.

> ⚠️ **v3 fix (one-line):** the uploaded `exportMachineConfig` reads `$machine->directional` — but the column is `direction`. Fix:
>
> ```php
> - 'directional' => $machine->directional ?? 'unidirectional',
> + 'directional' => $machine->direction   ?? 'unidirectional',
> ```
>
> Until the fix is live, set `"directional": "bidirectional"` by hand in the pasted `machineConfig` JSON.

## 34. Sample Laravel Controller Snippets

### 34.1 Auth — add a real `api_key` check

```php
$machine = Machine::find($input['machine_id'] ?? null);
abort_unless(
    $machine && hash_equals((string) $machine->api_key, (string) ($input['api_key'] ?? '')),
    401
);
```

### 34.2 Register the /api/get-worklist route (if missing — Pattern A only)

```php
// routes/api.php
Route::get('/get-worklist', [WorklistController::class, 'getWorklistByBarcodeApi']);
```

```php
public function getWorklistByBarcodeApi(Request $request)
{
    $request->validate(['barcode' => 'required|string']);
    $barcode = $request->query('barcode');
    $worklists = Worklist::with(['labReceipt.patient', 'test'])
        ->where('barcode', $barcode)->whereIn('status', ['pending', 'processing'])
        ->orderBy('priority', 'desc')->get();

    if ($worklists->isEmpty()) {
        return response()->json(['error' => 'No worklist found for barcode ' . $barcode], 404);
    }
    $first = $worklists->first();
    $patient = $first->labReceipt?->patient;
    return response()->json([
        'patient_id'   => $patient?->id,
        'patient_name' => trim(($patient?->name ?? '') . ' ' . ($patient?->last_name ?? '')),
        'tests' => $worklists->map(fn($w) => [
            'test_id' => $w->test_code, 'test_name' => $w->test_name,
        ])->values(),
    ]);
}
```

### 34.3 legacyResult — close the worklist loop (v3 fix)

The uploaded `legacyResult` writes `TestReport` but **never marks the worklist as completed**. Add:

```php
// C1. Before DB::transaction(...)
$touchedTestIds = [];

// C2. Closure header passes it by reference
DB::transaction(function () use ($input, $normalizedMapping, $labReceipt, $labTestIds, &$errorLog, &$touchedTestIds) {

// C3. After TestReport::updateOrCreate(...) inside the foreach
$touchedTestIds[$component->test_id] = true;

// C4. After $temp->update(['processed' => true, 'error_log' => $errorLog]);
$this->completeWorklists($input['machine_id'], $input['barcode'], array_keys($touchedTestIds), $parsedDate);

// C5. New method
private function completeWorklists(string $machineId, string $barcode, array $testIds, $date): void
{
    if (! $testIds) return;
    $machine = Machine::find($machineId);
    if (! $machine || ! $machine->isBidirectional()) return;

    $svc = app(WorklistService::class);
    Worklist::where('machine_id', $machine->id)
        ->where(fn ($q) => $q->where('barcode', $barcode)->orWhere('receipt_barcode', $barcode))
        ->whereIn('test_id', $testIds)
        ->whereIn('status', [Worklist::STATUS_PENDING, Worklist::STATUS_PROCESSING])
        ->get()
        ->each(fn ($w) => $svc->submitResult($w->id, [
            'source'      => 'mirth',
            'resulted_at' => $date?->toDateTimeString(),
        ]));
}
```

### 34.4 exportMachineConfig fix (v3 one-liner)

```php
- 'directional' => $machine->directional ?? 'unidirectional',
+ 'directional' => $machine->direction   ?? 'unidirectional',
```

---

# PART VIII — DB Schema (Laravel)

## 35. ER Diagram

```
┌──────────────┐       ┌────────────────────────┐       ┌──────────────┐
│ lab_receipts │──┐───<│ lab_tests               │<──────│ tests        │
│  (barcode,   │  │    │  (lab_id, test_id,      │       │ (code, name) │
│   patient_id)│  │    │   lab_status)          │       └──────┬───────┘
└──────┬───────┘  │    └────────────────────────┘              │
       │ 1        │ 1          │ 1                                │ 1
       │ N        │ N          │ N                                │ N
       ▼          ▼            ▼                                  ▼
┌──────────────┐       ┌────────────────────────┐       ┌──────────────┐
│ test_samples │──┐───<│ test_sample_items      │>─────│ test_components│
│  (barcode,   │  │    │  (test_sample_id,      │       │ (test_id,    │
│   tube_type, │  │    │   lab_test_id,         │       │  unit, ref)  │
│   timing)    │  │    │   sample_number,       │       └──────────────┘
└──────┬───────┘  │    │   suffix)              │              ▲
       ▼          │    └────────────────────────┘              │
┌──────────────┐       ┌────────────────────────┐       ┌──────────────┐
│ worklists    │       │ machines                │       │ test_reports │
│  (machine_id,│>──────│  (device_model,         │       │ (model_id,  │
│   barcode,   │       │   api_key, direction,  │       │  model_type,│
│   test_code, │       │   port, protocol)      │       │  component_id,│
│   status)    │       └────────────────────────┘       │  result)    │
└──────┬───────┘                  ▲                       └─────────────┘
       │                           │                              ▲
       ▼                           │                              │
┌──────────────────┐     ┌──────────────────────────┐      ┌────────┴──────┐
│ machine_test_    │     │ lis_results                │     │ failed_lis_   │
│  mappings        │     │  (worklist_id, machine_id,│     │  results      │
│  (machine_id,    │     │   barcode, test_code,    │     │  (retry_count,│
│   test_id,       │     │   message_hash)          │     │   max_retries,│
│   parameters)    │     └──────────────────────────┘     │   status)    │
│  ← machineConfig │                                       └───────────────┘
└──────────────────┘
```

## 36. machines table

```sql
CREATE TABLE machines (
  id                  CHAR(36)     NOT NULL PRIMARY KEY,
  name                VARCHAR(255) NOT NULL UNIQUE,
  display_name        VARCHAR(255) NULL,
  device_model        VARCHAR(100) NOT NULL,
  ip_address          VARCHAR(45)  NULL,
  port                INT          NULL CHECK (port BETWEEN 1 AND 65535),
  protocol            VARCHAR(20)  NOT NULL DEFAULT 'hl7',
  protocol_version    VARCHAR(20)  NULL,
  auto_push_enabled   TINYINT(1)   NOT NULL DEFAULT 0,
  auto_push_direction VARCHAR(20)  NULL,
  direction           VARCHAR(20)  NULL,
  api_key             CHAR(32)     NOT NULL,
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  priority            INT          NOT NULL DEFAULT 80,
  settings            JSON         NULL,
  last_received_at    DATETIME     NULL,
  created_at          DATETIME     NOT NULL,
  updated_at          DATETIME     NOT NULL,

  UNIQUE INDEX uniq_machines_device_model (device_model),
  INDEX idx_machines_api_key (api_key),
  INDEX idx_machines_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 37. machine_test_mappings table

```sql
CREATE TABLE machine_test_mappings (
  id                  CHAR(36)     NOT NULL PRIMARY KEY,
  machine_id          CHAR(36)     NOT NULL,
  test_id             CHAR(36)     NOT NULL,
  test_code           VARCHAR(50)  NOT NULL,
  is_multi_component   TINYINT(1)   NOT NULL DEFAULT 1,
  sample_type         VARCHAR(50)  NOT NULL DEFAULT 'Serum',
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  priority            INT          NOT NULL DEFAULT 50,
  parameters          JSON         NULL,                    -- ← machineConfig payload
  created_at          DATETIME     NOT NULL,
  updated_at          DATETIME     NOT NULL,

  UNIQUE KEY uniq_machine_test (machine_id, test_id),
  INDEX idx_mapping_machine (machine_id, is_active),
  INDEX idx_mapping_test (test_id),
  CONSTRAINT fk_mapping_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
  CONSTRAINT fk_mapping_test FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 38. worklists table

```sql
CREATE TABLE worklists (
  id                       CHAR(36)     NOT NULL PRIMARY KEY,
  order_id                 VARCHAR(50)  NOT NULL UNIQUE,
  test_sample_item_id      CHAR(36)     NULL,
  lab_receipt_id           CHAR(36)     NULL,
  lab_test_id              CHAR(36)     NULL,
  test_id                  CHAR(36)     NOT NULL,
  machine_id               CHAR(36)     NOT NULL,
  patient_id               CHAR(36)     NULL,
  barcode                  VARCHAR(100) NOT NULL,
  receipt_barcode          VARCHAR(50)  NULL,
  patient_name             VARCHAR(255) NULL,
  patient_age              INT          NULL,
  patient_gender           VARCHAR(10)  NULL,
  test_code                VARCHAR(50)  NOT NULL,
  test_name                VARCHAR(255) NULL,
  sample_type              VARCHAR(50)  NULL,
  tube_type                VARCHAR(100) NULL,
  timing                   VARCHAR(20)  NULL,
  priority                 VARCHAR(20)  NOT NULL DEFAULT 'routine',
  status                   VARCHAR(20)  NOT NULL DEFAULT 'pending',
  order_date               DATETIME     NULL,
  collection_date          DATETIME     NULL,
  started_at               DATETIME     NULL,
  completed_at             DATETIME     NULL,
  pushed_at                DATETIME     NULL,
  parameters               JSON         NULL,
  result_data              JSON         NULL,
  reassignment_history     JSON         NULL,
  suffix                   VARCHAR(10)  NULL,
  test_sample_id           CHAR(36)     NULL,
  sample_number            INT          NULL,
  created_at               DATETIME     NOT NULL,
  updated_at               DATETIME     NOT NULL,

  INDEX idx_worklist_machine_status (machine_id, status, pushed_at),
  INDEX idx_worklist_barcode (barcode),
  INDEX idx_worklist_test_sample_item (test_sample_item_id),
  CONSTRAINT fk_worklist_machine FOREIGN KEY (machine_id) REFERENCES machines(id),
  CONSTRAINT fk_worklist_test FOREIGN KEY (test_id) REFERENCES tests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 39. lab_receipts / lab_tests / test_samples / test_sample_items

```sql
CREATE TABLE lab_receipts (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  uuid         CHAR(36)     NOT NULL UNIQUE,
  tenant_id    CHAR(36)     NULL,
  barcode      VARCHAR(50)  NOT NULL UNIQUE,
  sl_no        VARCHAR(50)  NULL,
  date         DATETIME     NULL,
  patient_id   CHAR(36)     NULL,
  referral_id  CHAR(36)     NULL,
  from_lab_id  CHAR(36)     NULL,
  to_lab_id    CHAR(36)     NULL,
  visit_id     CHAR(36)     NULL,
  subtotal     DECIMAL(12,2) NULL,
  discount     DECIMAL(12,2) NULL,
  total        DECIMAL(12,2) NULL,
  paid         DECIMAL(12,2) NULL,
  due          DECIMAL(12,2) NULL,
  done         TINYINT(1)   NOT NULL DEFAULT 0,
  note         TEXT         NULL,
  created_by   CHAR(36)     NULL,
  updated_by   CHAR(36)     NULL,
  created_at   DATETIME     NOT NULL,
  updated_at   DATETIME     NOT NULL,
  deleted_at   DATETIME     NULL,
  INDEX idx_receipt_patient (patient_id),
  INDEX idx_receipt_barcode (barcode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
CREATE TABLE lab_tests (
  id                       CHAR(36)     NOT NULL PRIMARY KEY,
  uuid                     CHAR(36)     NOT NULL UNIQUE,
  tenant_id                CHAR(36)     NULL,
  lab_id                   CHAR(36)     NOT NULL,
  test_id                  CHAR(36)     NOT NULL,
  patient_id               CHAR(36)     NULL,
  has_entered              TINYINT(1)   NOT NULL DEFAULT 0,
  has_result               TINYINT(1)   NOT NULL DEFAULT 0,
  done                     TINYINT(1)   NOT NULL DEFAULT 0,
  machine_id               CHAR(36)     NULL,
  machine_code             VARCHAR(50)  NULL,
  machine_assigned_at      DATETIME     NULL,
  machine_sent_at          DATETIME     NULL,
  machine_result_at        DATETIME     NULL,
  machine_status           VARCHAR(50)  NULL,
  middleware_message_id    VARCHAR(100) NULL,
  middleware_status        VARCHAR(50)  NULL,
  middleware_retry_count   INT          NOT NULL DEFAULT 0,
  lab_status               VARCHAR(50)  NOT NULL DEFAULT 'ordered',
  is_retest                TINYINT(1)   NOT NULL DEFAULT 0,
  retest_of                CHAR(36)     NULL,
  retest_reason            VARCHAR(255) NULL,
  cancelled_at             DATETIME     NULL,
  priority                 INT          NOT NULL DEFAULT 50,
  requested_at             DATETIME     NULL,
  started_at               DATETIME     NULL,
  completed_at             DATETIME     NULL,
  verified_at              DATETIME     NULL,
  cancelled_by             CHAR(36)     NULL,
  cancellation_reason      VARCHAR(255) NULL,
  verified_by              CHAR(36)     NULL,
  metadata                 JSON         NULL,
  status                   VARCHAR(50)  NOT NULL DEFAULT 'active',
  processing_started_at    DATETIME     NULL,
  sent_at                  DATETIME     NULL,
  created_at               DATETIME     NOT NULL,
  updated_at               DATETIME     NOT NULL,
  INDEX idx_labtests_lab (lab_id),
  INDEX idx_labtests_test (test_id),
  INDEX idx_labtests_machine (machine_id),
  INDEX idx_labtests_status (lab_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
CREATE TABLE test_samples (
  id                          CHAR(36)     NOT NULL PRIMARY KEY,
  barcode                     VARCHAR(100) NOT NULL UNIQUE,
  sl_no                       VARCHAR(50)  NULL,
  tenant_id                   CHAR(36)     NULL,
  lab_receipt_id              CHAR(36)     NOT NULL,
  patient_id                  CHAR(36)     NULL,
  tube_type                   VARCHAR(100) NULL,
  product_id                  CHAR(36)     NULL,
  timing                      VARCHAR(20)  NULL,
  tube_sequence               INT          NULL,
  barcode_source              VARCHAR(20)  NULL,
  shared_from_tenant_barcode  VARCHAR(100) NULL,
  collected_by                CHAR(36)     NULL,
  collected_at                DATETIME     NULL,
  note                        TEXT         NULL,
  created_at                  DATETIME     NOT NULL,
  updated_at                  DATETIME     NOT NULL,
  INDEX idx_sample_receipt (lab_receipt_id),
  INDEX idx_sample_barcode (barcode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
CREATE TABLE test_sample_items (
  id                CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id         CHAR(36)     NULL,
  test_sample_id    CHAR(36)     NOT NULL,
  lab_test_id       CHAR(36)     NOT NULL,
  test_id           CHAR(36)     NOT NULL,
  collected_by      CHAR(36)     NULL,
  collected_at      DATETIME     NULL,
  notes             TEXT         NULL,
  suffix            VARCHAR(10)  NULL,
  sample_number     INT          NULL,
  status            VARCHAR(20)  NOT NULL DEFAULT 'pending',
  created_at        DATETIME     NOT NULL,
  updated_at        DATETIME     NOT NULL,
  INDEX idx_sample_item_sample (test_sample_id),
  INDEX idx_sample_item_labtest (lab_test_id),
  CONSTRAINT fk_sample_item_sample FOREIGN KEY (test_sample_id) REFERENCES test_samples(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 40. test_components / test_reports

```sql
CREATE TABLE test_components (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id       CHAR(36)     NULL,
  test_id         CHAR(36)     NOT NULL,
  name            VARCHAR(255) NOT NULL,
  short_name      VARCHAR(50)  NULL,
  type            VARCHAR(50)  NULL,
  `option`        JSON         NULL,
  option_value    VARCHAR(255) NULL,
  default_value   VARCHAR(255) NULL,
  mic_value       VARCHAR(100) NULL,
  zone_value      VARCHAR(100) NULL,
  unit            VARCHAR(50)  NULL,
  convert_unit    VARCHAR(50)  NULL,
  factor          DECIMAL(12,4) NULL,
  `order`         INT          NOT NULL DEFAULT 0,
  status          VARCHAR(20)  NOT NULL DEFAULT 'active',
  hide            TINYINT(1)   NOT NULL DEFAULT 0,
  mode            VARCHAR(20)  NULL,
  align           VARCHAR(20)  NULL,
  class           VARCHAR(100) NULL,
  used            TINYINT(1)   NOT NULL DEFAULT 0,
  style           VARCHAR(255) NULL,
  pattern         VARCHAR(100) NULL,
  reference_text  TEXT         NULL,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NOT NULL,
  INDEX idx_component_test (test_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
CREATE TABLE test_reports (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  model_id        CHAR(36)     NOT NULL,
  model_type      VARCHAR(255) NOT NULL,
  patient_id      CHAR(36)     NULL,
  test_id         CHAR(36)     NULL,
  component_id    CHAR(36)     NOT NULL,
  date            DATETIME     NULL,
  type            VARCHAR(50)  NULL,
  result          TEXT         NULL,
  result_text     TEXT         NULL,
  reference_text  TEXT         NULL,
  mic_value       VARCHAR(100) NULL,
  zone_value      VARCHAR(100) NULL,
  document        VARCHAR(255) NULL,
  unit            VARCHAR(50)  NULL,
  unit_id         CHAR(36)     NULL,
  status          VARCHAR(50)  NOT NULL DEFAULT 'final',
  `order`         INT          NOT NULL DEFAULT 0,
  color           VARCHAR(20)  NULL,
  hide            TINYINT(1)   NOT NULL DEFAULT 0,
  share           TINYINT(1)   NOT NULL DEFAULT 0,
  created_by      CHAR(36)     NULL,
  updated_by      CHAR(36)     NULL,
  deleted_by      CHAR(36)     NULL,
  verified_at     DATETIME     NULL,
  verified_by     CHAR(36)     NULL,
  tenant_id       CHAR(36)     NULL,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NOT NULL,
  UNIQUE KEY uniq_testreport (model_id, model_type, component_id),
  INDEX idx_report_component (component_id),
  INDEX idx_report_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 41. lis_results / failed_lis_results / legacy_result_temp

```sql
CREATE TABLE lis_results (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  worklist_id     CHAR(36)     NULL,
  machine_id      CHAR(36)     NOT NULL,
  barcode         VARCHAR(100) NOT NULL,
  test_code       VARCHAR(50)  NOT NULL,
  result_value    TEXT         NOT NULL,
  result_numeric  DECIMAL(15,4) NULL,
  unit            VARCHAR(50)  NULL,
  reference_range VARCHAR(100) NULL,
  abnormal_flag   VARCHAR(10)  NULL,
  raw_message     MEDIUMTEXT   NULL,
  message_hash    CHAR(64)     NOT NULL UNIQUE,
  received_at     DATETIME     NOT NULL,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NULL,
  INDEX idx_lis_results_worklist (worklist_id),
  INDEX idx_lis_results_machine (machine_id),
  INDEX idx_lis_results_barcode (barcode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
CREATE TABLE failed_lis_results (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  worklist_id     CHAR(36)     NULL,
  machine_id      CHAR(36)     NOT NULL,
  barcode         VARCHAR(100) NOT NULL,
  test_code       VARCHAR(50)  NOT NULL,
  result_value    TEXT         NULL,
  unit            VARCHAR(50)  NULL,
  reference_range VARCHAR(100) NULL,
  abnormal_flag   VARCHAR(10)  NULL,
  raw_message     MEDIUMTEXT   NULL,
  message_hash    CHAR(64)     NULL,
  error_message   TEXT         NOT NULL,
  retry_count     INT          NOT NULL DEFAULT 0,
  max_retries     INT          NOT NULL DEFAULT 5,
  status          VARCHAR(20)  NOT NULL DEFAULT 'pending',
  received_at     DATETIME     NULL,
  last_retry_at   DATETIME     NULL,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NULL,
  INDEX idx_failed_status (status, retry_count),
  INDEX idx_failed_machine (machine_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
CREATE TABLE legacy_result_temp (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  barcode         VARCHAR(100) NOT NULL,
  machine_id      VARCHAR(64)  NOT NULL,
  api_key         VARCHAR(64)  NULL,
  device_model    VARCHAR(100) NULL,
  `date`          DATETIME     NULL,
  mapping         JSON         NULL,
  results         JSON         NULL,
  processed       TINYINT(1)   NOT NULL DEFAULT 0,
  error_log       JSON         NULL,
  created_at      DATETIME     NULL,
  updated_at      DATETIME     NULL,
  INDEX idx_legacy_barcode (barcode),
  INDEX idx_legacy_machine (machine_id),
  INDEX idx_legacy_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 42. Sample SQL DDL (full file)

```sql
SET FOREIGN_KEY_CHECKS = 0;
-- DROP TABLE IF EXISTS failed_lis_results, lis_results, legacy_result_temp,
--   test_reports, test_components, test_sample_items, test_samples, lab_tests,
--   lab_receipts, worklists, machine_test_mappings, machines, tests;
SET FOREIGN_KEY_CHECKS = 1;
-- Run each CREATE TABLE from §36–§41 in order.
```

---

# PART VIII-A — Mirth Local DB Schema (Pattern B only)

## 42A. lis_cache schema (Mirth PostgreSQL)

Run on the Mirth internal PostgreSQL (the same instance Mirth uses for its own metadata). Create a separate schema to keep things tidy.

```sql
-- Run as the mirth user (or postgres superuser then grant)
CREATE SCHEMA IF NOT EXISTS lis_cache AUTHORIZATION mirth;

-- ===========================================
-- lis_worklists: local mirror of Laravel worklists
-- ===========================================
CREATE TABLE lis_cache.lis_worklists (
  id                       UUID         NOT NULL PRIMARY KEY,    -- local UUID
  laravel_id               UUID         NOT NULL UNIQUE,           -- worklists.id in Laravel
  device_model             VARCHAR(100) NOT NULL,                 -- machines.device_model (X-Machine-Key)
  machine_id               UUID         NULL,                     -- machines.id in Laravel
  barcode                  VARCHAR(100) NOT NULL,
  receipt_barcode          VARCHAR(50)  NULL,
  patient_id               VARCHAR(64)  NULL,
  patient_name             VARCHAR(255) NULL,
  patient_age              INT          NULL,
  patient_gender           VARCHAR(10)  NULL,
  test_code                VARCHAR(50)  NOT NULL,
  test_name                VARCHAR(255) NULL,
  sample_type              VARCHAR(50)  NULL,
  tube_type                VARCHAR(100) NULL,
  timing                   VARCHAR(20)  NULL,
  priority                 VARCHAR(20)  NOT NULL DEFAULT 'routine',
  status                   VARCHAR(20)  NOT NULL DEFAULT 'pending',  -- pending|processing|completed|cancelled|locked
  order_date               TIMESTAMP    NULL,
  collection_date          TIMESTAMP    NULL,
  started_at               TIMESTAMP    NULL,
  completed_at             TIMESTAMP    NULL,
  test_sample_item_id      UUID         NULL,
  test_sample_id           UUID         NULL,
  lab_receipt_id           UUID         NULL,
  lab_test_id              UUID         NULL,
  test_id                  UUID         NULL,
  suffix                   VARCHAR(10)  NULL,
  sample_number            INT          NULL,
  locked_by                VARCHAR(100) NULL,                       -- device_model of the machine that claimed it
  result_id                UUID         NULL,                        -- FK to lis_results.id (set when result arrives)
  source                   VARCHAR(20)  NOT NULL DEFAULT 'laravel',  -- laravel|manual
  laravel_status           VARCHAR(20)  NOT NULL DEFAULT 'pending',  -- last known status in Laravel (after sync-push)
  last_synced_at           TIMESTAMP    NOT NULL DEFAULT NOW(),
  created_at               TIMESTAMP    NOT NULL DEFAULT NOW(),
  updated_at               TIMESTAMP    NOT NULL DEFAULT NOW(),

  UNIQUE INDEX idx_liswl_laravel_id (laravel_id),
  INDEX idx_liswl_device_barcode_status (device_model, barcode, status),
  INDEX idx_liswl_status (status),
  INDEX idx_liswl_test_sample_item (test_sample_item_id),
  INDEX idx_liswl_last_synced (last_synced_at)
);

-- ===========================================
-- lis_results: queue of results to push to Laravel
-- ===========================================
CREATE TABLE lis_cache.lis_results (
  id                UUID         NOT NULL PRIMARY KEY,
  worklist_id       UUID         NULL,                              -- FK to lis_worklists.id (NULL for unidirectional)
  laravel_worklist_id UUID       NULL,                              -- Laravel worklists.id (set after sync-push completes)
  machine_id        VARCHAR(64)  NOT NULL,                          -- machines.id from payload
  device_model      VARCHAR(100) NOT NULL,
  barcode           VARCHAR(100) NOT NULL,
  test_code         VARCHAR(50)  NULL,
  result_data       JSONB        NOT NULL,                          -- the payload sent to /mirth/legacy-result
  raw_message       TEXT         NULL,                              -- original HL7 ORU
  status            VARCHAR(20)  NOT NULL DEFAULT 'completed',      -- completed|failed|cancelled
  retry_count       INT          NOT NULL DEFAULT 0,
  max_retries       INT          NOT NULL DEFAULT 5,
  synced_at         TIMESTAMP    NULL,                              -- NULL = not yet pushed to Laravel
  sync_response     TEXT         NULL,                              -- Laravel response body
  sync_error        TEXT         NULL,                              -- error message if sync failed permanently
  created_at        TIMESTAMP    NOT NULL DEFAULT NOW(),
  updated_at        TIMESTAMP    NOT NULL DEFAULT NOW(),

  INDEX idx_lisres_synced (synced_at, retry_count),
  INDEX idx_lisres_worklist (worklist_id),
  INDEX idx_lisres_barcode (barcode),
  INDEX idx_lisres_created (created_at)
);

-- ===========================================
-- lis_sync_state: tracks last sync timestamps per machine
-- ===========================================
CREATE TABLE lis_cache.lis_sync_state (
  device_model         VARCHAR(100) NOT NULL PRIMARY KEY,
  last_pull_at         TIMESTAMP    NULL,
  last_push_at         TIMESTAMP    NULL,
  last_pull_count      INT          NOT NULL DEFAULT 0,
  last_push_count      INT          NOT NULL DEFAULT 0,
  last_pull_error      TEXT         NULL,
  last_push_error      TEXT         NULL,
  updated_at           TIMESTAMP    NOT NULL DEFAULT NOW()
);
```

## 42B. Maintenance jobs

### Prune old synced results (90 days)

```sql
-- Run weekly via cron / pg_cron
DELETE FROM lis_cache.lis_results
WHERE synced_at IS NOT NULL AND created_at < NOW() - INTERVAL '90 days';
```

### Prune old completed worklists (30 days)

```sql
DELETE FROM lis_cache.lis_worklists
WHERE status IN ('completed', 'cancelled')
  AND updated_at < NOW() - INTERVAL '30 days';
```

### Check stuck processing rows (alert if > 1 hour)

```sql
SELECT id, barcode, device_model, started_at
FROM lis_cache.lis_worklists
WHERE status = 'processing'
  AND started_at < NOW() - INTERVAL '1 hour';
-- If rows return, an analyzer crashed mid-test. Reset to 'pending' or 'cancelled'.
```

---

# PART IX — Sample HL7 Messages

## 43. QRY^Q02 (Worklist Query from Analyzer)

```
MSH|^~\&|BH5390|URIT|LIS|LAB|20261114103000||QRY^Q02|Q001|P|2.3.1
QRD|20261114103000|R|I|||||2608281001-F
QRF|LAB|||||||||O
```

| Field | Value | Notes |
|---|---|---|
| MSH.9 | `QRY^Q02` | Worklist query |
| MSH.10 | `Q001` | Control ID — echoed in MSA.2 |
| QRD.8 | `2608281001-F` | The tube barcode |

### What Mirth does

- **Pattern A:** HTTP GET Laravel `/api/get-worklist?barcode=2608281001-F&api=KEY`, build OML, responseMap.put.
- **Pattern B:** `SELECT FROM lis_cache.lis_worklists WHERE device_model=? AND barcode=? AND status='pending'`, build OML, lock locally, responseMap.put.

## 44. OML^O21 (Worklist Reply from Mirth)

```
MSH|^~\&|LIS|LAB|ANALYZER|MODEL|20261114103001||OML^O21|OML20261114103001|P|2.3.1
MSA|AA|Q001||Worklist sent successfully
ORC|NW|2608281001-F||||CM
OBR|1||2608281001-F|||F|||P0001|JOHN DOE
OBX|1|NM|CBC^Complete Blood Count||||||F
```

On error: `MSA|AE|Q001||<reason>`.

> **OML^O21 vs DSR^Q03:** both are valid HL7 responses to `QRY^Q02`. v4 keeps `OML^O21` because that's what works in your lab.

## 45. ORU^R01 (Result Upload from Analyzer)

**v3/v4 fix:** code in `OBX.3.2` (matches parser); date in `OBR.7`.

```
MSH|^~\&|BH5390|URIT|LIS|LAB|20261114103500||ORU^R01|R001|P|2.3.1
PID|1||P0001||DOE^JOHN||19890115|M
OBR|1||2608281001-F|||20261114103400
OBX|1|NM|1^WBC||6.2|10^9/L|4.0-10.0|N|||F
OBX|2|NM|2^RBC||4.5|10^12/L|4.0-5.5|N|||F
OBX|3|NM|3^HGB||13.8|g/dL|12.0-16.0|N|||F
OBX|4|NM|4^HCT||41.2|%|37-50|N|||F
OBX|5|NM|5^MCV||91.6|fL|80-100|N|||F
... (etc — full CBC panel)
```

### What Mirth does

- **Pattern A:** parse OBX, build JSON, HTTP POST Laravel `/mirth/legacy-result`, read `body.type`.
- **Pattern B:** parse OBX, build JSON, INSERT into `lis_cache.lis_results`, UPDATE `lis_worklists` to `completed`, return ACK to analyzer. Sync-push channel later POSTs to Laravel.

JSON payload (both patterns, values as strings):

```json
{
  "machine_id": "0194abcd-...",
  "api_key": "a1b2c3d4...",
  "barcode": "2608281001-F",
  "device_model": "BH5390",
  "date": "20261114103400",
  "mapping": { "WBC": ["<component-uuid>"] },
  "results": [
    { "code": "WBC", "name": "White Blood Cell Count", "value": "6.20", "unit": "10^9/L" },
    { "code": "RBC", "name": "Red Blood Cell Count",   "value": "4.50", "unit": "10^12/L" }
  ]
}
```

## 46. ACK Variants

### Application Accept (success)

```
MSH|^~\&|LIS|LAB|BH5390|URIT|20261114103501||ACK|R001_ACK|P|2.3.1
MSA|AA|R001
```

### Application Error / Reject

```
MSA|AE|R001||Failed to parse OBX segment 5
MSA|AR|R001||Unknown barcode format
```

For ORU results, Mirth's standard "Auto-commit" produces the AA ACK automatically.

---

# PART X — Dynamic Multi-Analyzer Deployment

## 47. Channel Inventory Pattern

For N analyzers + 2 sync channels (Pattern B):

| # | Channel | Type | Port | Pattern |
|---|---|---|---|---|
| 0 | `lis_sync_pull` | Timer → DB Writer | n/a | B only |
| 0 | `lis_sync_push` | DB Reader → HTTP + DB Writer | n/a | B only |
| 1 | `urit_bh5390_bi` | TCP/MLLP :5700 | 5700 | A or B |
| 2 | `snibe_xxx_bi` | TCP/MLLP :5701 | 5701 | A or B |
| 3 | `sysmex_xn1000_bi` | TCP/MLLP :5702 | 5702 | A or B |
| 4 | `mindray_bc6800_bi` | TCP/MLLP :5703 | 5703 | A or B |
| 5 | `beckman_au680_uni` | TCP/MLLP :5710 | 5710 | A or B |
| 6 | `roche_cobas_uni` | TCP/MLLP :5711 | 5711 | A or B |

All analyzer channels must use the **same pattern** (all A or all B) — mixing causes inconsistencies (one analyzer reads from local DB, another hits Laravel directly, lock states diverge).

## 48. Adding a New Analyzer (cookbook)

### Step 1 — Register in Laravel admin UI

Machines → New. Name `sysmex_xn1000`, Display `Sysmex XN-1000`, Device model `SYSMEX-XN1000`, Direction `bidirectional`, Protocol `hl7`, Protocol version `2.5.1`, IP `<sysmex_ip>`, Port `5702`, Priority `80`. Save → Laravel auto-generates `api_key`.

### Step 2 — Assign tests

Machine edit page → Assign Tests → select tests → Save Parameters.

### Step 3 — Export config

Machine list → Export Config → `machine_config_<uuid>.json` downloads. Set `"directional": "bidirectional"` by hand until the §34.4 fix is live.

### Step 4 — Add to Mirth

#### Pattern A

Mirth admin UI → Channels → New. Source TCP Listener port 5702, MLLP `0x0B ... 0x1C 0x0D`. Source Transformer: §18.1 (v3 direct script). Destination: §21A (HTTP POST).

#### Pattern B

Same as Pattern A but:
- Source Transformer: §18.2 (Pattern B script — local DB query + lock)
- Destination: §21B (INSERT into `lis_cache.lis_results`)
- Add the new machine's `device_model` to the `MACHINE_KEYS` array in `lis_sync_pull` channel
- Ensure the machine is set up in Laravel (sync-pull will pull its worklists automatically)

Deploy.

### Step 5 — Configure the analyzer

On the analyzer host PC, set LIS IP = Mirth server IP, LIS Port = `5702`, Protocol = HL7 v2.5.1, Mode = Bidirectional.

### Step 6 — End-to-end test

1. Scan a tube with a pending worklist
2. **Pattern B:** Wait 30 s for sync-pull to populate local DB (or run sync-pull channel manually)
3. Mirth dashboard: QRY arrives → "OML^O21 built with N tests (Pattern B local query)" → analyzer receives OML
4. Run test → ORU arrives → Mirth logs "Storing N results locally for barcode..." → Mirth returns ACK
5. Wait 15 s for sync-push → check Laravel admin → Lab Receipt → Reports: TestReport rows populated, worklist `completed`

## 49. Channel Groups

```
/lab-sync
  ├── lis_sync_pull
  └── lis_sync_push
/lab-hematology
  ├── urit_bh5390_bi
  ├── sysmex_xn1000_bi
  └── mindray_bc6800_bi
/lab-chemistry
  ├── snibe_xxx_bi
  ├── beckman_au680_uni
  └── roche_cobas_uni
```

---

# PART XI — Testing & Validation

## 50. Test Strategy Overview

| Layer | Tool | What to verify |
|---|---|---|
| HL7 message round-trip | HL7 Send tool | Mirth parses QRY, builds OML |
| Mirth local DB (Pattern B) | psql / pgAdmin | `lis_worklists` populated by sync-pull; `lis_results` populated on ORU |
| Laravel API standalone | Postman | `/api/mirth/worklist` and `/mirth/legacy-result` return correct JSON |
| Database state | MySQL CLI / Adminer | TestReport rows created with correct component_id, result, unit |
| End-to-end | Physical analyzer + tube | Real barcode → real result row |
| Failure modes | Mirth + Postman mock failures | DLQ, retry, error ACK, offline tolerance |

## 51. Testing with HL7 Send Tool

### 51.1 Install

HL7 Inspector (`https://hl7inspector.com/`), Mirth Administrator's *Send Message*, or `nc`.

### 51.2 Send QRY^Q02

Server: `<mirth_ip>:5700`. Paste the QRY from §43. Receive back the OML from §44.

### 51.3 Send ORU^R01 with abnormal values

```
OBX|1|NM|1^WBC||*15.5|10^9/L|4.0-10.0|H|||F
OBX|2|NM|2^RBC||3.2|10^12/L|4.0-5.5|L|||F
```

Verify the `*` is stripped, and the abnormal flags `H`/`L` are not forwarded (payload only carries `code`, `name`, `value`, `unit`).

### 51.4 Command-line sending (no `mirthsend`)

```bash
printf '\x0b%s\x1c\r' "$(cat /tmp/qry.hl7 | tr '\n' '\r')" | nc 127.0.0.1 5700 | tr '\r' '\n'
```

## 52. Testing via Mirth Channel Reader

Clone the channel as `<name>_test`. Change source from TCP Listener to Channel Reader. Deploy. Open "Messages" view → Send Message → paste QRY → Send.

## 53. Postman for Laravel API

### 53.1 Pattern A — GET /api/get-worklist

```
GET https://lab.htncr.org/api/get-worklist?barcode=2608281001-F&api=KEY
```

Expected: 200 with `{patient_id, patient_name, tests:[]}`.

### 53.2 Both patterns — POST /mirth/legacy-result

Body (raw JSON) — note `value` is **string**:

```json
{
  "machine_id": "0194abcd-...",
  "api_key": "a1b2c3d4...",
  "barcode": "2608281001-F",
  "device_model": "BH-5390",
  "date": "20261114103500",
  "mapping": { "WBC": ["<component-uuid>"] },
  "results": [
    { "code": "WBC", "name": "White Blood Cell Count", "value": "6.2", "unit": "10^9/L" }
  ]
}
```

Expected: HTTP 200 with `{type:"success", note:"...", status:200}`.

### 53.3 Pattern B — GET /api/mirth/worklist (sync-pull endpoint)

```
GET https://lab.htncr.org/api/mirth/worklist
X-Machine-Key: BH-5390
```

Expected: 200 with `{success, machine, total, worklist:[...]}`. Sets `pushed_at = now()` on each returned row in Laravel.

## 54. End-to-End Validation Checklist

| # | Step | Expected outcome | Pass/Fail |
|---|---|---|---|
| 1 | Register machine in Laravel admin | Machine row with UUID + api_key | ☐ |
| 2 | Assign tests to machine | `machine_test_mappings` rows | ☐ |
| 3 | Export machine config JSON | File downloads with all components | ☐ |
| 4 | **Pattern B:** Create `lis_cache` schema + tables | `psql -c '\dt lis_cache.*'` lists 3 tables | ☐ |
| 5 | **Pattern B:** Deploy `lis_sync_pull` channel | After 30 s, `lis_cache.lis_worklists` has pending rows | ☐ |
| 6 | **Pattern B:** Deploy `lis_sync_push` channel | Channel Started, no errors in dashboard | ☐ |
| 7 | Create Mirth analyzer channel + deploy | Channel state = "Started" | ☐ |
| 8 | Configure analyzer IP:Port | Analyzer can ping Mirth server | ☐ |
| 9 | Send QRY^Q02 via HL7 Send tool | Receive OML^O21 with correct tests | ☐ |
| 10 | **Pattern B:** Verify local lock | `SELECT * FROM lis_cache.lis_worklists WHERE status='processing'` shows claimed row; siblings show 'locked' | ☐ |
| 11 | Scan a real tube at the analyzer | Analyzer displays "Tests received: CBC" | ☐ |
| 12 | Run the test on analyzer | ORU^R01 arrives in Mirth dashboard | ☐ |
| 13 | **Pattern A:** Mirth logs "Upload OK" | Laravel answered `type: success` | ☐ |
| 14 | **Pattern B:** Mirth logs "Stored result ... locally" | `lis_cache.lis_results` has new row with `synced_at=NULL` | ☐ |
| 15 | **Pattern B:** Wait 15 s | `synced_at` set; Laravel `test_reports` has new rows | ☐ |
| 16 | `test_reports` table has N new rows | Each component has a `result` value | ☐ |
| 17 | `worklists.status = 'completed'` | v3 `completeWorklists()` worked | ☐ |
| 18 | `lab_tests.lab_status = 'completed'` | Downstream report generation triggered | ☐ |
| 19 | Lab report PDF shows values | End user sees results | ☐ |
| 20 | Send duplicate ORU^R01 | No duplicate `test_reports` (`updateOrCreate`) | ☐ |
| 21 | **Pattern B:** Stop Laravel 5 min, send ORU | Mirth stores locally; sync-push errors but queue grows; analyzer ACKs normally | ☐ |
| 22 | **Pattern B:** Start Laravel | Within 15 s, queue drains; all `lis_results.synced_at` set | ☐ |
| 23 | Send malformed HL7 | Mirth logs error, no Laravel/local DB write | ☐ |

---

# PART XII — Troubleshooting

## 55. Troubleshooting Matrix

| Symptom | Likely cause | Diagnostic | Fix |
|---|---|---|---|
| Analyzer cannot connect to Mirth | Firewall / port not open | `telnet <mirth_ip> 5700` | Open port; channel Started |
| Pattern A: empty OML | Laravel 404 for barcode | Mirth log "Worklist API error 404" | Worklist row must exist with `status='pending'` |
| **Pattern B: empty OML** | Local DB has no pending row for this device+barcode | `SELECT * FROM lis_cache.lis_worklists WHERE device_model=? AND barcode=?` | Run sync-pull manually; check Laravel has the row with `pushed_at IS NULL` |
| **Pattern B: sync-pull no rows** | `X-Machine-Key` mismatch; machine direction != bidirectional; `pushed_at` already set | `curl -H "X-Machine-Key: X" https://lab.htncr.org/api/mirth/health` returns 200? | Fix `machines.device_model`; set `direction='bidirectional'`; reset `pushed_at = NULL` in Laravel if rows stuck |
| **Pattern B: lis_results not syncing** | Laravel 5xx; `body.type=fail`; `retry_count >= 5` | `SELECT id, sync_error, retry_count FROM lis_cache.lis_results WHERE synced_at IS NULL` | Inspect `sync_error`; fix data; reset `retry_count=0, sync_error=NULL` |
| **Pattern B: local lock stuck (status='processing' for >1h)** | Analyzer crashed mid-test | `SELECT id, barcode, started_at FROM lis_cache.lis_worklists WHERE status='processing' AND started_at < NOW() - INTERVAL '1 hour'` | `UPDATE lis_cache.lis_worklists SET status='pending', started_at=NULL WHERE id=...` (will be re-claimed by next QRY) |
| **Pattern B: cancel not propagating** | Sync-pull cancel-detection window too narrow | Check `lis_sync_pull` log "cancelled N stale local rows" | Increase window from 2 min to 5 min in sync-pull script |
| OML reply has no OBX | Laravel response missing `tests` (Pattern A) or local DB row missing `test_code` (Pattern B) | curl endpoint or `SELECT test_code FROM lis_cache.lis_worklists WHERE id=...` | Worklist has no `test_code` set |
| Analyzer rejects OML reply | MSH field format wrong | Compare with analyzer's "expected reply" sample | Adjust MSH field order in OML builder (§14) |
| ORU arrives but no Laravel POST (Pattern A) / no DB INSERT (Pattern B) | `interface_mode` defaulted to RESULT_UPLOAD but transform threw | Mirth log "Router error" or "Result transform error" | Fix the JS exception — usually missing segment access |
| Laravel returns 404 (`type: fail`) on legacy-result | LabReceipt not found for barcode | Check `test_samples.barcode` | Verify barcode format; check `test_sample` lookup |
| Laravel 200 but TestReport not created | `mapping[code]` empty or component_id wrong | Check `legacy_result_temp.error_log` | Re-export config from Laravel admin, repaste in Mirth |
| EOS# is null in TestReport | `rawValues['EOS#']` undefined; computation didn't trigger | Check `rawValues['WBC']` and `rawValues['EOS%']` defined | Add `EOS#` component to config; check `EOS%` spelling |
| Worklist stays pending after result | `legacyResult` doesn't complete worklists (v1 bug) | Check `worklists.status` after a result | Apply v3 `completeWorklists()` patch (§34.3) |
| Duplicate TestReports created | Two Mirth channels both POST for same barcode | Check `worklists.machine_id` matches channel's machine | Filter out ORU not belonging to this machine in source transformer |
| `connection refused` to Laravel | Laravel down / network / DNS | `curl -v https://lab.htncr.org/api/mirth/health` | Bring Laravel back up; check DNS |
| **Pattern B: sync-push HTTP 5xx** | Laravel down or 500 error | sync-push log | Laravel will be retried next cycle (up to 5×); then marked `sync_error` |
| Analyzer sends graph PNG as OBX | Urit/Sysmex graph feature enabled | Mirth log "code: WBC.PNG" | Filter already in place: `if (code.indexOf('.PNG') !== -1) continue;` |
| `MSA|AE` returned to analyzer | Laravel non-200 (Pattern A) or local DB error (Pattern B) | Mirth log | Fix Laravel-side issue (Pattern A) or local DB connection (Pattern B) |
| Channel goes to "Stopped" unexpectedly | Source transformer threw uncaught exception | Mirth `server.log` stacktrace | Wrap all segment access in try/catch |
| ACK too slow, analyzer times out | Pattern A: destination HTTP POST takes >5s | Mirth log duration | Switch to Pattern B (local DB write is 1–2 ms) |
| Patient name contains `^` | HL7 escape not applied | Verify OBR uses `\\ S\\` | Apply `hl7Escape()` (§17.4) |
| Upload logged OK but Laravel didn't save | v1 bug: logged OK on any 2xx | Mirth log shows HTTP 200 with `type: fail` | v3/v4 fix: destination reads `body.type === 'success'` |
| Laravel 419 on `/mirth/legacy-result` | Route is web + CSRF (cannot verify from upload) | `storage/logs/laravel.log` for 419 | If you see 419s, exclude `mirth/*` from CSRF |

## 56. Logging Best Practices

| Level | When | Where |
|---|---|---|
| `logger.info` | Normal milestones (barcode, OML built, upload OK, sync-pull/push counts) | Mirth dashboard + `mirth.log` |
| `logger.warn` | Recoverable issues (TLS skipped, OBX skip, sync retry) | `mirth.log` |
| `logger.error` | Failures (Laravel 500, transform exception, `type: fail`, sync stuck) | `mirth.log` + email alerts |

Recommended log points (Pattern B):

```javascript
logger.info('HL7 type: ' + msgType + '^' + msgTrigger);
logger.info('Worklist query barcode: ' + barcode);
logger.info('OML^O21 built with ' + testCounter + ' tests (Pattern B local query)');
logger.info('Result upload: ' + results.length + ' results for barcode ' + barcode);
logger.info("Stored result " + resultId + " for barcode " + barcode);
// In sync-pull channel:
logger.info('sync-pull ' + key + ': ' + worklists.length + ' worklists');
logger.info('sync-pull cancelled ' + toCancel.length + ' stale local rows');
// In sync-push channel:
logger.info('sync-push OK: ' + rowId);
logger.error('sync-push FAILED (data issue): ' + rowId + ' type=' + type);
```

Laravel side: `Log::info('Mirth legacy-result', ['barcode' => $input['barcode'], 'count' => count($input['results'])]);` at the top of each Mirth-facing method.

## 57. Common Mirth Errors

### 57.1 "TypeError: Cannot read property '...' from null"

Trying to access an HL7 segment that doesn't exist. Wrap in try/catch.

### 57.2 "JavaException: java.lang.NullPointerException"

Same cause — usually `msg['MSH']['MSH.9']` returned null. Catch and default.

### 57.3 "Connection refused" to Laravel

Laravel down or DNS wrong. `curl -v https://lab.htncr.org/api/mirth/health`.

### 57.4 "SSLHandshakeException: PKIX path building failed"

Public-CA hosts shouldn't fail — check system clock / proxy. For internal CA, create truststore (§8.4) and set as JVM truststore.

### 57.5 "SocketTimeoutException: Read timed out"

Pattern A: Laravel slower than 15 s read timeout. Pattern B: rarely (local DB is fast). Switch to Pattern B if Laravel is consistently slow.

### 57.6 Channel stuck in "Stopping" state

A destination thread is hanging. `sudo /opt/mirthconnect/mcservice restart`.

### 57.7 Pattern B: "DatabaseConnectionFactory not found"

Mirth 4.x may need the PostgreSQL JDBC driver installed. Copy `postgresql-42.x.jar` to `/opt/mirthconnect/custom-lib/` and restart Mirth.

### 57.8 Pattern B: "ON CONFLICT (laravel_id) syntax error"

Your Mirth PostgreSQL is too old (pre-9.5). Either upgrade PostgreSQL, or replace the `ON CONFLICT` clause with a `SELECT-then-UPDATE-or-INSERT` pattern in the sync-pull script.

## 58. Common Laravel Errors

### 58.1 "No mapping found for code: XYZ"

Recorded in `legacy_result_temp.error_log`; JSON answer is still `type: success`. Add the code to `machine_test_mappings.parameters.mapping`, re-export config, repaste in Mirth.

### 58.2 "LabReceipt not found" (`type: fail`)

Barcode not in `test_samples.barcode` nor `lab_receipts.barcode`. Verify barcode format; check `SampleController::sampleCollect`.

### 58.3 "Component not found"

`mapping[code]` points to a non-existent TestComponent.id. Re-export config.

### 58.4 419 (CSRF mismatch)

**Cannot verify from upload.** If `/mirth/legacy-result` is a web route and you see 419 errors in `storage/logs/laravel.log`, exclude it from CSRF.

Laravel 11/12 (`bootstrap/app.php`):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: ['mirth/*']);
})
```

Laravel ≤10: add `'mirth/*'` to `$except` in `VerifyCsrfToken`. `/api/*` routes have no CSRF.

### 58.5 Machine lookup errors (`X-Machine-Key`)

`MirthController` never returns 401. Header missing → 400; `device_model` unknown or inactive → 404; direction not allowed → 403.

### 58.6 Pattern B: `pushed_at` stuck

If sync-pull fails repeatedly, Laravel's `worklists.pushed_at` stays set and the same rows are never re-returned. Reset:

```sql
UPDATE worklists SET pushed_at = NULL
WHERE status = 'pending' AND pushed_at < NOW() - INTERVAL '10 minutes';
```

---

# PART XIII — Deployment & Operations

## 59. Production Deployment Checklist

### 59.1 Mirth-side (Pattern A)

- [ ] Channel deployed and Started
- [ ] Source port is unique
- [ ] MLLP framing matches analyzer spec (`0x0B ... 0x1C 0x0D`)
- [ ] Source Transformer pasted (no syntax errors)
- [ ] `machineConfig` JSON has all real component UUIDs (no `<component-uuid>` placeholders)
- [ ] Destination script pasted; reads `body.type === 'success'`
- [ ] Channel tags include vendor + direction
- [ ] Pruning: keep 30 days
- [ ] Message payload storage: enabled

### 59.2 Mirth-side (Pattern B additional)

- [ ] PostgreSQL JDBC driver in `/opt/mirthconnect/custom-lib/`
- [ ] `lis_cache` schema + 3 tables created (§42A)
- [ ] Mirth DB password stored in Configuration Map (not hardcoded in scripts)
- [ ] `lis_sync_pull` channel deployed, Started; `MACHINE_KEYS` array includes all bidirectional analyzers
- [ ] `lis_sync_push` channel deployed, Started
- [ ] Test sync-pull: after 30 s, `SELECT COUNT(*) FROM lis_cache.lis_worklists` > 0
- [ ] Test sync-push: send a fake ORU → wait 15 s → `SELECT synced_at FROM lis_cache.lis_results ORDER BY created_at DESC LIMIT 1` is not NULL
- [ ] Pruning cron: weekly DELETE of synced `lis_results` older than 90 days; completed `lis_worklists` older than 30 days

### 59.3 Laravel-side (both patterns)

- [ ] `machines` row exists with correct `device_model`, `direction='bidirectional'`, `api_key`, `port`, `is_active=1`
- [ ] `machine_test_mappings` rows exist for every test
- [ ] `parameters` JSON has correct `mapping` and `components`
- [ ] `tests` and `test_components` rows exist
- [ ] `routes/api.php` has `/api/mirth/*` endpoints; `/api/get-worklist` registered (Pattern A)
- [ ] `/mirth/legacy-result` excluded from CSRF if 419s appear
- [ ] **v3 fixes applied:** `direction` (not `directional`) in `exportMachineConfig` (§34.4); `completeWorklists()` in `legacyResult` (§34.3)
- [ ] `php artisan config:clear && php artisan route:clear && php artisan cache:clear`
- [ ] Postman: `/api/mirth/health` returns 200

### 59.4 Network-side

- [ ] Mirth reaches Laravel on port 443
- [ ] Analyzer reaches Mirth on assigned MLLP port
- [ ] Firewall rules persistent across reboots
- [ ] DNS resolves correctly

### 59.5 Operational readiness

- [ ] On-call rota assigned
- [ ] Dashboard alerts: "channel stopped" + "destination failed >3 in 10 min" + (Pattern B) "sync-push queue > 50"
- [ ] Backup of Mirth channel XML exported + offsite
- [ ] Backup of `machine_test_mappings.parameters` JSON exported
- [ ] Backup of Mirth local DB `lis_cache` schema (Pattern B)
- [ ] Run-book: how to manually re-trigger via `/api/mirth/retry-failed/{id}` (Pattern A) or `UPDATE lis_cache.lis_results SET synced_at=NULL` (Pattern B)
- [ ] 24-hour soak test: 50+ samples, no orphaned `worklists` (status='pending' forever)

## 60. Multi-Analyzer Rollout Plan

| Phase | Duration | What |
|---|---|---|
| 1 Pilot (Pattern A) | 1 week | Deploy Urit BH-5390 with Pattern A; logging INFO; resolve quirks |
| 2 Expansion (Pattern A) | 2 weeks | Add Snibe + 1 more; add Channel Groups; first XML backup |
| 3 Pattern B migration | 1 week | Install PostgreSQL JDBC, create `lis_cache`, deploy sync channels, switch analyzer channels one at a time |
| 4 Full deployment | 1 month | All analyzers on Pattern B; logging WARN; monitoring dashboards; runbook |
| 5 Hardening | ongoing | DLQ retry automation; Prometheus; alert on `lis_results` queue growth; 90-day cleanup cron |

## 61. Monitoring & Alerts

### 61.1 Mirth built-in dashboard

Dashboard view: messages received / queued / errored / filtered per channel. Set up email alerts on "Errors".

### 61.2 Laravel health endpoint

```bash
*/1 * * * * curl -sf -H "X-Machine-Key: BH-5390" https://lab.htncr.org/api/mirth/health \
  | jq -e '.status == "healthy"' || /usr/local/bin/alert-laravel-down.sh
```

### 61.3 Pattern B — sync-push queue depth alert

```bash
*/5 * * * * PGPASSWORD='<pwd>' psql -h localhost -U mirth -d mirth_db -t -c \
  "SELECT COUNT(*) FROM lis_cache.lis_results WHERE synced_at IS NULL" \
  | awk '{if ($1+0 > 50) system("/usr/local/bin/alert-sync-queue.sh")}'
```

### 61.4 Pattern B — stuck processing rows alert

```bash
*/10 * * * * PGPASSWORD='<pwd>' psql -h localhost -U mirth -d mirth_db -t -c \
  "SELECT COUNT(*) FROM lis_cache.lis_worklists WHERE status='processing' AND started_at < NOW() - INTERVAL '1 hour'" \
  | awk '{if ($1+0 > 0) system("/usr/local/bin/alert-stuck-processing.sh")}'
```

### 61.5 Prometheus / Grafana

> Illustrative — verify the JMX port and MBean names for your Mirth version.

Use `jmx_exporter` to convert Mirth JMX to Prometheus format. Visualize per channel. Add panels for `lis_cache` table sizes (via `postgres_exporter`).

## 62. Backup & Recovery

### 62.1 Mirth channel backup

> Illustrative. Administrator → *Export All Channels* is the safe route.

```bash
curl -u admin:<password> https://localhost:8443/api/channels?format=xml \
  -o /backup/mirth/channels_$(date +%Y%m%d).xml
```

### 62.2 Laravel DB backup

```bash
0 2 * * * mysqldump --defaults-extra-file=/root/.my.cnf lis_db | gzip > /backup/db/lis_$(date +%Y%m%d).sql.gz
find /backup/db -mtime +30 -delete
```

### 62.3 Pattern B — Mirth local DB backup

```bash
0 3 * * * PGPASSWORD='<pwd>' pg_dump -h localhost -U mirth -d mirth_db -n lis_cache \
  | gzip > /backup/mirth/lis_cache_$(date +%Y%m%d).sql.gz
find /backup/mirth -mtime +30 -delete
```

### 62.4 Recovery procedure

**Mirth crash:** Restore from VM snapshot, or reinstall + import channels + restore `lis_cache` schema → verify Started → test QRY.

**Laravel DB lost:** Restore from latest dump, run migrations, clear caches, re-export `machineConfig` for each analyzer. **Pattern B:** local `lis_cache.lis_results` queue will keep accumulating; once Laravel is back, sync-push drains it.

**Mirth local DB lost (Pattern B):** Restore `lis_cache` from latest `pg_dump`. If no backup: drop and recreate schema (§42A), sync-pull will repopulate `lis_worklists` from Laravel within 30 s. **Lost `lis_results` rows that hadn't been synced are gone** — analyzers would need to re-send.

### 62.5 Disaster recovery (RTO / RPO)

| Component | RPO | RTO | Strategy |
|---|---|---|---|
| Mirth channel config | 24h | 1h | Daily XML backup, offsite copy |
| Mirth message log | 1h | 4h | Local disk + DB pruning 30d |
| **Pattern B: `lis_cache` schema** | 24h | 1h | Daily `pg_dump` of `lis_cache` schema |
| Laravel DB | 24h | 2h | Daily mysqldump + binlog replication |
| Laravel app code | Continuous | 30min | Git + CI/CD redeploy |
| Machine test mappings | 24h | 1h | Part of MySQL dump |

---

# PART XIV — Appendices

## A. Glossary

| Term | Definition |
|---|---|
| **ACK** | Acknowledgement — sent by the receiver back to the sender confirming receipt |
| **ASTM** | ASTM E1394 — serial protocol used by older analyzers |
| **Barcode** | Identifier on a sample tube |
| **Bidirectional** | Communication in both directions — analyzer queries LIS for orders + sends results |
| **Channel** | A Mirth Connect configuration unit |
| **Component** | A specific analyte within a test (e.g. WBC, RBC, HGB) |
| **DLQ** | Dead-Letter Queue — failed messages for retry |
| **HL7** | Health Level 7 — healthcare information exchange standard |
| **Idempotency** | Multiple identical operations produce the same result |
| **LabReceipt** | Laravel entity for a patient's lab visit |
| **LIS** | Laboratory Information System |
| **MLLP** | Minimal Lower Layer Protocol — TCP framing for HL7 v2 |
| **Mirth Connect** | Open-source integration engine (NextGen Connect) |
| **OBX / OBR / ORC / MSH / MSA / QRD** | HL7 segment types |
| **OML^O21** | Laboratory Order Message — LIS reply to worklist query |
| **ORU^R01** | Observation Result Message — analyzer → LIS results |
| **QRY^Q02** | Query message — analyzer asks for tests on a barcode |
| **Pattern A · Direct** | Mirth calls Laravel per-message (no local cache) |
| **Pattern B · Cached** | Mirth maintains local mirror of worklists + results queue; sync channels handle Laravel I/O on a timer |
| **Source Transformer** | Mirth component that transforms an inbound message |
| **TestComponent** | Laravel entity for a single analyte definition |
| **TestReport** | Laravel entity storing the actual result value |
| **Unidirectional** | Communication in one direction only — analyzer sends results, does not query |
| **Worklist** | List of pending tests assigned to a specific analyzer for specific tubes |

## B. HL7 v2.3.1 Field Reference (used in this guide)

### MSH — Message Header

| Field | Name | Example |
|---|---|---|
| MSH.1 | Field Separator | `\|` |
| MSH.2 | Encoding Characters | `^~\&` |
| MSH.3 | Sending Application | `BH5390` |
| MSH.4 | Sending Facility | `URIT` |
| MSH.5 | Receiving Application | `LIS` |
| MSH.6 | Receiving Facility | `LAB` |
| MSH.7 | Date/Time of Message | `20261114103000` |
| MSH.9 | Message Type | `QRY^Q02`, `ORU^R01`, `OML^O21`, `ACK` |
| MSH.10 | Message Control ID | `Q001` |
| MSH.11 | Processing ID | `P` (production) / `T` (training) |
| MSH.12 | Version ID | `2.3.1` |

### MSA — Message Acknowledgement

| Field | Name | Example |
|---|---|---|
| MSA.1 | Acknowledgement Code | `AA` (accept) / `AE` (error) / `AR` (reject) |
| MSA.2 | Message Control ID | (echo of MSH.10) |
| MSA.3 | Text Message | `Worklist sent successfully` |

### ORC — Common Order

| Field | Name | Example |
|---|---|---|
| ORC.1 | Order Control | `NW` (new) / `CA` (cancel) |
| ORC.2 | Placer Order Number | `2608281001-F` |

### OBR — Observation Request

| Field | Name | Example |
|---|---|---|
| OBR.1 | Set ID | `1` |
| OBR.2 | Placer Order Number | `2608281001-F` |
| OBR.3 | Filler Order Number | `2608281001-F` |
| OBR.4 | Universal Service ID | (test code^name) |
| OBR.7 | Observation Date/Time | `20261114103400` |

### OBX — Observation Result

| Field | Name | Example |
|---|---|---|
| OBX.1 | Set ID | `1` |
| OBX.2 | Value Type | `NM` (numeric), `ST` (string), `CE` (coded) |
| OBX.3 | Observation Identifier | `1^WBC` — **parser reads code from `OBX.3.2`**; some analyzers use `OBX.3.1` |
| OBX.5 | Observation Value | `6.2` |
| OBX.6 | Units | `10^9/L` |
| OBX.7 | Reference Range | `4.0-10.0` |
| OBX.8 | Abnormal Flags | `N` (normal), `H` (high), `L` (low), `LL` (critically low), `HH` (critically high) |
| OBX.11 | Result Status | `F` (final), `P` (preliminary), `C` (corrected) |

> v3 fix: `LL` = critically low (not high), `HH` = critically high.

### QRD — Query Definition (for QRY^Q02)

| Field | Name | Example |
|---|---|---|
| QRD.1 | Query Date/Time | `20261114103000` |
| QRD.2 | Query Format Code | `R` (record response) |
| QRD.3 | Query Priority | `I` (immediate) / `D` (deferred) |
| QRD.8 | Who Subject Filter | `2608281001-F` (the barcode!) |

## C. Mirth Connect Glossary

| Term | Definition |
|---|---|
| **channelMap** | Variables scoped to the current message — set in source transformer, readable in destination |
| **connectorMap** | Variables scoped to the current connector (source or destination) |
| **globalMap** | Variables scoped to the entire Mirth server — shared across all channels |
| **responseMap** | Where the source transformer writes the response that should be sent back to the analyzer. Key `'Auto-generate (After source transformer)'` is the documented bidirectional MLLP reply pattern in Mirth 3.x / 4.x |
| **logger** | Mirth's logging object |
| **msg** | The inbound message object in the source transformer |
| **DateUtil** | Mirth utility — `DateUtil.getCurrentDate('yyyyMMddHHmmss')` |
| **DatabaseConnectionFactory** | Mirth utility for creating JDBC connections from JS — used in Pattern B |
| **Configuration Map** | Mirth server-level key/value store for secrets (DB passwords, API keys) — use this instead of hardcoding in scripts |
| **Auto-generate (After source transformer)** | The standard `responseMap` key Mirth uses to send the source's response back to the analyzer |
| **MC Service Manager** | The Mirth daemon — start/stop with `mcservice` |
| **Channel Group** | A folder in the channel tree for organization only — no runtime effect |

## D. File Layout / Project Structure

```
<project>/
├── upload/
│   ├── lis.md                            ← original Mirth templates
│   └── LabSomeFile.zip                   ← Laravel source
│       ├── Controller/MirthController.php      ← /api/mirth/*, /mirth/legacy-result (+ completeWorklists in v3/v4)
│       ├── Controller/WorklistController.php   ← /lab/worklists/* and duplicate API set (unrouted)
│       ├── Controller/MachineController.php    ← /lab/machines/* incl. export-config (fix direction)
│       └── ...
└── download/
    ├── mirth-connect-bidirectional-worklist-guide.md         ← v1 (original)
    ├── mirth-connect-bidirectional-worklist-guide_v3.md      ← v3 (Pattern A only, 14 fixes)
    └── mirth-connect-bidirectional-worklist-guide_v4.md       ← THIS GUIDE (Pattern A + Pattern B)

Mirth server:
<install>/
├── conf/ (mirth.properties; truststore only if internal CA used; Configuration Map for secrets)
├── custom-lib/  (postgresql-42.x.jar — Pattern B required)
└── extensions/

Mirth local PostgreSQL (Pattern B):
database: mirth_db
schema:   lis_cache
tables:   lis_worklists, lis_results, lis_sync_state

Laravel server:
<app>/
├── routes/api.php          ← /api/mirth/* incl. /api/get-worklist (Pattern A)
├── routes/web.php          ← /mirth/legacy-result (CSRF-excluded if 419s appear)
├── app/Http/Controllers/Lab/Api/MirthController.php
├── app/Http/Controllers/Lab/WorklistController.php
├── app/Http/Controllers/Lab/MachineController.php
└── app/Models, app/Services
```

## E. Quick Start (TL;DR)

### Pattern A · Direct (single analyzer, simple)

1. **Laravel:** Apply v3 patches (`completeWorklists()` §34.3, `direction` §34.4). Verify `/api/get-worklist` in `routes/api.php`. Exclude `mirth/*` from CSRF if 419s appear. Create machine (direction=bidirectional). Export config.
2. **Mirth:** Channel `urit_bh5390_bi`: TCP Listener :5700, MLLP `0x0B … 0x1C 0x0D`. Source Transformer §18.1 (v3 script). Destination §21A (HTTP POST, reads `body.type === 'success'`). Deploy.
3. **Test:** `curl "/api/get-worklist?barcode=X&api=KEY"` → send QRY §43 → expect OML §44 → send ORU §45 → expect ACK + TestReport rows + worklist completed.

### Pattern B · Cached (multi-analyzer, offline-tolerant)

1. **Laravel:** Same as Pattern A.
2. **Mirth PostgreSQL:** Create `lis_cache` schema + 3 tables (§42A). Install `postgresql-42.x.jar` in `/opt/mirthconnect/custom-lib/`. Restart Mirth. Store DB password in Configuration Map.
3. **Mirth channels:**
   - `lis_sync_pull`: Timer (30 s), JS transformer §21C, deploy
   - `lis_sync_push`: DB Reader (15 s), HTTP Sender, JS post-processor §21D, deploy
   - `urit_bh5390_bi`: TCP Listener :5700, MLLP. Source Transformer §18.2 (Pattern B script). Destination §21B (INSERT into `lis_cache.lis_results`). Add `BH-5390` to `MACHINE_KEYS` in sync-pull. Deploy.
4. **Test:** `SELECT COUNT(*) FROM lis_cache.lis_worklists` > 0 after 30 s → send QRY → expect OML (built from local DB) → send ORU → `SELECT * FROM lis_cache.lis_results WHERE synced_at IS NULL` shows new row → wait 15 s → `synced_at` set, Laravel `test_reports` populated.

### If something fails

- Troubleshooting Matrix §55.
- Pattern B-specific rows are marked.

### To add another analyzer

- Cookbook §48.

---

## Appendix F — v3.0 Changelog (inherited by v4.0)

### Applied fixes (14) — real bugs in v1.0

1. Sample ORU uses `1^WBC` (code in `OBX.3.2`) to match parser
2. `results.*.value` cast to `String()` — Laravel validates as `string`
3. Added `completeWorklists()` to `MirthController::legacyResult`
4. Destination reads `body.type` not just HTTP 2xx
5. One-line fix: `$machine->direction` (not `directional`) in `exportMachineConfig`
6. Removed hardcoded JKS password / API key / always-true hostname verifier
7. Fixed `LL` = critically low, `HH` = critically high
8. DDL for `lis_results` / `failed_lis_results` uses UUID PK + nullable columns matching the code
9. `legacy_result_temp` DDL has `mapping` / `results` / `error_log` columns
10. `retryFailed` sets `status='pending'`, +1 `retry_count`; no background worker in upload — flagged
11. `acknowledgeWorklist` (controller) doesn't lock siblings — only `WorklistService` does
12. `canReceiveResults()` returns true only for bidirectional machines
13. `mirthsend` is not a Mirth tool — use `nc` or Mirth *Send Message*
14. Backup / install / JMX sections marked as illustrative

### Reverted (5) — kept v1 working pattern

| # | v2.0 changed it to | v3/v4 keeps v1 |
|---|---|---|
| A | New `/api/mirth/worklist/query` method | `/api/get-worklist?barcode=&api=` (verify in `routes/api.php`) |
| B | Two-destination pattern | Single destination + `responseMap.put('Auto-generate (After source transformer)', …)` |
| C | Reply = `DSR^Q03` | Reply = `OML^O21` (both are valid HL7 responses to `QRY^Q02`) |
| D | `/mirth/legacy-result` flagged as web route → CSRF | Conditional — only if 419s appear in `storage/logs/laravel.log` |
| E | Default mode = `IGNORE` | Default mode = `RESULT_UPLOAD` (only QRY/ORU expected) |

---

*End of guide.*
