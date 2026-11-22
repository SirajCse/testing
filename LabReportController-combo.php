<?php

/* ═════════════════════════════════════════════════════════════════════════════
   COMBO EDIT / UPDATE / LIS MAP — paste these methods INTO your existing
   App\Http\Controllers\Lab\LabReportController (same class, no new file needed).

   WHAT TO DO
   ───────────
   1) Copy the four "public" methods (comboEdit, comboUpdate, comboLis, comboShift)
      and the five "private" helpers (comboSaveTest, comboTypeMapByTest,
      comboLisMap, comboLisPayload, comboLisNormalizeMapping) into
      app/Http/Controllers/Lab/LabReportController.php
      — e.g. right after bulkUpdateReceipt(), before the SHIFT section.

   2) Make sure these imports exist at the top of the controller
      (most of them are already there; ADD the two marked NEW):
         use App\Models\LabReceipt;
         use App\Models\LabTest;
         use App\Models\TestComponent;
         use App\Models\TestReport;
         use Illuminate\Support\Carbon;
         use Illuminate\Support\Facades\DB;
         use Illuminate\Support\Facades\Log;
         use Illuminate\Support\Facades\Validator;
         use Illuminate\Http\JsonResponse;
         use Illuminate\Http\UploadedFile;          // NEW (only used via instanceof)
         use App\Models\Worklist;                   // NEW — LIS worklist rows

   3) Add the routes from routes-combo.php (combo edit / update / lis / shift).

   4) Put combo-edit.blade.php into resources/views/lab/lab_report/combo-edit.blade.php

   DESIGN NOTES
   ────────────
   • comboEdit reuses exactly the bulkEditReceipt loading pattern
     (preloadTestConfigs → preloadLabTestUsers → hydrateEditComponents), so the
     blade gets the same hydrated components as the grid bulk edit.
   • comboUpdate accepts the SAME payload contract as bulkUpdateReceipt
     (JSON or multipart FormData):
        receipt_id + tests[i][id] + tests[i][complete] +
        tests[i][prepared_by|reviewed_by|authorized_by|comment] +
        tests[i][components][COMPONENT_ID][result|result_text|unit|status|color|
                                            hide|mic_value|zone_value|reference_text|result_file]
     Results are persisted model-wise with TestReport::updateOrCreate on the
     composite key (model_id, model_type, test_id, component_id) — a client-sent
     report id is never trusted. Component type is resolved from the DB, not from
     the request. File rows go through $report->updateFile() like update() does.
   • comboLis is the "LIS edit map" loader: for the receipt it reads the
     worklists rows (lab_test wise), takes the latest lis_results version
     (latest_result_id, falling back to worklists.result_data snapshot),
     normalizes the machine mapping ({CODE: [component_id, ...]}) exactly like
     MirthController does, and returns { labTestId: { machine, at, version,
     components: { componentId: { value, code, instrument, at, version } } } }.
     The combo page renders the values as "LIS · Use" badges (demo v23 style);
     accepting only fills the inputs — the user still saves via comboUpdate.
   ═════════════════════════════════════════════════════════════════════════════ */

/* ═══════════════════════════════════════════════
   COMBO EDIT (v23 shell · single / bulk / easy)
   ═══════════════════════════════════════════════ */

