<?php

namespace App\Http\Controllers\Lab\Api;

use App\Http\Controllers\Controller;
use App\Models\LabReceipt;
use App\Models\LabTest;
use App\Models\LegacyResultTemp;
use App\Models\Machine;
use App\Models\TestComponent;
use App\Models\TestReport;
use App\Models\TestSample;
use App\Models\TestSampleItem;
use App\Models\Worklist;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MirthController extends Controller
{
    /**
     * Handle incoming results from Mirth (Urit BH-5390).
     * Stores raw data in LegacyResultTemp, then processes and inserts into TestReport.
     */
    public function legacyResult(Request $request)
    {
        // 1. Validate input
        $validator = Validator::make($request->all(), [
            'machine_id' => 'required|string',
            'barcode' => 'required|string',
            'api_key' => 'nullable|string',
            'device_model' => 'nullable|string',
            'date' => 'nullable|string',
            'mapping' => 'required|array',
            'results' => 'required|array',
            'results.*.code' => 'required|string',
            'results.*.value' => 'required|string',
            'results.*.unit' => 'nullable|string',
            'results.*.name' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'type' => 'invalid',
                'note' => $validator->messages(),
                'status' => 400,
            ], 200);
        }

        $input = $validator->validated();

        // ============================================
        // FIX: Normalize mapping structure
        // Accepts both nested (from machine_test_mappings.parameters)
        // and flat mapping formats.
        // ============================================
        $rawMapping = $input['mapping'];

        // If nested (has 'mapping' key), extract flat mapping
        if (isset($rawMapping['mapping']) && is_array($rawMapping['mapping'])) {
            $rawMapping = $rawMapping['mapping'];
        }

        // Normalize: string → [string]
        $normalizedMapping = [];
        foreach ($rawMapping as $code => $ids) {
            if (is_array($ids)) {
                $normalizedMapping[$code] = array_values(array_filter($ids));
            } elseif (! empty($ids)) {
                $normalizedMapping[$code] = [$ids];
            }
        }

        // 2. Parse date (expects YYYYMMDDHHMMSS)
        $parsedDate = null;
        if (! empty($input['date']) && is_string($input['date'])) {
            $dateStr = trim($input['date']);
            if (preg_match('/^\d{14}$/', $dateStr)) {
                try {
                    $parsedDate = Carbon::createFromFormat('YmdHis', $dateStr);
                } catch (\Exception $e) {
                    // ignore
                }
            } else {
                try {
                    $parsedDate = new Carbon($dateStr);
                } catch (\Exception $e) {
                    // ignore
                }
            }
        }

        // 3. Store raw data in temp table
        $temp = LegacyResultTemp::create([
            'id' => (string) Str::uuid(),
            'barcode' => $input['barcode'],
            'machine_id' => $input['machine_id'],
            'api_key' => $input['api_key'] ?? null,
            'device_model' => $input['device_model'] ?? null,
            'date' => $parsedDate,
            'mapping' => json_encode($normalizedMapping),
            'results' => json_encode($input['results']),
            'processed' => false,
        ]);

        $errorLog = [];

        // 4. Find LabReceipt via barcode
        $testSample = TestSample::where('barcode', $input['barcode'])->first();

        $labReceipt = $testSample?->labReceipt
            ?? LabReceipt::where('barcode', $input['barcode'])->first();

        if (! $labReceipt) {
            $errorLog[] = "LabReceipt not found for barcode: {$input['barcode']}";
            $temp->update(['processed' => true, 'error_log' => $errorLog]);

            return response()->json([
                'type' => 'fail',
                'note' => 'LabReceipt not found',
                'status' => 404,
            ], 200);
        }

        // 5. Get all test IDs for this receipt
        $labTestIds = $labReceipt->labTest()->pluck('test_id')->toArray();

        // 6. Process each result inside a transaction
        DB::transaction(function () use ($input, $normalizedMapping, $labReceipt, $labTestIds, &$errorLog) {
            foreach ($input['results'] as $result) {
                $code = $result['code'];
                $value = $result['value'];
                $unit = $result['unit'] ?? '';

                $possibleIds = $normalizedMapping[$code] ?? [];
                if (empty($possibleIds)) {
                    $errorLog[] = "No mapping found for code: {$code}";

                    continue;
                }

                // Find component belonging to one of the receipt's tests
                $componentId = null;
                foreach ($possibleIds as $id) {
                    $component = TestComponent::find($id);
                    if ($component && in_array($component->test_id, $labTestIds)) {
                        $componentId = $id;
                        break;
                    }
                }

                if (! $componentId) {
                    $componentId = $possibleIds[0];
                    $errorLog[] = "Using fallback component ID for code {$code}: {$componentId}";
                }

                $component = TestComponent::find($componentId);
                if (! $component) {
                    $errorLog[] = "Component not found for ID: {$componentId} (code: {$code})";

                    continue;
                }

                $labTest = LabTest::where('lab_id', $labReceipt->id)
                    ->where('test_id', $component->test_id)
                    ->first();

                if (! $labTest) {
                    $errorLog[] = "LabTest not found for receipt {$labReceipt->id} and test {$component->test_id} (code: {$code})";

                    continue;
                }

                TestReport::updateOrCreate(
                    [
                        'model_id' => $labTest->id,
                        'model_type' => get_class($labTest),
                        'component_id' => $componentId,
                    ],
                    [
                        'result' => $value,
                        'unit' => $unit,
                        'patient_id' => $labReceipt->patient_id,
                        'test_id' => $component->test_id,
                    ]
                );
            }
        });

        $temp->update([
            'processed' => true,
            'error_log' => $errorLog,
        ]);

        return response()->json([
            'type' => 'success',
            'note' => 'Results processed successfully',
            'status' => 200,
        ], 200);
    }

    // ============================================
    // ENHANCED MIRTH API
    // ============================================

    /**
     * Get pending worklist for a specific machine (Enhanced)
     * GET /api/mirth/worklist
     * Headers: X-Machine-Key: hbh560
     *
     * FIXES:
     * - Removed redundant tube_barcode (barcode IS the tube barcode)
     * - Fixed broken testSample relation
     * - Set pushed_at on fetch (prevents duplicate processing)
     * - Added direction check
     * - Optional ?barcode= filter
     */
    public function getWorklist(Request $request)
    {
        $deviceModel = $request->header('X-Machine-Key');

        if (! $deviceModel) {
            return response()->json(['error' => 'X-Machine-Key header required'], 400);
        }

        $machine = Machine::query()
            ->where('device_model', $deviceModel)
            ->where('is_active', true)
            ->first();

        if (! $machine) {
            return response()->json(['error' => 'Machine not found'], 404);
        }

        // FIX: Direction check
        if (! $machine->canPullWorklist()) {
            Log::warning('Worklist pull rejected — machine cannot pull', [
                'machine_id' => $machine->id,
                'device_model' => $deviceModel,
                'direction' => $machine->direction,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Machine cannot pull worklists (direction: '.($machine->direction ?? 'unidirectional').')',
            ], 403);
        }

        // Optional barcode filter
        $barcodeFilter = $request->query('barcode') ?? $request->input('barcode');

        $query = Worklist::where('machine_id', $machine->id)
            ->where('status', 'pending')
            ->whereNull('pushed_at')
            ->with(['labReceipt.patient', 'labTest.test'])
            ->orderByRaw("FIELD(priority, 'high', 'routine', 'low')")
            ->orderBy('created_at', 'asc');

        if ($barcodeFilter) {
            $query->where('barcode', $barcodeFilter);
        }

        $worklists = $query->limit(100)->get();

        // FIX: Set pushed_at on fetch (optimistic reservation)
        if ($worklists->isNotEmpty()) {
            Worklist::whereIn('id', $worklists->pluck('id'))
                ->update(['pushed_at' => now()]);
        }

        $machine->update(['last_received_at' => now()]);

        return response()->json([
            'success' => true,
            'machine' => [
                'id' => $machine->id,
                'name' => $machine->display_name,
                'device_model' => $machine->device_model,
                'direction' => $machine->direction,
                'protocol' => $machine->protocol,
            ],
            'total' => $worklists->count(),
            'worklist' => $worklists->map(function ($worklist) {
                return [
                    'worklist_id' => $worklist->id,
                    'order_id' => $worklist->order_id,

                    // barcode IS the tube barcode
                    'barcode' => $worklist->barcode,
                    'receipt_barcode' => $worklist->receipt_barcode,

                    // Tube metadata
                    'sample_number' => $worklist->sample_number,
                    'suffix' => $worklist->suffix,
                    'tube_type' => $worklist->tube_type,
                    'timing' => $worklist->timing,
                    'sample_type' => $worklist->sample_type,

                    // Test metadata
                    'test_code' => $worklist->test_code,
                    'test_name' => $worklist->test_name,

                    // Priority & status
                    'priority' => $worklist->priority,
                    'status' => $worklist->status,

                    // Patient
                    'patient_id' => $worklist->patient_id,
                    'patient_name' => $worklist->patient_name,
                    'patient_age' => $worklist->patient_age,
                    'patient_gender' => $worklist->patient_gender,

                    // Dates
                    'order_date' => $worklist->order_date?->toISOString(),
                    'collection_date' => $worklist->collection_date?->toISOString(),

                    // Full machine mapping
                    'parameters' => $worklist->parameters,
                ];
            }),
        ]);
    }

    /**
     * Debug helper — get worklist for first machine (dev only)
     */
    public function getWorklistTest(Request $request)
    {
        $machine = Machine::query()->first();

        if (! $machine) {
            return response()->json(['error' => 'Machine not found'], 404);
        }

        $worklists = Worklist::where('machine_id', $machine->id)
            ->where('status', 'pending')
            ->whereNull('pushed_at')
            ->with(['labReceipt.patient', 'labTest.test'])
            ->orderByRaw("FIELD(priority, 'high', 'routine', 'low')")
            ->orderBy('created_at', 'asc')
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'machine' => [
                'id' => $machine->id,
                'name' => $machine->display_name,
                'device_model' => $machine->device_model,
            ],
            'total' => $worklists->count(),
            'worklist' => $worklists->map(fn ($w) => [
                'worklist_id' => $w->id,
                'barcode' => $w->barcode,
                'test_code' => $w->test_code,
                'test_name' => $w->test_name,
                'status' => $w->status,
            ]),
        ]);
    }

    /**
     * Acknowledge worklist (mark as processing)
     * POST /api/mirth/acknowledge
     */
    public function acknowledgeWorklist(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'worklist_id' => 'required|string',
            'barcode' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $worklist = Worklist::where('id', $request->worklist_id)
            ->where('barcode', $request->barcode)
            ->where('status', 'pending')
            ->first();

        if (! $worklist) {
            return response()->json(['error' => 'Worklist not found'], 404);
        }

        $worklist->status = 'processing';
        $worklist->started_at = now();
        // pushed_at already set from fetch — don't overwrite
        $worklist->save();

        if ($worklist->test_sample_item_id) {
            TestSampleItem::where('id', $worklist->test_sample_item_id)
                ->update(['status' => 'processing']);
        }

        if ($worklist->lab_test_id) {
            LabTest::where('id', $worklist->lab_test_id)
                ->update(['lab_status' => 'processing', 'started_at' => now()]);
        }

        Log::info('Worklist acknowledged', [
            'worklist_id' => $worklist->id,
            'barcode' => $request->barcode,
            'machine_id' => $worklist->machine_id,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Receive results with idempotency + direction check
     * POST /api/mirth/results
     */
    /**
     * Receive results with idempotency + direction check
     * POST /api/mirth/results
     */
    public function receiveResults(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcode' => 'required|string',
            'test_code' => 'required|string',
            'result_value' => 'required|string',
            'sample_number' => 'nullable|integer',
            'suffix' => 'nullable|string',   // 🟢 NEW: F / R / 2H / PP / C1
            'unit' => 'nullable|string',
            'reference_range' => 'nullable|string',
            'abnormal_flag' => 'nullable|string',
            'raw_message' => 'nullable|string',
            'worklist_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $deviceModel = $request->header('X-Machine-Key');

        if (! $deviceModel) {
            return response()->json(['error' => 'X-Machine-Key header required'], 400);
        }

        $machine = Machine::where('device_model', $deviceModel)
            ->where('is_active', true)
            ->first();

        if (! $machine) {
            return response()->json(['error' => 'Machine not found'], 404);
        }

        // FIX: Only bidirectional machines can submit results
        if (! $machine->canReceiveResults()) {
            Log::warning('Result rejected from non-bidirectional machine', [
                'machine_id' => $machine->id,
                'device_model' => $deviceModel,
                'direction' => $machine->direction,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Machine cannot submit results (direction: '.($machine->direction ?? 'unidirectional').')',
            ], 403);
        }

        // 🟢 Idempotency hash — now includes suffix so F/R/2H don't collide
        $messageHash = hash('sha256', json_encode([
            'barcode' => $request->barcode,
            'test_code' => $request->test_code,
            'sample_number' => $request->sample_number,
            'suffix' => $request->suffix,
            'date' => date('Y-m-d'),
            'machine_id' => $machine->id,
        ]));

        $existingResult = DB::table('lis_results')
            ->where('message_hash', $messageHash)
            ->first();

        if ($existingResult) {
            Log::info('Duplicate result rejected', [
                'barcode' => $request->barcode,
                'test_code' => $request->test_code,
                'suffix' => $request->suffix,
                'hash' => $messageHash,
            ]);

            return response()->json([
                'success' => true,
                'duplicate' => true,
                'message' => 'Result already processed',
            ]);
        }

        DB::beginTransaction();

        try {
            // ── Find matching worklist ────────────────────────────
            $worklist = null;

            if ($request->worklist_id) {
                $worklist = Worklist::where('id', $request->worklist_id)
                    ->where('machine_id', $machine->id)
                    ->first();
            }

            if (! $worklist) {
                // 🟢 GLUE-aware lookup: barcode + test_code + machine + suffix + sample_number
                $worklist = Worklist::where('barcode', $request->barcode)
                    ->where('test_code', $request->test_code)
                    ->where('machine_id', $machine->id)
                    ->whereIn('status', ['pending', 'processing'])
                    ->when($request->filled('sample_number'), fn ($q) => $q->where('sample_number', $request->sample_number)
                    )
                    ->when($request->filled('suffix'), fn ($q) => $q->where('suffix', $request->suffix)
                    )
                    ->orderBy('created_at', 'asc')
                    ->first();
            }

            $resultId = DB::table('lis_results')->insertGetId([
                'id' => (string) Str::uuid7(),
                'worklist_id' => $worklist?->id,
                'machine_id' => $machine->id,
                'barcode' => $request->barcode,
                'test_code' => $request->test_code,
                'result_value' => $request->result_value,
                'result_numeric' => is_numeric($request->result_value) ? $request->result_value : null,
                'unit' => $request->unit,
                'reference_range' => $request->reference_range,
                'abnormal_flag' => $request->abnormal_flag,
                'raw_message' => $request->raw_message,
                'message_hash' => $messageHash,
                'received_at' => now(),
                'created_at' => now(),
            ]);

            if ($worklist) {
                $worklist->status = 'completed';
                $worklist->completed_at = now();
                $worklist->result_data = array_merge($worklist->result_data ?? [], [
                    'result_value' => $request->result_value,
                    'unit' => $request->unit,
                    'abnormal_flag' => $request->abnormal_flag,
                    'reference_range' => $request->reference_range,
                ]);
                $worklist->save();

                if ($worklist->test_sample_item_id) {
                    TestSampleItem::where('id', $worklist->test_sample_item_id)
                        ->update(['status' => 'completed']);
                }

                if ($worklist->lab_test_id) {
                    LabTest::where('id', $worklist->lab_test_id)
                        ->update([
                            'lab_status' => 'completed',
                            'completed_at' => now(),
                            'has_result' => true,
                        ]);
                }
            }

            DB::commit();

            Log::info('Result stored successfully', [
                'result_id' => $resultId,
                'barcode' => $request->barcode,
                'test_code' => $request->test_code,
                'suffix' => $request->suffix,
                'machine' => $deviceModel,
                'worklist_id' => $worklist?->id,
            ]);

            return response()->json([
                'success' => true,
                'duplicate' => false,
                'result_id' => $resultId,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Result storage failed', [
                'error' => $e->getMessage(),
                'barcode' => $request->barcode,
                'test_code' => $request->test_code,
            ]);

            DB::table('failed_lis_results')->insert([
                'id' => (string) Str::uuid7(),
                'machine_id' => $machine->id,
                'barcode' => $request->barcode,
                'test_code' => $request->test_code,
                'raw_message' => $request->raw_message,
                'error_message' => $e->getMessage(),
                'retry_count' => 0,
                'max_retries' => 5,
                'status' => 'pending',
                'created_at' => now(),
            ]);

            return response()->json(['error' => 'Failed to store result'], 500);
        }
    }

    /**
     * Submit batch results
     * POST /api/mirth/submit-batch
     */
    /**
     * Submit batch results
     * POST /api/mirth/submit-batch
     */
    public function submitBatch(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'results' => 'required|array',
            'results.*.barcode' => 'required|string',
            'results.*.test_code' => 'required|string',
            'results.*.result_value' => 'required|string',
            'results.*.sample_number' => 'nullable|integer',
            'results.*.suffix' => 'nullable|string',   // 🟢 NEW
            'results.*.unit' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $deviceModel = $request->header('X-Machine-Key');

        if (! $deviceModel) {
            return response()->json(['error' => 'X-Machine-Key header required'], 400);
        }

        $machine = Machine::where('device_model', $deviceModel)
            ->where('is_active', true)
            ->first();

        if (! $machine) {
            return response()->json(['error' => 'Machine not found'], 404);
        }

        if (! $machine->canReceiveResults()) {
            return response()->json([
                'success' => false,
                'error' => 'Machine cannot submit results',
            ], 403);
        }

        $processed = 0;
        $duplicates = 0;

        DB::transaction(function () use ($request, $machine, &$processed, &$duplicates) {
            foreach ($request->results as $result) {
                $suffix = $result['suffix'] ?? null;
                $sampleNumber = $result['sample_number'] ?? null;

                // 🟢 Idempotency hash includes suffix + sample_number
                $messageHash = hash('sha256', json_encode([
                    'barcode' => $result['barcode'],
                    'test_code' => $result['test_code'],
                    'sample_number' => $sampleNumber,
                    'suffix' => $suffix,
                    'date' => date('Y-m-d'),
                    'machine_id' => $machine->id,
                ]));

                $existing = DB::table('lis_results')->where('message_hash', $messageHash)->first();
                if ($existing) {
                    $duplicates++;

                    continue;
                }

                // 🟢 Match the worklist (GLUE-aware)
                $worklist = Worklist::where('barcode', $result['barcode'])
                    ->where('test_code', $result['test_code'])
                    ->where('machine_id', $machine->id)
                    ->whereIn('status', ['pending', 'processing'])
                    ->when(! empty($sampleNumber), fn ($q) => $q->where('sample_number', $sampleNumber))
                    ->when(! empty($suffix), fn ($q) => $q->where('suffix', $suffix))
                    ->orderBy('created_at', 'asc')
                    ->first();

                DB::table('lis_results')->insert([
                    'id' => (string) Str::uuid7(),
                    'worklist_id' => $worklist?->id,
                    'machine_id' => $machine->id,
                    'barcode' => $result['barcode'],
                    'test_code' => $result['test_code'],
                    'result_value' => $result['result_value'],
                    'result_numeric' => is_numeric($result['result_value']) ? $result['result_value'] : null,
                    'unit' => $result['unit'] ?? null,
                    'message_hash' => $messageHash,
                    'received_at' => now(),
                    'created_at' => now(),
                ]);

                if ($worklist) {
                    $worklist->status = 'completed';
                    $worklist->completed_at = now();
                    $worklist->save();

                    if ($worklist->test_sample_item_id) {
                        TestSampleItem::where('id', $worklist->test_sample_item_id)
                            ->update(['status' => 'completed']);
                    }

                    if ($worklist->lab_test_id) {
                        LabTest::where('id', $worklist->lab_test_id)
                            ->update([
                                'lab_status' => 'completed',
                                'completed_at' => now(),
                                'has_result' => true,
                            ]);
                    }
                }

                $processed++;
            }
        });

        Log::info('Batch results processed', [
            'machine' => $deviceModel,
            'processed' => $processed,
            'duplicates' => $duplicates,
        ]);

        return response()->json([
            'success' => true,
            'processed' => $processed,
            'duplicates' => $duplicates,
        ]);
    }

    /**
     * Health check
     * GET /api/mirth/health
     */
    public function health(Request $request)
    {
        $deviceModel = $request->header('X-Machine-Key');

        $machine = null;
        if ($deviceModel) {
            $machine = Machine::where('device_model', $deviceModel)->first();
        }

        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'version' => '2.1.0',
            'machine' => $machine ? [
                'id' => $machine->id,
                'name' => $machine->display_name,
                'device_model' => $machine->device_model,
                'direction' => $machine->direction,
                'is_active' => $machine->is_active,
            ] : null,
            'pending_worklists' => $machine
                ? Worklist::where('machine_id', $machine->id)->where('status', 'pending')->count()
                : 0,
            'processing_worklists' => $machine
                ? Worklist::where('machine_id', $machine->id)->where('status', 'processing')->count()
                : 0,
            'completed_today' => $machine
                ? Worklist::where('machine_id', $machine->id)->whereDate('completed_at', today())->count()
                : 0,
        ]);
    }

    /**
     * Get failed results for retry
     * GET /api/mirth/failed-results
     */
    public function getFailedResults(Request $request)
    {
        $deviceModel = $request->header('X-Machine-Key');

        if (! $deviceModel) {
            return response()->json(['error' => 'X-Machine-Key header required'], 400);
        }

        $machine = Machine::where('device_model', $deviceModel)->first();

        if (! $machine) {
            return response()->json(['error' => 'Machine not found'], 404);
        }

        $failedResults = DB::table('failed_lis_results')
            ->where('machine_id', $machine->id)
            ->where('status', 'pending')
            ->whereColumn('retry_count', '<', 'max_retries')
            ->orderBy('created_at', 'asc')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'total' => $failedResults->count(),
            'results' => $failedResults,
        ]);
    }

    /**
     * Retry failed result
     * POST /api/mirth/retry-failed/{id}
     */
    public function retryFailed(Request $request, $id)
    {
        $deviceModel = $request->header('X-Machine-Key');

        if (! $deviceModel) {
            return response()->json(['error' => 'X-Machine-Key header required'], 400);
        }

        $machine = Machine::where('device_model', $deviceModel)->first();

        if (! $machine) {
            return response()->json(['error' => 'Machine not found'], 404);
        }

        $failedResult = DB::table('failed_lis_results')
            ->where('id', $id)
            ->where('machine_id', $machine->id)
            ->first();

        if (! $failedResult) {
            return response()->json(['error' => 'Failed result not found'], 404);
        }

        DB::table('failed_lis_results')
            ->where('id', $id)
            ->update([
                'status' => 'pending',
                'retry_count' => $failedResult->retry_count + 1,
            ]);

        Log::info('Failed result retried', [
            'failed_result_id' => $id,
            'machine' => $deviceModel,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Failed result queued for retry',
        ]);
    }
}
