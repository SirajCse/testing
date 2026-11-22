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
     *
     * Column reduction 2026-11-18: worklists.parameters is gone — the analyzer
     * mapping config now comes from HERE (one call per channel start) instead of
     * riding inside every worklist row.
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
                'id' => $m->id,
                'test_id' => $m->test_id,
                'test_code' => $m->test_code,
                'test_name' => $m->test?->name,
                'sample_type' => $m->sample_type,
                'parameters' => is_string($m->parameters)
                    ? json_decode($m->parameters, true)
                    : ($m->parameters ?? []),
            ]);

        return response()->json([
            'success' => true,
            'machine_id' => $machine->id,
            'device_model' => $machine->device_model,
            'direction' => $machine->direction ?? 'unidirectional',
            'machine_mappings' => $mappings,
        ]);
    }
}