public function comboEdit($rid)
{
    isAccess('edit_lab_report');

    try {
        $tenantId = auth()->user()->tenant_id;

        $receipt = LabReceipt::with([
            'patient:id,name,phone,age,dob,gender,blood_group,reg_no,nid_bc',
            'referral:id,name,designation',
            'labTest' => function ($q) use ($tenantId) {
                $q->withWhereHas('test', fn ($query) => $query
                    ->accessibleByUser()
                    ->where('category_id', '!=', config('app.none_test_category')))
                    ->with([
                        'test.category:id,name',
                        'components' => function ($q) use ($tenantId) {
                            $q->select(
                                'id', 'test_id', 'name', 'type', 'unit',
                                'default_value', 'mic_value', 'zone_value', 'option_value',
                                'reference_text', 'class', 'order', 'hide'
                            )->with(['override' => fn ($o) => $o->where('tenant_id', $tenantId)]);
                        },
                        'reports' => function ($q) {
                            $q->select(
                                'id', 'model_id', 'model_type', 'component_id',
                                'result', 'result_text', 'reference_text', 'mic_value', 'zone_value',
                                'unit', 'document', 'status', 'color', 'hide', 'share'
                            );
                        },
                    ])
                    ->orderBy('created_at', 'asc');
            },
        ])->findOrFail($rid);

        $this->preloadTestConfigs($receipt->labTest, $tenantId);
        $this->preloadLabTestUsers($receipt->labTest);

        foreach ($receipt->labTest as $test) {
            $this->hydrateEditComponents($test);
        }

        $groupedTests = $receipt->labTest->groupBy(fn ($test) => $test->test?->category?->name ?? 'Uncategorized');

        $tests = $receipt->labTest;
        $stats = [
            'total_tests'     => $tests->count(),
            'total_categories' => $groupedTests->count(),
            'completed_tests' => $tests->where('done', 1)->count(),
            'pending_tests'   => $tests->where('done', 0)->count(),
            'prepared_count'  => $tests->whereNotNull('prepared_at')->count(),
            'reviewed_count'  => $tests->whereNotNull('reviewed_at')->count(),
            'authorized_count' => $tests->whereNotNull('authorized_at')->count(),
        ];

        if (request()->has('json')) {
            return response()->json(['receipt' => $receipt, 'stats' => $stats]);
        }

        return view('lab.lab_report.combo-edit', compact('receipt', 'groupedTests', 'stats'));

    } catch (\Throwable $e) {
        Log::error('Combo edit error: '.$e->getMessage());

        return redirect()->back()->with([
            'header'  => 'Error',
            'message' => 'Unable to load receipt: '.$e->getMessage(),
            'alert-type' => 'error',
        ]);
    }
}

/* ═══════════════════════════════════════════════
   COMBO UPDATE — JSON or multipart, model-wise
   updateOrCreate of every posted component result.
   ═══════════════════════════════════════════════ */

