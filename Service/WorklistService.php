<?php

namespace App\Services;

use App\Models\LabReceipt;
use App\Models\LabTest;
use App\Models\Machine;
use App\Models\MachineTestMapping;
use App\Models\TestSample;
use App\Models\TestSampleItem;
use App\Models\Worklist;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WorklistService
{
    const PRIORITY_HIGH = 'high';

    const PRIORITY_ROUTINE = 'routine';

    const PRIORITY_LOW = 'low';

    const STATUS_PENDING = 'pending';

    const STATUS_PROCESSING = 'processing';

    const STATUS_COMPLETED = 'completed';

    const STATUS_CANCELLED = 'cancelled';

    const STATUS_LOCKED = 'locked';

    // ============================================
    // CORE WORKLIST CREATION
    // ============================================

    /**
     * Create worklist for a lab receipt group - WITH DEBUG
     */
    public function createWorklistForGroup(string $labReceiptId, $collectedAt = null): array
    {
        $collectedAt = $collectedAt ?? now();

        $receipt = LabReceipt::find($labReceiptId);
        if (! $receipt) {
            Log::error('WORKLIST: Receipt not found', ['lab_receipt_id' => $labReceiptId]);
            return [];
        }

        // Eager-load everything we need — no N+1
        $items = TestSampleItem::with([
            'testSample.labReceipt.patient',
            'labTest.test',
            'test',
        ])
            ->whereHas('testSample', fn ($q) => $q->where('lab_receipt_id', $labReceiptId))
            ->whereNotNull('collected_by')   // FIXED: was ->where('is_collected', true) — that is an accessor, not a DB column
            ->get();

        if ($items->isEmpty()) {
            Log::warning('WORKLIST: No collected items', ['lab_receipt_id' => $labReceiptId]);
            return [];
        }

        $allWorklists = [];

        foreach ($items as $item) {
            $created = $this->createWorklistForTest($item);
            $allWorklists = array_merge($allWorklists, $created);
        }

        Log::info('WORKLIST: Batch complete', [
            'lab_receipt_id'  => $labReceiptId,
            'total_worklists' => count($allWorklists),
        ]);

        return $allWorklists;
    }

    /**
     * Create worklist for a single test - WITH FIXES
     *
     * Fixes applied:
     * - Resolves test_id with fallback chain (item → labTest)
     * - Loud logging when things fail
     * - Populates test_sample_id
     * - Column reduction 2026-11-18: no more order_date/parameters/sample_number
     *   writes — machine_mapping_id is a native column, mapping params are fetched
     *   by Mirth via GET /api/mirth/machine-config/{device_model} (cached per channel)
     */
    public function createWorklistForTest(TestSampleItem $item): array
    {
        $labTest    = $item->labTest;
        $testSample = $item->testSample;

        // ── Resolve test_id with fallback ─────────────────────────
        $testId = $item->test_id ?? $labTest?->test_id;
        if (! $testId) {
            Log::error('WORKLIST SKIPPED: cannot resolve test_id', [
                'item_id'       => $item->id,
                'lab_test_id'   => $item->lab_test_id,
                'item_test_id'  => $item->test_id,
                'lab_test_test' => $labTest?->test_id,
            ]);
            return [];
        }

        if (! $labTest || ! $testSample) {
            Log::error('WORKLIST SKIPPED: missing relations', [
                'item_id'        => $item->id,
                'has_labTest'    => (bool) $labTest,
                'has_testSample' => (bool) $testSample,
            ]);
            return [];
        }

        // ── Find active machine mappings (single query) ───────────
        $machineMappings = MachineTestMapping::query()
            ->where('test_id', $testId)
            ->where('is_active', true)
            ->whereHas('machine', fn ($q) => $q->where('is_active', true))
            ->with('machine')
            ->get();

        if ($machineMappings->isEmpty()) {
            Log::warning('WORKLIST SKIPPED: no active machine mapping', [
                'item_id'   => $item->id,
                'test_id'   => $testId,
                'test_name' => $labTest->test?->name,
            ]);
            return [];
        }

        // ── resolve barcode / tube basics (column reduction 2026-11-18) ──
        // worklists.sample_number is DROPPED → tube number = test_samples.tube_sequence.
        // test_sample_items.suffix is KEPT (needed) but the worklist create no longer
        // writes sample_number / suffix / parameters — Mirth resolves the tube by barcode.
        $barcode  = $testSample->barcode;
        $tubeType = $testSample->tube_type;

        $labReceipt = $testSample->labReceipt;
        $patient    = $labReceipt?->patient;

        $worklists = [];

        foreach ($machineMappings as $mapping) {
            $machine = $mapping->machine;

            // Duplicate guard (same item + same machine)
            $existing = Worklist::where('test_sample_item_id', $item->id)
                ->where('machine_id', $machine->id)
                ->first();

            if ($existing) {
                Log::info('WORKLIST: already exists', [
                    'item_id'     => $item->id,
                    'machine_id'  => $machine->id,
                    'worklist_id' => $existing->id,
                ]);
                $worklists[] = $existing;
                continue;
            }

            // Lock if test already completed elsewhere
            $isCompleted = Worklist::where('test_sample_item_id', $item->id)
                ->where('status', self::STATUS_COMPLETED)
                ->exists();

            $status = $isCompleted ? self::STATUS_LOCKED : self::STATUS_PENDING;

            // Mapping params are NOT embedded anymore — Mirth fetches them once via
            // GET /api/mirth/machine-config/{device_model} and caches per channel start.

            try {
                $worklist = Worklist::create([
                    'test_sample_item_id' => $item->id,
                    'lab_receipt_id'      => $labReceipt?->id,
                    'lab_test_id'         => $labTest->id,
                    'test_id'             => $testId,
                    'machine_id'          => $machine->id,
                    'machine_mapping_id'  => $mapping->id,   // NEW — machine_test_mappings(id) (was inside parameters JSON)
                    'patient_id'          => $patient?->id,

                    'barcode'             => $barcode,
                    'receipt_barcode'     => $labReceipt?->barcode,

                    'test_sample_id'      => $testSample->id,

                    'patient_name'        => $patient?->name,
                    'patient_age'         => $patient?->age,
                    'patient_gender'      => $patient?->gender,

                    'test_code'           => $mapping->test_code
                        ?? $labTest->test?->code
                            ?? (string) $testId,
                    'test_name'           => $labTest->test?->name ?? 'Unknown',
                    'sample_type'         => $mapping->sample_type
                        ?? $labTest->test?->sample_type
                            ?? 'Serum',
                    'tube_type'           => $tubeType,
                    'timing'              => $testSample->timing,

                    'priority'            => $this->determinePriority($labReceipt, $machine),
                    'status'              => $status,
                    'collection_date'     => $testSample->collected_at ?? now(),
                    'source'              => 'fanout',
                    // REMOVED: order_date, parameters, sample_number
                    //          (suffix column kept, not written here — rides in receipt_barcode)
                ]);

                Log::info('WORKLIST CREATED', [
                    'worklist_id'   => $worklist->id,
                    'item_id'       => $item->id,
                    'machine_id'    => $machine->id,
                    'machine_name'  => $machine->display_name,
                    'test_name'     => $labTest->test?->name,
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
                throw $e;
            }
        }

        return $worklists;
    }

    /**
     * Create recheck worklist for a test
     */
    public function createRecheckWorklist(string $labTestId, string $reason, string $barcode, ?string $specificMachineId = null): array
    {
        try {
            $labTest = LabTest::with(['test', 'receipt.patient', 'sampleItems.testSample'])->findOrFail($labTestId);

            // Prevent duplicate recheck worklists
            $existingRecheck = Worklist::where('lab_test_id', $labTest->id)
                ->where('barcode', $barcode)
                ->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING])
                ->first();

            if ($existingRecheck) {
                Log::info('Recheck worklist already exists', [
                    'lab_test_id' => $labTestId,
                    'barcode' => $barcode,
                    'worklist_id' => $existingRecheck->id,
                ]);

                return ['success' => true, 'worklist' => $existingRecheck];
            }

            // Get machine configuration
            $machineConfig = null;
            if ($specificMachineId) {
                $machineConfig = MachineTestMapping::where('machine_id', $specificMachineId)
                    ->where('test_id', $labTest->test_id)
                    ->where('is_active', true)
                    ->with('machine')
                    ->first();
            } else {
                $machineConfig = MachineTestMapping::where('test_id', $labTest->test_id)
                    ->where('is_active', true)
                    ->with('machine')
                    ->first();
            }

            if (! $machineConfig || ! $machineConfig->machine) {
                return ['success' => false, 'message' => 'No machine configured for this test'];
            }

            // Tube number — test_sample_items.sample_number is DROPPED; use the tube's sequence
            $sampleNumber = $labTest->sampleItems->first()?->testSample?->tube_sequence ?? 1;

            $machine = $machineConfig->machine;

            // Get or create TestSample for recheck
            $testSample = TestSample::firstOrCreate(
                ['barcode' => $barcode],
                [
                    'lab_receipt_id' => $labTest->lab_id,
                    'patient_id' => $labTest->receipt->patient_id,
                    'tube_type' => 'Recheck Tube',
                    'tube_sequence' => $sampleNumber,
                    'barcode_source' => 'system',
                ]
            );

            // Get or create TestSampleItem
            $testSampleItem = TestSampleItem::firstOrCreate(
                [
                    'test_sample_id' => $testSample->id,
                    'lab_test_id' => $labTest->id,
                ],
                [
                    'test_id' => $labTest->test_id,
                    'notes' => "Recheck: {$reason}",
                ]
            );

            // Get patient info for denormalization
            $patient = $labTest->receipt->patient;
            $receiptBarcode = $labTest->receipt->barcode;

            $worklist = Worklist::create([
                'test_sample_item_id' => $testSampleItem->id,
                'lab_receipt_id' => $labTest->lab_id,
                'lab_test_id' => $labTest->id,
                'test_id' => $labTest->test_id,
                'machine_id' => $machine->id,
                'patient_id' => $labTest->receipt->patient_id,

                'barcode' => $barcode,
                'receipt_barcode' => $receiptBarcode,

                'patient_name' => $patient?->name,
                'patient_age' => $patient?->age,
                'patient_gender' => $patient?->gender,

                'test_code' => $machineConfig->test_code ?? $labTest->test->code ?? (string) $labTest->test_id,
                'test_name' => $labTest->test->name.' (Recheck)',
                'sample_type' => $machineConfig->sample_type ?? $labTest->test->sample_type ?? 'Serum',
                'tube_type' => $testSample->tube_type,
                'timing' => null,

                'priority' => self::PRIORITY_HIGH,
                'status' => self::STATUS_PENDING,
                'collection_date' => now(),
                'source' => 'recheck',   // NEW — was parameters.is_recheck (parameters column dropped)
                // REMOVED: order_date, parameters (is_recheck/recheck_reason/original_test_id/recheck_type)
            ]);

            Log::info('Recheck worklist created', [
                'lab_test_id' => $labTestId,
                'barcode' => $barcode,
                'worklist_id' => $worklist->id,
                'recheck_reason' => $reason,
                'original_test_id' => $labTest->retest_of ?? $labTest->id,
            ]);

            return ['success' => true, 'worklist' => $worklist];

        } catch (\Exception $e) {
            Log::error('Failed to create recheck worklist', [
                'lab_test_id' => $labTestId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ============================================
    // WORKLIST RETRIEVAL
    // ============================================

    /**
     * Get next available worklist for a machine
     */
    public function getNextWorklistForMachine(string $machineId): ?Worklist
    {
        $worklists = Worklist::where('machine_id', $machineId)
            ->where('status', Worklist::STATUS_PENDING)
            ->with(['testSampleItem', 'labTest', 'test', 'machine'])
            ->orderByRaw("FIELD(priority, 'high', 'routine', 'low')")
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($worklists as $worklist) {
            if ($worklist->canProcess()) {
                return $worklist;
            }
        }

        return null;
    }

    /**
     * Get all pending worklists for a machine (for API)
     */
    public function getPendingWorklistsForMachine(string $machineId, int $limit = 50)
    {
        return Worklist::where('machine_id', $machineId)
            ->whereIn('status', [Worklist::STATUS_PENDING, Worklist::STATUS_PROCESSING])
            ->with([
                'testSampleItem.testSample',
                'testSampleItem.test',
                'labReceipt.patient',
                'test',
                'machine',
            ])
            ->orderByRaw("FIELD(priority, 'high', 'routine', 'low')")
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get worklist by ID
     */
    public function getWorklistById(string $worklistId): ?Worklist
    {
        return Worklist::with([
            'machine',
            'labReceipt.patient',
            'testSample',
            'testSampleItem',
            'test',
            'labTest',
        ])->find($worklistId);
    }

    /**
     * Get worklist by barcode
     */
    public function getWorklistByBarcode(string $barcode): ?Worklist
    {
        return Worklist::where('barcode', $barcode)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING])
            ->with(['labReceipt.patient', 'test', 'machine'])
            ->first();
    }

    /**
     * Get worklists by lab receipt
     */
    public function getWorklistsByReceipt(string $labReceiptId): array
    {
        return Worklist::where('lab_receipt_id', $labReceiptId)
            ->with(['machine', 'test'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Get worklists by barcode (grouped by tube)
     */
    public function getWorklistsByBarcodeGroup(string $labReceiptId): array
    {
        return Worklist::where('lab_receipt_id', $labReceiptId)
            ->with(['machine'])
            ->orderBy('barcode')
            ->get()
            ->groupBy('barcode')
            ->toArray();
    }

    // ============================================
    // WORKLIST ACTIONS
    // ============================================

    /**
     * Acknowledge worklist (machine picks up test)
     */
    public function acknowledgeWorklist(string $worklistId): array
    {
        $worklist = Worklist::find($worklistId);
        if (! $worklist) {
            return ['success' => false, 'message' => 'Worklist not found'];
        }

        if (! $worklist->canProcess()) {
            return ['success' => false, 'message' => 'Test already completed on another machine'];
        }

        if ($worklist->markAsProcessing()) {
            $worklist->markAsPushed();

            // Update related TestSampleItem if exists
            if ($worklist->test_sample_item_id) {
                TestSampleItem::where('id', $worklist->test_sample_item_id)
                    ->update(['status' => 'processing']);
            }

            // Update LabTest if exists
            if ($worklist->lab_test_id) {
                LabTest::where('id', $worklist->lab_test_id)
                    ->update(['lab_status' => 'processing', 'started_at' => now()]);
            }

            return [
                'success' => true,
                'message' => 'Worklist acknowledged',
                'worklist' => $worklist,
            ];
        }

        return ['success' => false, 'message' => 'Failed to acknowledge worklist'];
    }

    /**
     * Submit result for a worklist
     */
    public function submitResult(string $worklistId): array
    {
        $worklist = Worklist::find($worklistId);
        if (! $worklist) {
            return ['success' => false, 'message' => 'Worklist not found'];
        }

        if ($worklist->status === Worklist::STATUS_COMPLETED) {
            return ['success' => false, 'message' => 'Test already completed'];
        }

        if ($worklist->markAsCompleted()) {   // no $resultData — the result lives in lis_results
            // Update TestSampleItem
            if ($worklist->test_sample_item_id) {
                TestSampleItem::where('id', $worklist->test_sample_item_id)
                    ->update(['status' => 'completed']);
            }

            // Update LabTest
            if ($worklist->lab_test_id) {
                LabTest::where('id', $worklist->lab_test_id)
                    ->update([
                        'lab_status' => 'completed',
                        'completed_at' => now(),
                        'has_result' => true,
                    ]);
            }

            return [
                'success' => true,
                'message' => 'Result submitted successfully',
                'worklist' => $worklist,
            ];
        }

        return ['success' => false, 'message' => 'Failed to submit result'];
    }

    /**
     * Cancel worklist
     */
    public function cancelWorklist(string $worklistId, ?string $reason = null): array
    {
        $worklist = Worklist::find($worklistId);
        if (! $worklist) {
            return ['success' => false, 'message' => 'Worklist not found'];
        }

        if ($worklist->status === Worklist::STATUS_COMPLETED) {
            return ['success' => false, 'message' => 'Cannot cancel completed worklist'];
        }

        if ($worklist->markAsCancelled($reason)) {
            return [
                'success' => true,
                'message' => 'Worklist cancelled',
                'worklist' => $worklist,
            ];
        }

        return ['success' => false, 'message' => 'Failed to cancel worklist'];
    }

    /**
     * Retry worklist (reset to pending)
     */
    public function retryWorklist(string $worklistId): array
    {
        $worklist = Worklist::find($worklistId);
        if (! $worklist) {
            return ['success' => false, 'message' => 'Worklist not found'];
        }

        if ($worklist->status === Worklist::STATUS_COMPLETED) {
            return ['success' => false, 'message' => 'Cannot retry completed worklist'];
        }

        if ($worklist->markAsPending()) {
            return [
                'success' => true,
                'message' => 'Worklist reset to pending',
                'worklist' => $worklist,
            ];
        }

        return ['success' => false, 'message' => 'Failed to retry worklist'];
    }

    /**
     * Delete worklist (only pending)
     */
    public function deleteWorklist(string $worklistId): array
    {
        $worklist = Worklist::find($worklistId);
        if (! $worklist) {
            return ['success' => false, 'message' => 'Worklist not found'];
        }

        if ($worklist->status !== Worklist::STATUS_PENDING) {
            return ['success' => false, 'message' => 'Only pending worklists can be deleted'];
        }

        $worklist->delete();

        return ['success' => true, 'message' => 'Worklist deleted'];
    }

    /**
     * Update worklist status (simple wrapper)
     */
    public function updateWorklistStatus(string $worklistId, string $status): bool
    {
        $worklist = Worklist::find($worklistId);
        if (! $worklist) {
            return false;
        }

        $worklist->status = $status;
        // REMOVED: if ($resultData) { $worklist->result_data = $resultData; } — column dropped
        if ($status === self::STATUS_COMPLETED) {
            $worklist->completed_at = now();
        }
        if ($status === self::STATUS_PROCESSING) {
            $worklist->started_at = now();
        }

        return $worklist->save();
    }

    // ============================================
    // WORKLIST REASSIGNMENT
    // ============================================

    /**
     * Reassign worklist to a different machine
     */
    public function reassignWorklist(string $worklistId, string $newMachineId, ?string $reason = null): array
    {
        try {
            $worklist = Worklist::findOrFail($worklistId);

            if ($worklist->status !== self::STATUS_PENDING) {
                return ['success' => false, 'message' => 'Only pending worklists can be reassigned'];
            }

            // Check if new machine can run this test
            $machineConfig = MachineTestMapping::where('machine_id', $newMachineId)
                ->where('test_id', $worklist->test_id)
                ->where('is_active', true)
                ->first();

            if (! $machineConfig) {
                return ['success' => false, 'message' => 'New machine cannot run this test'];
            }

            $oldMachineId = $worklist->machine_id;

            // Update mapping + test_code to match the new machine
            $worklist->machine_id = $newMachineId;
            $worklist->machine_mapping_id = $machineConfig->id;   // NEW — was inside parameters JSON
            $worklist->test_code = $machineConfig->test_code ?? $worklist->test_code;
            $worklist->save();

            // Audit moved to the dedicated worklist_reassignments table
            // (replaces the dropped reassignment_history JSON — §1.7.3)
            \DB::table('worklist_reassignments')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid7(),
                'worklist_id' => $worklistId,
                'from_machine_id' => $oldMachineId,
                'to_machine_id' => $newMachineId,
                'reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('Worklist reassigned', [
                'worklist_id' => $worklistId,
                'from_machine' => $oldMachineId,
                'to_machine' => $newMachineId,
            ]);

            return ['success' => true, 'message' => 'Worklist reassigned successfully', 'worklist' => $worklist];

        } catch (\Exception $e) {
            Log::error('Worklist reassignment failed', [
                'worklist_id' => $worklistId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Reassign worklist by barcode (all tests on a tube)
     */
    public function reassignWorklistByBarcode(string $barcode, ?string $newMachineId = null): array
    {
        try {
            $worklists = Worklist::where('barcode', $barcode)
                ->where('status', self::STATUS_PENDING)
                ->get();

            if ($worklists->isEmpty()) {
                return ['success' => false, 'message' => 'No pending worklists found for this barcode'];
            }

            $updated = 0;
            foreach ($worklists as $worklist) {
                if ($newMachineId) {
                    $result = $this->reassignWorklist($worklist->id, $newMachineId);
                    if ($result['success']) {
                        $updated++;
                    }
                } else {
                    // Auto-assign to best machine
                    $bestMachine = MachineTestMapping::where('test_id', $worklist->test_id)
                        ->where('is_active', true)
                        ->orderBy('priority', 'desc')
                        ->with('machine')
                        ->first();

                    if ($bestMachine && $bestMachine->machine) {
                        $result = $this->reassignWorklist($worklist->id, $bestMachine->machine_id);
                        if ($result['success']) {
                            $updated++;
                        }
                    }
                }
            }

            return [
                'success' => true,
                'message' => "{$updated} worklist(s) reassigned",
                'updated' => $updated,
                'total' => $worklists->count(),
            ];

        } catch (\Exception $e) {
            Log::error('Barcode reassignment failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Bulk reassign multiple worklists
     */
    public function reassignMultipleWorklists(array $worklistIds, string $newMachineId): array
    {
        try {
            $updated = 0;
            $failed = 0;

            foreach ($worklistIds as $worklistId) {
                $result = $this->reassignWorklist($worklistId, $newMachineId);
                if ($result['success']) {
                    $updated++;
                } else {
                    $failed++;
                }
            }

            return [
                'success' => true,
                'message' => "{$updated} worklist(s) reassigned, {$failed} failed",
                'updated' => $updated,
                'failed' => $failed,
            ];

        } catch (\Exception $e) {
            Log::error('Bulk reassignment failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Reassign all worklists from a source machine
     */
    public function reassignAllFromMachine(string $sourceMachineId, ?string $targetMachineId = null): array
    {
        try {
            $worklists = Worklist::where('machine_id', $sourceMachineId)
                ->where('status', self::STATUS_PENDING)
                ->get();

            if ($worklists->isEmpty()) {
                return ['success' => false, 'message' => 'No pending worklists found for this machine'];
            }

            $worklistIds = $worklists->pluck('id')->toArray();

            if ($targetMachineId) {
                return $this->reassignMultipleWorklists($worklistIds, $targetMachineId);
            }

            // Auto-assign each to best machine
            $updated = 0;
            foreach ($worklists as $worklist) {
                $bestMachine = MachineTestMapping::where('test_id', $worklist->test_id)
                    ->where('is_active', true)
                    ->orderBy('priority', 'desc')
                    ->with('machine')
                    ->first();

                if ($bestMachine && $bestMachine->machine) {
                    $result = $this->reassignWorklist($worklist->id, $bestMachine->machine_id);
                    if ($result['success']) {
                        $updated++;
                    }
                }
            }

            return [
                'success' => true,
                'message' => "{$updated} worklist(s) reassigned",
                'updated' => $updated,
            ];

        } catch (\Exception $e) {
            Log::error('Machine reassignment failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Reassign worklists by date range
     */
    public function reassignWorklistsByDateRange(string $startDate, string $endDate, ?string $newMachineId = null): array
    {
        try {
            $worklists = Worklist::whereDate('created_at', '>=', $startDate)
                ->whereDate('created_at', '<=', $endDate)
                ->where('status', self::STATUS_PENDING)
                ->get();

            if ($worklists->isEmpty()) {
                return ['success' => false, 'message' => 'No pending worklists found in this date range'];
            }

            $worklistIds = $worklists->pluck('id')->toArray();

            if ($newMachineId) {
                return $this->reassignMultipleWorklists($worklistIds, $newMachineId);
            }

            // Auto-assign each to best machine
            $updated = 0;
            foreach ($worklists as $worklist) {
                $bestMachine = MachineTestMapping::where('test_id', $worklist->test_id)
                    ->where('is_active', true)
                    ->orderBy('priority', 'desc')
                    ->with('machine')
                    ->first();

                if ($bestMachine && $bestMachine->machine) {
                    $result = $this->reassignWorklist($worklist->id, $bestMachine->machine_id);
                    if ($result['success']) {
                        $updated++;
                    }
                }
            }

            return [
                'success' => true,
                'message' => "{$updated} worklist(s) reassigned",
                'updated' => $updated,
            ];

        } catch (\Exception $e) {
            Log::error('Date range reassignment failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ============================================
    // STATISTICS
    // ============================================

    /**
     * Get worklist statistics
     */
    public function getStatistics(?string $machineId = null): array
    {
        $query = Worklist::query();

        if ($machineId) {
            $query->where('machine_id', $machineId);
        }

        return [
            'pending' => (clone $query)->where('status', self::STATUS_PENDING)->count(),
            'processing' => (clone $query)->where('status', self::STATUS_PROCESSING)->count(),
            'completed' => (clone $query)->where('status', self::STATUS_COMPLETED)->count(),
            'locked' => (clone $query)->where('status', self::STATUS_LOCKED)->count(),
            'cancelled' => (clone $query)->where('status', self::STATUS_CANCELLED)->count(),
            'total' => (clone $query)->count(),
        ];
    }

    /**
     * Get machine statistics
     */
    public function getMachineStatistics(string $machineId): array
    {
        $query = Worklist::where('machine_id', $machineId);

        return [
            'machine_id' => $machineId,
            'pending' => (clone $query)->where('status', self::STATUS_PENDING)->count(),
            'processing' => (clone $query)->where('status', self::STATUS_PROCESSING)->count(),
            'completed' => (clone $query)->where('status', self::STATUS_COMPLETED)->count(),
            'completed_today' => (clone $query)->where('status', self::STATUS_COMPLETED)
                ->whereDate('completed_at', today())
                ->count(),
            'total_today' => (clone $query)->whereDate('created_at', today())
                ->count(),
        ];
    }

    /**
     * Get worklist count by status for a machine
     */
    public function getWorklistCountByStatus(string $machineId): array
    {
        $statuses = [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_LOCKED,
            self::STATUS_CANCELLED,
        ];

        $counts = [];
        foreach ($statuses as $status) {
            $counts[$status] = Worklist::where('machine_id', $machineId)
                ->where('status', $status)
                ->count();
        }

        return $counts;
    }

    /**
     * Get daily worklist report
     */
    public function getDailyReport(string $date): array
    {
        $query = Worklist::whereDate('created_at', $date);

        return [
            'date' => $date,
            'total' => (clone $query)->count(),
            'pending' => (clone $query)->where('status', self::STATUS_PENDING)->count(),
            'processing' => (clone $query)->where('status', self::STATUS_PROCESSING)->count(),
            'completed' => (clone $query)->where('status', self::STATUS_COMPLETED)->count(),
            'cancelled' => (clone $query)->where('status', self::STATUS_CANCELLED)->count(),
            'locked' => (clone $query)->where('status', self::STATUS_LOCKED)->count(),
            'by_machine' => (clone $query)->select('machine_id', \DB::raw('COUNT(*) as count'))
                ->groupBy('machine_id')
                ->with('machine')
                ->get()
                ->map(function ($item) {
                    return [
                        'machine_id' => $item->machine_id,
                        'machine_name' => $item->machine->display_name ?? 'Unknown',
                        'count' => $item->count,
                    ];
                })
                ->toArray(),
        ];
    }

    // ============================================
    // AVAILABLE MACHINES
    // ============================================

    /**
     * Get available machines for a test
     */
    public function getAvailableMachinesForTest(string $testId): array
    {
        return MachineTestMapping::where('test_id', $testId)
            ->where('is_active', true)
            ->whereHas('machine', function ($q) {
                $q->where('is_active', true);
            })
            ->with('machine')
            ->get()
            ->map(function ($mapping) {
                return [
                    'machine_id' => $mapping->machine_id,
                    'machine_name' => $mapping->machine->display_name,
                    'device_model' => $mapping->machine->device_model,
                    'test_code' => $mapping->test_code,
                    'sample_type' => $mapping->sample_type,
                    'priority' => $mapping->priority,
                ];
            })
            ->toArray();
    }

    /**
     * Get available machines for a worklist
     */
    public function getAvailableMachinesForWorklist(string $worklistId): array
    {
        $worklist = Worklist::findOrFail($worklistId);

        $machines = Machine::where('is_active', true)
            ->whereHas('testMappings', function ($q) use ($worklist) {
                $q->where('test_id', $worklist->test_id)->where('is_active', true);
            })
            ->orderBy('priority', 'desc')
            ->get();

        return $machines->map(function ($machine) use ($worklist) {
            return [
                'id' => $machine->id,
                'name' => $machine->display_name,
                'device_model' => $machine->device_model,
                'priority' => $machine->priority,
                'is_current' => $machine->id == $worklist->machine_id,
                'test_code' => $machine->testMappings->first()?->test_code,
            ];
        })->toArray();
    }

    /**
     * Get machine for a specific test (cached)
     */
    public function getMachineForTest(string $testId): ?Machine
    {
        $cacheKey = "machine_for_test_{$testId}";

        return Cache::remember($cacheKey, 3600, function () use ($testId) {
            $config = MachineTestMapping::where('test_id', $testId)
                ->where('is_active', true)
                ->with('machine')
                ->first();

            return $config ? $config->machine : null;
        });
    }

    /**
     * Check if a test can be processed on a specific machine
     */
    public function canProcessOnMachine(string $testSampleItemId, string $machineId): bool
    {
        // Check if test is already completed
        $completed = Worklist::where('test_sample_item_id', $testSampleItemId)
            ->where('status', Worklist::STATUS_COMPLETED)
            ->exists();

        if ($completed) {
            return false;
        }

        // Check if this machine has an active mapping for this test
        $item = TestSampleItem::with('test')->find($testSampleItemId);
        if (! $item || ! $item->test) {
            return false;
        }

        $hasMapping = MachineTestMapping::where('machine_id', $machineId)
            ->where('test_id', $item->test_id)
            ->where('is_active', true)
            ->exists();

        if (! $hasMapping) {
            return false;
        }

        // Check if this machine has a worklist for this test
        $worklist = Worklist::where('test_sample_item_id', $testSampleItemId)
            ->where('machine_id', $machineId)
            ->whereIn('status', [Worklist::STATUS_PENDING, Worklist::STATUS_PROCESSING])
            ->first();

        if (! $worklist) {
            return false;
        }

        // Check if there's another machine processing this test
        $processingElsewhere = Worklist::where('test_sample_item_id', $testSampleItemId)
            ->where('machine_id', '!=', $machineId)
            ->where('status', Worklist::STATUS_PROCESSING)
            ->exists();

        if ($processingElsewhere) {
            return false;
        }

        return true;
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    // REMOVED: generateOrderId() — worklists.order_id column is dropped

    /**
     * Determine priority for worklist
     */
    private function determinePriority($labReceipt, $machine): string
    {
        if (isset($labReceipt->is_urgent) && $labReceipt->is_urgent == 1) {
            return self::PRIORITY_HIGH;
        }
        if (isset($labReceipt->priority) && $labReceipt->priority === 'high') {
            return self::PRIORITY_HIGH;
        }
        if ($machine && $machine->priority >= 80) {
            return self::PRIORITY_HIGH;
        }
        if (isset($labReceipt->priority) && $labReceipt->priority === 'low') {
            return self::PRIORITY_LOW;
        }

        return self::PRIORITY_ROUTINE;
    }

    /**
     * Parse timing JSON from consumption
     */
    public function parseTiming($timing): array
    {
        if (empty($timing)) {
            return [''];
        }
        if (is_string($timing) && str_starts_with($timing, '[')) {
            $decoded = json_decode($timing, true);
            if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }

            return [$timing];
        }
        if (is_array($timing)) {
            return $timing;
        }

        return [''];
    }

    /**
     * Get timing label
     */
    public function getTimingLabel($timing): ?string
    {
        if (! $timing) {
            return null;
        }

        $normalized = strtoupper(str_replace([' ', '_'], '', ltrim(trim($timing), '-')));

        // No-timing recheck: C, C2, C3
        if (preg_match('/^C([0-9]*)$/', $normalized, $m)) {
            $n = ! empty($m[1]) ? (int) $m[1] : 1;

            return $n === 1 ? 'Recheck' : "Recheck #{$n}";
        }

        // Timing with recheck number: F2, F3, 2H2
        if (preg_match('/^([A-Z0-9]+?)([0-9]+)$/', $normalized, $m)) {
            $timing = $m[1];
            $n = (int) $m[2];
            $baseLabel = $this->getTimingLabel($timing) ?? $timing;

            return $n >= 2 ? "{$baseLabel} Recheck #".($n - 1) : $baseLabel;
        }

        // Plain timing
        $map = [
            'F' => 'Fasting',
            'R' => 'Random',
            'E' => 'Extended',
            '1H' => '1 Hour',
            '2H' => '2 Hour',
            '3H' => '3 Hour',
            '4H' => '4 Hour',
            'PP' => 'Post Prandial',
        ];

        return $map[$normalized] ?? $normalized;
    }

    /**
     * Lock all worklists for a test (when completed elsewhere)
     */
    public function lockWorklistsForTest(string $testSampleItemId): int
    {
        return Worklist::where('test_sample_item_id', $testSampleItemId)
            ->whereIn('status', [Worklist::STATUS_PENDING, Worklist::STATUS_PROCESSING])
            ->update(['status' => Worklist::STATUS_LOCKED]);
    }

    /**
     * Clear old worklists (completed/cancelled older than days)
     */
    public function clearOldWorklists(int $days = 30): int
    {
        return Worklist::whereIn('status', [Worklist::STATUS_COMPLETED, Worklist::STATUS_CANCELLED])
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }

    /**
     * Get the suffixes from lab test consumptions
     */
    private function getSuffixesFromLabTest($labTest): array
    {
        $test = $labTest->test;
        if (! $test || ! $test->consumptions) {
            return [];
        }

        $suffixes = [];
        foreach ($test->consumptions as $consumption) {
            if ($consumption->timing) {
                $timings = $this->parseTiming($consumption->timing);
                foreach ($timings as $timing) {
                    $timing = trim($timing);
                    if (! empty($timing)) {
                        $suffixes[] = strtoupper($timing);
                    }
                }
            }
        }

        return array_unique($suffixes);
    }

    /**
     * Get worklist summary (pending by machine)
     */
    public function getWorklistSummary(): array
    {
        $byMachine = Worklist::where('status', 'pending')
            ->select('machine_id', \DB::raw('COUNT(*) as count'))
            ->groupBy('machine_id')
            ->with('machine')
            ->get()
            ->map(function ($item) {
                return [
                    'machine' => $item->machine?->display_name ?? 'Unknown',
                    'machine_id' => $item->machine_id,
                    'pending_count' => $item->count,
                ];
            });

        return [
            'by_machine' => $byMachine,
            'total_pending' => Worklist::where('status', 'pending')->count(),
            'total_processing' => Worklist::where('status', 'processing')->count(),
        ];
    }

    /**
     * REMOVED helpers (dead after the column reduction):
     * - extractSuffixFromBarcode()  — only fed the removed worklist suffix write
     * - extractSampleNumberFromBarcode() — only fed the removed sample_number write
     * - decodeParams() — mapping params are no longer embedded; Mirth fetches
     *   them via GET /api/mirth/machine-config/{device_model}
     */
}
