# Mirth Connect — Bidirectional Worklist Integration Guide

### For LIS (Laravel) ⇄ Analyzers (Urit BH-5390, Snibe, and other HL7/ASTM machines)

> **Document type:** Reference / Operations Manual
> **Audience:** Integration engineers, LIS administrators, lab IT staff
> **Scope:** Bidirectional **and** Unidirectional, dynamic multi-analyzer deployment, end-to-end
> **Pattern origin:** Production templates already running in lab — extended with verified corrections
> **Version:** 3.0

---

## What changed in v3.0 (read first)

v3.0 = v1.0 + the **14 verified bug fixes** from v2.0. Five v2.0 changes that altered your working pattern are reverted.

### Applied fixes (14) — real bugs in v1.0

| # | Fix | Where |
|---|---|---|
| 1 | Sample ORU uses `1^WBC` (code in `OBX.3.2`) to match the parser | §43 |
| 2 | `results.*.value` cast to `String()` — Laravel validates it as `string` | §15.4, §18 |
| 3 | Added `completeWorklists()` to `MirthController::legacyResult` — otherwise worklists stay pending forever | §28, §34.3 |
| 4 | Destination reads `body.type` not just HTTP 2xx — Laravel returns 200 even on failure | §21 |
| 5 | One-line fix: `$machine->direction` (not `directional`) in `exportMachineConfig` | §33, §34.4 |
| 6 | Removed hardcoded JKS password / API key / always-true hostname verifier | §8.4 |
| 7 | Fixed `LL` = critically low, `HH` = critically high (were reversed) | App. B |
| 8 | DDL for `lis_results` / `failed_lis_results` uses UUID PK + nullable columns matching the code | §41 |
| 9 | `legacy_result_temp` DDL has `mapping` / `results` / `error_log` columns (not one `payload`) | §41 |
| 10 | `retryFailed` sets `status='pending'`, +1 `retry_count`; **no background worker in the upload** — flagged | §32 |
| 11 | `acknowledgeWorklist` (controller) doesn't lock siblings — only `WorklistService` does | §30 |
| 12 | `canReceiveResults()` returns true only for bidirectional machines | §22, §25 |
| 13 | `mirthsend` is not a Mirth tool — use `nc` or Mirth *Send Message* | §51.4 |
| 14 | Backup / install / JMX sections marked as illustrative (cannot verify from upload) | §9, §61, §62 |

### Reverted (5) — kept your working v1 pattern

| # | v2.0 changed it to | v3.0 keeps v1 |
|---|---|---|
| A | New `/api/mirth/worklist/query` method | `/api/get-worklist?barcode=&api=` — **verify it is registered in `routes/api.php`** before relying on it (route file is not in the upload). If it works in your lab, leave it alone. |
| B | Two-destination pattern ("Respond from = Reply") | Single destination + `responseMap.put('Auto-generate (After source transformer)', …)` — this is a documented Mirth 3.x/4.x pattern and works. |
| C | Reply = `DSR^Q03` | Reply = `OML^O21` — **both are valid HL7 responses to `QRY^Q02`**. Urit BH-5390 may accept either; keep `OML^O21` since that's what works in your lab. Switch only if the analyzer's manual requires `DSR^Q03`. |
| D | `/mirth/legacy-result` flagged as a web route → CSRF | **Cannot verify** without `routes/web.php`. If your Mirth → Laravel POST currently works, CSRF is already handled (Laravel skips CSRF for JSON requests without session cookies). Only add CSRF exclusion if you see `419` errors in `storage/logs/laravel.log`. |
| E | Default mode = `IGNORE` | Default mode = `RESULT_UPLOAD` — safer in your case because the only message types your analyzers send are `QRY^Q02` and `ORU^R01`. An unknown type being parsed as results is a low risk; misclassifying a QRY as IGNORE would break the analyzer. |

---

## Table of Contents

**PART I — Foundations** — 1 Executive Summary · 2 Concepts · 3 Architecture · 4 Communication Styles
**PART II — Multi-Analyzer Strategy** — 5 One Channel Per Analyzer · 6 Naming · 7 Dynamic Config
**PART III — Mirth Setup** — 8 Prerequisites · 9 Install · 10 Channel Config · 11 MLLP & ACK
**PART IV — Source Transformer** — 12 Strategy · 13 Router · 14 Mode A (QRY→OML^O21) · 15 Mode B (ORU→JSON) · 16 Dynamic Loader · 17 Helpers · 18 Full Source Transformer
**PART V — Destination** — 19 Logic · 20 HTTP POST · 21 Full Destination Code
**PART VI — Unidirectional** — 22 When to Use · 23 Source · 24 Destination · 25 Comparison
**PART VII — Laravel Backend** — 26 Endpoints · 27 GET /api/get-worklist · 28 POST /mirth/legacy-result · 29 POST /api/mirth/results · 30 ACK · 31 Health · 32 DLQ · 33 Export Config · 34 Snippets
**PART VIII — DB Schema** — 35 ER · 36 machines · 37 mappings · 38 worklists · 39 receipts/tests/samples · 40 components/reports · 41 results/DLQ/temp · 42 DDL
**PART IX — Sample HL7** — 43 QRY^Q02 · 44 OML^O21 · 45 ORU^R01 · 46 ACK
**PART X — Multi-Analyzer** — 47 Inventory · 48 New Analyzer · 49 Channel Groups
**PART XI — Testing** — 50 Strategy · 51 HL7 Send · 52 Channel Reader · 53 Postman · 54 Checklist
**PART XII — Troubleshooting** — 55 Matrix · 56 Logging · 57 Mirth Errors · 58 Laravel Errors
**PART XIII — Operations** — 59 Checklist · 60 Rollout · 61 Monitoring · 62 Backup
**PART XIV — Appendices** — A Glossary · B HL7 Reference · C Mirth Glossary · D Files · E Quick Start

---

# PART I — Foundations

## 1. Executive Summary

This guide documents the **complete, production-grade integration pattern** between Mirth Connect and a Laravel-based LIS, with focus on **bidirectional worklist** (analyzer queries the LIS for which tests to run, then sends back results) and **unidirectional result upload** (analyzer only sends results).

The pattern documented here is **already running in production** for the Urit BH-5390 hematology analyzer and is structured so the **same skeleton** can be reused for any other HL7 v2.x analyzer (Snibe, Sysmex, Mindray, Beckman, Roche, Abbott) by:

1. Cloning the channel
2. Changing the MLLP port
3. Loading the new analyzer's `machineConfig` JSON

**No structural code changes required.** The router, the OML^O21 builder, the OBX parser, and the destination HTTP POST remain identical. Analyzer-specific knowledge lives in two places:

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
3. The user clicks "Generate Worklist" — `WorklistService::createWorklistForGroup()` inserts one `Worklist` row per (tube, machine, test) tuple

### 2.2 Unidirectional vs Bidirectional

| Aspect | Unidirectional | Bidirectional |
|---|---|---|
| Analyzer asks LIS for tests? | No | Yes (QRY^Q02) |
| Analyzer sends results? | Yes (ORU^R01) | Yes (ORU^R01) |
| LIS sends worklist reply? | No | Yes (OML^O21) |
| Operator must key tests into analyzer? | Yes | No — fully automatic |
| Laravel `machines.direction` value | `'unidirectional'` | `'bidirectional'` |

### 2.3 HL7 Messages Used

| Direction | Message | Trigger | Sender | Receiver |
|---|---|---|---|---|
| Analyzer → LIS | `QRY^Q02` | Q02 | Analyzer | Mirth (Source) |
| LIS → Analyzer | `OML^O21` | O21 | Mirth (responseMap) | Analyzer |
| Analyzer → LIS | `ORU^R01` | R01 | Analyzer | Mirth (Source) |
| LIS → Analyzer | `ACK^*` | Application ACK | Mirth (Source ACK) | Analyzer |

---

## 3. Architecture Overview

### 3.1 High-Level Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                              MIRTH CONNECT                               │
│                                                                         │
│   ┌─────────────────────────────┐    ┌─────────────────────────────┐    │
│   │  Channel: urit_bh5390_bi    │    │  Channel: snibe_xxx_bi      │    │
│   │  (MLLP Listener :5700)      │    │  (MLLP Listener :5701)      │    │
│   │  Source Transformer (JS):    │    │                              │    │
│   │   ├ Router: QRY/ORU         │    │                              │    │
│   │   ├ Mode A: Worklist→OML    │    │                              │    │
│   │   └ Mode B: Results→JSON    │    │                              │    │
│   │           │                  │    │                              │    │
│   │      channelMap + responseMap│   │                              │    │
│   │           ▼                  │    │                              │    │
│   │  Destination: HTTP Sender   │    │                              │    │
│   │  POST /mirth/legacy-result   │    │                              │    │
│   └─────────────────────────────┘    └─────────────────────────────┘    │
└──────────────────┬──────────────────────────────────┬──────────────────┘
                   │ HTTPS                             │ HTTPS
                   │ (X-Machine-Key header)            │
                   ▼                                   ▼