public function comboUpdate(Request $request): JsonResponse
{
    isAccess('edit_lab_report');

    $data = $request->isJson() ? $request->json()->all() : $request->all();

    $validator = Validator::make($data, [
        'receipt_id' => ['required', 'integer_or_uuid'],
        'tests' => ['required', 'array', 'min:1'],
        'tests.*.id' => ['required', 'integer_or_uuid'],
        'tests.*.complete' => ['nullable', 'numeric', 'in:0,1'],
        'tests.*.prepared_by' => ['nullable', 'integer_or_uuid'],
        'tests.*.reviewed_by' => ['nullable', 'integer_or_uuid'],
        'tests.*.authorized_by' => ['nullable', 'integer_or_uuid'],
        'tests.*.comment' => ['nullable', 'string'],
        'tests.*.components' => ['nullable', 'array'],
        'tests.*.components.*.component_id' => ['nullable', 'integer_or_uuid'],
        'tests.*.components.*.result' => ['nullable', 'string'],
        'tests.*.components.*.result_text' => ['nullable', 'string'],
        'tests.*.components.*.result_file' => ['nullable', 'file', 'max:1024', 'mimetypes:application/pdf,image/jpeg,image/jpg,image/png'],
        'tests.*.components.*.unit' => ['nullable', 'string', 'max:50'],
        'tests.*.components.*.status' => ['nullable', 'string', 'max:191'],
        'tests.*.components.*.color' => ['nullable', 'string', 'max:191'],
        'tests.*.components.*.hide' => ['nullable', 'numeric'],
        'tests.*.components.*.order' => ['nullable', 'numeric'],
        'tests.*.components.*.mic_value' => ['nullable', 'string', 'max:50'],
        'tests.*.components.*.zone_value' => ['nullable', 'string', 'max:50'],
        'tests.*.components.*.reference_text' => ['nullable', 'string', 'max:500'],
    ]);

    if ($validator->fails()) {
        return response()->json(['type' => 'invalid', 'note' => $validator->messages(), 'status' => 400], 200);
    }

    $validated = $validator->validated();
    $saved  = [];
    $failed = [];
    $reportIds = [];

    try {
        $receipt = LabReceipt::findOrFail($validated['receipt_id']);
        $testIds = collect($validated['tests'])->pluck('id')->unique()->values()->all();

        /* Only tests of THIS receipt the user may edit — same rule as comboEdit */
        $labTests = $receipt->labTest()
            ->whereIn('id', $testIds)
            ->whereHas('test', fn ($q) => $q
                ->accessibleByUser()
                ->where('category_id', '!=', config('app.none_test_category')))
            ->get()
            ->keyBy('id');

        $typesByTest = $this->comboTypeMapByTest($labTests->pluck('test_id')->unique()->all());
        $now = Carbon::now();

        foreach ($validated['tests'] as $testData) {
            $labTest = $labTests->get($testData['id']);

            if (! $labTest) {
                $failed[(string) $testData['id']] = 'Test not found, not accessible, or not part of this receipt';
                continue;
            }

            try {
                $reportIds[$labTest->id] = DB::transaction(fn () => $this->comboSaveTest(
                    $labTest,
                    $receipt,
                    $testData,
                    $typesByTest->get($labTest->test_id, collect()),
                    $now
                ));
                $saved[] = $labTest->id;
            } catch (\Throwable $e) {
                Log::error("Combo update test {$labTest->id}: ".$e->getMessage());
                $failed[(string) $labTest->id] = 'Could not save this test';
            }
        }

        if ($saved) {
            $receipt->update([
                'done' => LabTest::where('lab_id', $receipt->id)->where('done', 0)->doesntExist() ? 1 : 0,
            ]);
        }

        /* fresh per-test state so the page can refresh pills without reload */
        $fresh = LabTest::whereIn('id', $saved)
            ->get(['id', 'done', 'prepared_by', 'reviewed_by', 'authorized_by', 'comment'])
            ->map(fn ($t) => [
                'id'            => $t->id,
                'done'          => (int) $t->done,
                'prepared_by'   => $t->prepared_by,
                'reviewed_by'   => $t->reviewed_by,
                'authorized_by' => $t->authorized_by,
                'comment'       => $t->comment,
            ])->values()->all();

        $allFailed = empty($saved) && ! empty($failed);

        return response()->json([
            'type'       => $allFailed ? 'fail' : (empty($failed) ? 'success' : 'partial'),
            'note'       => 'Saved '.count($saved).' test(s)'.($failed ? ', '.count($failed).' failed' : ''),
            'saved'      => $saved,
            'failed'     => $failed,
            'report_ids' => $reportIds,   // [labTestId => [componentId => reportId]]
            'tests'      => $fresh,
            'status'     => $allFailed ? 422 : 200,
        ], $allFailed ? 422 : 200);

    } catch (\Throwable $e) {
        Log::error('Combo update error: '.$e->getMessage());

        return response()->json([
            'type' => 'fail',
            'note' => 'Failed to update: '.$e->getMessage(),
            'status' => 500,
        ], 500);
    }
}

/**
 * Saves ONE lab test (components + sign-off) inside a transaction, so a failing
 * test never leaves half-written results. Returns [componentId => reportId].
 * Column rules mirror update() / bulkUpdateReceipt():
 *   textarea → result_text only · culture → result + mic + zone · else → result
 *   empty value (and no file) → hide = 1 · title keeps its posted hide
 */
