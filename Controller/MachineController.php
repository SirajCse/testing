<?php

namespace App\Http\Controllers\Lab;

use App\Enums\SampleType;
use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\MachineTestMapping;
use App\Models\Test;
use App\Models\TestCategory;
use App\Models\Worklist;
use App\Services\WorklistService;
use Carbon\Carbon;
use DataTables;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MachineController extends Controller
{
    // ============================================
    // CONSTANTS
    // ============================================

    const STATUS_ACTIVE = true;

    const STATUS_INACTIVE = false;

    const DEFAULT_PRIORITY = 80;

    const DEFAULT_SAMPLE_TYPE = 'Serum';

    // ============================================
    // WEB METHODS - VIEWS
    // ============================================

    /**
     * Display list of machines
     * GET /lab/machines
     */
    public function index()
    {
        $machines = Machine::withCount('testMappings')
            ->orderBy('priority', 'desc')
            ->get();

        return view('lab.machines.index', compact('machines'));
    }

    /**
     * Show create machine form
     * GET /lab/machines/create
     */
    public function create()
    {
        return view('lab.machines.create', [
            'protocols' => config('machine_protocols.protocols'),
            'protocolVersions' => config('machine_protocols.protocol_versions'),
            'defaults' => config('machine_protocols.defaults'),
        ]);
    }

    /**
     * Store new machine
     * POST /lab/machines
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:machines,name',
            'display_name' => 'required',
            'device_model' => 'nullable',
            'ip_address' => 'nullable|ip',
            'port' => 'nullable|integer',
            'protocol' => 'nullable|string',
            'protocol_version' => 'nullable|string',
            'auto_push_enabled' => 'nullable|boolean',
            'auto_push_direction' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:0|max:100',
            'api_key' => 'nullable|string',
            'settings' => 'nullable|string',
            'settings_json' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();

        // Auto-generate API key if not provided
        if (empty($data['api_key'])) {
            $data['api_key'] = (string) Str::uuid7();
        }

        // Set defaults
        $data['auto_push_enabled'] = $data['auto_push_enabled'] ?? false;
        $data['is_active'] = $data['is_active'] ?? true;
        $data['priority'] = $data['priority'] ?? 0;
        $data['port'] = $data['port'] ?? null;
        $data['protocol'] = $data['protocol'] ?? 'hl7';

        // Convert settings to JSON if array
        $rawSettings = $data['settings_json'] ?? $data['settings'] ?? null;
        $data['settings'] = $this->parseSettingsInput($rawSettings);
        unset($data['settings_json']);

        Machine::create($data);

        Log::info('Machine created', [
            'machine_name' => $data['name'],
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('lab.machines.index')
            ->with('success', 'Machine created successfully');
    }

    /**
     * Show edit machine form
     * GET /lab/machines/{id}/edit
     */
    public function edit($id)
    {
        $machine = Machine::findOrFail($id);

        return view('lab.machines.edit', [
            'machine' => $machine,
            'categories' => TestCategory::orderBy('name')->get(['id', 'name']),
            'samples' => SampleType::values(),
            'protocols' => config('machine_protocols.protocols'),
            'protocolVersions' => config('machine_protocols.protocol_versions'),
            'defaults' => config('machine_protocols.defaults'),
        ]);
    }

    /**
     * Update machine
     * PUT /lab/machines/{id}
     */
    public function update(Request $request, $id)
    {
        $machine = Machine::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:machines,name,'.$id,
            'display_name' => 'required',
            'device_model' => 'nullable',
            'ip_address' => 'nullable|ip',
            'port' => 'nullable|integer',
            'protocol' => 'nullable|string',
            'protocol_version' => 'nullable|string',
            'auto_push_enabled' => 'nullable|boolean',
            'auto_push_direction' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:0|max:100',
            'api_key' => 'nullable|string',
            'settings' => 'nullable|string',
            'settings_json' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            if ($request->ajax()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            return redirect()->back()->withErrors($validator)->withInput();
        }

        try {
            $data = $validator->validated();

            // Handle settings conversion
            if (isset($data['settings']) && is_array($data['settings'])) {
                $existingSettings = is_array($machine->settings) ? $machine->settings : [];
                $newSettings = $this->parseSettingsInput($data['settings']);
                $data['settings'] = array_merge($existingSettings, $newSettings);
            } else {
                unset($data['settings']);
            }

            // Auto-generate API key if requested
            if ($request->has('regenerate_api_key') && $request->regenerate_api_key) {
                $data['api_key'] = Str::random(32);
            }

            // Convert boolean values
            if (isset($data['auto_push_enabled'])) {
                $data['auto_push_enabled'] = filter_var($data['auto_push_enabled'], FILTER_VALIDATE_BOOLEAN);
            }
            if (isset($data['is_active'])) {
                $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
            }

            $machine->update($data);

            Log::info('Machine updated', [
                'machine_id' => $machine->id,
                'machine_name' => $machine->name,
                'updated_by' => auth()->id(),
            ]);

            if ($request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Machine updated successfully',
                    'machine' => $machine->fresh(),
                ]);
            }

            return redirect()->route('lab.machines.index')
                ->with('success', 'Machine updated successfully');

        } catch (\Exception $e) {
            Log::error('Machine update failed', [
                'machine_id' => $id,
                'error' => $e->getMessage(),
            ]);

            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to update machine: '.$e->getMessage(),
                ], 500);
            }

            return redirect()->back()
                ->with('error', 'Failed to update machine: '.$e->getMessage())
                ->withInput();
        }
    }

    /**
     * Delete machine
     * DELETE /lab/machines/{id}
     */
    public function destroy($id)
    {
        try {
            $machine = Machine::findOrFail($id);

            // Check if machine has worklists
            $worklistCount = Worklist::where('machine_id', $id)->count();
            if ($worklistCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete machine with {$worklistCount} worklist entries. Archive or reassign first.",
                ], 400);
            }

            // Delete test mappings first
            MachineTestMapping::where('machine_id', $id)->delete();

            // Delete machine
            $machine->delete();

            Log::info('Machine deleted', ['machine' => $machine->name, 'id' => $id]);

            return response()->json([
                'success' => true,
                'message' => 'Machine deleted successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete machine: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle machine active status
     * POST /lab/machines/{id}/toggle-status
     */
    public function toggleStatus($id)
    {
        try {
            $machine = Machine::findOrFail($id);
            $machine->is_active = ! $machine->is_active;
            $machine->save();

            Log::info('Machine status toggled', [
                'machine_id' => $id,
                'new_status' => $machine->is_active,
                'action_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => $machine->is_active ? 'Machine activated' : 'Machine deactivated',
                'is_active' => $machine->is_active,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle status',
            ], 500);
        }
    }

    private function parseSettingsInput(mixed $settings): array
    {
        if (is_array($settings)) {
            return $settings;
        }
        if (is_string($settings) && trim($settings) !== '') {
            $decoded = json_decode($settings, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Regenerate API key
     * POST /lab/machines/{id}/regenerate-api-key
     */
    public function regenerateApiKey($id)
    {
        try {
            $machine = Machine::findOrFail($id);
            $newApiKey = Str::random(32);
            $machine->api_key = $newApiKey;
            $machine->save();

            Log::info('API key regenerated', [
                'machine_id' => $id,
                'machine_name' => $machine->name,
                'action_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'API key regenerated successfully',
                'api_key' => $newApiKey,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to regenerate API key',
            ], 500);
        }
    }

    /**
     * Update machine settings only
     * PUT /lab/machines/{id}/settings
     */
    public function updateSettings(Request $request, $id)
    {

        $machine = Machine::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'settings' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        try {

            $existingSettings = is_array($machine->settings) ? $machine->settings : [];
            $newSettings = $this->parseSettingsInput($data['settings']);
            $mergedSettings = array_merge($existingSettings, $newSettings);
            $machine->settings = json_encode($mergedSettings);
            $machine->save();

            return response()->json([
                'success' => true,
                'message' => 'Settings updated successfully',
                'settings' => $mergedSettings,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update settings: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update machine port
     * PUT /lab/machines/{id}/port
     */
    public function updatePort(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'port' => 'required|integer|min:1|max:65535',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $machine = Machine::findOrFail($id);
            $machine->port = $request->port;
            $machine->save();

            return response()->json([
                'success' => true,
                'message' => 'Port updated successfully',
                'port' => $machine->port,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update port',
            ], 500);
        }
    }

    /**
     * Update machine IP address
     * PUT /lab/machines/{id}/ip
     */
    public function updateIpAddress(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'ip_address' => 'nullable|ip',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $machine = Machine::findOrFail($id);
            $machine->ip_address = $request->ip_address;
            $machine->save();

            return response()->json([
                'success' => true,
                'message' => 'IP address updated successfully',
                'ip_address' => $machine->ip_address,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update IP address',
            ], 500);
        }
    }

    /**
     * Show machine worklist
     * GET /lab/machines/{id}/worklist
     */
    public function worklist($id)
    {
        $machine = Machine::findOrFail($id);

        $worklists = Worklist::where('machine_id', $id)
            ->with(['labReceipt.patient', 'test'])
            ->orderByRaw("FIELD(priority, 'high', 'routine', 'low')")
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        $allMachines = Machine::where('is_active', true)
            ->orderBy('priority', 'desc')
            ->get();

        return view('lab.machines.worklist', compact('machine', 'worklists', 'allMachines'));
    }

    /**
     * Get machine performance metrics
     * GET /lab/machines/{id}/performance
     */
    public function getPerformance($id)
    {
        try {
            $machine = Machine::findOrFail($id);

            $last7Days = Worklist::where('machine_id', $id)
                ->where('created_at', '>=', now()->subDays(7))
                ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
                ->groupBy('date')
                ->orderBy('date', 'desc')
                ->get();

            $avgProcessingTime = Worklist::where('machine_id', $id)
                ->whereNotNull('completed_at')
                ->whereNotNull('started_at')
                ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, started_at, completed_at)) as avg_minutes')
                ->first();

            return response()->json([
                'machine' => $machine->display_name,
                'last_7_days' => $last7Days,
                'avg_processing_time_minutes' => round($avgProcessingTime->avg_minutes ?? 0, 2),
                'total_processed' => Worklist::where('machine_id', $id)->where('status', 'completed')->count(),
                'total_pending' => Worklist::where('machine_id', $id)->where('status', 'pending')->count(),
                'total_processing' => Worklist::where('machine_id', $id)->where('status', 'processing')->count(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get performance data',
            ], 500);
        }
    }

    // ============================================
    // TEST ASSIGNMENT METHODS
    // ============================================

    /**
     * Get assigned and unassigned tests for machine
     * GET /lab/machines/{id}/tests
     */
    public function getTests($id)
    {
        $machine = Machine::findOrFail($id);

        // Get assigned tests with pivot data
        $assignedTests = MachineTestMapping::where('machine_id', $id)
            ->with(['test:id,name,short_name,category_id', 'test.config:id,tenant_id,test_id,sample_type,short_name'])
            ->orderBy('priority', 'desc')
            ->get()
            ->map(function ($mapping) {
                return [
                    'id' => $mapping->test_id,
                    'name' => $mapping->test->name,
                    'short_name' => $mapping->test->short_name,
                    'code' => $mapping->test_code ?? $mapping->test->code ?? 'N/A',
                    'sample_type' => $mapping->test->config->sample_type ?? self::DEFAULT_SAMPLE_TYPE,
                    'category_id' => $mapping->test->category_id,
                    'pivot' => [
                        'test_code' => $mapping->test_code,
                        'sample_type' => $mapping->test->config->sample_type ?? self::DEFAULT_SAMPLE_TYPE,
                        'priority' => $mapping->priority,
                        'is_active' => $mapping->is_active,
                        'is_multi_component' => $mapping->is_multi_component,
                        'parameters' => $mapping->parameters ? json_decode($mapping->parameters, true) : null,
                    ],
                ];
            });

        // Get assigned test IDs
        $assignedTestIds = MachineTestMapping::where('machine_id', $id)
            ->pluck('test_id')
            ->toArray();

        // Get unassigned tests
        $unassignedTests = Test::whereNotIn('id', $assignedTestIds)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'shortcut']);

        return response()->json([
            'tests' => $assignedTests,
            'unassigned_tests' => $unassignedTests,
        ]);
    }

    /**
     * Assign multiple tests to machine
     * POST /lab/machines/{id}/assign-tests
     */
    public function assignTests(Request $request, $id)
    {
        $machine = Machine::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'tests' => 'required|array',
            'tests.*.test_id' => 'required|exists:tests,id',
            'tests.*.test_code' => 'nullable|string|max:100',
            'tests.*.sample_type' => 'nullable|string|max:100',
            'tests.*.priority' => 'nullable|integer|min:0|max:100',
            'tests.*.is_active' => 'nullable|boolean',
            'tests.*.is_multi_component' => 'nullable|boolean',
            'tests.*.parameters' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();

        try {
            $assigned = 0;
            $updated = 0;

            foreach ($request->tests as $test) {
                $testId = $test['test_id'];

                $data = [
                    'test_code' => $test['test_code'] ?? null,
                    'sample_type' => $test['sample_type'] ?? self::DEFAULT_SAMPLE_TYPE,
                    'priority' => $test['priority'] ?? self::DEFAULT_PRIORITY,
                    'is_active' => $test['is_active'] ?? true,
                    'is_multi_component' => $test['is_multi_component'] ?? true,
                    'parameters' => isset($test['parameters']) ? json_encode($test['parameters']) : null,
                ];

                $existing = MachineTestMapping::where('machine_id', $machine->id)
                    ->where('test_id', $testId)
                    ->first();

                if ($existing) {
                    $existing->update($data);
                    $updated++;
                } else {
                    MachineTestMapping::create(array_merge($data, [
                        'id' => (string) Str::uuid7(),
                        'machine_id' => $machine->id,
                        'test_id' => $testId,
                    ]));
                    $assigned++;
                }
            }

            DB::commit();

            Log::info('Tests assigned to machine', [
                'machine_id' => $machine->id,
                'assigned' => $assigned,
                'updated' => $updated,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => "{$assigned} tests assigned, {$updated} tests updated",
                'assigned' => $assigned,
                'updated' => $updated,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to assign tests', [
                'machine_id' => $machine->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to assign tests: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign single test to machine
     * POST /lab/machines/{id}/assign-single-test
     */
    public function assignSingleTest(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'test_id' => 'required|exists:tests,id',
            'test_code' => 'nullable|string|max:100',
            'sample_type' => 'nullable|string|max:100',
            'priority' => 'nullable|integer|min:0|max:100',
            'is_active' => 'nullable|boolean',
            'is_multi_component' => 'nullable|boolean',
            'parameters' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 400);
        }

        try {
            $machine = Machine::findOrFail($id);
            $testId = $request->test_id;

            $data = [
                'test_code' => $request->test_code ?? null,
                'sample_type' => $request->sample_type ?? self::DEFAULT_SAMPLE_TYPE,
                'priority' => $request->priority ?? self::DEFAULT_PRIORITY,
                'is_active' => $request->is_active ?? true,
                'is_multi_component' => $request->is_multi_component ?? true,
                'parameters' => $request->parameters ? json_encode($request->parameters) : null,
            ];

            $existing = MachineTestMapping::where('machine_id', $machine->id)
                ->where('test_id', $testId)
                ->first();

            if ($existing) {
                $existing->update($data);
                $message = 'Test configuration updated';
            } else {
                MachineTestMapping::create(array_merge($data, [
                    'id' => (string) Str::uuid7(),
                    'machine_id' => $machine->id,
                    'test_id' => $testId,
                ]));
                $message = 'Test assigned successfully';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign test: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk assign tests
     * POST /lab/machines/{id}/bulk-assign-tests
     */
    public function bulkAssignTests(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'test_ids' => 'required|array',
            'test_ids.*' => 'exists:tests,id',
            'default_sample_type' => 'nullable|string',
            'default_priority' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 400);
        }

        try {
            $machine = Machine::findOrFail($id);

            $created = 0;
            foreach ($request->test_ids as $testId) {
                $exists = MachineTestMapping::where('machine_id', $machine->id)
                    ->where('test_id', $testId)
                    ->exists();

                if (! $exists) {
                    MachineTestMapping::create([
                        'id' => (string) Str::uuid7(),
                        'machine_id' => $machine->id,
                        'test_id' => $testId,
                        'sample_type' => $request->default_sample_type ?? self::DEFAULT_SAMPLE_TYPE,
                        'priority' => $request->default_priority ?? self::DEFAULT_PRIORITY,
                        'is_active' => true,
                        'is_multi_component' => true,
                    ]);
                    $created++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => "{$created} tests assigned successfully",
                'assigned' => $created,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign tests: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove test from machine
     * DELETE /lab/machines/{id}/tests/{testId}
     */
    public function removeTest($id, $testId)
    {
        try {
            $deleted = MachineTestMapping::where('machine_id', $id)
                ->where('test_id', $testId)
                ->delete();

            if ($deleted) {
                Log::info('Test removed from machine', [
                    'machine_id' => $id,
                    'test_id' => $testId,
                    'action_by' => auth()->id(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Test removed from machine',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Test not found on this machine',
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove test',
            ], 500);
        }
    }

    /**
     * Update test configuration
     * PUT /lab/machines/{id}/tests/{testId}/config
     */
    public function updateTestConfig(Request $request, $id, $testId)
    {
        $validator = Validator::make($request->all(), [
            'test_code' => 'nullable|string|max:100',
            'sample_type' => 'nullable|string|max:100',
            'priority' => 'nullable|integer|min:0|max:100',
            'is_active' => 'nullable|boolean',
            'is_multi_component' => 'nullable|boolean',
            'parameters' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 400);
        }

        try {
            $mapping = MachineTestMapping::where('machine_id', $id)
                ->where('test_id', $testId)
                ->firstOrFail();

            $updateData = [];
            if ($request->has('test_code')) {
                $updateData['test_code'] = $request->test_code;
            }
            if ($request->has('sample_type')) {
                $updateData['sample_type'] = $request->sample_type;
            }
            if ($request->has('priority')) {
                $updateData['priority'] = $request->priority;
            }
            if ($request->has('is_active')) {
                $updateData['is_active'] = $request->is_active;
            }
            if ($request->has('is_multi_component')) {
                $updateData['is_multi_component'] = $request->is_multi_component;
            }
            if ($request->has('parameters')) {
                $updateData['parameters'] = json_encode($request->parameters);
            }

            if (! empty($updateData)) {
                $mapping->update($updateData);
            }

            return response()->json([
                'success' => true,
                'message' => 'Test configuration updated',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update configuration',
            ], 500);
        }
    }

    /**
     * Update test parameters
     * PUT /lab/machines/{id}/tests/{testId}/parameters
     */
    public function updateTestParameters(Request $request, $id, $testId)
    {
        $validator = Validator::make($request->all(), [
            'parameters' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 400);
        }

        try {
            $mapping = MachineTestMapping::where('machine_id', $id)
                ->where('test_id', $testId)
                ->firstOrFail();

            $mapping->parameters = $request->parameters ? json_encode($request->parameters) : null;
            $mapping->save();

            return response()->json([
                'success' => true,
                'message' => 'Test parameters updated',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update parameters',
            ], 500);
        }
    }

    /**
     * Update test active status
     * PUT /lab/machines/{id}/tests/{testId}/status
     */
    public function updateTestStatus(Request $request, $id, $testId)
    {
        $validator = Validator::make($request->all(), [
            'is_active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 400);
        }

        try {
            $mapping = MachineTestMapping::where('machine_id', $id)
                ->where('test_id', $testId)
                ->firstOrFail();

            $mapping->is_active = $request->is_active;
            $mapping->save();

            $status = $request->is_active ? 'activated' : 'deactivated';

            return response()->json([
                'success' => true,
                'message' => "Test {$status} successfully",
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update status',
            ], 500);
        }
    }

    /**
     * Get test configuration details
     * GET /lab/machines/{id}/tests/{testId}/config
     */
    public function getTestConfigDetails($id, $testId)
    {
        try {
            $mapping = MachineTestMapping::where('machine_id', $id)
                ->where('test_id', $testId)
                ->with('test')
                ->firstOrFail();

            return response()->json([
                'id' => $mapping->id,
                'test_id' => $mapping->test_id,
                'test_name' => $mapping->test->name,
                'test_code' => $mapping->test_code,
                'sample_type' => $mapping->sample_type,
                'priority' => $mapping->priority,
                'is_active' => $mapping->is_active,
                'is_multi_component' => $mapping->is_multi_component,
                'parameters' => $mapping->parameters ? json_decode($mapping->parameters, true) : null,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Test configuration not found',
            ], 404);
        }
    }

    // ============================================
    // AJAX / SELECT2 METHODS
    // ============================================

    /**
     * Get available tests for Select2 AJAX search
     * GET /lab/machines/{id}/available-tests
     */
    public function getAvailableTests(Request $request, $id)
    {
        $search = $request->get('search', '');
        $categoryId = $request->get('category_id', 0);
        $page = $request->get('page', 1);
        $perPage = 20;

        $machine = Machine::findOrFail($id);

        $assignedTestIds = MachineTestMapping::where('machine_id', $id)
            ->pluck('test_id')
            ->toArray();

        $query = Test::with(['category', 'config:id,tenant_id,test_id,short_name,sample_type'])
            ->whereNotIn('id', $assignedTestIds);

        if ($categoryId && $categoryId > 0) {
            $query->where('category_id', $categoryId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('short_name', 'like', "%{$search}%");
                //                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $tests = $query->orderBy('name')->paginate($perPage);

        $results = [];
        foreach ($tests as $test) {
            $results[] = [
                'id' => $test->id,
                'text' => $test->name.' ('.($test->short_name ?? $test->code ?? 'N/A').')',
                'name' => $test->name,
                'code' => $test->code ?? 'N/A',
                'short_name' => $test->short_name,
                'sample_type' => $test->config->sample_type ?? self::DEFAULT_SAMPLE_TYPE,
                'category_name' => $test->category->name ?? null,
            ];
        }

        return response()->json([
            'results' => $results,
            'pagination' => [
                'more' => $tests->hasMorePages(),
            ],
        ]);
    }

    /**
     * Get unassigned tests for Select2 AJAX search with pagination
     * Only tests with an active (status = 1) config are returned
     * GET /lab/machines/{id}/unassigned-tests
     */
    /**
     * Get unassigned tests for Select2 AJAX search with pagination
     * Only tests with an active (status = 1) config are returned
     * GET /lab/machines/{id}/unassigned-tests
     */
    /**
     * Get unassigned tests for Select2 AJAX search with pagination
     * Only tests with an active (status = 1) config are returned
     * GET /lab/machines/{id}/unassigned-tests
     */
    public function getUnassignedTests(Request $request, $id)
    {
        $search = trim((string) $request->input('search', ''));
        $categoryId = trim((string) $request->input('category_id', '')); // UUID string
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 20;

        // One active config row per test
        $cfgSub = DB::table('test_configs')
            ->select('test_id')
            ->selectRaw('MIN(short_name)  AS short_name')
            ->selectRaw('MIN(sample_type) AS sample_type')
            ->where('status', 1)
            ->groupBy('test_id');

        $rows = Test::query()
            ->select([
                'tests.id',
                'tests.name',
                'tests.short_name',
                'tests.category_id',
                'cfg.short_name  as cfg_short_name',
                'cfg.sample_type as cfg_sample_type',
            ])
            // Only tests with an active config
            ->joinSub($cfgSub, 'cfg', 'cfg.test_id', '=', 'tests.id')
            // Exclude tests already mapped to this machine
            ->whereNotExists(function ($q) use ($id) {
                $q->select(DB::raw(1))
                    ->from('machine_test_mappings as mtm')
                    ->whereColumn('mtm.test_id', 'tests.id')
                    ->where('mtm.machine_id', $id);
            })
            ->where('tests.status', 1)
            ->when($categoryId !== '', fn ($q) => $q->where('tests.category_id', $categoryId))
            ->when($search !== '', function ($q) use ($search) {
                $like = "%{$search}%";
                $q->where(function ($w) use ($like) {
                    $w->where('tests.name', 'like', $like)
                        ->orWhere('tests.short_name', 'like', $like)
                        ->orWhere('cfg.short_name', 'like', $like);
                });
            })
            ->orderBy('tests.name')
            ->limit($perPage + 1)
            ->offset(($page - 1) * $perPage)
            ->toBase()   // no Eloquent hydration
            ->get();

        $hasMore = $rows->count() > $perPage;
        if ($hasMore) {
            $rows->pop();
        }

        $results = $rows->map(function ($t) {
            $shortName = $t->cfg_short_name ?? $t->short_name ?? 'N/A';
            $sampleType = $t->cfg_sample_type ?? self::DEFAULT_SAMPLE_TYPE;

            return [
                'id' => $t->id,
                'text' => $t->name.' ('.$shortName.')',
                'name' => $t->name,
                'code' => $t->short_name ?? 'N/A',
                'short_name' => $t->short_name,
                'sample_type' => $sampleType,
                'category_id' => $t->category_id,
            ];
        });

        return response()->json([
            'results' => $results,
            'pagination' => ['more' => $hasMore],
        ]);
    }

    /**
     * Get assigned tests
     * GET /lab/machines/{id}/assigned-tests
     */
    public function getAssignedTests($id)
    {
        $mappings = MachineTestMapping::where('machine_id', $id)
            ->with(['test:id,name,short_name,category_id', 'test.config:id,tenant_id,test_id,sample_type,short_name'])
            ->orderBy('priority', 'desc')
            ->get();

        $assignedTests = $mappings->map(function ($mapping) {
            // Parameters may be array (if cast) or string (if not)
            $params = $mapping->parameters;
            if (is_string($params)) {
                $params = json_decode($params, true);
            }
            $params = $params ?? [];

            return [
                'id' => $mapping->test_id,
                'name' => $mapping->test->name,
                'short_name' => $mapping->test->short_name,
                'code' => $mapping->test_code ?? $mapping->test->code ?? 'N/A',
                'sample_type' => $mapping->test->config->sample_type ?? self::DEFAULT_SAMPLE_TYPE,
                'category_id' => $mapping->test->category_id,
                'pivot' => [
                    'test_code' => $mapping->test_code,
                    'sample_type' => $mapping->test->config->sample_type ?? self::DEFAULT_SAMPLE_TYPE,
                    'priority' => $mapping->priority,
                    'is_active' => $mapping->is_active,
                    'is_multi_component' => $mapping->is_multi_component,
                    'parameters' => $params, // now safe
                ],
            ];
        });

        return response()->json([
            'tests' => $assignedTests,
        ]);
    }

    // ============================================
    // DATA TABLES METHOD
    // ============================================

    /**
     * Get machines data for DataTables
     * POST /lab/machines/data
     */
    public function getMachinesData(Request $request)
    {
        $model = Machine::withCount('testMappings')->orderBy('priority', 'desc');

        // Search filter
        if ($request->has('search_value') && $request->search_value) {
            $search = $request->search_value;

            $model->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%")
                    ->orWhere('device_model', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($request->has('status') && isset($request->status)) {
            $model->where('is_active', $request->status);
        }

        // Protocol filter
        if ($request->has('protocol') && isset($request->protocol)) {
            $model->where('protocol', $request->protocol);
        }

        // Online filter (based on last_received_at within 30 minutes)
        if ($request->has('online') && isset($request->online) && $request->online !== '') {
            if ($request->online == 1) {
                $model->where('last_received_at', '>=', now()->subMinutes(30));
            } else {
                $model->where(function ($q) {
                    $q->whereNull('last_received_at')
                        ->orWhere('last_received_at', '<', now()->subMinutes(30));
                });
            }
        }

        return DataTables::eloquent($model)
            ->addIndexColumn()
            ->addColumn('status_badge', function ($row) {
                if (! $row->is_active) {
                    return '<span class="status-badge status-inactive"><i class="fas fa-circle fa-xs"></i> Inactive</span>';
                }

                return '<span class="status-badge status-active"><i class="fas fa-circle fa-xs"></i> Active</span>';
            })
            ->addColumn('protocol_badge', function ($row) {
                $colors = [
                    'hl7' => 'protocol-hl7',
                    'astm' => 'protocol-astm',
                    'rest' => 'protocol-rest',
                    'mllp' => 'protocol-mllp',
                    'tcp' => 'protocol-tcp',
                ];
                $class = $colors[$row->protocol] ?? 'protocol-hl7';

                return '<span class="protocol-badge '.$class.'">'.strtoupper($row->protocol ?? 'HL7').'</span>';
            })
            ->addColumn('protocol_version', function ($row) {
                if (! $row->protocol_version) {
                    return '<span class="text-muted">-</span>';
                }

                $versions = [
                    '2.1' => 'v2.1', '2.2' => 'v2.2', '2.3' => 'v2.3', '2.3.1' => 'v2.3.1',
                    '2.4' => 'v2.4', '2.5' => 'v2.5', '2.5.1' => 'v2.5.1', '2.6' => 'v2.6',
                    '2.7' => 'v2.7', '2.8' => 'v2.8',
                    'astm-e1381' => 'E1381', 'astm-e1394' => 'E1394',
                ];
                $display = $versions[$row->protocol_version] ?? $row->protocol_version;

                return '<span class="badge bg-secondary">'.$display.'</span>';
            })
            ->addColumn('connection_info', function ($row) {
                if ($row->ip_address && $row->port) {
                    return '<small>'.$row->ip_address.':'.$row->port.'</small>';
                } elseif ($row->ip_address) {
                    return '<small>'.$row->ip_address.'</small>';
                } elseif ($row->port) {
                    return '<small>Port: '.$row->port.'</small>';
                }

                return '<span class="text-muted">-</span>';
            })
            ->addColumn('priority_badge', function ($row) {
                if ($row->priority >= 80) {
                    return '<span class="priority-high">'.$row->priority.'</span>';
                } elseif ($row->priority >= 50) {
                    return '<span class="priority-medium">'.$row->priority.'</span>';
                }

                return '<span class="priority-low">'.$row->priority.'</span>';
            })
            ->addColumn('online_badge', function ($row) {
                $isOnline = $row->last_received_at && Carbon::parse($row->last_received_at)->diffInMinutes() <= 30;
                if ($isOnline) {
                    return '<span class="status-badge status-online"><i class="fas fa-circle fa-xs"></i> Online</span>';
                }

                return '<span class="status-badge status-offline"><i class="fas fa-circle fa-xs"></i> Offline</span>';
            })
            ->addColumn('tests_count', function ($row) {
                $count = $row->test_mappings_count ?? 0;
                $color = $count > 0 ? 'primary' : 'secondary';

                return '<span class="badge badge-'.$color.'">'.$count.' Tests</span>';
            })
            ->addColumn('last_received', function ($row) {
                if ($row->last_received_at) {
                    $time = Carbon::parse($row->last_received_at);

                    return '<small title="'.$time->format('Y-m-d H:i:s').'">'.$time->diffForHumans().'</small>';
                }

                return '<span class="text-muted">Never</span>';
            })
            ->addColumn('actions', function ($row) {
                $actions = '<div class="btn-group">';
                $actions .= '<a href="'.route('lab.machines.edit', $row->id).'" class="btn btn-sm btn-info btn-icon" title="Edit">
                <i class="fas fa-edit"></i>
            </a>';
                $actions .= '<a href="'.route('lab.machines.worklist', $row->id).'" class="btn btn-sm btn-secondary btn-icon" title="Worklist">
                <i class="fas fa-list"></i>
            </a>';
                $actions .= '<button class="btn btn-sm btn-primary btn-icon assign-tests" data-id="'.$row->id.'" data-name="'.htmlspecialchars($row->display_name).'" title="Assign Tests">
                <i class="fas fa-link"></i>
            </button>';
                $actions .= '<button class="btn btn-sm btn-warning btn-icon toggle-status" data-id="'.$row->id.'" data-active="'.($row->is_active ? '1' : '0').'" title="'.($row->is_active ? 'Deactivate' : 'Activate').'">
                <i class="fas '.($row->is_active ? 'fa-pause' : 'fa-play').'"></i>
            </button>';
                $actions .= '<button class="btn btn-sm btn-danger btn-icon delete-machine" data-id="'.$row->id.'" data-name="'.htmlspecialchars($row->display_name).'" title="Delete">
                <i class="fas fa-trash"></i>
            </button>';
                $actions .= '</div>';

                return $actions;
            })
            ->rawColumns(['status_badge', 'protocol_badge', 'protocol_version', 'connection_info', 'priority_badge', 'online_badge', 'tests_count', 'last_received', 'actions'])
            ->make(true);
    }

    // ============================================
    // STATISTICS METHODS
    // ============================================

    /**
     * Get machine statistics
     * GET /lab/machines/stats
     */
    public function getStats(Request $request)
    {
        $query = Machine::query();

        // Apply search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%")
                    ->orWhere('device_model', 'like', "%{$search}%");
            });
        }

        // Apply status filter
        if ($request->has('status') && $request->status !== '') {
            $query->where('is_active', $request->status);
        }

        // Apply protocol filter
        if ($request->has('protocol') && $request->protocol !== '') {
            $query->where('protocol', $request->protocol);
        }

        // Apply online filter
        if ($request->has('online') && $request->online !== '') {
            if ($request->online == 1) {
                $query->where('last_received_at', '>=', now()->subMinutes(30));
            } else {
                $query->where(function ($q) {
                    $q->whereNull('last_received_at')
                        ->orWhere('last_received_at', '<', now()->subMinutes(30));
                });
            }
        }

        $totalMachines = $query->count();
        $activeMachines = (clone $query)->where('is_active', true)->count();
        $inactiveMachines = $totalMachines - $activeMachines;
        $totalAssignedTests = MachineTestMapping::count();

        // Online machines count with filters
        $onlineMachines = (clone $query)->where('last_received_at', '>=', now()->subMinutes(30))->count();

        return response()->json([
            'total_machines' => $totalMachines,
            'active_machines' => $activeMachines,
            'inactive_machines' => $inactiveMachines,
            'total_assigned_tests' => $totalAssignedTests,
            'online_machines' => $onlineMachines,
        ]);
    }

    /**
     * Export a machine‑level configuration using the stored component codes
     * from each test mapping's parameters.
     */
    public function exportMachineConfig($machineId)
    {
        $machine = Machine::with('testMappings')->findOrFail($machineId);

        $allComponents = [];
        $activeMappings = $machine->testMappings()->where('is_active', true)->get();

        foreach ($activeMappings as $mapping) {
            // Decode parameters if it's a string
            $params = $mapping->parameters;
            if (is_string($params)) {
                $params = json_decode($params, true);
            }
            $params = $params ?? [];

            // If mapping or components missing, skip
            if (empty($params['mapping']) || empty($params['components'])) {
                continue;
            }

            $mappingKeys = array_keys($params['mapping']);
            $componentIds = array_values($params['mapping']);
            $components = $params['components'];

            // Iterate over components (same order as mapping)
            foreach ($components as $index => $comp) {
                // Get the corresponding code and ID from mapping
                $code = $mappingKeys[$index] ?? null;
                $id = $componentIds[$index] ?? null;

                // If code is missing or null, generate from name
                if (empty($code)) {
                    $code = Str::slug($comp['name'] ?? 'unknown', '_');
                }

                // If we have an ID, use it; otherwise generate a temporary one
                if (empty($id)) {
                    $id = (string) Str::uuid7(); // fallback, but ideally mapping provides it
                }

                // Build component data with ID and code
                $componentData = [
                    'id' => $id,
                    'code' => $code,
                    'name' => $comp['name'] ?? 'Unknown',
                    'unit' => $comp['unit'] ?? null,
                    'ref_range' => $comp['ref_range'] ?? null,
                    'order' => $comp['order'] ?? 999,
                    'functions' => $comp['functions'] ?? [],
                ];

                // Include extra fields if present
                if (isset($comp['is_opinion'])) {
                    $componentData['is_opinion'] = $comp['is_opinion'];
                }
                if (isset($comp['depends_on'])) {
                    $componentData['depends_on'] = $comp['depends_on'];
                }

                $allComponents[] = $componentData;
            }
        }

        if (empty($allComponents)) {
            return response()->json([
                'error' => 'No active tests with components found for this machine',
                'data' => $activeMappings,
            ], 404);
        }

        // Group by code
        $grouped = [];
        foreach ($allComponents as $comp) {
            $key = $comp['code'];
            $grouped[$key][] = $comp;
        }

        $mapping = [];
        $components = [];

        foreach ($grouped as $key => $compList) {
            $ids = array_column($compList, 'id');
            $mapping[$key] = $ids;

            // Use first component for metadata
            $first = $compList[0];
            $compArray = [
                'name' => $first['name'],
                'code' => $key,
                'unit' => $first['unit'],
                'ref_range' => $first['ref_range'],
                'order' => $first['order'],
                'functions' => $first['functions'],
            ];

            if (isset($first['is_opinion'])) {
                $compArray['is_opinion'] = $first['is_opinion'];
            }
            if (isset($first['depends_on'])) {
                $compArray['depends_on'] = $first['depends_on'];
            }

            $components[] = $compArray;
        }

        $config = [
            'machine_id' => $machine->id,
            'api_key' => $machine->api_key,
            'directional' => $machine->directional ?? 'unidirectional',
            'sample_type' => 'serum',
            'mapping' => $mapping,
            'components' => $components,
        ];

        $filename = "machine_config_{$machine->id}.json";

        return response()->json($config)
            ->withHeaders([
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Content-Type' => 'application/json',
            ]);
    }

    /**
     * Generate default test parameters (including mapping) for Mirth integration.
     */
    public function getTestDefaultParameters($machineId, $testId)
    {
        $test = Test::with('components')->findOrFail($testId);
        $machine = Machine::findOrFail($machineId);

        $components = [];
        $mapping = []; // code => component_id mapping
        $isMultiComponent = $test->components->count() > 1 ||
            $test->components->contains('is_opinion', true);

        foreach ($test->components as $component) {
            // Determine mapping key: use code if available, otherwise slugified name
            $mapKey = ! empty($component->code)
                ? $component->code
                : Str::slug($component->name, '_');

            $mapping[$mapKey] = $component->id;

            // Build component array – include ID and set code to match mapping key
            $comp = [
                'id' => $component->id,
                'name' => $component->name,
                'code' => $mapKey, // ✅ Now matches the mapping key
                'unit' => $component->unit,
                'ref_range' => $component->ref_range,
                'decimal_places' => $component->decimal_places ?? 2,
                'order' => $component->order,
            ];

            // Auto-generate functions
            $comp['functions'] = [];

            // 1. Round function for numeric components (non-opinion)
            if (($component->decimal_places ?? 2) > 0 && ! $component->is_opinion) {
                $comp['functions'][] = [
                    'name' => 'round',
                    'params' => ['decimal' => $component->decimal_places ?? 2],
                ];
            }

            // 2. Opinion (positive/negative) function
            if ($component->is_opinion) {
                $comp['functions'][] = [
                    'name' => 'opinion_positive_negative',
                    'params' => [
                        'positive' => $component->cutoff_value ?? 1,
                        'text_positive' => $component->text_positive ?? 'Reactive - Positive',
                        'negative' => $component->cutoff_value ?? 1,
                        'text_negative' => $component->text_negative ?? 'Non-Reactive - Negative',
                        'equivocal' => $component->cutoff_value ?? 1,
                        'text_equivocal' => $component->text_equivocal ?? 'Equivocal - Gray Zone',
                    ],
                ];
            }
            // 3. Range‑based opinion (if reference range exists)
            elseif ($component->ref_range && strpos($component->ref_range, '-') !== false) {
                $rangeParts = explode('-', $component->ref_range);
                if (count($rangeParts) == 2) {
                    $minNorm = floatval($rangeParts[0]);
                    $maxNorm = floatval($rangeParts[1]);
                    $comp['functions'][] = [
                        'name' => 'opinion_range',
                        'params' => [
                            'ranges' => [
                                ['min' => $maxNorm * 2.5, 'max' => 999999, 'text' => 'Very High'],
                                ['min' => $maxNorm, 'max' => $maxNorm * 2.49, 'text' => 'High'],
                                ['min' => $minNorm, 'max' => $maxNorm, 'text' => 'Normal'],
                                ['min' => $minNorm * 0.5, 'max' => $minNorm, 'text' => 'Low'],
                                ['min' => 0, 'max' => $minNorm * 0.49, 'text' => 'Very Low'],
                            ],
                        ],
                    ];
                }
            }

            // Extra flags
            if ($component->is_opinion) {
                $comp['is_opinion'] = true;
            }
            if ($component->depends_on) {
                $comp['depends_on'] = $component->depends_on;
            }

            $components[] = $comp;
        }

        // Build final parameters array
        $parameters = [
            'test_id' => $test->id,
            'machine_id' => $machine->id,
            'api_key' => $machine->api_key,
            'directional' => $machine->directional ?? 'unidirectional',
            'is_multi_component' => $isMultiComponent,
            'test_name' => $test->name,
            'test_code' => $test->short_name ?? '',
            'sample_type' => 'serum',
            'mapping' => $mapping,
            'components' => $components,
        ];

        // For single‑component tests, flatten some fields for convenience
        if (! $isMultiComponent && count($components) == 1) {
            $mainComp = $components[0];
            $parameters['unit'] = $mainComp['unit'];
            $parameters['reference_range'] = $mainComp['ref_range'];
            $parameters['decimal_places'] = $mainComp['decimal_places'];
            if (isset($mainComp['functions'])) {
                $parameters['functions'] = $mainComp['functions'];
            }
        }

        return response()->json([
            'success' => true,
            'parameters' => $parameters,
            'parameters_json' => json_encode($parameters, JSON_PRETTY_PRINT),
        ]);
    }

    public function getTestParameters($id, $testId)
    {
        $machine = Machine::with('testMappings')->findOrFail($id);
        $testMapping = $machine->testMappings->firstWhere('test_id', $testId);

        if ($testMapping && ! empty($testMapping->parameters)) {
            $params = $testMapping->parameters;
            if (is_string($params)) {
                $params = json_decode($params, true);
            }

            return response()->json([
                'success' => true,
                'parameters' => $params,
                'parameters_json' => json_encode($params, JSON_PRETTY_PRINT),
            ]);
        }

        return $this->getTestDefaultParameters($id, $testId);
    }

    // ============================================
    // API METHODS FOR MIDDLEWARE INTEGRATION
    // ============================================

    /**
     * API: Get worklist for machine
     * POST /api/mirth/machine-worklist
     */
    public function apiGetWorklist(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'api_key' => 'required|string',
            'machine_key' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $machine = Machine::where('api_key', $request->api_key)
            ->where('device_model', $request->machine_key)
            ->where('is_active', true)
            ->first();

        if (! $machine) {
            return response()->json(['error' => 'Invalid credentials or machine not found'], 401);
        }

        $service = new WorklistService;
        $worklists = $service->getPendingWorklistsForMachine($machine->id);

        // Update last received timestamp
        $machine->update(['last_received_at' => now()]);

        return response()->json([
            'success' => true,
            'machine' => $machine->display_name,
            'machine_key' => $machine->device_model,
            'data' => $worklists,
            'count' => $worklists->count(),
        ]);
    }

    /**
     * API: Get worklist by barcode
     * POST /api/mirth/machine-by-barcode
     */
    public function apiGetByBarcode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'api_key' => 'required|string',
            'barcode' => 'required|string',
            'machine_key' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $machine = Machine::where('api_key', $request->api_key)
            ->where('device_model', $request->machine_key)
            ->where('is_active', true)
            ->first();

        if (! $machine) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        $service = new WorklistService;
        $worklist = $service->getWorklistByBarcode($request->barcode);

        if (! $worklist) {
            return response()->json(['error' => 'Worklist not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $worklist,
        ]);
    }

    /**
     * API: Mark worklist as processed
     * POST /api/mirth/machine-mark-processed
     */
    public function apiMarkProcessed(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'api_key' => 'required|string',
            'barcode' => 'required|string',
            'machine_key' => 'required|string',
            'result_data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $machine = Machine::where('api_key', $request->api_key)
            ->where('device_model', $request->machine_key)
            ->where('is_active', true)
            ->first();

        if (! $machine) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        $service = new WorklistService;
        $worklist = $service->getWorklistByBarcode($request->barcode);

        if (! $worklist) {
            return response()->json(['error' => 'Worklist not found'], 404);
        }

        $result = $service->updateWorklistStatus(
            $worklist->id,
            WorklistService::STATUS_COMPLETED,
            $request->result_data
        );

        if (! $result) {
            return response()->json(['error' => 'Failed to update worklist'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Worklist marked as completed',
        ]);
    }
}