┌──────────────────────────────────────────────────────────────────────────┐
│                              LARAVEL LIS                                 │
│                                                                          │
│  MirthController (/api/mirth/*):                                        │
│    GET  /api/mirth/worklist            → bulk pull (sets pushed_at)      │
│    POST /api/mirth/acknowledge          → mark processing                 │
│    POST /api/mirth/results              → single result (idempotent)      │
│    POST /api/mirth/submit-batch         → batch results                   │
│    POST /mirth/legacy-result            → legacy Urit BH-5390 path        │
│    GET  /api/mirth/health               → health check                    │
│    GET  /api/mirth/failed-results      → DLQ poll                        │
│    POST /api/mirth/retry-failed/{id}    → DLQ requeue                    │
│  WorklistController:                                                     │
│    GET  /api/get-worklist?barcode=&api= → single-tube worklist (Mirth A) │
│  MachineController:                                                      │
│    GET  /lab/machines/{id}/export-config → machineConfig JSON            │
│                                                                          │
│  MySQL: machines · machine_test_mappings · worklists · lab_receipts ·    │
│         lab_tests · test_samples · test_sample_items · test_components · │
│         test_reports · lis_results · failed_lis_results · legacy_result_temp │
└──────────────────────────────────────────────────────────────────────────┘

        ┌───────────────────────┐         ┌───────────────────────┐
        │  Urit BH-5390 (CBC)    │         │  Snibe (Chemistry)    │
        │  TCP/MLLP :5700        │         │  TCP/MLLP :5701        │
        └───────────────────────┘         └───────────────────────┘
```

### 3.2 Data Flow — Bidirectional (Urit BH-5390)

```
1. Tech scans tube barcode 2608281001-F into Urit BH-5390
2. Analyzer opens TCP/MLLP to Mirth port 5700, sends QRY^Q02
   MSH|^~\&|BH5390|URIT|LIS|LAB|20261114103000||QRY^Q02|Q001|P|2.3.1
   QRD|20261114103000|R|I|||||2608281001-F
3. Mirth Source Transformer detects QRY^Q02 → mode = WORKLIST_QUERY
   Extracts barcode from QRD.8.1
4. Mirth GET https://lab.htncr.org/api/get-worklist?barcode=2608281001-F&api=KEY
5. Laravel responds JSON:
   { patient_id, patient_name, tests: [{ test_id, test_name }] }
6. Mirth builds OML^O21 reply:
   MSH|^~\&|LIS|LAB|ANALYZER|MODEL|20261114103001||OML^O21|OML001|P|2.3.1
   MSA|AA|Q001||Worklist sent successfully
   ORC|NW|2608281001-F||||CM
   OBR|1||2608281001-F|||F|||P0001|JOHN DOE
   OBX|1|NM|CBC^Complete Blood Count||||||F
7. Mirth returns OML via responseMap.put('Auto-generate (After source transformer)', oml)
   Mirth wraps with MLLP framing (0x0B … 0x1C 0x0D) and writes to same socket
8. Analyzer runs CBC, generates results
9. Analyzer sends ORU^R01 to Mirth port 5700
10. Mirth Source Transformer detects ORU^R01 → mode = RESULT_UPLOAD
    Extracts barcode from OBR.2/OBR.3
    Parses OBX segments → rawValues = {WBC:6.2, RBC:4.5, HGB:13.8, ...}
    Applies machineConfig.functions (round, convert_unit, format) — values cast to String
    Computes EOS# = (EOS% / 100) × WBC if missing
    Builds JSON payload { machine_id, api_key, barcode, results:[...] }
11. Mirth Destination: HTTP POST https://admcr.advmedi.com/mirth/legacy-result
12. Laravel MirthController::legacyResult:
    → Raw payload stored in legacy_result_temp (audit)
    → Each code → TestComponent.id via mapping JSON
    → TestReport::updateOrCreate([model_id=labTest.id, model_type=LabTest, component_id], [...])
    → completeWorklists() (v3.0 fix): matching worklists → completed, LabTest.has_result=true,
      TestSampleItem.status='completed'
    → Returns {type:'success', note:'…', status:200}
13. TestReport rows visible in Laravel admin → report generated → printable
```

### 3.3 Data Flow — Unidirectional

Steps 1–9 skipped. Analyzer just sends ORU^R01 (step 10); the rest is identical.

---

## 4. The Two Communication Styles

### 4.1 Style A — Query / Response (Bidirectional)

```
Analyzer ──QRY^Q02──▶ Mirth ──HTTP GET──▶ Laravel
                                              │
Analyzer ◀──OML^O21── Mirth ◀──JSON response──┘
```

### 4.2 Style B — Push (Unidirectional)

```
Analyzer ──ORU^R01──▶ Mirth ──HTTP POST──▶ Laravel
```

### 4.3 Style C — Pull (LIS polls Mirth local DB)

Used by some Snibe setups. Out of scope for this guide; mentioned only for context.

---

# PART II — Multi-Analyzer Strategy

## 5. Why One Channel Per Analyzer

Each analyzer speaks HL7 with subtle dialect differences:

- Different MLLP framing variants
- Different field separator / encoding characters
- Different `MSH.9` shapes (`QRY^Q02`, `QBP^Q11`, `TSREQ`)
- Different OBX-3 conventions (code in `OBX.3.1` vs `OBX.3.2`)
- Different ACK expectations

A single mega-channel becomes unmaintainable. Clean pattern: **one channel per physical analyzer**, unique MLLP port, own `machineConfig`.

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

## 7. Dynamic Machine Config Pattern

### 7.1 The Problem with Hardcoded Configs

Each analyzer has different OBX codes, reference ranges, unit conventions, decimal places, and transformation functions. Hardcoding in JS works (your current pattern) but every change requires a Mirth channel redeploy.

### 7.2 The Dynamic Pattern

`machineConfig` is stored in the Laravel `machine_test_mappings.parameters` JSON column. Laravel exposes it via `GET /lab/machines/{machine_id}/export-config`:

```json
{
  "machine_id": "0194...",
  "api_key": "abc123...",
  "directional": "bidirectional",
  "sample_type": "serum",
  "mapping": { "WBC": ["<component-uuid>"], "HGB": ["<component-uuid>"] },
  "components": [
    { "name": "White Blood Cell Count", "code": "WBC", "unit": "10^9/L", "ref_range": "4.0-10.0",
      "order": 1, "functions": [{ "name": "round", "params": { "decimal": 2 } }] }
  ]
}
```

> ⚠️ This route requires a web session (admin login). Mirth cannot call it with `?api=`. To use a dynamic loader you must first add a token-authenticated export route. Until then, **use the embedded pattern** (paste the JSON into the source transformer).

### 7.3 When to Embed vs Fetch

| Situation | Strategy |
|---|---|
| Single analyzer, stable config | Embed `machineConfig` directly (your current pattern — keep it) |
| Multiple analyzers, frequent changes | Fetch dynamically + cache in `globalMap` for 10 min (**needs token-auth route first**) |
| Network reliability concerns | Hybrid — fetch dynamic, fall back to embedded |

---

# PART III — Mirth Connect Setup

## 8. Prerequisites

### 8.1 Software Stack

| Component | Version | Notes |
|---|---|---|
| Java JDK | 8 or 11 | Mirth 4.x requires Java 8+ |
| Mirth Connect / NextGen / BridgeLink | 4.1.x or 4.4.x | |
| Mirth internal DB | PostgreSQL 12+ recommended | Don't reuse the LIS MySQL instance |
| OS | Linux x86_64 (Ubuntu 22.04 LTS preferred) | |
| Laravel | 10.x / 11.x | Already in production |

### 8.2 Network

- Mirth server must reach Laravel HTTPS on port 443
- Analyzers must reach Mirth on their assigned MLLP ports (5700, 5701, …)
- Firewall: inbound TCP per analyzer port, outbound HTTPS to Laravel
- `lab.htncr.org` / `admcr.advmedi.com` are behind Cloudflare with public CAs — **no truststore needed** for them

### 8.3 Lab-side Configuration

For each analyzer: set LIS-IP to Mirth server IP, LIS-Port to channel MLLP port, protocol = HL7 v2.3.1 (match `machines.protocol_version`), mode = bidirectional/unidirectional (match `machines.direction`).

### 8.4 TLS Truststore (only for self-signed / internal CA)

**Skip for `lab.htncr.org` and `admcr.advmedi.com`** — public CAs. Never disable hostname verification on internet-facing hosts.

For internal hosts:

```bash
openssl s_client -connect <host>:443 -showcerts </dev/null 2>/dev/null \
  | openssl x509 -outform PEM > /tmp/host.pem
keytool -import -alias <host> -file /tmp/host.pem \
  -keystore /opt/mirth/conf/truststore.jks \
  -storepass '<STRONG_PASSWORD>' -noprompt
chown mirth:mirth /opt/mirth/conf/truststore.jks && chmod 600 /opt/mirth/conf/truststore.jks
```

> v1.0 hardcoded a JKS password and an always-true hostname verifier in the source transformer. **Don't do that.** Keep secrets in Mirth's Configuration Map / Settings, and trust the JVM default truststore for public CAs.

## 9. Mirth Installation Quick Reference

> Generic example — installer URLs/versions change, and BridgeLink on Windows uses different ports/paths. Follow your own installation.

```bash
sudo apt-get update && sudo apt-get install -y openjdk-11-jdk-headless
sudo useradd -r -m -d /opt/mirth -s /bin/bash mirth
cd /tmp && wget <mirth-installer-url>
sudo chmod +x mirthconnect-*.sh && sudo ./mirthconnect-*.sh     # accept defaults → /opt/mirthconnect
sudo /opt/mirthconnect/mcservice start
curl -k https://localhost:8443/api/system/information    # verify
```

Default login: `admin / admin` — **change immediately**. Configure internal DB as PostgreSQL (don't reuse the LIS MySQL).

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
| Response | None — reply via responseMap | The OML/ACK is sent via `responseMap.put('Auto-generate (After source transformer)', oml)` |
| Socket Timeout | 0 (infinite) | |

### 10.3 MLLP Settings

| Field | Value | Meaning |
|---|---|---|
| Start byte | `0x0B` (`\u000b`) | VT — start of HL7 frame |
| End byte 1 | `0x1C` (`\u001c`) | FS — end of HL7 frame |
| End byte 2 | `0x0D` (`\u000d`) | CR — terminates frame |
| Send ACK | Yes (use response) | We send OML^O21 (worklist) or simple AA ACK (results) via responseMap |

Urit BH-5390 follows HL7 v2.3.1 MLLP literally. Don't change unless the analyzer's manual says otherwise.

### 10.4 Source Transformer

Transformer type: JavaScript · Message data type: HL7 v2.3.1 (match your `protocol_version`) · Inbound template: a sample QRY^Q02. Paste the **Full Source Transformer** script from §18.

### 10.5 Filter

Leave empty.

### 10.6 Destination — JavaScript Writer

Single JavaScript Writer destination. Paste §21 destination code. JavaScript Writer (not HTTP Sender) so we can:
- Conditionally skip the POST for `WORKLIST_QUERY` (the reply was already sent via responseMap)
- Read `body.type` from Laravel (HTTP 200 ≠ success)

## 11. MLLP & ACK Settings Deep Dive

### 11.1 The MLLP Frame

```
[0x0B] MSH|...|... [0x1C][0x0D]
```

Leading `0x0B` = "message starts". Trailing `0x1C 0x0D` = "end, process now".

### 11.2 ACK Types

| Type | When | Example |
|---|---|---|
| Commit ACK (transport) | Sent automatically by Mirth after a complete frame | minimal MSA\|AA |
| Application ACK (semantic) | Sent by your Source Transformer via responseMap | OML^O21 (for QRY) or MSA\|AA (for ORU) |

For Urit BH-5390 bidirectional: analyzer expects an OML^O21 for QRY, simple `MSA|AA` for ORU.

### 11.3 How responseMap.put Works

When the Source Transformer writes `responseMap.put('Auto-generate (After source transformer)', oml)`, Mirth:

1. Uses `oml` as the Source Connector's response to the analyzer
2. Sends it over the same TCP socket the request came in on
3. Wraps it with MLLP framing bytes automatically

The string `'Auto-generate (After source transformer)'` is a special Mirth-recognized responseMap key (it appears as a dropdown choice in the Source Response settings). This is the documented bidirectional MLLP reply pattern in Mirth 3.x / 4.x — works reliably when the string matches exactly.

### 11.4 ACK for ORU (Results)

For ORU, Mirth's standard "Auto-commit" produces `MSA|AA` automatically. You only need a custom ACK for AE/AR errors.

---

# PART IV — Source Transformer (Core)

## 12. File Header & Strategy

The Source Transformer receives raw HL7 and decides:

1. **Message type** — QRY (worklist query) vs ORU (result upload)
2. **Extract fields** — barcode from QRD or OBR, OBX values
3. **Generate response** — for QRY: build OML^O21 and put in `responseMap`; for ORU: build JSON and put in `channelMap`

Single script handles both cases via an `interface_mode` variable.

## 13. Router Logic — Detect QRY vs ORU

### 13.1 Where the Message Type Lives

`MSH.9` is the Message Type field:
- `MSH.9.1` — Message Code (e.g. `QRY`, `ORU`, `ACK`)
- `MSH.9.2` — Trigger Event (e.g. `Q02`, `R01`)

Worklist query = `MSH|^~\&|...|||QRY^Q02|...`. Result upload = `MSH|^~\&|...|||ORU^R01|...`.

### 13.2 Detection Code

```javascript
var msgType    = msg['MSH']['MSH.9']['MSH.9.1'].toString() || '';
var msgTrigger = msg['MSH']['MSH.9']['MSH.9.2'].toString() || '';
logger.info('HL7 type: ' + msgType + '^' + msgTrigger);

if (msgType == 'QRY' || msgTrigger == 'Q02' || msgTrigger == 'TSREQ') {
    interface_mode = 'WORKLIST_QUERY';
}
```

### 13.3 Why `TSREQ`?

Some Urit firmware revisions send `QRY^TSREQ` instead of `QRY^Q02`. Cover both without firmware updates.

### 13.4 Default to RESULT_UPLOAD

If message type is neither QRY nor recognized trigger, assume `RESULT_UPLOAD`. Safe default — most messages are ORU; misclassifying ORU as QRY would reply with an empty worklist and break the analyzer.

## 14. Mode A — Worklist Query (QRY → OML^O21)

### 14.1 Steps

1. **Extract barcode** from `QRD.8.1`, fallback to `SPM.2.1`.
2. **HTTP GET** Laravel `/api/get-worklist?barcode=<X>&api=<KEY>`. Laravel returns JSON with patient + tests.
3. **Build OML^O21** (MSH, MSA, ORC, OBR, OBX×N).
4. **Put OML in responseMap**.
5. **Set channelMap variables** for logging/auditing.

### 14.2 Extract Barcode

```javascript
var barcode = '';
try { barcode = msg['QRD']['QRD.8']['QRD.8.1'].toString(); } catch(e) {}
if (!barcode) { try { barcode = msg['SPM']['SPM.2']['SPM.2.1'].toString(); } catch(e) {} }
logger.info('Worklist query barcode: ' + barcode);
```

try/catch is essential — accessing a missing segment throws NullPointerException in Mirth's Rhino JS engine.

### 14.3 HTTP GET to Laravel

```javascript
var apiUrl = 'https://lab.htncr.org/api/get-worklist?barcode=' +
             java.net.URLEncoder.encode(barcode, 'UTF-8') +
             '&api=<API_KEY>';

var httpsUrl = new java.net.URL(apiUrl);
var conn = httpsUrl.openConnection();
conn.setRequestMethod('GET');
conn.setConnectTimeout(10000);
conn.setReadTimeout(10000);
conn.setRequestProperty('Accept', 'application/json');

// TLS truststore setup — ONLY for self-signed/internal CA (skip for public-CA hosts)
// See §8.4 — do NOT hardcode the truststore password in the script
```

> ⚠️ **Verify `/api/get-worklist` is registered in your `routes/api.php`** before relying on it. The routes file is not in the uploaded zip, but your `lis.md` template uses this URL, so it should exist. If it does not, register it (see §34.2).

### 14.4 Parse JSON Response

```javascript
if (respCode == 200) {
    var br = new java.io.BufferedReader(
        new java.io.InputStreamReader(conn.getInputStream(), 'UTF-8'));
    var responseJson = '';
    var rline;
    while ((rline = br.readLine()) != null) { responseJson += rline; }
    br.close();
    var worklistData = JSON.parse(responseJson);
    // { patient_id, patient_name, tests: [...] }
```

### 14.5 Build OML^O21 Reply

| Segment | Purpose |
|---|---|
| MSH | Header — `OML^O21`, new control id |
| MSA | `AA` (accept) + original `MSH.10` |
| ORC | Order control `NW`, placer order = barcode |
| OBR | Observation request — specimen type + patient |
| OBX (×N) | One per test the analyzer should run |

```javascript
var msgCtrlId = msg['MSH']['MSH.10'].toString() || '';
var newCtrlId = 'OML' + DateUtil.getCurrentDate('yyyyMMddHHmmss');

var msh = 'MSH|^~\\&|LIS|LAB|ANALYZER|MODEL|' +
          DateUtil.getCurrentDate('yyyyMMddHHmmss') +
          '||OML^O21|' + newCtrlId + '|P|2.3.1';
var msa = 'MSA|AA|' + msgCtrlId + '||Worklist sent successfully';
var orc = 'ORC|NW|' + barcode + '||||CM';
var obr = 'OBR|1||' + barcode + '|||F|||' +
          (worklistData.patient_id || '') + '|' +
          (worklistData.patient_name || '');

var obxLines = '';
var testCounter = 0;
var tests = [];
try {
    if (worklistData.tests) {
        if (worklistData.tests instanceof Array) tests = worklistData.tests;
        else { for (var tk in worklistData.tests) tests.push(worklistData.tests[tk]); }
    } else if (worklistData.test_id) {
        tests = [{ test_id: worklistData.test_id, test_name: worklistData.test_name || '' }];
    }
} catch(e) { logger.warn('Error parsing tests: ' + e); }

for each (var t in tests) {
    testCounter++;
    var tName = ''; var tId = '';
    try { tName = t.test_name || t.name || ''; } catch(e) {}
    try { tId = t.test_id || t.id || ''; } catch(e) {}
    obxLines += '\r' + 'OBX|' + testCounter + '|NM|' + tId + '^' + tName + '||||||F';
}
if (testCounter === 0) {
    obxLines = '\r' + 'OBX|1|NM|NO_TEST^No Test Ordered||||||F';
}

omlResponse = msh + '\r' + msa + '\r' + orc + '\r' + obr + obxLines;
logger.info('OML^O21 built with ' + testCounter + ' tests');
```

> ⚠️ **OML^O21 vs DSR^Q03:** both are valid HL7 responses to `QRY^Q02`. Your existing template uses OML^O21 and it works. Switch to DSR^Q03 only if the Urit BH-5390 manual specifically requires it.

### 14.6 Error Path (MSA|AE)

```javascript
} else {
    var errStream = conn.getErrorStream();
    var errMsg = '';
    if (errStream) {
        var ebr = new java.io.BufferedReader(new java.io.InputStreamReader(errStream));
        var el;
        while ((el = ebr.readLine()) != null) { errMsg += el; }
        ebr.close();
    }
    logger.error('Worklist API error ' + respCode + ': ' + errMsg);

    var mcid = msg['MSH']['MSH.10'].toString() || '';
    omlResponse = 'MSH|^~\\&|LIS|LAB|ANALYZER|MODEL|' +
        DateUtil.getCurrentDate('yyyyMMddHHmmss') +
        '||OML^O21|OML' + DateUtil.getCurrentDate('yyyyMMddHHmmss') + '|P|2.3.1\r' +
        'MSA|AE|' + mcid + '||Worklist query failed: API ' + respCode;
}
```

`MSA|AE` tells the analyzer "error in processing" — most analyzers retry after a delay.

### 14.7 Put Reply into responseMap

```javascript
responseMap.put('Auto-generate (After source transformer)', omlResponse);
channelMap.put('barcode', barcode);
channelMap.put('device_model', 'ANALYZER');
channelMap.put('date', '');
channelMap.put('data', '[]');
```

## 15. Mode B — Result Upload (ORU → JSON)

### 15.1 Steps

1. Load `machineConfig`.
2. Extract common fields: barcode (`OBR.2` or `OBR.3`), date (`OBR.7`), device model (`MSH.4`).
3. Iterate every OBX — skip non-numeric (PNG images, mode flags, references); extract OBX-3 code from `OBX.3.2`; extract value from `OBX.5.1`; strip leading `*`; parse to float.
4. Apply machine config functions (round, convert_unit, format).
5. Compute derived values (EOS# = EOS%/100 × WBC) if missing.
6. Build JSON payload, stash in `channelMap.put('output', ...)`.

### 15.2 Extract Common Fields

```javascript
var barcode = msg['OBR']['OBR.2']['OBR.2.1'].toString() ||
              msg['OBR']['OBR.3']['OBR.3.1'].toString() || null;
var date = msg['OBR']['OBR.7']['OBR.7.1'].toString() || null;
var deviceModel = msg['MSH']['MSH.4']['MSH.4.1'].toString() || 'ANALYZER';
```

**OBR.2 vs OBR.3**: Some analyzers put the barcode in OBR.2 (Placer), some in OBR.3 (Filler). Try OBR.2 first; fall back to OBR.3.

### 15.3 Parse OBX Segments

```javascript
var rawValues = {};
if (msg['OBX']) {
    for each (var obx in msg['OBX']) {
        var code = obx['OBX.3']['OBX.3.2'].toString() || null;
        if (!code) continue;
        if (code.indexOf('.PNG') !== -1) continue;          // skip graph images
        if (['MODE','MODE_EX','Ref','Note'].indexOf(code) !== -1) continue;
        var raw = obx['OBX.5']['OBX.5.1'].toString() || null;
        if (raw === null) continue;
        raw = raw.replace(/^\*/, '');   // strip leading * (abnormal flag prefix)
        var num = parseFloat(raw);
        if (!isNaN(num)) rawValues[code] = num;
    }
}
```

Skip rules:

| Skip pattern | Reason |
|---|---|
| `code.indexOf('.PNG') !== -1` | Urit/Sysmex send base64 graph images as OBX (e.g. `WBC.PNG`) |
| `['MODE','MODE_EX','Ref','Note']` | Mode indicators / reference text |
| `raw === null` | Empty value |
| `raw.replace(/^\*/, '')` | Urit prefixes abnormal values with `*` |

> ⚠️ **OBX.3 convention:** your parser reads `OBX.3.2`. If your analyzer puts the code in `OBX.3.1`, change one line:
> ```javascript
> var code = obx['OBX.3']['OBX.3.1'].toString() || obx['OBX.3']['OBX.3.2'].toString() || null;
> ```

### 15.4 Apply Machine Config Functions (v3 fix: cast to String)

The `machineConfig.components[]` describes per-component transformations:

```json
{
  "code": "WBC",
  "name": "White Blood Cell Count",
  "unit": "10^9/L",
  "ref_range": "4.0-10.0",
  "functions": [ { "name": "round", "params": { "decimal": 2 } } ]
}
```

Supported functions:

| Function | Params | Effect |
|---|---|---|
| `convert_unit` | `{ "factor": 0.001 }` | Multiply raw value by factor |
| `round` | `{ "decimal": 2 }` | Round to N decimals |
| `format` | `{ "type": "comma" }` | Thousands separator (e.g. `1234.5` → `1,234.5`) |

**Implementation** (v3 fix: returns `String`, not number — Laravel validates `results.*.value` as `string` and rejects a JSON number as `invalid`):

```javascript
function applyFunctions(value, functions) {
    if (typeof value !== 'number') return String(value);
    var result = value;
    for each (var fn in functions) {
        switch (fn.name) {
            case 'convert_unit':
                result = result * (fn.params.factor || 1);
                break;
            case 'round':
                result = result.toFixed(fn.params.decimal || 0);
                break;
            case 'format':
                if (fn.params.type === 'comma') {
                    var parts = result.toString().split('.');
                    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                    result = parts.length > 1 ? parts[0] + '.' + parts[1] : parts[0];
                }
                break;
        }
    }
    return String(result);   // ← CRITICAL: Laravel validates results.*.value as string
}
```

### 15.5 Compute Derived Values (EOS#)

Urit BH-5390 reports `EOS%` and `WBC` but not `EOS#`. Formula: `EOS# = (EOS% / 100) × WBC`.