private function comboSaveTest(LabTest $labTest, LabReceipt $receipt, array $testData, $typesByComponent, Carbon $now): array
{
    $morph = $labTest->getMorphClass();
    $reportIds = [];
    $touched = false;

    foreach ($testData['components'] ?? [] as $compKey => $row) {
        if (! is_array($row) || (empty($row['component_id']) && empty($compKey))) {
            continue;
        }

        /* the id normally arrives as a promoted field; fall back to the array
           key (tests[i][components][COMPONENT_ID][field]) like bulk does */
        $componentId = (string) ($row['component_id'] !== null && $row['component_id'] !== ''
            ? $row['component_id'] : $compKey);
        $type = $typesByComponent->get($componentId);

        if ($type === null) {
            Log::warning('Skipping component not belonging to test', [
                'lab_test_id'  => $labTest->id,
                'component_id' => $componentId,
            ]);
            continue;
        }

        $file = $row['result_file'] ?? null;
        $hasFile = $file instanceof UploadedFile;

        /* strip non-column keys, keep the whitelist of real columns */
        $report = collect($row)->only([
            'result', 'result_text', 'unit', 'status', 'color',
            'hide', 'order', 'mic_value', 'zone_value', 'reference_text',
        ])->all();

        if (array_key_exists('order', $report) && $report['order'] === null) {
            unset($report['order']);
        }

        if ($type === 'textarea') {
            unset($report['result']);
        } elseif ($type !== 'culture') {
            unset($report['result_text']);
        }

        $hasValue = $type === 'textarea'
            ? trim((string) ($report['result_text'] ?? '')) !== ''
            : trim((string) ($report['result'] ?? '')) !== '';

        $previousHide = (int) ($report['hide'] ?? 0);
        $report['hide'] = (! $hasValue && ! $hasFile && $type !== 'title') ? 1 : $previousHide;

        /* identity is always the composite key — never trust a client report id */
        $keys = [
            'model_id'     => $labTest->id,
            'model_type'   => $morph,
            'test_id'      => $labTest->test_id,
            'component_id' => $componentId,
        ];

        $model = TestReport::updateOrCreate($keys, $report + ['patient_id' => $receipt->patient_id] + $keys);

        if ($hasFile) {
            $ext = strtolower($file->getClientOriginalExtension());

            if (in_array($ext, ['pdf', 'jpeg', 'jpg', 'png', 'gif'])) {
                $fileType = $ext === 'pdf' ? 'pdf' : 'image';
                $model->updateFile($file, [
                    'name' => 'document',
                    'type' => $fileType,
                    'path' => 'LabReport/'.$fileType,
                ]);
            }
        }

        $reportIds[$componentId] = $model->id;
        $touched = true;
    }

    if ($touched && $typesByComponent->isNotEmpty()) {
        TestReport::where('model_id', $labTest->id)
            ->where('model_type', $morph)
            ->where('test_id', $labTest->test_id)
            ->whereNotIn('component_id', $typesByComponent->keys()->all())
            ->delete();
    }

    $update = [
        'has_entered' => 1,
        'done'        => (int) ($testData['complete'] ?? 0),
        'prepared_at' => $labTest->prepared_at ?? $now,
    ];

    foreach (['prepared' => false, 'reviewed' => true, 'authorized' => true] as $role => $stamp) {
        if (! array_key_exists("{$role}_by", $testData)) {
            continue;
        }

        $by = $testData["{$role}_by"] ?: null;
        $update["{$role}_by"] = $by;

        if ($stamp) {
            $update["{$role}_at"] = $by ? ($labTest->{"{$role}_at"} ?? $now) : null;
        }
    }

    if (! empty($testData['authorized_by'])) {
        $update['done'] = 1;
    }

    if (array_key_exists('comment', $testData)) {
        $update['comment'] = $testData['comment'];
    }

    $labTest->update($update);

    return $reportIds;
}

/** [test_id => componentId => type] for the given test ids (withTrashed like bulk). */
private function comboTypeMapByTest(array $testIds)
{
    if (empty($testIds)) {
        return collect();
    }

    return TestComponent::withTrashed()
        ->whereIn('test_id', $testIds)
        ->get(['id', 'test_id', 'type'])
        ->groupBy('test_id')
        ->map(fn ($g) => $g->pluck('type', 'id'));
}

/* ═══════════════════════════════════════════════
   COMBO LIS — "LIS edit map", lab_test wise.
   GET lab/report/combo-lis/{rid} → { type, tests: { labTestId: {...} } }
   ═══════════════════════════════════════════════ */

public function comboLis($rid): JsonResponse
{
    isAccess('edit_lab_report');

    try {
        return response()->json([
            'type'  => 'success',
            'tests' => $this->comboLisMap((string) $rid),
            'status' => 200,
        ], 200);
    } catch (\Throwable $e) {
        Log::error('Combo LIS map error: '.$e->getMessage());

        return response()->json([
            'type'  => 'fail',
            'note'  => 'Unable to load LIS values',
            'tests' => [],
            'status' => 500,
        ], 500);
    }
}

/**
 * Worklist rows of this receipt → latest LIS result version → component values.
 * Falls back to the worklists.result_data snapshot when lis_results is not
 * reachable, and to an empty map when the worklist tables are not installed.
 */