```javascript
if (rawValues['EOS#'] === undefined &&
    rawValues['WBC'] !== undefined &&
    rawValues['EOS%'] !== undefined) {
    rawValues['EOS#'] = (rawValues['EOS%'] / 100) * rawValues['WBC'];
}
```

### 15.6 Build the JSON Payload (v3 fix: value as String)

```javascript
var results = [];
for each (var compDef in machineConfig.components) {
    var code = compDef.code;
    if (!code || rawValues[code] === undefined) continue;
    var formatted = applyFunctions(rawValues[code], compDef.functions);
    results.push({
        code: code,
        name: compDef.name,
        value: formatted,                          // String — Laravel validates as string
        unit: compDef.unit || ''
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

> ⚠️ **Optional and not usable as-is.** The export route requires an admin web session, so Mirth cannot fetch it with `?api=`. Add a token-authenticated export route first. Sketch below shows the intended pattern.

```javascript
function loadMachineConfig(machineId, apiKey) {
    // 1. Try cache
    var cacheKey = 'machineConfig_' + machineId;
    var cache = globalMap.get(cacheKey);
    var now = Date.now();
    if (cache && (now - cache.ts) < 600000) {  // 10-min TTL
        return cache.value;
    }
    // 2. Try fetch (needs token-auth route — not in upload)
    try {
        var url = 'https://lab.htncr.org/api/machines/' + machineId + '/export-config';
        // ... HTTP GET with X-Machine-Key + api_key ...
        var cfg = JSON.parse(body);
        globalMap.put(cacheKey, { ts: now, value: cfg });
        return cfg;
    } catch (e) { logger.warn('Fallback to embedded: ' + e); }
    // 3. Fall back to embedded
    return EMBEDDED_MACHINE_CONFIG;
}
```

To enable: rename your existing `machineConfig` to `EMBEDDED_MACHINE_CONFIG`, then add `var machineConfig = loadMachineConfig('<machine_id>');` at the top of Mode B.

## 17. Helper Functions Reference

### 17.1 applyFunctions — see §15.4

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

Paste into the Source Transformer step. Replace `machineConfig` with the JSON exported from `GET /lab/machines/<id>/export-config`. Placeholder UUIDs (`<component-uuid>`) **must** be replaced with real `TestComponent.id` values or Laravel cannot map codes.

```javascript
// ================================================================
//  BIDIRECTIONAL ROUTER + TRANSFORMER  —  Urit BH-5390 (CBC)
//  Handles: QRY^Q02 (worklist query) and ORU^R01 (result upload)
//  Pattern: Bidirectional MLLP → Laravel HTTPS API
// ================================================================

var interface_mode = 'RESULT_UPLOAD';

try {
    // ---------- Detect message type ----------
    var msgType    = msg['MSH']['MSH.9']['MSH.9.1'].toString() || '';
    var msgTrigger = msg['MSH']['MSH.9']['MSH.9.2'].toString() || '';
    logger.info('HL7 type: ' + msgType + '^' + msgTrigger);

    if (msgType == 'QRY' || msgTrigger == 'Q02' || msgTrigger == 'TSREQ') {
        interface_mode = 'WORKLIST_QUERY';
    }
} catch (e) { logger.error('Router error: ' + e); }

channelMap.put('interface_mode', interface_mode);

// ================================================================
//  MODE A: WORKLIST QUERY
// ================================================================
if (interface_mode === 'WORKLIST_QUERY') {
    try {
        // 1. Extract barcode
        var barcode = '';
        try { barcode = msg['QRD']['QRD.8']['QRD.8.1'].toString(); } catch(e) {}
        if (!barcode) { try { barcode = msg['SPM']['SPM.2']['SPM.2.1'].toString(); } catch(e) {} }

        logger.info('Worklist query barcode: ' + barcode);

        // 2. Call Laravel worklist API
        var apiUrl = 'https://lab.htncr.org/api/get-worklist?barcode=' +
                     java.net.URLEncoder.encode(barcode, 'UTF-8') +
                     '&api=<API_KEY>';     // ← put your real API key here

        var httpsUrl = new java.net.URL(apiUrl);
        var conn = httpsUrl.openConnection();
        conn.setRequestMethod('GET');
        conn.setConnectTimeout(10000);
        conn.setReadTimeout(10000);
        conn.setRequestProperty('Accept', 'application/json');

        // TLS truststore setup — ONLY for self-signed/internal CA
        // For public-CA hosts (lab.htncr.org) skip this block entirely
        // If needed, set JVM truststore via -Djavax.net.ssl.trustStore=...
        // instead of hardcoding password here

        var respCode = conn.getResponseCode();
        logger.info('Worklist API response code: ' + respCode);

        var omlResponse = '';

        if (respCode == 200) {
            var br = new java.io.BufferedReader(
                new java.io.InputStreamReader(conn.getInputStream(), 'UTF-8'));
            var responseJson = '';
            var rline;
            while ((rline = br.readLine()) != null) { responseJson += rline; }
            br.close();
            logger.info('Worklist API response: ' + responseJson);

            var worklistData = JSON.parse(responseJson);

            // 3. Build OML^O21 reply
            var msgCtrlId = msg['MSH']['MSH.10'].toString() || '';
            var newCtrlId = 'OML' + DateUtil.getCurrentDate('yyyyMMddHHmmss');

            var msh = 'MSH|^~\\&|LIS|LAB|ANALYZER|MODEL|' +
                      DateUtil.getCurrentDate('yyyyMMddHHmmss') +
                      '||OML^O21|' + newCtrlId + '|P|2.3.1';
            var msa = 'MSA|AA|' + msgCtrlId + '||Worklist sent successfully';
            var orc = 'ORC|NW|' + barcode + '||||CM';
            var obr = 'OBR|1||' + barcode + '|||F|||' +
                      (worklistData.patient_id || '') + '|' +
                      (worklistData.patient_name || '');

            var obxLines = '';
            var testCounter = 0;
            var tests = [];
            try {
                if (worklistData.tests) {
                    if (worklistData.tests instanceof Array) tests = worklistData.tests;
                    else { for (var tk in worklistData.tests) tests.push(worklistData.tests[tk]); }
                } else if (worklistData.test_id) {
                    tests = [{ test_id: worklistData.test_id, test_name: worklistData.test_name || '' }];
                }
            } catch(e) { logger.warn('Error parsing tests: ' + e); }

            for each (var t in tests) {
                testCounter++;
                var tName = ''; var tId = '';
                try { tName = t.test_name || t.name || ''; } catch(e) {}
                try { tId = t.test_id || t.id || ''; } catch(e) {}
                obxLines += '\r' + 'OBX|' + testCounter + '|NM|' + tId + '^' + tName + '||||||F';
            }
            if (testCounter === 0) {
                obxLines = '\r' + 'OBX|1|NM|NO_TEST^No Test Ordered||||||F';
            }

            omlResponse = msh + '\r' + msa + '\r' + orc + '\r' + obr + obxLines;
            logger.info('OML^O21 built with ' + testCounter + ' tests');
        } else {
            // Error response
            var errStream = conn.getErrorStream();
            var errMsg = '';
            if (errStream) {
                var ebr = new java.io.BufferedReader(new java.io.InputStreamReader(errStream));
                var el; while ((el = ebr.readLine()) != null) { errMsg += el; }
                ebr.close();
            }
            logger.error('Worklist API error ' + respCode + ': ' + errMsg);

            var mcid = msg['MSH']['MSH.10'].toString() || '';
            omlResponse = 'MSH|^~\\&|LIS|LAB|ANALYZER|MODEL|' +
                DateUtil.getCurrentDate('yyyyMMddHHmmss') +
                '||OML^O21|OML' + DateUtil.getCurrentDate('yyyyMMddHHmmss') + '|P|2.3.1\r' +
                'MSA|AE|' + mcid + '||Worklist query failed: API ' + respCode;
        }

        conn.disconnect();

        // 4. Send reply back to analyzer via responseMap
        responseMap.put('Auto-generate (After source transformer)', omlResponse);
        channelMap.put('barcode', barcode);
        channelMap.put('device_model', 'ANALYZER');
        channelMap.put('date', '');
        channelMap.put('data', '[]');

    } catch (apiErr) {
        logger.error('Worklist error: ' + apiErr);
        var mcid = '';
        try { mcid = msg['MSH']['MSH.10'].toString(); } catch(e) {}
        var errorReply = 'MSH|^~\\&|LIS|LAB|ANALYZER|MODEL|' +
            DateUtil.getCurrentDate('yyyyMMddHHmmss') +
            '||OML^O21|OML' + DateUtil.getCurrentDate('yyyyMMddHHmmss') + '|P|2.3.1\r' +
            'MSA|AE|' + mcid + '||Worklist error: ' + apiErr.message;
        responseMap.put('Auto-generate (After source transformer)', errorReply);
    }
}

// ================================================================
//  MODE B: RESULT UPLOAD
// ================================================================
if (interface_mode === 'RESULT_UPLOAD') {
    try {
        // ---------- Machine Configuration ----------
        //  ↓↓↓ Replace with JSON from GET /lab/machines/<id>/export-config
        //  Note: until the §33 fix is applied, the export wrongly says
        //  "directional":"unidirectional" — set it by hand to "bidirectional"
        var machineConfig = {
            "machine_id": "YOUR_MACHINE_ID",
            "api_key": "YOUR_API_KEY",
            "directional": "bidirectional",
            "sample_type": "serum",
            "mapping": {
                "WBC": ["<component-uuid>"], "RBC": ["<component-uuid>"],
                "HGB": ["<component-uuid>"], "HCT": ["<component-uuid>"],
                "MCV": ["<component-uuid>"], "MCH": ["<component-uuid>"],
                "MCHC": ["<component-uuid>"], "PLT": ["<component-uuid>"],
                "LYM%": ["<component-uuid>"], "LYM#": ["<component-uuid>"],
                "MON%": ["<component-uuid>"], "MON#": ["<component-uuid>"],
                "NEU%": ["<component-uuid>"], "NEU#": ["<component-uuid>"],
                "EOS%": ["<component-uuid>"], "EOS#": ["<component-uuid>"],
                "BAS%": ["<component-uuid>"], "BAS#": ["<component-uuid>"]
            },
            "components": [
                { "name": "White Blood Cell Count", "code": "WBC",  "unit": "10^9/L",  "ref_range": "4.0-10.0",  "order": 1,  "functions": [{"name":"round","params":{"decimal":2}}] },
                { "name": "Red Blood Cell Count",   "code": "RBC",  "unit": "10^12/L", "ref_range": "4.0-5.5",   "order": 2,  "functions": [{"name":"round","params":{"decimal":2}}] },
                { "name": "Hemoglobin",             "code": "HGB",  "unit": "g/dL",    "ref_range": "12.0-16.0", "order": 3,  "functions": [{"name":"round","params":{"decimal":1}}] },
                { "name": "Hematocrit",             "code": "HCT",  "unit": "%",       "ref_range": "37-50",     "order": 4,  "functions": [{"name":"round","params":{"decimal":1}}] },
                { "name": "MCV",                    "code": "MCV",  "unit": "fL",      "ref_range": "80-100",    "order": 5,  "functions": [{"name":"round","params":{"decimal":1}}] },
                { "name": "MCH",                    "code": "MCH",  "unit": "pg",      "ref_range": "27-33",     "order": 6,  "functions": [{"name":"round","params":{"decimal":1}}] },
                { "name": "MCHC",                   "code": "MCHC", "unit": "g/dL",    "ref_range": "32-36",    "order": 7,  "functions": [{"name":"round","params":{"decimal":1}}] },
                { "name": "Platelet Count",         "code": "PLT",  "unit": "10^9/L",  "ref_range": "150-400",  "order": 8,  "functions": [{"name":"round","params":{"decimal":0}}] },
                { "name": "Lymphocyte %",           "code": "LYM%", "unit": "%",       "ref_range": "20-40",     "order": 9,  "functions": [{"name":"round","params":{"decimal":1}}] },
                { "name": "Lymphocyte #",           "code": "LYM#", "unit": "10^9/L",  "ref_range": "1.0-3.0",   "order": 10, "functions": [{"name":"round","params":{"decimal":2}}] },
                { "name": "Monocyte %",             "code": "MON%", "unit": "%",       "ref_range": "3-10",      "order": 11, "functions": [{"name":"round","params":{"decimal":1}}] },
                { "name": "Monocyte #",             "code": "MON#", "unit": "10^9/L",  "ref_range": "0.1-0.6",   "order": 12, "functions": [{"name":"round","params":{"decimal":2}}] },
                { "name": "Neutrophil %",           "code": "NEU%", "unit": "%",       "ref_range": "45-70",     "order": 13, "functions": [{"name":"round","params":{"decimal":1}}] },
                { "name": "Neutrophil #",           "code": "NEU#", "unit": "10^9/L",  "ref_range": "2.0-7.0",   "order": 14, "functions": [{"name":"round","params":{"decimal":2}}] },
                { "name": "Eosinophil %",           "code": "EOS%", "unit": "%",       "ref_range": "0.5-5",     "order": 15, "functions": [{"name":"round","params":{"decimal":1}}] },
                { "name": "Eosinophil #",           "code": "EOS#", "unit": "10^9/L",  "ref_range": "0.02-0.5",  "order": 16, "functions": [{"name":"round","params":{"decimal":2}}] },
                { "name": "Basophil %",             "code": "BAS%", "unit": "%",       "ref_range": "0-1",       "order": 17, "functions": [{"name":"round","params":{"decimal":1}}] },
                { "name": "Basophil #",             "code": "BAS#", "unit": "10^9/L",  "ref_range": "0-0.1",     "order": 18, "functions": [{"name":"round","params":{"decimal":2}}] }
            ]
        };

        // ---------- Build lookup by code ----------
        var compDefs = {};
        for each (var comp in machineConfig.components) {
            if (comp.code) compDefs[comp.code] = comp;
        }

        // ---------- Helper: apply functions ----------
        // v3 fix: returns String — Laravel validates results.*.value as string
        function applyFunctions(value, functions) {
            if (typeof value !== 'number') return String(value);
            var result = value;
            for each (var fn in functions) {
                switch (fn.name) {
                    case 'convert_unit':
                        result = result * (fn.params.factor || 1);
                        break;
                    case 'round':
                        result = result.toFixed(fn.params.decimal || 0);
                        break;
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

        // ---------- Extract common fields ----------
        var barcode = msg['OBR']['OBR.2']['OBR.2.1'].toString() ||
                      msg['OBR']['OBR.3']['OBR.3.1'].toString() || null;
        var date = msg['OBR']['OBR.7']['OBR.7.1'].toString() || null;
        var deviceModel = msg['MSH']['MSH.4']['MSH.4.1'].toString() || 'ANALYZER';

        // ---------- Parse OBX ----------
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

        // ---------- Compute EOS# if missing ----------
        if (rawValues['EOS#'] === undefined &&
            rawValues['WBC'] !== undefined &&
            rawValues['EOS%'] !== undefined) {
            rawValues['EOS#'] = (rawValues['EOS%'] / 100) * rawValues['WBC'];
        }

        // ---------- Build results (value as String) ----------
        var results = [];
        for each (var compDef in machineConfig.components) {
            var code = compDef.code;
            if (!code || rawValues[code] === undefined) continue;
            var formatted = applyFunctions(rawValues[code], compDef.functions);
            results.push({
                code: code,
                name: compDef.name,
                value: formatted,                // String — Laravel validates as string
                unit: compDef.unit || ''
            });
        }

        // ---------- Build payload ----------
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

Single JavaScript Writer destination:

1. Check `interface_mode` — only act on `RESULT_UPLOAD`.
2. For `WORKLIST_QUERY`, do nothing — reply already sent via `responseMap`.
3. For `RESULT_UPLOAD`, retrieve JSON from `channelMap.get('output')`, HTTP POST to Laravel.
4. **v3 fix:** Read `body.type` from Laravel response — HTTP 2xx ≠ success because Laravel returns 200 even on failure.

## 20. HTTP POST to Laravel

Apache Commons HttpClient (bundled with Mirth). Endpoint: `POST https://admcr.advmedi.com/mirth/legacy-result`.

Headers: `Content-Type: application/json; charset=UTF-8` · `Accept: application/json` · `User-Agent: AdvMedi-MirthConnector/1.0`.

**v3 fix — outcome handling:**

| Result | Mirth status | Meaning |
|---|---|---|
| HTTP ≥ 500 or 429 | ERROR (or QUEUED if Mirth queue enabled) | transient → Mirth retries |
| HTTP 2xx **and** `body.type == 'success'` | SENT | done |
| anything else (`type: fail / invalid`, 4xx) | ERROR | data problem — fix and Reprocess from dashboard |

## 21. Full Destination Code

```javascript
// ================================================================
//  DESTINATION — sends result payload to Laravel
//  (Skipped for WORKLIST_QUERY — that reply went via responseMap)
// ================================================================

var interface_mode = channelMap.get('interface_mode') || 'RESULT_UPLOAD';

if (interface_mode === 'RESULT_UPLOAD') {
    try {
        var url = "https://admcr.advmedi.com/mirth/legacy-result";
        var client = new org.apache.commons.httpclient.HttpClient();
        var method = new org.apache.commons.httpclient.methods.PostMethod(url);

        var payload = channelMap.get('output');
        if (!payload) {
            logger.error("No output payload in channelMap.");
            return;
        }

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

            // v3 fix: Laravel returns HTTP 200 even on failure → real verdict is body.type
            var type = '';
            try { type = JSON.parse(response).type; } catch (e) {}

            if (statusCode >= 200 && statusCode < 300 && type === 'success') {
                logger.info("Upload OK. Barcode: " + barcode + " Body: " + response);
            } else if (statusCode >= 500 || statusCode == 429) {
                // Transient — log; if queue enabled, Mirth retries automatically
                logger.error("Upload TRANSIENT (will retry). Status: " + statusCode +
                             " Barcode: " + barcode + " Body: " + response);
            } else {
                // 4xx or type=fail/invalid — data problem, won't fix by retrying
                logger.error("Upload FAILED (data issue). Status: " + statusCode +
                             " Type: " + type +
                             " Barcode: " + barcode + " Body: " + response);
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

### 21.1 What Happens in Laravel

1. `MirthController::legacyResult` validates JSON body (`machine_id`, `barcode`, `mapping`, `results[]`, `results.*.value` as **string**).
2. Row inserted into `legacy_result_temp` (raw audit).
3. LabReceipt found by `test_samples.barcode` (fallback `lab_receipts.barcode`).
4. For each result: `mapping[code]` → candidate `TestComponent.id` → find one whose `test_id` matches a receipt lab_test → `TestReport::updateOrCreate(...)`.
5. **v3 fix:** `completeWorklists()` — mark matching worklists as `completed`, set `LabTest.has_result=true`, `TestSampleItem.status='completed'` (see §34.3).
6. Returns `{type:'success'|'fail'|'invalid', note:'...', status:200|400|404}` (HTTP always 200).

### 21.2 Idempotency

`legacy-result` is idempotent in effect: `updateOrCreate` overwrites the same `TestReport` row; `completeWorklists()` only touches `pending/processing` worklists. Mirth retries (if destination queue is enabled) are harmless.

`POST /api/mirth/results` is strict-idempotent (SHA-256 hash). Writes `lis_results` + worklist only, **not** `TestReport` — use `legacy-result` for multi-component (CBC), `/api/mirth/results` for single-value.

---

# PART VI — Unidirectional Template

## 22. When to Use Unidirectional

Use when the analyzer: has no worklist query function, has its own internal sample-order entry, or sends only final results.

Laravel `machines.direction = 'unidirectional'` (or NULL). `Machine::canReceiveResults()` returns `true` **only for bidirectional** — so `POST /api/mirth/results` rejects unidirectional with 403. Unidirectional results go through `/mirth/legacy-result` which does not check direction.

## 23. Unidirectional Source Transformer

Same as bidirectional Mode B block, without the worklist query branch. Use `"directional": "unidirectional"`.

```javascript
// ================================================================
//  UNIDIRECTIONAL TRANSFORMER — Result upload only
// ================================================================

try {
    // ---------- Machine Configuration ----------
    var machineConfig = {
        "machine_id": "YOUR_MACHINE_ID",
        "api_key": "YOUR_API_KEY",
        "directional": "unidirectional",
        "sample_type": "serum",
        "mapping": {
            "GLU": ["<component-uuid>"],
            "ALT": ["<component-uuid>"],
            "AST": ["<component-uuid>"]
        },
        "components": [
            { "name": "Glucose",              "code": "GLU", "unit": "mg/dL", "ref_range": "70-100",  "order": 1, "functions": [{"name":"round","params":{"decimal":0}}] },
            { "name": "Alanine Transaminase", "code": "ALT", "unit": "U/L",  "ref_range": "7-56",   "order": 2, "functions": [{"name":"round","params":{"decimal":0}}] },
            { "name": "Aspartate Transaminase","code": "AST","unit": "U/L",  "ref_range": "10-40",  "order": 3, "functions": [{"name":"round","params":{"decimal":0}}] }
        ]
    };

    var compDefs = {};
    for each (var comp in machineConfig.components) { if (comp.code) compDefs[comp.code] = comp; }

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

Same as §21, but skip the `interface_mode` check (this channel only handles results):

```javascript
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

        // v3 fix: read body.type — HTTP 200 ≠ success
        var type = '';
        try { type = JSON.parse(response).type; } catch (e) {}

        if (statusCode >= 200 && statusCode < 300 && type === 'success') {
            logger.info("Upload OK. Barcode: " + barcode);
        } else {
            logger.error("Upload issue. Status: " + statusCode + " Type: " + type +
                         " Barcode: " + barcode + " Body: " + response);
        }
    } catch (error) {
        logger.error("Request failed for " + barcode + ": " + error);
    } finally {
        method.releaseConnection();
    }
} catch (error) {
    logger.error("Destination fatal error: " + error);
}
```

## 25. Side-by-Side Comparison

| Feature | Bidirectional | Unidirectional |
|---|---|---|
| Handles QRY/SPM | Yes | No |
| Handles ORU | Yes | Yes |
| Replies with OML^O21 | Yes (via responseMap) | No |
| Posts to `/mirth/legacy-result` | Yes (results only) | Yes |
| Requires `/api/get-worklist` endpoint | Yes | No |
| Worklist completed on result | Yes (`completeWorklists()` v3 fix) | n/a |
| Laravel `machines.direction` | `'bidirectional'` | `'unidirectional'` |
| `Machine::canPullWorklist()` | true | false (or `auto_push_enabled`) |
| `Machine::canReceiveResults()` | true | **false** |
| Typical use | Urit BH-5390, Sysmex XN, Mindray BC | Beckman AU (legacy), Roche Cobas (legacy) |

---

# PART VII — Laravel Backend

## 26. Endpoints Reference

### 26.1 Mirth-facing endpoints (`MirthController`)

| # | Method | Route | Auth | Purpose |
|---|---|---|---|---|
| 1 | POST | `/mirth/legacy-result` | `api_key` body (advisory) | Multi-component result — writes TestReport + completes worklists (v3 fix); **HTTP 200 even on failure** |
| 2 | GET | `/api/mirth/worklist` | `X-Machine-Key` header | Bulk pull — sets `pushed_at` on fetch → **not for QRY** |
| 3 | POST | `/api/mirth/acknowledge` | body | Mark `processing` (controller doesn't lock siblings) |
| 4 | POST | `/api/mirth/results` | `X-Machine-Key` | Single result, SHA-256 idempotent (bidirectional only) |
| 5 | POST | `/api/mirth/submit-batch` | `X-Machine-Key` | Batch results |
| 6 | GET | `/api/mirth/health` | optional `X-Machine-Key` | Health + counts |
| 7 | GET | `/api/mirth/failed-results` | `X-Machine-Key` | DLQ poll |
| 8 | POST | `/api/mirth/retry-failed/{id}` | `X-Machine-Key` | `retry_count + 1`, `status='pending'` |

### 26.2 Worklist endpoints (`WorklistController`)

| # | Method | Route | Auth | Purpose |
|---|---|---|---|---|
| 9 | GET | `/api/get-worklist?barcode=X&api=KEY` | `api` query string | Single-tube worklist (Mirth Mode A) — **verify this route exists in `routes/api.php`** |

### 26.3 Machine config endpoints (`MachineController`)

| # | Method | Route | Auth | Purpose |
|---|---|---|---|---|
| 10 | GET | `/lab/machines/{id}/export-config` | web session (admin) | Download `machineConfig` JSON |

> ⚠️ The `WorklistController` also has a parallel API set (`getWorklistForMirth`, `acknowledgeWorklist`, `receiveHl7Results`, `/api/worklist/*` methods) duplicating `MirthController` with different params. Use `MirthController` only and leave the duplicates unrouted to avoid two ways to do the same thing.

## 27. GET /api/get-worklist

Used by Mirth bidirectional source transformer (Mode A) when analyzer sends QRY^Q02.

### Request

```
GET /api/get-worklist?barcode=2608281001-F&api=66ffe8a2-b1b0-800a-802b-ec397f1bcec8
```

| Param | Required | Description |
|---|---|---|
| `barcode` | Yes | Tube barcode = `worklists.barcode` |
| `api` | Yes | API key (currently informational — see §34.1 for adding real auth) |

### Response (200)

```json
{
  "patient_id": "P0001",
  "patient_name": "JOHN DOE",
  "tests": [
    { "test_id": "CBC", "test_name": "Complete Blood Count" }
  ]
}
```

### Response (404)

```json
{ "error": "No worklist found for barcode 2608281001-F" }
```

> ⚠️ **The routes file is not in the uploaded zip.** If this route is not yet registered in `routes/api.php`, add it (see §34.2). If your Mirth template currently works against this URL, the route is already there.

## 28. POST /mirth/legacy-result

Used by Mirth Destination (Mode B) when analyzer sends ORU^R01.

### Request

```json
{
  "machine_id": "0194-...",
  "api_key": "abc123...",
  "barcode": "2608281001-F",
  "device_model": "BH-5390",
  "date": "20261114103500",
  "mapping": {
    "WBC": ["<TestComponent.id>"],
    "RBC": ["<TestComponent.id>"]
  },
  "results": [
    { "code": "WBC", "name": "White Blood Cell Count", "value": "6.2", "unit": "10^9/L" },
    { "code": "RBC", "name": "Red Blood Cell Count",   "value": "4.5", "unit": "10^12/L" }
  ]
}
```

> **v3 fix:** `value` must be a **string** (`"6.2"` not `6.2`). Laravel validates `results.*.value => 'required|string'` and rejects a JSON number as `invalid`.

### Response (always HTTP 200 — verdict in body)

```json
{ "type": "success", "note": "Results processed successfully", "status": 200 }
```

| `type` | Meaning |
|---|---|
| `success` | All results mapped and saved (v3: worklists also completed) |
| `fail` | LabReceipt not found for barcode (`status: 404` in body) |
| `invalid` | Validation failed — missing field or non-string `value` |

### Internal flow (`MirthController::legacyResult`)

```
1. Validate body → 'invalid' if bad (machine_id/barcode/mapping/results/value-string)
2. Normalize mapping (nested {mapping:{…}} or flat; string → [string])
3. Parse date (YmdHis or any Carbon string)
4. INSERT legacy_result_temp (raw audit: barcode, machine_id, mapping, results, processed=false)
5. Find LabReceipt: test_samples.barcode → else lab_receipts.barcode → else 'fail'
6. DB::transaction — for each result:
     mapping[code] → candidate TestComponent.id (prefer one whose test_id is on the receipt)
     LabTest for (receipt, component.test_id)
     TestReport::updateOrCreate([model_id=labTest.id, model_type=LabTest, component_id], [result, unit, …])
7. v3 fix: completeWorklists(machine_id, barcode, touched test_ids) — bidirectional only
8. legacy_result_temp.processed = true, error_log = [...]
9. Return {type:'success', note:'…', status:200}
```

## 29. POST /api/mirth/results

Newer, idempotent endpoint. **Does not write `TestReport`** — only `lis_results` and updates `Worklist.status`.

### Request

```json
{
  "barcode": "2608281001-F",
  "test_code": "CBC",
  "result_value": "6.2",
  "sample_number": 1,
  "suffix": "F",
  "unit": "10^9/L",
  "reference_range": "4.0-10.0",
  "abnormal_flag": "N",
  "raw_message": "<HL7 raw>",
  "worklist_id": "0194-..."
}
```

Header: `X-Machine-Key: BH-5390`.

### Response (200)

```json
{ "success": true, "duplicate": false, "result_id": 123 }
```

### Idempotency Hash

`sha256(json_encode([barcode, test_code, sample_number, suffix, date('Y-m-d'), machine_id]))`

> ⚠️ The date is the **day only** — a same-day re-run of the same tube is silently dropped as a duplicate. Include a run id if re-runs must be accepted.

## 30. POST /api/mirth/acknowledge

Marks a worklist as `processing`. Body: `{worklist_id, barcode}`. Response: `{success:true}`.

**Side effects** (`MirthController::acknowledgeWorklist`):
- `worklists.status = 'processing'`, `started_at = now()` (`pushed_at` untouched)
- `TestSampleItem.status` and `LabTest.lab_status` set to `processing`
- **Sibling worklists on other machines are NOT locked here.** Locking happens only in `WorklistService::acknowledgeWorklist()` → `Worklist::markAsProcessing()` (used by `/api/mirth/worklist/query` if you adopt v2's new route).
- The method does not read `X-Machine-Key` (body fields only).

## 31. GET /api/mirth/health

```
GET /api/mirth/health
X-Machine-Key: BH-5390    (optional)
```

Response: `{status:'healthy', timestamp, version:'2.1.0', machine:{...}, pending_worklists:N, processing_worklists:N, completed_today:N}`.

## 32. Failed Results & Retry

`GET /api/mirth/failed-results` returns up to 50 rows where `retry_count < max_retries` and `status = 'pending'` (max 50, response `{success, total, results[]}`).

`POST /api/mirth/retry-failed/{id}` increments `retry_count` and sets `status = 'pending'`.

> ⚠️ **The uploaded code contains no background worker** that reprocesses these rows. You must add one (or reprocess manually). `max_retries = 5` by default.

## 33. Machine Config Export Endpoint

`GET /lab/machines/0194.../export-config` (web session auth). Returns JSON:

```json
{
  "machine_id": "0194-...",
  "api_key": "abc123def456...",
  "directional": "bidirectional",
  "sample_type": "serum",
  "mapping": {
    "WBC": ["<component-uuid-1>", "<component-uuid-2>"],
    "RBC": ["<component-uuid-3>"]
  },
  "components": [
    { "name": "White Blood Cell Count", "code": "WBC", "unit": "10^9/L",
      "ref_range": "4.0-10.0", "order": 1, "functions": [] }
  ]
}
```

> ⚠️ **v3 fix (one-line):** the uploaded `MachineController::exportMachineConfig` reads `$machine->directional` — but the column is `direction`. The export therefore always says `"directional": "unidirectional"`. Fix:
>
> ```php
> - 'directional' => $machine->directional ?? 'unidirectional',
> + 'directional' => $machine->direction   ?? 'unidirectional',
> ```
>
> Until the fix is live, set `"directional": "bidirectional"` by hand in the pasted `machineConfig` JSON.

## 34. Sample Laravel Controller Snippets

### 34.1 Auth — add a real `api_key` check

`legacyResult` accepts `api_key` in the body but does not enforce it. Add at the top of the method:

```php
$machine = Machine::find($input['machine_id'] ?? null);
abort_unless(
    $machine && hash_equals((string) $machine->api_key, (string) ($input['api_key'] ?? '')),
    401
);
```

### 34.2 Register the /api/get-worklist route (if missing)

If `/api/get-worklist` is not in `routes/api.php`, add it:

```php
// routes/api.php
use App\Http\Controllers\Lab\WorklistController;
Route::get('/get-worklist', [WorklistController::class, 'getWorklistByBarcodeApi']);
```

Controller method:

```php
// WorklistController.php
public function getWorklistByBarcodeApi(Request $request)
{
    $request->validate(['barcode' => 'required|string']);
    $barcode = $request->query('barcode');

    $worklists = Worklist::with(['labReceipt.patient', 'test'])
        ->where('barcode', $barcode)
        ->whereIn('status', ['pending', 'processing'])
        ->orderBy('priority', 'desc')
        ->get();

    if ($worklists->isEmpty()) {
        return response()->json(['error' => 'No worklist found for barcode ' . $barcode], 404);
    }

    $first = $worklists->first();
    $patient = $first->labReceipt?->patient;

    return response()->json([
        'patient_id'   => $patient?->id,
        'patient_name' => trim(($patient?->name ?? '') . ' ' . ($patient?->last_name ?? '')),
        'tests'        => $worklists->map(fn($w) => [
            'test_id'   => $w->test_code,
            'test_name' => $w->test_name,
        ])->values(),
    ]);
}
```

### 34.3 legacyResult — close the worklist loop (v3 fix)

The uploaded `legacyResult` writes `TestReport` but **never marks the worklist as completed**. Add this patch:

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
    if (! $machine || ! $machine->isBidirectional()) return;   // unidirectional: no worklist

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

`submitResult()` → `Worklist::markAsCompleted()` also locks sibling worklists and sets `LabTest.has_result = true` and `TestSampleItem.status = 'completed'`.

### 34.4 exportMachineConfig fix (v3 one-liner)

```php
- 'directional' => $machine->directional ?? 'unidirectional',
+ 'directional' => $machine->direction   ?? 'unidirectional',
```

---

# PART VIII — Database Schema

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
       │          │    └────────────────────────┘              │
       ▼          │            │                                  │
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
│   parameters)    │     └──────────────────────────┘      │   status)    │
│  ← machineConfig │                                       └───────────────┘
└──────────────────┘
```

## 36. machines table

```sql
CREATE TABLE machines (
  id                  CHAR(36)     NOT NULL PRIMARY KEY,
  name                VARCHAR(255) NOT NULL UNIQUE,
  display_name        VARCHAR(255) NULL,
  device_model        VARCHAR(100) NOT NULL,                  -- X-Machine-Key value
  ip_address          VARCHAR(45)  NULL,
  port                INT          NULL CHECK (port BETWEEN 1 AND 65535),
  protocol            VARCHAR(20)  NOT NULL DEFAULT 'hl7',
  protocol_version    VARCHAR(20)  NULL,
  auto_push_enabled   TINYINT(1)   NOT NULL DEFAULT 0,
  auto_push_direction VARCHAR(20)  NULL,
  direction           VARCHAR(20)  NULL,                       -- 'bidirectional' | 'unidirectional' | NULL
  api_key             CHAR(32)     NOT NULL,                   -- Str::random(32)
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  priority            INT          NOT NULL DEFAULT 80,
  settings            JSON         NULL,
  last_received_at    DATETIME     NULL,
  created_at          DATETIME     NOT NULL,
  updated_at          DATETIME     NOT NULL,

  UNIQUE INDEX uniq_machines_device_model (device_model),      -- looked up with first() — must be unique
  INDEX idx_machines_api_key (api_key),
  INDEX idx_machines_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Sample row

```sql
INSERT INTO machines (id, name, display_name, device_model, ip_address, port,
  protocol, protocol_version, auto_push_enabled, direction, api_key, is_active,
  priority, last_received_at, created_at, updated_at)
VALUES (
  '0194abcd-1234-7890-abcd-ef0123456789',
  'urit_bh5390', 'Urit BH-5390', 'BH-5390', '192.168.1.50', 5700,
  'hl7', '2.3.1', 0, 'bidirectional',
  'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6',
  1, 80, NULL, NOW(), NOW()
);
```

## 37. machine_test_mappings table

The `parameters` JSON column is what Mirth embeds as `machineConfig`.

```sql
CREATE TABLE machine_test_mappings (
  id                  CHAR(36)     NOT NULL PRIMARY KEY,
  machine_id          CHAR(36)     NOT NULL,
  test_id             CHAR(36)     NOT NULL,
  test_code           VARCHAR(50)  NOT NULL,                   -- analyzer-side code e.g. "CBC"
  is_multi_component   TINYINT(1)   NOT NULL DEFAULT 1,
  sample_type         VARCHAR(50)  NOT NULL DEFAULT 'Serum',
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  priority            INT          NOT NULL DEFAULT 50,
  parameters          JSON         NULL,                       -- ← machineConfig payload
  created_at          DATETIME     NOT NULL,
  updated_at          DATETIME     NOT NULL,

  UNIQUE KEY uniq_machine_test (machine_id, test_id),
  INDEX idx_mapping_machine (machine_id, is_active),
  INDEX idx_mapping_test (test_id),
  CONSTRAINT fk_mapping_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
  CONSTRAINT fk_mapping_test FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Sample row (parameters JSON expanded)

```sql
INSERT INTO machine_test_mappings (id, machine_id, test_id, test_code, is_multi_component,
  sample_type, is_active, priority, parameters, created_at, updated_at)
VALUES (
  '0194-mapping-uuid-1',
  '0194abcd-1234-7890-abcd-ef0123456789',
  '0194-test-cbc-uuid', 'CBC', 1, 'Whole Blood', 1, 80,
  '{
    "test_id": "0194-test-cbc-uuid",
    "machine_id": "0194abcd-1234-7890-abcd-ef0123456789",
    "api_key": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6",
    "directional": "bidirectional",
    "is_multi_component": true,
    "test_name": "Complete Blood Count",
    "test_code": "CBC",
    "sample_type": "whole_blood",
    "mapping": {
      "WBC": ["<component-uuid-WBC>"],
      "RBC": ["<component-uuid-RBC>"],
      "HGB": ["<component-uuid-HGB>"]
    },
    "components": [
      { "id":"<component-uuid-WBC>", "name":"White Blood Cell Count", "code":"WBC",  "unit":"10^9/L", "ref_range":"4.0-10.0", "order":1, "functions":[{"name":"round","params":{"decimal":2}}] },
      { "id":"<component-uuid-HGB>", "name":"Hemoglobin",             "code":"HGB",  "unit":"g/dL",   "ref_range":"12.0-16.0","order":3, "functions":[{"name":"round","params":{"decimal":1}}] }
    ]
  }',
  NOW(), NOW()
);
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
  barcode                  VARCHAR(100) NOT NULL,                -- tube barcode
  receipt_barcode          VARCHAR(50)  NULL,
  patient_name             VARCHAR(255) NULL,
  patient_age              INT          NULL,
  patient_gender           VARCHAR(10)  NULL,
  test_code                VARCHAR(50)  NOT NULL,
  test_name                VARCHAR(255) NULL,
  sample_type              VARCHAR(50)  NULL,
  tube_type                VARCHAR(100) NULL,
  timing                   VARCHAR(20)  NULL,
  priority                 VARCHAR(20)  NOT NULL DEFAULT 'routine',  -- high|routine|low
  status                   VARCHAR(20)  NOT NULL DEFAULT 'pending', -- pending|processing|completed|cancelled|locked
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

### Sample row

```sql
INSERT INTO worklists (id, order_id, test_sample_item_id, lab_receipt_id, lab_test_id,
  test_id, machine_id, patient_id, barcode, receipt_barcode, patient_name, patient_age,
  patient_gender, test_code, test_name, sample_type, tube_type, timing, priority, status,
  order_date, collection_date, pushed_at, parameters, suffix, sample_number, created_at, updated_at)
VALUES (
  '0194-worklist-uuid-1', 'WL2611140001',
  '0194-sample-item-uuid-1', '0194-receipt-uuid-1', '0194-labtest-uuid-1',
  '0194-test-cbc-uuid', '0194abcd-1234-7890-abcd-ef0123456789',
  '0194-patient-uuid-1', '2608281001-F', '2608281001',
  'JOHN DOE', 35, 'M',
  'CBC', 'Complete Blood Count', 'Whole Blood', 'EDTA Tube', 'F', 'routine', 'pending',
  '2026-11-14 10:00:00', '2026-11-14 10:05:00', NULL,
  JSON_OBJECT('machine_mapping_id','0194-mapping-uuid-1','tube_barcode','2608281001-F','timing','F','sample_number',1,'suffix','F'),
  'F', 1, NOW(), NOW()
);
```

## 39. lab_receipts / lab_tests / test_samples / test_sample_items

### lab_receipts

```sql
CREATE TABLE lab_receipts (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  uuid         CHAR(36)     NOT NULL UNIQUE,
  tenant_id    CHAR(36)     NULL,
  barcode      VARCHAR(50)  NOT NULL UNIQUE,    -- receipt barcode YYMMDDGSSS
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

### lab_tests

```sql
CREATE TABLE lab_tests (
  id                       CHAR(36)     NOT NULL PRIMARY KEY,
  uuid                     CHAR(36)     NOT NULL UNIQUE,
  tenant_id                CHAR(36)     NULL,
  lab_id                   CHAR(36)     NOT NULL,                -- → lab_receipts.id
  test_id                  CHAR(36)     NOT NULL,                -- → tests.id
  patient_id               CHAR(36)     NULL,
  price                    DECIMAL(12,2) NULL,
  amount                   DECIMAL(12,2) NULL,
  paid                     DECIMAL(12,2) NULL,
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

### test_samples

```sql
CREATE TABLE test_samples (
  id                          CHAR(36)     NOT NULL PRIMARY KEY,
  barcode                     VARCHAR(100) NOT NULL UNIQUE,    -- tube barcode "2608281001-F"
  sl_no                       VARCHAR(50)  NULL,
  tenant_id                   CHAR(36)     NULL,
  lab_receipt_id              CHAR(36)     NOT NULL,
  patient_id                  CHAR(36)     NULL,
  tube_type                   VARCHAR(100) NULL,
  product_id                  CHAR(36)     NULL,
  timing                      VARCHAR(20)  NULL,
  tube_sequence               INT          NULL,
  barcode_source              VARCHAR(20)  NULL,                 -- system|custom|shared
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

### test_sample_items

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

### test_components

```sql
CREATE TABLE test_components (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id       CHAR(36)     NULL,
  test_id         CHAR(36)     NOT NULL,
  name            VARCHAR(255) NOT NULL,
  short_name      VARCHAR(50)  NULL,
  type            VARCHAR(50)  NULL,                    -- numeric|opinion|text|image
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

> `code` is NOT a column on `test_components`. OBX code mapping lives **only** in `machine_test_mappings.parameters` JSON — intentional (same component can have different OBX codes on different analyzers).

### test_reports

```sql
CREATE TABLE test_reports (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  model_id        CHAR(36)     NOT NULL,                 -- polymorphic: usually lab_tests.id
  model_type      VARCHAR(255) NOT NULL,                  -- "App\\Models\\LabTest"
  patient_id      CHAR(36)     NULL,
  test_id         CHAR(36)     NULL,
  component_id    CHAR(36)     NOT NULL,                  -- → test_components.id
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

### lis_results (v3 fix: UUID PK + nullable columns matching code)

```sql
CREATE TABLE lis_results (
  id              CHAR(36)     NOT NULL PRIMARY KEY,     -- code inserts Str::uuid7()
  worklist_id     CHAR(36)     NULL,
  machine_id      CHAR(36)     NOT NULL,
  barcode         VARCHAR(100) NOT NULL,
  test_code       VARCHAR(50)  NOT NULL,
  result_value    TEXT         NOT NULL,
  result_numeric  DECIMAL(15,4) NULL,
  unit            VARCHAR(50)  NULL,
  reference_range VARCHAR(100) NULL,
  abnormal_flag   VARCHAR(10)  NULL,                      -- N|L|H|LL|HH
  raw_message     MEDIUMTEXT   NULL,
  message_hash    CHAR(64)     NOT NULL UNIQUE,           -- SHA-256 idempotency key
  received_at     DATETIME     NOT NULL,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NULL,                      -- code does not set it

  INDEX idx_lis_results_worklist (worklist_id),
  INDEX idx_lis_results_machine (machine_id),
  INDEX idx_lis_results_barcode (barcode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> v1.0 declared `id BIGINT AUTO_INCREMENT` and `updated_at NOT NULL` — `receiveResults()` inserts a UUID id and no `updated_at`, so the insert would fail.

### failed_lis_results (v3 fix: nullable to match code)

```sql
CREATE TABLE failed_lis_results (
  id              CHAR(36)     NOT NULL PRIMARY KEY,      -- code inserts Str::uuid7()
  worklist_id     CHAR(36)     NULL,
  machine_id      CHAR(36)     NOT NULL,
  barcode         VARCHAR(100) NOT NULL,
  test_code       VARCHAR(50)  NOT NULL,
  result_value    TEXT         NULL,                      -- not set by failure handler
  unit            VARCHAR(50)  NULL,
  reference_range VARCHAR(100) NULL,
  abnormal_flag   VARCHAR(10)  NULL,
  raw_message     MEDIUMTEXT   NULL,
  message_hash    CHAR(64)     NULL,
  error_message   TEXT         NOT NULL,
  retry_count     INT          NOT NULL DEFAULT 0,
  max_retries     INT          NOT NULL DEFAULT 5,
  status          VARCHAR(20)  NOT NULL DEFAULT 'pending', -- retry sets 'pending' again
  received_at     DATETIME     NULL,
  last_retry_at   DATETIME     NULL,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NULL,

  INDEX idx_failed_status (status, retry_count),
  INDEX idx_failed_machine (machine_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### legacy_result_temp (v3 fix: matches MirthController writes)

```sql
CREATE TABLE legacy_result_temp (
  id              CHAR(36)     NOT NULL PRIMARY KEY,      -- Str::uuid()
  barcode         VARCHAR(100) NOT NULL,
  machine_id      VARCHAR(64)  NOT NULL,
  api_key         VARCHAR(64)  NULL,
  device_model    VARCHAR(100) NULL,
  `date`          DATETIME     NULL,                      -- parsed from YYYYMMDDHHMMSS
  mapping         JSON         NULL,                      -- normalized mapping
  results         JSON         NULL,                      -- results[] as sent
  processed       TINYINT(1)   NOT NULL DEFAULT 0,
  error_log       JSON         NULL,                      -- unmapped codes, fallbacks, missing LabTest
  created_at      DATETIME     NULL,
  updated_at      DATETIME     NULL,

  INDEX idx_legacy_barcode (barcode),
  INDEX idx_legacy_machine (machine_id),
  INDEX idx_legacy_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 42. Sample SQL DDL (full file)

Save as `database/migrations/0001_01_01_000000_create_mirth_integration_tables.sql`:

```sql
SET FOREIGN_KEY_CHECKS = 0;
-- DROP TABLE IF EXISTS failed_lis_results, lis_results, legacy_result_temp,
--   test_reports, test_components, test_sample_items, test_samples, lab_tests,
--   lab_receipts, worklists, machine_test_mappings, machines, tests;
SET FOREIGN_KEY_CHECKS = 1;

-- Run each CREATE TABLE from §36–§41 in order:
-- tests → machines → machine_test_mappings → lab_receipts → lab_tests →
-- test_samples → test_sample_items → test_components → test_reports →
-- lis_results → failed_lis_results → legacy_result_temp → worklists

-- Then insert sample rows from each section.
```

---

# PART IX — Sample HL7 Messages

## 43. QRY^Q02 (Worklist Query from Analyzer)

A Urit BH-5390 sends this when the operator scans a tube and presses "Query":

```
MSH|^~\&|BH5390|URIT|LIS|LAB|20261114103000||QRY^Q02|Q001|P|2.3.1
QRD|20261114103000|R|I|||||2608281001-F
QRF|LAB|||||||||O
```

| Field | Value | Notes |
|---|---|---|
| MSH.3 / MSH.4 | `BH5390` / `URIT` | Sending app / facility |
| MSH.5 / MSH.6 | `LIS` / `LAB` | Receiving app / facility |
| MSH.7 | `20261114103000` | Timestamp YYYYMMDDHHMMSS |
| MSH.9 | `QRY^Q02` | Worklist query |
| MSH.10 | `Q001` | Control ID — echoed in MSA.2 |
| MSH.12 | `2.3.1` | HL7 version |
| QRD.8 | `2608281001-F` | **The tube barcode** |

### What Mirth does

1. Source Transformer: `MSH.9 = QRY^Q02` → `interface_mode = 'WORKLIST_QUERY'`
2. Extracts `barcode = '2608281001-F'` from `QRD.8.1`
3. HTTP GET `https://lab.htncr.org/api/get-worklist?barcode=2608281001-F&api=KEY`
4. Builds `OML^O21` reply (see §44)
5. Sends OML via `responseMap.put('Auto-generate (After source transformer)', oml)`

## 44. OML^O21 (Worklist Reply from Mirth)

Mirth's response to the QRY above, assuming Laravel returned a worklist for CBC:

```
MSH|^~\&|LIS|LAB|ANALYZER|MODEL|20261114103001||OML^O21|OML20261114103001|P|2.3.1
MSA|AA|Q001||Worklist sent successfully
ORC|NW|2608281001-F||||CM
OBR|1||2608281001-F|||F|||P0001|JOHN DOE
OBX|1|NM|CBC^Complete Blood Count||||||F
```

| Field | Value | Notes |
|---|---|---|
| MSH.9 | `OML^O21` | Reply type (your existing working pattern) |
| MSA.1 | `AA` | Application accept |
| MSA.2 | `Q001` | Echo of analyzer's control ID |
| ORC.1 | `NW` | New order |
| ORC.2 | `2608281001-F` | Placer order = tube barcode |
| OBR.3 | `2608281001-F` | Filler order number |
| OBR.7 | `F` | Specimen type flag (Urit expects `F`) |
| OBX.3 | `CBC^Complete Blood Count` | Code^Name |
| OBX.11 | `F` | Final status |

On error:

```
MSH|^~\&|LIS|LAB|ANALYZER|MODEL|20261114103001||OML^O21|OML20261114103001|P|2.3.1
MSA|AE|Q001||Worklist query failed: API 500
```

> **OML^O21 vs DSR^Q03:** both are valid HL7 responses to `QRY^Q02`. v3 keeps `OML^O21` because that's what works in your lab. If the Urit BH-5390 manual specifies `DSR^Q03` (or `OML^O33` / `ORL^O34`), change only the MSH.9 builder line and the segment layout.

## 45. ORU^R01 (Result Upload from Analyzer)

A Urit BH-5390 sends this after running CBC on tube `2608281001-F`. **v3 fix:** code in `OBX.3.2` (matches parser); date in `OBR.7` (matches parser):

```
MSH|^~\&|BH5390|URIT|LIS|LAB|20261114103500||ORU^R01|R001|P|2.3.1
PID|1||P0001||DOE^JOHN||19890115|M
PV1|1|OUT
OBR|1||2608281001-F|||20261114103400
OBX|1|NM|1^WBC||6.2|10^9/L|4.0-10.0|N|||F
OBX|2|NM|2^RBC||4.5|10^12/L|4.0-5.5|N|||F
OBX|3|NM|3^HGB||13.8|g/dL|12.0-16.0|N|||F
OBX|4|NM|4^HCT||41.2|%|37-50|N|||F
OBX|5|NM|5^MCV||91.6|fL|80-100|N|||F
OBX|6|NM|6^MCH||30.7|pg|27-33|N|||F
OBX|7|NM|7^MCHC||33.5|g/dL|32-36|N|||F
OBX|8|NM|8^PLT||245|10^9/L|150-400|N|||F
OBX|9|NM|9^LYM%||32.5|%|20-40|N|||F
OBX|10|NM|10^LYM#||2.0|10^9/L|1.0-3.0|N|||F
OBX|11|NM|11^MON%||7.2|%|3-10|N|||F
OBX|12|NM|12^MON#||0.45|10^9/L|0.1-0.6|N|||F
OBX|13|NM|13^NEU%||55.3|%|45-70|N|||F
OBX|14|NM|14^NEU#||3.43|10^9/L|2.0-7.0|N|||F
OBX|15|NM|15^EOS%||3.2|%|0.5-5|N|||F
OBX|16|NM|16^BAS%||0.4|%|0-1|N|||F
OBX|17|NM|17^BAS#||0.02|10^9/L|0-0.1|N|||F
```

### What Mirth does

1. `MSH.9 = ORU^R01` → `interface_mode = 'RESULT_UPLOAD'`
2. `barcode` from `OBR.2.1` or `OBR.3.1` (`2608281001-F`); `date` from `OBR.7.1`
3. For each OBX: `code = OBX.3.2` (so `1^WBC` → `WBC`); value = `OBX.5.1` with leading `*` stripped; `.PNG` graph rows and `MODE`/`MODE_EX`/`Ref`/`Note` skipped
4. `rawValues = {WBC:6.2, RBC:4.5, HGB:13.8, HCT:41.2, MCV:91.6, ...}`
5. Derived value (missing in this example): `EOS# = (EOS% / 100) × WBC = (3.2 / 100) × 6.2 = 0.198`
6. Build JSON payload — **values are strings**:
```json
{
  "machine_id": "0194abcd-...",
  "api_key": "a1b2c3d4...",
  "barcode": "2608281001-F",
  "device_model": "BH5390",
  "date": "20261114103400",
  "mapping": { "WBC": ["<component-uuid>"], "RBC": ["<component-uuid>"] },
  "results": [
    { "code": "WBC", "name": "White Blood Cell Count", "value": "6.20", "unit": "10^9/L" },
    { "code": "RBC", "name": "Red Blood Cell Count",   "value": "4.50", "unit": "10^12/L" }
  ]
}
```
7. Destination HTTP POSTs to Laravel `/mirth/legacy-result`
8. Laravel responds `{type:'success', note:'…', status:200}`; Destination logs OK only if `type == 'success'`

> **Verify the OBX.3 convention against a real capture.** v1.0's sample put the code in `OBX.3.1` (`WBC^White Blood Cell`) and the date in `OBR.6` — with that sample, the parser would read the code from `OBX.3.2` (which would be `White Blood Cell` — wrong) and the date would be empty. v3 fixes the sample to match the parser. If your Urit sends the code in `OBX.3.1`, change the parser line:
> ```javascript
> var code = obx['OBX.3']['OBX.3.1'].toString() || obx['OBX.3']['OBX.3.2'].toString() || null;
> ```

## 46. ACK Variants

### Application Accept (success)

```
MSH|^~\&|LIS|LAB|BH5390|URIT|20261114103501||ACK|R001_ACK|P|2.3.1
MSA|AA|R001
```

### Application Error (parse failure)

```
MSH|^~\&|LIS|LAB|BH5390|URIT|20261114103501||ACK|R001_ACK|P|2.3.1
MSA|AE|R001||Failed to parse OBX segment 5
```

### Application Reject (unknown barcode format)

```
MSH|^~\&|LIS|LAB|BH5390|URIT|20261114103501||ACK|R001_ACK|P|2.3.1
MSA|AR|R001||Unknown barcode format
```

For ORU results, Mirth's standard "Auto-commit" produces the AA ACK automatically. You only need a custom ACK for AE/AR.

---

# PART X — Dynamic Multi-Analyzer Deployment

## 47. Channel Inventory Pattern

For N analyzers, your Mirth server has N channels — one per analyzer — plus optionally a sync timer channel.

### Example Inventory

| # | Channel | Direction | Port | Config source | Laravel `device_model` |
|---|---|---|---|---|---|
| 1 | `urit_bh5390_bi` | Bidirectional | 5700 | Embedded | `BH-5390` |
| 2 | `snibe_xxx_bi` | Bidirectional | 5701 | Embedded | `SNIBE-XXX` |
| 3 | `sysmex_xn1000_bi` | Bidirectional | 5702 | Dynamic (needs token route) | `SYSMEX-XN1000` |
| 4 | `mindray_bc6800_bi` | Bidirectional | 5703 | Dynamic | `BC-6800` |
| 5 | `beckman_au680_uni` | Unidirectional | 5710 | Embedded | `AU680` |
| 6 | `roche_cobas_uni` | Unidirectional | 5711 | Embedded | `COBAS` |

## 48. Adding a New Analyzer (cookbook)

### Step 1 — Register in Laravel admin UI

Machines → New. Fill in: Name `sysmex_xn1000`, Display name `Sysmex XN-1000`, Device model `SYSMEX-XN1000`, Direction `bidirectional`, Protocol `hl7`, Protocol version `2.5.1`, IP `<sysmex_ip>`, Port `5702`, Priority `80`. Save → Laravel auto-generates `api_key`.

### Step 2 — Assign tests to the machine

Machine edit page → "Assign Tests" tab. Select tests (CBC, DIFF, RETIC). For each, the system builds a default `parameters` JSON. Save.

### Step 3 — Export the machine config

Machine list → "Export Config" button → `machine_config_<uuid>.json` downloads.

### Step 4 — Create the Mirth channel

Mirth admin UI → Channels → New Channel.

- General: name `sysmex_xn1000_bi`, tags `hematology`, `bidirectional`, `sysmex`
- Source: TCP Listener, port `5702`, MLLP `0x0B ... 0x1C 0x0D` (verify with Sysmex manual)
- Source Transformer: paste §18, replace `machineConfig` JSON with the exported file
- Destination: JavaScript Writer, paste §21
- Deploy

### Step 5 — Configure the analyzer

On the Sysmex host PC, set LIS IP = Mirth server IP, LIS Port = `5702`, Protocol = HL7 v2.5.1, Mode = Bidirectional.

### Step 6 — End-to-end test

1. Scan a tube with a pending worklist
2. Mirth dashboard: QRY arrives → "Worklist query barcode: ..." → Laravel 200 → analyzer receives OML
3. Run test on analyzer → ORU arrives → Mirth POSTs JSON to `/mirth/legacy-result`
4. Laravel admin → Lab Receipt → Reports: TestReport rows populated, worklist `completed`

## 49. Channel Groups

Right-click in the channel tree → New Channel Group → name it (e.g. `lab-hematology`). Drag channels into it. Purely visual, no runtime effect.

```
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
| HL7 message round-trip | HL7 Send tool | Mirth parses QRY, builds OML correctly |
| Laravel API standalone | Postman | `/api/get-worklist` and `/mirth/legacy-result` return correct JSON |
| Database state | MySQL CLI / Adminer | TestReport rows created with correct component_id, result, unit |
| End-to-end | Physical analyzer + tube | Real barcode → real result row |
| Failure modes | Mirth + Postman mock failures | DLQ, retry, error ACK |

## 51. Testing with HL7 Send Tool

### 51.1 Install HL7 Send

Use HL7 Inspector (`https://hl7inspector.com/`), Mirth Administrator's *Send Message*, or `nc`.

### 51.2 Send a QRY^Q02 to Mirth port 5700

Server: `<mirth_ip>:5700`. Paste the QRY^Q02 from §43. Click "Send". Receive back the OML^O21 from §44.

### 51.3 Send an ORU^R01 with abnormal values

Replace OBX rows with abnormal values:

```
OBX|1|NM|1^WBC||*15.5|10^9/L|4.0-10.0|H|||F
OBX|2|NM|2^RBC||3.2|10^12/L|4.0-5.5|L|||F
```

- The `*` prefix should be stripped by Mirth (verify in logs)
- The `H`/`L` flags are not forwarded — the payload carries only `code`, `name`, `value`, `unit`

### 51.4 Command-line sending (no `mirthsend` — that is not a Mirth tool)

```bash
# Frame the HL7 (VT … FS CR) and send with netcat; reply is printed
printf '\x0b%s\x1c\r' "$(cat /tmp/qry.hl7 | tr '\n' '\r')" | nc 127.0.0.1 5700 | tr '\r' '\n'
```

Or use Mirth Administrator → Channel → **Send Message**, or HL7 Inspector.

## 52. Testing via Mirth Channel Reader

Clone `urit_bh5390_bi` as `urit_bh5390_test`. Change source from TCP Listener to Channel Reader. Deploy. Open "Messages" view → click "Send Message" → paste QRY → Send. Watch the transformer logs and response.

## 53. Postman for Laravel API

### 53.1 Test GET /api/get-worklist

```
GET https://lab.htncr.org/api/get-worklist?barcode=2608281001-F&api=KEY
```

Expected: 200 with `{patient_id, patient_name, tests:[]}`.

### 53.2 Test POST /mirth/legacy-result

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

### 53.3 Test GET /api/mirth/health

```
GET https://lab.htncr.org/api/mirth/health
X-Machine-Key: BH-5390
```

Expected: 200 with `{status:"healthy", machine:{...}, pending_worklists:N, ...}`.

## 54. End-to-End Validation Checklist

| # | Step | Expected outcome | Pass/Fail |
|---|---|---|---|
| 1 | Register machine in Laravel admin | Machine row created with UUID + api_key | ☐ |
| 2 | Assign tests to machine | `machine_test_mappings` rows created | ☐ |
| 3 | Export machine config JSON | File downloads with all components | ☐ |
| 4 | Create Mirth channel + deploy | Channel state = "Started" | ☐ |
| 5 | Configure analyzer IP:Port | Analyzer can ping Mirth server | ☐ |
| 6 | Send QRY^Q02 via HL7 Send tool | Receive OML^O21 with correct tests | ☐ |
| 7 | Scan a real tube at the analyzer | Analyzer displays "Tests received: CBC" | ☐ |
| 8 | Run the test on analyzer | ORU^R01 arrives in Mirth dashboard | ☐ |
| 9 | Mirth logs "Upload OK" | Laravel answered `type: success` (not just HTTP 200) | ☐ |
| 10 | `test_reports` table has N new rows | Each component has a `result` value | ☐ |
| 11 | `worklists.status = 'completed'` | v3 fix `completeWorklists()` worked | ☐ |
| 12 | `lab_tests.lab_status = 'completed'` | Downstream report generation triggered | ☐ |
| 13 | Lab report PDF/preview shows values | End user sees results | ☐ |
| 14 | Send duplicate ORU^R01 | No duplicate `test_reports` rows (`updateOrCreate`) | ☐ |
| 15 | Stop Laravel (simulate downtime) | Mirth logs error, channel stays up | ☐ |
| 16 | Send malformed HL7 (missing MSH) | Mirth logs error, no Laravel POST | ☐ |

---

# PART XII — Troubleshooting

## 55. Troubleshooting Matrix

| Symptom | Likely cause | Diagnostic | Fix |
|---|---|---|---|
| Analyzer cannot connect to Mirth | Firewall / port not open | `telnet <mirth_ip> 5700` from analyzer host | Open port; verify channel Started |
| Mirth receives QRY but returns empty OML | Laravel 404 for barcode | Mirth log "Worklist API error 404" | Worklist row must exist with `status='pending'` |
| OML reply has no OBX | Laravel response missing `tests` field | `curl` the get-worklist endpoint | Worklist has no `test_code` set |
| Analyzer rejects OML reply | MSH field format wrong | Compare with analyzer's "expected reply" sample | Adjust MSH field order in OML builder (§14.5) |
| ORU arrives but no Laravel POST | `interface_mode` defaulted to RESULT_UPLOAD but transform threw | Mirth log "Router error" or "Result transform error" | Fix the JS exception — usually missing segment access |
| Laravel returns 404 (`type: fail`) on legacy-result | LabReceipt not found for barcode | Check `test_samples.barcode` | Verify barcode format; check `test_sample` lookup |
| Laravel 200 but TestReport not created | `mapping[code]` empty or component_id wrong | Check `legacy_result_temp.error_log` column | Re-export config from Laravel admin, repaste in Mirth |
| EOS# is null in TestReport | `rawValues['EOS#']` undefined; computation didn't trigger | Check `rawValues['WBC']` and `rawValues['EOS%']` defined | Add `EOS#` component to config; check `EOS%` spelling |
| Worklist stays pending after result | `legacyResult` doesn't complete worklists (v1 bug) | Check `worklists.status` after a result | Apply v3 `completeWorklists()` patch (§34.3) |
| Duplicate TestReports created | Two Mirth channels both POST for same barcode | Check `worklists.machine_id` matches channel's machine | Filter out ORU not belonging to this machine in source transformer |
| Mirth logs "TLS setup skipped" | JKS path wrong / password mismatch | Check `/opt/mirth/conf/truststore.jks` exists | For public-CA hosts (lab.htncr.org), skip TLS setup entirely |
| `connection refused` to Laravel | Laravel down / network / DNS | `curl -v https://lab.htncr.org/api/mirth/health` | Bring Laravel back up; check DNS |
| Analyzer sends graph PNG as OBX | Urit/Sysmex graph feature enabled | Mirth log "code: WBC.PNG" | Filter already in place: `if (code.indexOf('.PNG') !== -1) continue;` — auto-skipped |
| `MSA|AE` returned to analyzer | Laravel non-200 or threw exception | Mirth log "Worklist API error NNN" | Fix Laravel-side issue (check `storage/logs/laravel.log`) |
| Channel goes to "Stopped" unexpectedly | Source transformer threw uncaught exception | Mirth `server.log` stacktrace | Wrap all segment access in try/catch (already done in template) |
| ACK too slow, analyzer times out | Destination HTTP POST takes >5s | Mirth log duration between source and destination POST | Increase Laravel timeout, or async-queue the destination |
| Patient name contains `^` | HL7 escape not applied | Verify `OBR.11` uses `\\S\\` for any `^` | Apply `hl7Escape()` (§17.4) |
| Upload logged OK but Laravel didn't save | v1 bug: logged OK on any 2xx | Mirth log shows HTTP 200 with `type: fail` | v3 fix: destination reads `body.type === 'success'` (§21) |
| Laravel 419 on `/mirth/legacy-result` | Route is web + CSRF (cannot verify from upload) | `storage/logs/laravel.log` for 419 | If you see 419s, exclude `mirth/*` from CSRF |

## 56. Logging Best Practices

| Level | When to use | Where |
|---|---|---|
| `logger.info(...)` | Normal milestones (barcode, OML built, upload OK) | Mirth dashboard + `mirth.log` |
| `logger.warn(...)` | Recoverable issues (TLS skipped, OBX skip, fallback config) | `mirth.log` |
| `logger.error(...)` | Failures (Laravel 500, transform exception, `type: fail`) | `mirth.log` + email alerts |
| `logger.debug(...)` | Verbose — disable in production | `mirth.log` if DEBUG |

Recommended log points:

```javascript
logger.info('HL7 type: ' + msgType + '^' + msgTrigger);
logger.info('Worklist query barcode: ' + barcode);
logger.info('Worklist API response code: ' + respCode);
logger.info('OML^O21 built with ' + testCounter + ' tests');
logger.info('Result upload: ' + results.length + ' results for barcode ' + barcode);
logger.info("Upload OK. Barcode: " + barcode);   // only when body.type === 'success'
logger.error("Upload FAILED. Status: " + statusCode + " Body: " + response);
```

Laravel side: `Log::info('Mirth legacy-result', ['barcode' => $input['barcode'], 'count' => count($input['results'])]);` at the top of each Mirth-facing method. The `legacy_result_temp` table is your raw audit log:

```sql
SELECT * FROM legacy_result_temp WHERE barcode = '2608281001-F' ORDER BY created_at DESC;
```

## 57. Common Mirth Errors

### 57.1 "TypeError: Cannot read property '...' from null"

Trying to access an HL7 segment that doesn't exist. Wrap in try/catch.

### 57.2 "JavaException: java.lang.NullPointerException"

Same cause — usually `msg['MSH']['MSH.9']` returned null. Catch and default.

### 57.3 "Connection refused" to Laravel

Laravel down or DNS wrong. `curl -v https://lab.htncr.org/api/mirth/health`.

### 57.4 "SSLHandshakeException: PKIX path building failed"

Public-CA hosts (lab.htncr.org) shouldn't fail — check system clock / proxy. For internal CA, create truststore (§8.4) and set as JVM truststore (`-Djavax.net.ssl.trustStore=…`) — **don't disable hostname verification**.

### 57.5 "SocketTimeoutException: Read timed out"

Laravel slower than configured timeout (5 s Reply, 15 s Upload). Increase timeout or optimize Laravel endpoint.

### 57.6 Channel stuck in "Stopping" state

A destination thread is hanging. Restart: `sudo /opt/mirthconnect/mcservice restart`.

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

`MirthController` never returns 401. Header missing → 400; `device_model` unknown or inactive → 404; direction not allowed → 403. Verify:

```bash
curl -H "X-Machine-Key: BH-5390" https://lab.htncr.org/api/mirth/health
```

The `X-Machine-Key` value must match `machines.device_model` exactly, which must be unique.

---

# PART XIII — Deployment & Operations

## 59. Production Deployment Checklist

### 59.1 Mirth-side

- [ ] Channel deployed and Started
- [ ] Source port is unique
- [ ] MLLP framing matches analyzer spec (`0x0B ... 0x1C 0x0D`)
- [ ] Source Transformer pasted (no syntax errors)
- [ ] `machineConfig` JSON has all real component UUIDs (no `<component-uuid>` placeholders)
- [ ] Destination script pasted; reads `body.type === 'success'`
- [ ] Channel tags include vendor + direction
- [ ] Pruning: keep 30 days
- [ ] Message payload storage: enabled
- [ ] Channel Group assigned
- [ ] Test message sent — correct OML reply

### 59.2 Laravel-side

- [ ] `machines` row exists with correct `device_model`, `direction='bidirectional'`, `api_key`, `port`, `is_active=1`
- [ ] `machine_test_mappings` rows exist for every test
- [ ] `parameters` JSON has correct `mapping` and `components`
- [ ] `tests` and `test_components` rows exist
- [ ] `routes/api.php` has `/api/mirth/*` endpoints and `/api/get-worklist`; `/mirth/legacy-result` excluded from CSRF if 419s appear
- [ ] **v3 fixes applied:** `direction` (not `directional`) in `exportMachineConfig` (§34.4); `completeWorklists()` in `legacyResult` (§34.3)
- [ ] `php artisan config:clear && php artisan route:clear && php artisan cache:clear`
- [ ] Postman: `/api/mirth/health` returns 200

### 59.3 Network-side

- [ ] Mirth reaches Laravel on port 443 (`curl https://lab.htncr.org/api/mirth/health`)
- [ ] Analyzer reaches Mirth on assigned MLLP port (`telnet <mirth_ip> 5700`)
- [ ] Firewall rules persistent across reboots
- [ ] DNS for `lab.htncr.org` resolves correctly

### 59.4 Operational readiness

- [ ] On-call rota assigned
- [ ] Dashboard alerts: "channel stopped" + "destination failed >3 in 10 min"
- [ ] Backup of Mirth channel XML exported + offsite
- [ ] Backup of `machine_test_mappings.parameters` JSON exported (via `export-config`)
- [ ] Run-book: how to manually re-trigger via `/api/mirth/retry-failed/{id}`
- [ ] 24-hour soak test: 50+ samples, no orphaned `worklists` (status='pending' forever)

## 60. Multi-Analyzer Rollout Plan

| Phase | Duration | What |
|---|---|---|
| 1 Pilot | 1 week | Deploy Urit BH-5390; logging INFO; resolve quirks |
| 2 Expansion | 2 weeks | Add Snibe + 1 more; add Channel Groups; first XML backup |
| 3 Full | 1 month | All analyzers; logging WARN; monitoring dashboards; runbook |
| 4 Hardening | ongoing | DLQ retry automation; Prometheus; `failed_lis_results > 0` alert; 90-day `legacy_result_temp` cleanup |

## 61. Monitoring & Alerts

### 61.1 Mirth built-in dashboard

Dashboard view: messages received / queued / errored / filtered per channel. Set up email alerts on "Errors".

### 61.2 Laravel health endpoint

```bash
*/1 * * * * curl -sf -H "X-Machine-Key: BH-5390" https://lab.htncr.org/api/mirth/health \
  | jq -e '.status == "healthy"' || /usr/local/bin/alert-laravel-down.sh
```

### 61.3 Failed results alert

```bash
*/5 * * * * curl -sf -H "X-Machine-Key: BH-5390" \
  https://lab.htncr.org/api/mirth/failed-results \
  | jq -e '.total == 0' || /usr/local/bin/alert-failed-results.sh
```

### 61.4 Prometheus / Grafana

> Illustrative — verify the JMX port and MBean names for your Mirth version.

Use `jmx_exporter` to convert Mirth JMX to Prometheus format. Visualize per channel.

## 62. Backup & Recovery

### 62.1 Mirth channel backup

> Illustrative. The REST API requires `X-Requested-With: OpenAPI` header, uses your server's port, and import call differs by version. Administrator → *Export All Channels* is the safe route.

```bash
curl -u admin:<password> \
  https://localhost:8443/api/channels?format=xml \
  -o /backup/mirth/channels_$(date +%Y%m%d).xml
```

Schedule daily cron + offsite copy.

### 62.2 Laravel DB backup

```bash
0 2 * * * mysqldump --defaults-extra-file=/root/.my.cnf lis_db | gzip > /backup/db/lis_$(date +%Y%m%d).sql.gz
find /backup/db -mtime +30 -delete
```

### 62.3 Recovery procedure

Mirth crash: restore from VM snapshot, or reinstall + import channels → verify Started → test QRY from HL7 Send tool.

Laravel DB lost: restore from latest dump, run migrations, clear caches, re-export `machineConfig` for each analyzer.

### 62.4 Disaster recovery (RTO / RPO)

| Component | RPO | RTO | Strategy |
|---|---|---|---|
| Mirth channel config | 24h | 1h | Daily XML backup, offsite copy |
| Mirth message log | 1h | 4h | Local disk + DB pruning 30d |
| Laravel DB | 24h | 2h | Daily mysqldump + binlog replication |
| Laravel app code | Continuous | 30min | Git + CI/CD redeploy |
| Machine test mappings | 24h | 1h | Part of MySQL dump |

---

# PART XIV — Appendices

## A. Glossary

| Term | Definition |
|---|---|
| **ACK** | Acknowledgement — sent by the receiver back to the sender confirming receipt |
| **ASTM** | ASTM E1394 — serial protocol used by older analyzers (preceded HL7 v2) |
| **Barcode** | Identifier on a sample tube; scanned by the analyzer to identify the sample |
| **Bidirectional** | Communication in both directions — analyzer queries LIS for orders, LIS replies; analyzer also sends results |
| **Channel** | A Mirth Connect configuration unit that processes messages from one source to one or more destinations |
| **Component** | A specific analyte within a test (e.g. WBC, RBC, HGB are components of CBC) |
| **DLQ** | Dead-Letter Queue — where failed messages go for retry or inspection |
| **HL7** | Health Level 7 — international standard for exchanging healthcare information |
| **Idempotency** | Property of an operation where performing it multiple times produces the same result as performing it once |
| **LabReceipt** | Laravel entity representing a patient's visit to the lab with one or more test requests |
| **LIS** | Laboratory Information System |
| **MLLP** | Minimal Lower Layer Protocol — TCP framing used for HL7 v2 messages |
| **Mirth Connect** | Open-source integration engine (now NextGen Connect) |
| **OBX** | Observation Result segment in HL7 — carries a single result value |
| **OBR** | Observation Request segment in HL7 — describes the test requested |
| **OML^O21** | Laboratory Order Message — sent by LIS in response to a worklist query |
| **ORC** | Common Order segment in HL7 — order control info |
| **ORU^R01** | Observation Result Message — sent by analyzer when results are available |
| **QRY^Q02** | Query message — sent by analyzer to ask for tests on a barcode |
| **Source Transformer** | Mirth component that transforms an inbound message before routing to destinations |
| **TestComponent** | Laravel entity for a single analyte definition (unit, ref range, etc.) |
| **TestReport** | Laravel entity storing the actual result value, linked to a TestComponent |
| **Unidirectional** | Communication in one direction only — analyzer sends results, does not query |
| **Worklist** | The list of pending tests assigned to a specific analyzer for specific tubes |

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
| MSA.2 | Message Control ID | (echo of MSH.10 from original message) |
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
| **logger** | Mirth's logging object — `logger.info()`, `logger.warn()`, `logger.error()` |
| **msg** | The inbound message object in the source transformer — `msg['MSH']['MSH.9']['MSH.9.1']` |
| **DateUtil** | Mirth utility — `DateUtil.getCurrentDate('yyyyMMddHHmmss')` |
| **Source Transformer** | First transformer in a channel; receives `msg`, can write to `channelMap` and `responseMap` |
| **Destination** | Final step in a channel; receives `channelMap` data, performs the action (HTTP POST, DB write, etc.) |
| **Auto-generate (After source transformer)** | The standard `responseMap` key Mirth uses to send the source's response back to the analyzer — works reliably when the string matches exactly |
| **MC Service Manager** | The Mirth daemon — start/stop with `mcservice` |
| **Channel Group** | A folder in the channel tree for organization only — no runtime effect |

## D. File Layout / Project Structure

```
<project>/
├── upload/
│   ├── lis.md                            ← original Mirth templates
│   └── LabSomeFile.zip                   ← Laravel source
│       ├── Controller/MirthController.php      ← /api/mirth/*, /mirth/legacy-result (+ completeWorklists in v3)
│       ├── Controller/WorklistController.php   ← /lab/worklists/* and duplicate API set (unrouted)
│       ├── Controller/MachineController.php    ← /lab/machines/* incl. export-config (fix direction in v3)
│       ├── Controller/SampleController.php     ← sample collection → createWorklistForGroup()
│       ├── Controller/LabReceiptController.php
│       ├── Model/ Machine, Worklist, MachineTestMapping, LabReceipt, LabTest, Test, TestComponent,
│       │          TestSample, TestSampleItem, TestReport
│       └── Service/WorklistService.php         ← worklist creation, acknowledge, submitResult, reassign …
└── download/
    └── mirth-connect-bidirectional-worklist-guide_v3.md   ← THIS GUIDE

Mirth server (paths depend on install):
<install>/
├── conf/  (mirth.properties; truststore only if internal CA used)
└── extensions/

Laravel server:
<app>/
├── routes/api.php          ← /api/mirth/* incl. /api/get-worklist
├── routes/web.php          ← /mirth/legacy-result (CSRF-excluded if 419s appear)
├── app/Http/Controllers/Lab/Api/MirthController.php
├── app/Http/Controllers/Lab/WorklistController.php
├── app/Http/Controllers/Lab/MachineController.php
└── app/Models, app/Services
```

## E. Quick Start (TL;DR)

1. **Laravel side:**
   - Apply v3 patches: `completeWorklists()` in `legacyResult` (§34.3); `direction` (not `directional`) in `exportMachineConfig` (§34.4)
   - Verify `/api/get-worklist` is in `routes/api.php` (if missing, add §34.2)
   - If you see 419 errors on `/mirth/legacy-result`, exclude `mirth/*` from CSRF (§58.4)
   - Machines → create Urit BH-5390: `direction='bidirectional'`, unique `device_model`, active
   - Assign CBC test → Save Parameters → Export Config → download JSON
   - **Manually set `"directional": "bidirectional"`** in the pasted JSON until the export fix is live

2. **Mirth side:**
   - Install Mirth (§9)
   - Channel `urit_bh5390_bi`: Source TCP Listener port 5700, MLLP `0x0B … 0x1C 0x0D`
   - Source Transformer: §18, with your exported `machineConfig`
   - Destination: JavaScript Writer, §21 (reads `body.type === 'success'`)
   - Deploy

3. **Test (in this order):**
   - `curl "https://lab.htncr.org/api/get-worklist?barcode=2608281001-F&api=KEY"`
   - Send the QRY^Q02 from §43 (Mirth *Send Message* or `nc`) → expect OML^O21 from §44
   - Send the ORU^R01 from §45 → expect ACK, Mirth log "Upload OK", new `test_reports` rows, worklist `completed`
   - Real analyzer: scan → worklist appears → run → result saved

4. **If something fails:** Troubleshooting Matrix §55.

5. **To add another analyzer:** cookbook §48.

---

*End of guide.*