private function comboLisMap(string $rid): array
{
    if (! class_exists(\App\Models\Worklist::class)) {
        return [];
    }

    try {
        $worklists = \App\Models\Worklist::query()
            ->where('lab_receipt_id', $rid)
            ->whereNotNull('lab_test_id')
            ->with('machine:id,name')
            ->get(['id', 'lab_test_id', 'test_id', 'machine_id', 'latest_result_id', 'result_data', 'completed_at']);
    } catch (\Throwable $e) {
        Log::warning('Combo LIS: worklist table unavailable — '.$e->getMessage());

        return [];
    }

    $map = [];

    foreach ($worklists as $worklist) {
        $payload = null;
        $version = null;
        $resultRow = null;

        if (! empty($worklist->latest_result_id)) {
            try {
                $resultRow = DB::table('lis_results')
                    ->where('id', $worklist->latest_result_id)
                    ->first(['version', 'result_data', 'device_model']);

                if ($resultRow && ! empty($resultRow->result_data)) {
                    $payload = json_decode($resultRow->result_data, true);
                    $version = $resultRow->version;
                }
            } catch (\Throwable $e) {
                Log::warning('Combo LIS: lis_results unavailable — '.$e->getMessage());
            }
        }

        if ($payload === null && ! empty($worklist->result_data)) {
            $payload = json_decode($worklist->result_data, true);
        }

        if (! is_array($payload)) {
            continue;
        }

        $mapping = $this->comboLisNormalizeMapping($payload['mapping'] ?? []);
        $results = $payload['results'] ?? [];

        if (empty($mapping) || ! is_array($results)) {
            continue;
        }

        $machineName = $worklist->machine?->name
            ?? ($payload['device_model'] ?? ($resultRow->device_model ?? 'LIS'));
        $at = optional($worklist->completed_at)->format('H:i');
        $labTestId = (string) $worklist->lab_test_id;

        foreach ($results as $result) {
            $code = $result['code'] ?? null;
            $value = $result['value'] ?? null;

            if ($code === null || ! array_key_exists($code, $mapping)) {
                continue;
            }

            foreach ((array) $mapping[$code] as $componentId) {
                $map[$labTestId]['components'][(string) $componentId] = [
                    'value'      => $value === null ? '' : (string) $value,
                    'code'       => (string) $code,
                    'instrument' => (string) $machineName,
                    'at'         => (string) ($result['time'] ?? $at ?? ''),
                    'version'    => $version,
                ];
            }
        }

        if (! empty($map[$labTestId])) {
            $map[$labTestId]['machine'] = (string) $machineName;
            $map[$labTestId]['at'] = (string) ($at ?? '');
            $map[$labTestId]['version'] = $version;
        }
    }

    return $map;
}

/** Same normalization MirthController applies (nested or flat mapping). */
private function comboLisNormalizeMapping($raw): array
{
    if (! is_array($raw)) {
        return [];
    }

    if (isset($raw['mapping']) && is_array($raw['mapping'])) {
        $raw = $raw['mapping'];
    }

    $normalized = [];

    foreach ($raw as $code => $targets) {
        if (is_array($targets)) {
            $normalized[(string) $code] = $targets;
        } elseif (is_string($targets) && $targets !== '') {
            $normalized[(string) $code] = [$targets];
        }
    }

    return $normalized;
}

/* ═══════════════════════════════════════════════
   COMBO SHIFT — prev / next receipt, staying on the combo page
   ═══════════════════════════════════════════════ */

public function comboShift($id, $d)
{
    isAccess('edit_lab_report');

    try {
        $next = LabReceipt::query()
            ->when($d == 'next', fn ($q) => $q->where('id', '>', $id)->orderBy('id'))
            ->when($d == 'previous', fn ($q) => $q->where('id', '<', $id)->orderByDesc('id'))
            ->value('id');

        if ($next) {
            return redirect()->route('lab.report.combo.edit', $next);
        }

        return redirect()->back()->with([
            'header' => 'No More Records',
            'message' => 'You have reached the '.($d == 'next' ? 'end' : 'beginning').' of the list.',
            'alert-type' => 'warning',
        ]);

    } catch (\Throwable $e) {
        return redirect()->back()->with([
            'header' => 'Error',
            'message' => 'Unable to navigate: '.$e->getMessage(),
            'alert-type' => 'error',
        ]);
    }
}
