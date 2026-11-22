<?php

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Models\LabReceipt;
use App\Models\LabTest;
use App\Models\TestComponent;
use App\Models\TestConfig;
use App\Models\TestReport;
use App\Models\User;
use App\Scopes\SelfTenantScope;
use Cache;
use DataTables;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PDF;

class LabReportController extends Controller
{
    private array $resolvedMemo = [];

    private array $reportsMemo = [];

    private array $timings = [];

    private function time(string $label, callable $fn)
    {
        $start = microtime(true);
        $result = $fn();
        $this->timings[$label] = round((microtime(true) - $start) * 1000, 2);

        return $result;
    }

    public function index(): View|Application|Factory|\Illuminate\Contracts\Foundation\Application
    {
        isAccess('view_lab_report');

        return view('lab.lab_report.index');
    }

    /* ═══════════════════════════════════════════════
       PRELOAD HELPERS — accept tenant as parameter
       ═══════════════════════════════════════════════ */

    private function preloadTestConfigs($labTests, ?string $tenantId = null): void
    {
        if (! $labTests || $labTests->isEmpty()) {
            return;
        }

        $testIds = $labTests->pluck('test_id')->filter()->unique()->values()->all();
        if (empty($testIds)) {
            return;
        }

        $currentTenant = $tenantId ?? webTenantId();
        $defaultTenantId = config('app.default_tenant_id');

        $configs = TestConfig::withoutGlobalScope(SelfTenantScope::class)
            ->whereIn('test_id', $testIds)
            ->whereIn('tenant_id', array_filter([$currentTenant, $defaultTenantId]))
            ->whereNull('deleted_at')
            ->get();

        $byTestId = [];
        foreach ($configs as $config) {
            $testId = $config->test_id;
            if (! isset($byTestId[$testId]) || $config->tenant_id === $currentTenant) {
                $byTestId[$testId] = $config;
            }
        }

        foreach ($labTests as $labTest) {
            if ($labTest->test) {
                $labTest->test->setRelation('config', $byTestId[$labTest->test_id] ?? null);
            }
        }
    }

    private function preloadLabTestUsers($labTests): void
    {
        if (! $labTests || $labTests->isEmpty()) {
            return;
        }

        $userIds = $labTests
            ->flatMap(fn ($t) => [$t->prepared_by, $t->reviewed_by, $t->authorized_by])
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($userIds)) {
            return;
        }

        $users = User::withoutGlobalScope(SelfTenantScope::class)
            ->whereIn('id', $userIds)
            ->select('id', 'tenant_id', 'name', 'designation', 'signature', 'code')
            ->get()
            ->keyBy('id');

        foreach ($labTests as $labTest) {
            $labTest->setRelation('prepared', $users->get($labTest->prepared_by));
            $labTest->setRelation('reviewed', $users->get($labTest->reviewed_by));
            $labTest->setRelation('authorized', $users->get($labTest->authorized_by));
        }
    }

    /* ═══════════════════════════════════════════════
       RESOLVED COMPONENTS
       ═══════════════════════════════════════════════ */

    private function resolvedComponents(string $testId, ?string $tenantId = null): array
    {
        $tenantId = $tenantId ?? webTenantId();

        $memoKey = "{$testId}:{$tenantId}";
        if (isset($this->resolvedMemo[$memoKey])) {
            return $this->resolvedMemo[$memoKey];
        }

        $version = $this->resolvedVersion($tenantId);
        $cacheKey = "resolved_components_test_{$testId}_tenant_{$tenantId}_v{$version}";

        return $this->resolvedMemo[$memoKey] = Cache::remember($cacheKey, 3600, function () use ($testId, $tenantId) {
            return TestComponent::withTrashed()
                ->where('test_id', $testId)
                ->with(['override' => fn ($o) => $o->where('tenant_id', $tenantId)])
                ->get()
                ->mapWithKeys(fn ($c) => [$c->id => $this->resolveOne($c, $tenantId)])
                ->toArray();
        });
    }

    private function resolvedComponentsBatch(array $testIds, ?string $tenantId = null): array
    {
        $tenantId = $tenantId ?? webTenantId();

        $version = $this->resolvedVersion($tenantId);

        $result = [];
        $missing = [];

        foreach (array_unique($testIds) as $testId) {
            $memoKey = "{$testId}:{$tenantId}";

            if (isset($this->resolvedMemo[$memoKey])) {
                $result[$testId] = $this->resolvedMemo[$memoKey];
                continue;
            }

            $cached = Cache::get("resolved_components_test_{$testId}_tenant_{$tenantId}_v{$version}");

            if ($cached !== null) {
                $result[$testId] = $this->resolvedMemo[$memoKey] = $cached;
            } else {
                $missing[] = $testId;
            }
        }

        if (! empty($missing)) {
            $components = TestComponent::withTrashed()
                ->whereIn('test_id', $missing)
                ->with(['override' => fn ($o) => $o->where('tenant_id', $tenantId)])
                ->get()
                ->groupBy('test_id');

            foreach ($missing as $testId) {
                $mapped = $components->get($testId, collect())
                    ->mapWithKeys(fn ($c) => [$c->id => $this->resolveOne($c, $tenantId)])
                    ->toArray();

                $result[$testId] = $this->resolvedMemo["{$testId}:{$tenantId}"] = $mapped;

                Cache::put(
                    "resolved_components_test_{$testId}_tenant_{$tenantId}_v{$version}",
                    $mapped,
                    3600
                );
            }
        }

        return $result;
    }

    private function resolvedVersion(string $tenantId): string
    {
        return (string) Cache::remember(
            "resolved_version_tenant_{$tenantId}",
            86400,
            fn () => (string) now()->timestamp
        );
    }

    private function resolveOne(TestComponent $c, string $tenantId): array
    {
        $o = $c->override;

        $rawMaster = $c->getRawOriginal('reference_text') ?? '';
        $tenantRefText = $o?->reference_text ?? null;

        $masterSegments = $this->splitReferences($rawMaster);
        $tenantSegments = ($tenantRefText !== null && $tenantRefText !== '')
            ? $this->splitReferences($tenantRefText)
            : [];

        $effective = ($tenantRefText !== null && $tenantRefText !== '')
            ? $tenantRefText
            : $rawMaster;

        $segments = $this->splitReferences($effective);

        $referenceTexts = count($tenantSegments) > 1
            ? $tenantSegments
            : ($tenantSegments ?: $masterSegments);

        return [
            'id' => $c->id,
            'name' => $c->name,
            'type' => $c->type,
            'class' => $c->class,
            'order' => $o->order ?? $c->order ?? 0,
            'hide' => $o->hide ?? $c->hide ?? 0,
            'status' => $o->status ?? $c->status ?? 1,
            'is_hidden' => $o !== null && $o->status !== null && (int) $o->status === 0,
            'report_hidden' => (int) ($o->hide ?? $c->hide ?? 0) === 1,
            'unit' => $o->unit ?? $c->unit ?? '',
            'convert_unit' => $o->convert_unit ?? $c->convert_unit ?? '',
            'factor' => $o->factor ?? $c->factor ?? null,
            'default_value' => $o->default_value ?? $c->default_value ?? '',
            'option_value' => $o->option_value ?? $c->option_value ?? '',
            'mic_value' => $o->mic_value ?? $c->mic_value ?? '',
            'zone_value' => $o->zone_value ?? $c->zone_value ?? '',
            'reference_text' => $effective,
            'reference_default' => $segments[0] ?? '',
            'reference_segments' => $segments,
            'reference_texts' => $referenceTexts,
            'mode' => $c->mode,
            'align' => $c->align,
            'style' => $c->style,
        ];
    }

    private function splitReferences(?string $text): array
    {
        if (empty($text)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(';', $text)),
            fn ($s) => $s !== ''
        ));
    }

    /* ═══════════════════════════════════════════════
       TRANSFORM REPORTS
       ═══════════════════════════════════════════════ */

    private function transformReports(Collection $reports, array $resolved): Collection
    {
        return $reports
            ->filter(function ($report) use ($resolved) {
                if ($report->hide) {
                    return false;
                }

                $row = $resolved[$report->component_id] ?? null;
                if (! $row) {
                    return true;
                }

                if ($row['type'] !== 'title' && ! empty($row['is_hidden'])) {
                    return false;
                }

                if (! empty($row['report_hidden'])) {
                    return false;
                }

                return true;
            })
            ->map(function ($report) use ($resolved) {
                $row = $resolved[$report->component_id] ?? null;
                if (! $row) {
                    return $report;
                }

                $report->type = $row['type'];
                $report->default_value = $row['default_value'];
                $report->mic_value = $report->mic_value ?: $row['mic_value'];
                $report->zone_value = $report->zone_value ?: $row['zone_value'];
                $report->name = $row['name'];
                $report->reference_text = $report->reference_text ?: $row['reference_default'];
                $report->result = $report->result ?? $row['default_value'];
                $report->result_text = $report->result_text ?? $row['default_value'];
                $report->unit = $report->unit ?: $row['unit'];
                $report->order = $row['order'];
                $report->mode = in_array($row['mode'], ['before', 'after']) ? $row['mode'] : 'inside';
                $report->align = $row['align'] ?? '';
                $report->class = $row['class'] ?? '';
                $report->style = $row['style'] ?? '';

                if (
                    ! empty($row['convert_unit']) &&
                    ! empty($row['unit']) &&
                    $report->result !== null &&
                    ! empty($row['factor'])
                ) {
                    $report->convert_unit = $row['convert_unit'];

                    try {
                        $report->convert_result = convertUnitResult((object) $row, $report);
                    } catch (\Throwable $e) {
                        $report->convert_result = (float) $report->result * (float) $row['factor'];
                    }
                }

                return $report;
            })
            ->sortBy(fn ($r) => (int) ($r->order ?? 0))
            ->values();
    }

    /* ═══════════════════════════════════════════════
       AJAX DATATABLE
       ═══════════════════════════════════════════════ */

    public function ajax(Request $request): JsonResponse
    {
        isAccess('view_lab_report');

        $isOwn = $request->get('own') == '1';
        $noneTestCategory = config('app.none_test_category');

        $baseQuery = LabReceipt::query()->with([
            'labTest' => function ($q) use ($isOwn, $noneTestCategory) {
                $q->select([
                    'id', 'lab_id', 'test_id', 'collected_at', 'result_at',
                    'prepared_at', 'reviewed_at', 'authorized_at', 'done', 'has_entered',
                ]);

                $q->whereHas('test', function ($t) use ($isOwn, $noneTestCategory) {
                    $t->where('category_id', '!=', $noneTestCategory);
                    if ($isOwn) {
                        $t->accessibleByUser();
                    }
                });
            },
            'labTest.test:id,name,short_name,category_id',
            'labTest.test.config:id,tenant_id,test_id,short_name,is_same_page,status',
            'patient:id,name,phone,gender,age,dob',
        ]);

        if (auth()->user()->hasAnyPermission(['restore_lab_report', 'force_delete_lab_report'])) {
            $baseQuery->withTrashed();
        }

        [$startDate, $endDate] = $this->parseDateRange($request);

        $applyFilters = function ($query) use ($isOwn, $request, $startDate, $endDate) {
            if ($request->filled('barcode')) {
                $this->applyFastSearch($query, $request->get('barcode'), ['barcode', 'sl_no'], true);
            }

            if ($startDate && $endDate) {
                $query->whereBetween('created_at', [$startDate, $endDate]);
            } elseif ($request->filled('period') && $request->period !== 'all') {
                match ($request->period) {
                    'today' => $query->where('created_at', '>=', Carbon::today()),
                    'yesterday' => $query->whereBetween('created_at', [
                        Carbon::yesterday()->startOfDay(),
                        Carbon::yesterday()->endOfDay(),
                    ]),
                    '7days' => $query->where('created_at', '>=', Carbon::now()->subDays(7)),
                    '30days' => $query->where('created_at', '>=', Carbon::now()->subDays(30)),
                    default => null,
                };
            }

            if ($request->filled('status')) {
                $status = $request->get('status');
                $query->whereHas('labTest', function ($q) use ($status) {
                    match ($status) {
                        'pending' => $q->where('done', 0)->where('has_entered', 0),
                        'partial' => $q->where('has_entered', 1)->where('done', 0),
                        'completed' => $q->where('done', 1),
                        'collection_pending' => $q->whereNull('collected_at'),
                        'review_pending' => $q->whereNotNull('prepared_at')->whereNull('reviewed_at'),
                        'authorization_pending' => $q->whereNotNull('reviewed_at')->whereNull('authorized_at'),
                        default => null,
                    };
                });
            }

            if ($request->filled('signed')) {
                $isSigned = $request->signed === 'signed';
                $query->whereHas(
                    'labTest',
                    fn ($q) => $isSigned ? $q->whereNotNull('authorized_at') : $q->whereNull('authorized_at')
                );
            }

            if ($isOwn) {
                $query->whereHas('labTest.test', fn ($q) => $q->accessibleByUser());
            }

            if ($request->get('priority') === 'urgent') {
                $query->where(function ($q) {
                    $q->where('priority', 'urgent')
                        ->orWhereHas('labTest', fn ($q2) => $q2
                            ->whereNull('collected_at')
                            ->where('created_at', '<=', now()->subHours(2)));
                });
            }

            if ($request->filled('test_id')) {
                $testIds = (array) $request->get('test_id');
                $query->whereHas('labTest', fn ($q) => $q->whereIn('lab_tests.test_id', $testIds));
            }

            if ($request->filled('done')) {
                $query->where('done', $request->get('done'));
            }

            return $query;
        };

        $stats = $this->buildStats($applyFilters(clone $baseQuery));

        $dataTable = DataTables::eloquent($applyFilters($baseQuery))
            ->filter(function ($query) use ($request) {
                $kw = $request->input('search.value');
                if ($kw) {
                    $this->applyFastSearch($query, $kw, ['barcode', 'sl_no'], true);
                }
            })
            ->addColumn('bulk_checkbox',
                fn ($row) => '<input type="checkbox" class="bulk-checkbox" value="'.$row->id.'">')
            ->addColumn('report_patient', fn ($row) => view('lab.lab_report.partials.report_patient', compact('row'))->render())
            ->addColumn('test_details', fn ($row) => view('lab.lab_report.partials.test_details', compact('row'))->render())
            ->addColumn('signed_badge', function ($row) {
                $signed = $row->labTest->contains(fn ($t) => ! empty($t->authorized_at));

                return $signed
                    ? '<span class="status-badge status-signed">Signed</span>'
                    : '<span class="status-badge status-unsigned">Unsigned</span>';
            })
            ->addColumn('action', fn ($row) => view('lab.lab_report.action', compact('row'))->render())
            ->rawColumns(['bulk_checkbox', 'report_patient', 'test_details', 'signed_badge', 'action'])
            ->make(true);

        $response = $dataTable->getData(true);
        $response['stats'] = $stats;

        return response()->json($response);
    }

    private function parseDateRange(Request $request): array
    {
        $dateRange = $request->get('date') ?? $request->get('date_range');
        $startDate = $endDate = null;

        if ($dateRange) {
            $parts = preg_split('/\s+to\s+/', trim($dateRange));
            $startRaw = trim($parts[0] ?? '');
            $endRaw = trim($parts[1] ?? $startRaw);

            if ($startRaw) {
                $startDate = Carbon::parse($startRaw)->startOfDay();
                $endDate = Carbon::parse($endRaw ?: $startRaw)->endOfDay();
            }
        }

        if ($request->filled('barcode') || $request->get('period') === 'all') {
            return [null, null];
        }

        if (! $startDate || ! $endDate) {
            return [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()];
        }

        return [$startDate, $endDate];
    }

    protected function applyFastSearch($query, string $kw, array $columns, bool $withPatient = false): void
    {
        $kw = trim($kw);
        if ($kw === '') {
            return;
        }

        $escaped = addcslashes($kw, '%_\\');
        $digits = preg_replace('/\D+/', '', $kw);

        $query->where(function ($outer) use ($columns, $kw, $escaped, $digits, $withPatient) {
            foreach ($columns as $col) {
                $outer->orWhere($col, $kw);
                $outer->orWhere($col, 'LIKE', "{$escaped}%");
                $outer->orWhere($col, 'LIKE', "%{$escaped}");
            }

            if ($withPatient) {
                $outer->orWhereHas('patient', function ($pq) use ($escaped, $digits) {
                    $pq->where(function ($inner) use ($escaped, $digits) {
                        $inner->where('name', 'LIKE', "{$escaped}%")
                            ->orWhere('name', 'LIKE', "%{$escaped}");
                        $inner->orWhere('phone', 'LIKE', "{$escaped}%")
                            ->orWhere('phone', 'LIKE', "%{$escaped}");

                        if ($digits && $digits !== $escaped) {
                            $inner->orWhereRaw(
                                "REPLACE(REPLACE(REPLACE(phone,'-',''),' ',''),'+','') LIKE ?",
                                ["{$digits}%"]
                            );
                            $inner->orWhereRaw(
                                "REPLACE(REPLACE(REPLACE(phone,'-',''),' ',''),'+','') LIKE ?",
                                ["%{$digits}"]
                            );
                        }
                    });
                });
            }
        });
    }

    private function buildStats($query): array
    {
        $today = Carbon::today()->toDateString();
        $tenantId = auth()->user()->tenant_id;

        $receipt = DB::query()->fromSub($query->toBase(), 'g')
            ->where('tenant_id', $tenantId)
            ->selectRaw('
                COUNT(*)                                                             as total_reports,
                SUM(CASE WHEN done = 1 THEN 1 ELSE 0 END)                            as total_completed,
                SUM(CASE WHEN done = 0 THEN 1 ELSE 0 END)                            as total_pending,
                SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END)                as today_total,
                SUM(CASE WHEN DATE(created_at) = ? AND done = 1 THEN 1 ELSE 0 END)   as today_completed
            ', [$today, $today])
            ->first();

        $testAgg = LabTest::query()
            ->whereIn('lab_id', $query->select('id'))
            ->where('tenant_id', $tenantId)
            ->selectRaw('
                SUM(CASE WHEN has_entered = 1 AND done = 0                      THEN 1 ELSE 0 END) as partial_count,
                SUM(CASE WHEN collected_at IS NULL                              THEN 1 ELSE 0 END) as collection_pending,
                SUM(CASE WHEN prepared_at IS NOT NULL AND reviewed_at IS NULL   THEN 1 ELSE 0 END) as review_pending,
                SUM(CASE WHEN reviewed_at IS NOT NULL AND authorized_at IS NULL THEN 1 ELSE 0 END) as authorization_pending,
                SUM(CASE WHEN authorized_at IS NOT NULL                         THEN 1 ELSE 0 END) as signed_count,
                SUM(CASE WHEN authorized_at IS NULL                             THEN 1 ELSE 0 END) as unsigned_count
            ')
            ->first();

        return [
            'total_reports' => (int) ($receipt->total_reports ?? 0),
            'total_pending' => (int) ($receipt->total_pending ?? 0),
            'total_completed' => (int) ($receipt->total_completed ?? 0),
            'total_partial' => (int) ($testAgg->partial_count ?? 0),
            'collection_pending' => (int) ($testAgg->collection_pending ?? 0),
            'review_pending' => (int) ($testAgg->review_pending ?? 0),
            'authorization_pending' => (int) ($testAgg->authorization_pending ?? 0),
            'total_signed' => (int) ($testAgg->signed_count ?? 0),
            'total_unsigned' => (int) ($testAgg->unsigned_count ?? 0),
            'today_total' => (int) ($receipt->today_total ?? 0),
            'today_completed' => (int) ($receipt->today_completed ?? 0),
            'urgent_reports' => 0,
        ];
    }

    public function statistics(): JsonResponse
    {
        isAccess('view_lab_report');

        return response()->json($this->buildStats(LabReceipt::query()));
    }

    public function details($id): JsonResponse
    {
        isAccess('show_lab_report');

        try {
            $data = LabReceipt::with([
                'labTest' => fn ($q) => $q->withAppend(),
                'labExtra' => fn ($q) => $q->withAppend(),
                'patient:id,name,tenant_id,phone,email,age,dob,gender,blood_group,religion,nid_bc,address,district,thana',
                'payment',
            ])->findOrFail($id);

            return response()->json($data);
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => 'Can not Found Data', 'status' => 400], 500);
        }
    }

    /* ═══════════════════════════════════════════════
       SHOW
       ═══════════════════════════════════════════════ */

    public function show($rid, $tid = null)
    {
        isAccess('edit_lab_report');

        try {
            $data = LabReceipt::with([
                'labTest' => function ($q) {
                    $q->withWhereHas('test', fn ($t) => $t->where('category_id', '!=', config('app.none_test_category')))
                        ->withAppend();
                },
                'patient',
            ])->findOrFail($rid);

            $tenantId = auth()->user()->tenant_id;
            $this->preloadTestConfigs($data->labTest, $tenantId);

            $labTest = null;

            if ($tid) {
                $labTest = LabTest::with([
                    'test',
                    'components' => fn ($q) => $q->withTrashed(),
                    'reports',
                ])
                    ->where('lab_id', $rid)
                    ->where('id', $tid)
                    ->whereHas('test', fn ($t) => $t->where('category_id', '!=', config('app.none_test_category')))
                    ->first();

                if ($labTest) {
                    $this->preloadTestConfigs(collect([$labTest]), $tenantId);
                }

                if ($labTest && $labTest->reports) {
                    $resolved = $this->resolvedComponents($labTest->test_id, $tenantId);

                    $labTest->setRelation(
                        'reports',
                        $this->transformReports($labTest->reports, $resolved)
                    );
                }
            }
        } catch (\Throwable $e) {
            return redirect()->back()->with([
                'header' => 'No Test Found',
                'message' => 'Invalid Receive, Please try correct on !',
                'alert-type' => 'warning',
            ]);
        }

        if (request()->has('json')) {
            return response()->json([$data, $labTest ?? null]);
        }

        return view('lab.lab_report.show')->with(['data' => $data, 'report' => $labTest ?? null]);
    }

    /* ═══════════════════════════════════════════════
       EDIT
       ═══════════════════════════════════════════════ */

    public function edit($rid = null, $tid = null)
    {
        isAccess('edit_lab_report');

        try {
            $data = null;
            $labTest = null;

            if ($rid) {
                $tenantId = auth()->user()->tenant_id;

                $data = LabReceipt::select([
                    'id', 'patient_id', 'referral_id', 'contract_id',
                    'barcode', 'sl_no', 'date',
                    'discount', 'subtotal', 'total', 'paid', 'due', 'tax',
                    'done', 'note', 'created_by',
                ])
                    ->with([
                        'patient:id,name,phone,email,age,dob,gender,blood_group,reg_no',
                        'referral:id,name,designation',
                        'labTest' => function ($q) use ($tenantId) {
                            $q->select([
                                'id', 'lab_id', 'test_id', 'machine_id',
                                'done', 'has_entered', 'comment',
                                'prepared_by', 'reviewed_by', 'authorized_by',
                                'prepared_at', 'reviewed_at', 'authorized_at',
                            ])
                                ->withWhereHas('test', fn ($query) => $query
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
                                ])
                                ->orderBy('created_at', 'asc');
                        },
                        'creator:id,name',
                    ])
                    ->findOrFail($rid);

                $this->preloadTestConfigs($data->labTest, $tenantId);

                if ($tid) {
                    $labTest = $data->labTest->firstWhere('id', $tid);

                    if (! $labTest) {
                        throw new \Exception('You do not have access to this test');
                    }

                    $labTest->load([
                        'reports' => function ($q) {
                            $q->select(
                                'id', 'model_id', 'model_type', 'component_id',
                                'result', 'result_text', 'reference_text', 'mic_value', 'zone_value',
                                'unit', 'document', 'status', 'color', 'hide', 'share'
                            );
                        },
                    ]);

                    $this->hydrateEditComponents($labTest);
                }
            }
        } catch (\Throwable $e) {
            return redirect()->back()->with([
                'header' => 'Access Denied or Receive Not Found',
                'message' => $e->getMessage() ?: 'Invalid Receive or no access, please try correct one!',
                'alert-type' => 'warning',
            ]);
        }

        if (request()->has('json')) {
            return response()->json([$data, $labTest]);
        }

        return view('lab.lab_report.edit', ['data' => $data, 'report' => $labTest]);
    }

    private function hydrateEditComponents(LabTest $labTest): void
    {
        if (! $labTest->components) {
            return;
        }

        $reportsByComponent = $labTest->reports->keyBy('component_id');
        $isHidden = fn ($c) => $c->override !== null
            && $c->override->status !== null
            && (int) $c->override->status === 0;

        $components = $labTest->components
            ->reject($isHidden)
            ->map(function ($component) use ($reportsByComponent) {
                $report = $reportsByComponent->get($component->id);
                $override = $component->override;

                $overrideRefText = $override?->reference_text;
                $overrideSnapshot = $override ? $override->toArray() : null;

                $component->suppressOverride();

                $component->result = $report?->result ?? $component->default_value ?? '';
                $component->result_text = $report?->result_text ?? $component->default_value ?? '';
                $component->mic_value = $report?->mic_value ?? $component->mic_value ?? '';
                $component->zone_value = $report?->zone_value ?? $component->zone_value ?? '';
                $component->unit = $report?->unit ?? $component->unit ?? '';
                $component->order = $report?->order ?? $component->order ?? 0;
                $component->status = $report?->status ?? $component->status ?? 1;
                $component->document = $report?->document ?? '';
                $component->color = $report?->color ?? '';
                $component->hide = $report?->hide ?? $component->hide ?? 0;
                $component->share = $report?->share ?? 0;
                $component->report_id = $report?->id ?? null;

                $rawMaster = $component->getRawOriginal('reference_text') ?? '';
                $tenantRefText = $overrideRefText;

                $effective = ($tenantRefText !== null && $tenantRefText !== '')
                    ? $tenantRefText
                    : $rawMaster;

                $segments = $this->splitReferences($effective);
                $tenantSegments = ($tenantRefText !== null && $tenantRefText !== '')
                    ? $this->splitReferences($tenantRefText)
                    : [];
                $masterSegments = $this->splitReferences($rawMaster);

                $referenceTexts = count($tenantSegments) > 1
                    ? $tenantSegments
                    : ($tenantSegments ?: $masterSegments);

                $component->reference_text = $report?->reference_text ?: ($segments[0] ?? '');
                $component->reference_default = $segments[0] ?? '';
                $component->reference_texts = $referenceTexts;
                $component->available_references = count($referenceTexts) > 1 ? $referenceTexts : [];
                $component->override_snapshot = $overrideSnapshot;

                return $component;
            })
            ->values();

        $labTest->setRelation('components', $components);
        $labTest->unsetRelation('reports');
    }

    /* ═══════════════════════════════════════════════
       UPDATE
       ═══════════════════════════════════════════════ */

    public function update($model_id, Request $request): JsonResponse
    {
        isAccess('edit_lab_report');

        $validator = Validator::make($request->all(), [
            'complete' => ['nullable', 'numeric'],
            'prepared_by' => ['nullable', 'integer_or_uuid'],
            'reviewed_by' => ['nullable', 'integer_or_uuid'],
            'authorized_by' => ['nullable', 'integer_or_uuid'],
            'comment' => ['nullable', 'string'],
            'model_id' => ['required', 'integer_or_uuid'],
            'component.*.id' => ['nullable', 'integer_or_uuid'],
            'component.*.component_id' => ['required', 'integer_or_uuid'],
            'component.*.order' => ['required', 'numeric'],
            'component.*.type' => ['required', 'string', 'max:50'],
            'component.*.result' => ['nullable', 'string'],
            'component.*.result_text' => ['nullable', 'string'],
            'component.*.result_file' => ['nullable', 'file', 'max:1024', 'mimetypes:application/pdf,image/jpeg,image/jpg,image/png'],
            'component.*.unit' => ['nullable', 'string', 'max:50'],
            'component.*.status' => ['nullable', 'string', 'max:191'],
            'component.*.color' => ['nullable', 'string', 'max:191'],
            'component.*.hide' => ['nullable', 'numeric'],
            'component.*.mic_value' => ['nullable', 'string', 'max:50'],
            'component.*.zone_value' => ['nullable', 'string', 'max:50'],
            'component.*.reference_text' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json(['type' => 'invalid', 'note' => $validator->messages(), 'status' => 400], 200);
        }

        $data = $validator->validated();

        try {
            $labTestModel = LabTest::with('receipt:id,tenant_id,patient_id')->findOrFail($data['model_id']);

            if (! $labTestModel->receipt) {
                return response()->json([
                    'type' => 'fail',
                    'note' => 'Lab receipt not found for this test',
                    'status' => 400,
                ], 400);
            }

            $validComponentIds = [];
            if (isset($data['component'])) {
                $validComponentIds = TestComponent::withTrashed()
                    ->where('test_id', $labTestModel->test_id)
                    ->pluck('id')
                    ->all();
            }

            if (isset($data['component'])) {
                foreach ($data['component'] as $result) {
                    if (! empty($validComponentIds) && ! in_array($result['component_id'], $validComponentIds, true)) {
                        Log::warning('Skipping component not belonging to test', [
                            'lab_test_id' => $labTestModel->id,
                            'component_id' => $result['component_id'],
                        ]);
                        continue;
                    }

                    $result['patient_id'] = $labTestModel->receipt->patient_id;
                    $result['test_id'] = $labTestModel->test_id;
                    $result['model_id'] = $labTestModel->id;
                    $result['model_type'] = $labTestModel->getMorphClass();

                    if ($result['type'] == 'textarea') {
                        unset($result['result']);
                    } elseif ($result['type'] == 'culture') {
                        // keep both
                    } else {
                        unset($result['result_text']);
                    }

                    $previous_hide = $result['hide'] ?? 0;

                    $hasValue = $result['type'] == 'textarea'
                        ? (isset($result['result_text']) && trim((string) $result['result_text']) !== '')
                        : (isset($result['result']) && trim((string) $result['result']) !== '');

                    $hasFile = isset($result['type']) && $result['type'] == 'file' && isset($result['result_file']);

                    $result['hide'] = (! $hasValue && ! $hasFile) ? 1 : ($result['hide'] ?? 0);

                    if ($result['type'] == 'title' && ! $previous_hide) {
                        $result['hide'] = 0;
                    }

                    $reportId = $result['id'] ?? null;
                    unset($result['id']);

                    if (! empty($reportId)) {
                        $report = TestReport::updateOrCreate(['id' => $reportId], $result);
                    } else {
                        $report = TestReport::updateOrCreate([
                            'model_id' => $result['model_id'],
                            'model_type' => $result['model_type'],
                            'test_id' => $result['test_id'],
                            'component_id' => $result['component_id'],
                        ], $result);
                    }

                    if ($hasFile) {
                        $file = $result['result_file'];
                        $ext = strtolower($file->getClientOriginalExtension());

                        if (in_array($ext, ['pdf', 'jpeg', 'jpg', 'png', 'gif'])) {
                            $type = $ext === 'pdf' ? 'pdf' : 'image';
                            $report->updateFile($file, [
                                'name' => 'document',
                                'type' => $type,
                                'path' => 'LabReport/'.$type,
                            ]);
                        }
                    }
                }
            }

            $existingComponentIds = TestComponent::withTrashed()
                ->where('test_id', $labTestModel->test_id)
                ->pluck('id')
                ->all();

            TestReport::where('model_id', $labTestModel->id)
                ->where('model_type', $labTestModel->getMorphClass())
                ->where('test_id', $labTestModel->test_id)
                ->whereNotIn('component_id', $existingComponentIds)
                ->delete();

            $now = Carbon::now();
            $labTestData = [
                'done' => $data['complete'] ?? 0,
                'has_entered' => 1,
                'comment' => $data['comment'] ?? null,
                'prepared_by' => $data['prepared_by'] ?? null,
                'prepared_at' => $labTestModel->prepared_at ?? $now,
                'reviewed_by' => $data['reviewed_by'] ?? null,
                'reviewed_at' => isset($data['reviewed_by']) ? ($labTestModel->reviewed_at ?? $now) : null,
                'authorized_by' => $data['authorized_by'] ?? null,
                'authorized_at' => isset($data['authorized_by']) ? ($labTestModel->authorized_at ?? $now) : null,
            ];

            if (isset($data['authorized_by'])) {
                $labTestData['done'] = 1;
            }

            $labTestModel->update($labTestData);

            return response()->json([
                'type' => 'success',
                'note' => 'Test Report input successfully',
                'status' => 400,
            ], 201);

        } catch (\Throwable $e) {
            Log::error($e->getMessage());
        }

        return response()->json(['type' => 'fail', 'note' => 'Fail to Insert', 'status' => 400], 500);
    }

    /* ═══════════════════════════════════════════════
       SHIFT
       ═══════════════════════════════════════════════ */

    public function bulkShift($id, $d, $r = 'bulk')
    {
        isAccess('edit_lab_report');

        return $this->shiftTo($id, $d, $r, 'bulk');
    }

    public function shift($id, $d, $r = 'edit')
    {
        isAccess('edit_lab_report');

        return $this->shiftTo($id, $d, $r, 'edit');
    }

    private function shiftTo($id, $d, $r, $default)
    {
        try {
            $next = LabReceipt::query()
                ->when($d == 'next', fn ($q) => $q->where('id', '>', $id)->orderBy('id'))
                ->when($d == 'previous', fn ($q) => $q->where('id', '<', $id)->orderByDesc('id'))
                ->value('id');

            if ($next) {
                return match ($r) {
                    'bulk' => redirect()->route('lab.report.bulk.edit.receipt', $next),
                    'combo' => redirect()->route('lab.report.combo.edit', $next),
                    'show' => redirect()->route('lab.report.show', $next),
                    default => redirect()->route('lab.report.edit', $next),
                };
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

    /* ═══════════════════════════════════════════════
       BULK EDIT
       ═══════════════════════════════════════════════ */

    public function bulkEditReceipt($rid, Request $request)
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
                'total_tests' => $tests->count(),
                'total_categories' => $groupedTests->count(),
                'completed_tests' => $tests->where('done', 1)->count(),
                'pending_tests' => $tests->where('done', 0)->count(),
                'prepared_count' => $tests->whereNotNull('prepared_at')->count(),
                'reviewed_count' => $tests->whereNotNull('reviewed_at')->count(),
                'authorized_count' => $tests->whereNotNull('authorized_at')->count(),
            ];

            return view('lab.lab_report.bulk-edit-receipt', compact('receipt', 'groupedTests', 'stats'));

        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return redirect()->back()->with([
                'header' => 'Error',
                'message' => 'Unable to load receipt: '.$e->getMessage(),
                'alert-type' => 'error',
            ]);
        }
    }

    public function bulkUpdateReceipt(Request $request)
    {
        isAccess('edit_lab_report');

        $data = $request->isJson() ? $request->json()->all() : $request->all();

        $validator = Validator::make($data, [
            'receipt_id' => ['required', 'integer_or_uuid'],
            'tests' => ['required', 'array'],
            'tests.*.id' => ['required', 'integer_or_uuid'],
            'tests.*.complete' => ['nullable', 'numeric', 'in:0,1'],
            'tests.*.prepared_by' => ['nullable', 'integer_or_uuid'],
            'tests.*.reviewed_by' => ['nullable', 'integer_or_uuid'],
            'tests.*.authorized_by' => ['nullable', 'integer_or_uuid'],
            'tests.*.comment' => ['nullable', 'string'],
            'tests.*.components' => ['nullable', 'array'],
            'tests.*.components.*.id' => ['nullable', 'integer_or_uuid'],
            'tests.*.components.*.component_id' => ['required', 'integer_or_uuid'],
            'tests.*.components.*.type' => ['nullable', 'string'],
            'tests.*.components.*.result' => ['nullable', 'string'],
            'tests.*.components.*.result_text' => ['nullable', 'string'],
            'tests.*.components.*.unit' => ['nullable', 'string'],
            'tests.*.components.*.status' => ['nullable', 'string'],
            'tests.*.components.*.color' => ['nullable', 'string'],
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
        $successCount = $failCount = 0;
        $errors = [];

        try {
            $receiptId = $validated['receipt_id'];
            $existingTestIds = LabTest::where('lab_id', $receiptId)->pluck('id')->all();

            DB::transaction(function () use ($validated, $existingTestIds, $receiptId, &$successCount, &$failCount, &$errors) {
                $receipt = LabReceipt::with(['labTest' => function ($q) {
                    $q->select('id', 'lab_id', 'test_id', 'done', 'has_entered', 'prepared_at', 'reviewed_at', 'authorized_at');
                }])->findOrFail($receiptId);

                $labTestMap = $receipt->labTest->keyBy('id');
                $now = Carbon::now();

                $testIds = $receipt->labTest->pluck('test_id')->unique()->values()->all();
                $componentsByTest = TestComponent::withTrashed()
                    ->whereIn('test_id', $testIds)
                    ->get(['id', 'test_id'])
                    ->groupBy('test_id');

                foreach ($validated['tests'] as $testData) {
                    try {
                        if (! in_array($testData['id'], $existingTestIds, true)) {
                            $failCount++;
                            $errors[] = "Test ID {$testData['id']} does not belong to this receipt";
                            continue;
                        }

                        $labTestModel = $labTestMap->get($testData['id']);
                        if (! $labTestModel) {
                            $failCount++;
                            $errors[] = "Test ID {$testData['id']} not found";
                            continue;
                        }

                        if (! empty($testData['components'])) {
                            $validComponentIds = $componentsByTest
                                ->get($labTestModel->test_id, collect())
                                ->pluck('id')
                                ->all();

                            foreach ($testData['components'] as $componentData) {
                                if (! is_array($componentData) || empty($componentData)) {
                                    continue;
                                }

                                if (! empty($validComponentIds) && ! in_array($componentData['component_id'], $validComponentIds, true)) {
                                    Log::warning('Skipping component not belonging to test', [
                                        'lab_test_id' => $labTestModel->id,
                                        'component_id' => $componentData['component_id'],
                                    ]);
                                    continue;
                                }

                                $componentData['patient_id'] = $labTestModel->receipt->patient_id;
                                $componentData['test_id'] = $labTestModel->test_id;
                                $componentData['model_id'] = $labTestModel->id;
                                $componentData['model_type'] = $labTestModel->getMorphClass();

                                $type = $componentData['type'] ?? null;

                                if ($type === 'textarea') {
                                    unset($componentData['result']);
                                } elseif ($type !== 'culture') {
                                    unset($componentData['result_text']);
                                }

                                $previous_hide = $componentData['hide'] ?? 0;

                                $hasValue = $type === 'textarea'
                                    ? trim((string) ($componentData['result_text'] ?? '')) !== ''
                                    : trim((string) ($componentData['result'] ?? '')) !== '';

                                $hasFile = $type === 'file' && isset($componentData['result_file']);

                                $componentData['hide'] = (! $hasValue && ! $hasFile) ? 1 : ($componentData['hide'] ?? 0);

                                if ($type === 'title' && ! $previous_hide) {
                                    $componentData['hide'] = 0;
                                }

                                $reportId = $componentData['id'] ?? null;
                                unset($componentData['id']);

                                if (! empty($reportId)) {
                                    TestReport::updateOrCreate(['id' => $reportId], $componentData);
                                } else {
                                    TestReport::updateOrCreate([
                                        'model_id' => $componentData['model_id'],
                                        'model_type' => $componentData['model_type'],
                                        'test_id' => $componentData['test_id'],
                                        'component_id' => $componentData['component_id'],
                                    ], $componentData);
                                }
                            }

                            TestReport::where('model_id', $labTestModel->id)
                                ->where('model_type', $labTestModel->getMorphClass())
                                ->where('test_id', $labTestModel->test_id)
                                ->whereNotIn('component_id', $validComponentIds)
                                ->delete();
                        }

                        $update = [
                            'has_entered' => 1,
                            'done' => isset($testData['complete']) ? (int) $testData['complete'] : 0,
                            'prepared_by' => $testData['prepared_by'] ?? null,
                            'prepared_at' => $labTestModel->prepared_at ?? $now,
                            'reviewed_by' => $testData['reviewed_by'] ?? null,
                            'reviewed_at' => ! empty($testData['reviewed_by'])
                                ? ($labTestModel->reviewed_at ?? $now)
                                : null,
                            'authorized_by' => $testData['authorized_by'] ?? null,
                            'authorized_at' => ! empty($testData['authorized_by'])
                                ? ($labTestModel->authorized_at ?? $now)
                                : null,
                        ];

                        if (! empty($testData['authorized_by'])) {
                            $update['done'] = 1;
                        }

                        if (isset($testData['comment'])) {
                            $update['comment'] = $testData['comment'];
                        }

                        $labTestModel->update($update);
                        $successCount++;

                    } catch (\Throwable $e) {
                        $failCount++;
                        $errors[] = "Test ID {$testData['id']}: ".$e->getMessage();
                        Log::error('Bulk update test error: '.$e->getMessage());
                    }
                }

                $allCompleted = LabTest::where('lab_id', $receiptId)->where('done', 0)->doesntExist();
                $receipt->update(['done' => $allCompleted ? 1 : 0]);
            });

            return response()->json([
                'type' => 'success',
                'note' => "Updated {$successCount} tests successfully. Failed: {$failCount}",
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'errors' => $errors,
                'status' => 200,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Bulk update error: '.$e->getMessage());

            return response()->json([
                'type' => 'fail',
                'note' => 'Failed to update: '.$e->getMessage(),
                'status' => 500,
            ], 500);
        }
    }

    /* ═══════════════════════════════════════════════
       COMBO EDIT  (single / bulk / easy-grid theme)
       One screen · one JSON payload in · one JSON save out
       ═══════════════════════════════════════════════ */

    public function comboEdit($rid, Request $request)
    {
        isAccess('edit_lab_report');

        try {
            $tenantId = auth()->user()->tenant_id;

            $receipt = $this->loadReceiptForCombo($rid, $tenantId);

            $this->preloadTestConfigs($receipt->labTest, $tenantId);
            $this->preloadLabTestUsers($receipt->labTest);

            foreach ($receipt->labTest as $test) {
                $this->hydrateEditComponents($test);
            }

            $combo = $this->comboPayload($receipt);

            if ($request->has('json')) {
                return response()->json($combo);
            }

            return view('lab.lab_report.combo-edit', compact('receipt', 'combo'));

        } catch (\Throwable $e) {
            Log::error('Combo edit error: '.$e->getMessage());

            return redirect()->back()->with([
                'header' => 'Error',
                'message' => 'Unable to load receipt: '.$e->getMessage(),
                'alert-type' => 'error',
            ]);
        }
    }

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
            'tests.*.components.*.component_id' => ['required', 'integer_or_uuid'],
            'tests.*.components.*.type' => ['required', 'string', 'max:50'],
            'tests.*.components.*.order' => ['nullable', 'numeric'],
            'tests.*.components.*.result' => ['nullable', 'string'],
            'tests.*.components.*.result_text' => ['nullable', 'string'],
            'tests.*.components.*.unit' => ['nullable', 'string', 'max:50'],
            'tests.*.components.*.status' => ['nullable', 'string', 'max:191'],
            'tests.*.components.*.color' => ['nullable', 'string', 'max:191'],
            'tests.*.components.*.hide' => ['nullable', 'numeric'],
            'tests.*.components.*.mic_value' => ['nullable', 'string', 'max:50'],
            'tests.*.components.*.zone_value' => ['nullable', 'string', 'max:50'],
            'tests.*.components.*.reference_text' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json(['type' => 'invalid', 'note' => $validator->messages(), 'status' => 400], 200);
        }

        $validated = $validator->validated();
        $saved = [];
        $failed = [];
        $reportIds = [];

        try {
            $receipt = LabReceipt::findOrFail($validated['receipt_id']);

            $testIds = collect($validated['tests'])->pluck('id')->unique()->values()->all();

            // Only tests of THIS receipt that the user may edit (same rule as comboEdit)
            $labTests = $receipt->labTest()
                ->whereIn('id', $testIds)
                ->whereHas('test', fn ($q) => $q
                    ->accessibleByUser()
                    ->where('category_id', '!=', config('app.none_test_category')))
                ->get()
                ->keyBy('id');

            $componentIdsByTest = TestComponent::withTrashed()
                ->whereIn('test_id', $labTests->pluck('test_id')->unique()->all())
                ->get(['id', 'test_id'])
                ->groupBy('test_id')
                ->map(fn ($g) => $g->pluck('id')->map(fn ($i) => (string) $i)->all());

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
                        $componentIdsByTest->get($labTest->test_id, []),
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

            $allFailed = empty($saved) && ! empty($failed);

            return response()->json([
                'type' => $allFailed ? 'fail' : (empty($failed) ? 'success' : 'partial'),
                'note' => 'Saved '.count($saved).' test(s)'.($failed ? ', '.count($failed).' failed' : ''),
                'saved' => $saved,
                'failed' => $failed,
                'report_ids' => $reportIds,   // [labTestId => [componentId => reportId]]
                'status' => $allFailed ? 422 : 200,
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
     * Saves ONE lab test (components + sign-off). Runs inside a transaction so a
     * failing test never leaves half-written results. Returns [componentId => reportId].
     */
    private function comboSaveTest(LabTest $labTest, LabReceipt $receipt, array $testData, array $validComponentIds, Carbon $now): array
    {
        $morph = $labTest->getMorphClass();
        $reportIds = [];
        $touched = false;

        foreach ($testData['components'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = $row['type'] ?? null;

            // File uploads are not part of this theme — never overwrite them here.
            if ($type === 'file') {
                continue;
            }

            if (! in_array((string) $row['component_id'], $validComponentIds, true)) {
                Log::warning('Skipping component not belonging to test', [
                    'lab_test_id' => $labTest->id,
                    'component_id' => $row['component_id'],
                ]);
                continue;
            }

            $report = Arr::only($row, [
                'result', 'result_text', 'unit', 'status', 'color',
                'hide', 'order', 'mic_value', 'zone_value', 'reference_text',
            ]);

            if (array_key_exists('order', $report) && $report['order'] === null) {
                unset($report['order']);
            }

            // same column rules as update() / bulkUpdateReceipt()
            if ($type === 'textarea') {
                unset($report['result']);
            } elseif ($type !== 'culture') {
                unset($report['result_text']);
            }

            $hasValue = $type === 'textarea'
                ? trim((string) ($report['result_text'] ?? '')) !== ''
                : trim((string) ($report['result'] ?? '')) !== '';

            $postedHide = (int) ($report['hide'] ?? 0);
            $report['hide'] = ($type === 'title' || $hasValue) ? $postedHide : 1;

            // Identity is always the composite key — a client-sent report id is never trusted.
            $keys = [
                'model_id' => $labTest->id,
                'model_type' => $morph,
                'test_id' => $labTest->test_id,
                'component_id' => $row['component_id'],
            ];

            $model = TestReport::updateOrCreate($keys, $report + ['patient_id' => $receipt->patient_id] + $keys);

            $reportIds[$row['component_id']] = $model->id;
            $touched = true;
        }

        if ($touched && ! empty($validComponentIds)) {
            TestReport::where('model_id', $labTest->id)
                ->where('model_type', $morph)
                ->where('test_id', $labTest->test_id)
                ->whereNotIn('component_id', $validComponentIds)
                ->delete();
        }

        $update = [
            'has_entered' => 1,
            'done' => (int) ($testData['complete'] ?? 0),
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

    private function loadReceiptForCombo($rid, string $tenantId): LabReceipt
    {
        return LabReceipt::with([
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
    }

    /** Sign-off choices: tenant users + anyone already signed on these tests. Labels are unique. */
    private function comboUsers(LabReceipt $receipt): array
    {
        $users = User::select('id', 'name', 'designation', 'code')
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->keyBy('id');

        foreach ($receipt->labTest as $lt) {
            foreach (['prepared', 'reviewed', 'authorized'] as $rel) {
                $u = $lt->relationLoaded($rel) ? $lt->getRelation($rel) : null;

                if ($u && ! $users->has($u->id)) {
                    $users->put($u->id, $u);
                }
            }
        }

        $nameCount = $users->countBy(fn ($u) => mb_strtolower(trim((string) $u->name)));

        return $users->map(function ($u) use ($nameCount) {
            $name = trim((string) $u->name);
            $dup = ($nameCount[mb_strtolower($name)] ?? 0) > 1;

            return ['id' => $u->id, 'label' => $dup ? $name.' ('.($u->code ?: $u->id).')' : $name];
        })->values()->all();
    }

    /** Builds the exact JSON shape the combo theme expects (TESTS / PT / USERS …). */
    private function comboPayload(LabReceipt $receipt): array
    {
        $users = $this->comboUsers($receipt);
        $labelById = collect($users)->pluck('label', 'id');
        $label = fn ($id) => $id ? (string) ($labelById->get($id) ?? '') : '';
        $patient = $receipt->patient;

        $tests = $receipt->labTest->map(function ($lt) use ($label) {
            $comps = $lt->components
                ->reject(fn ($c) => $c->type === 'file')
                ->map(function ($c) {
                    $type = (string) $c->type;

                    $result = $type === 'textarea'
                        ? (string) ($c->result_text ?? '')
                        : (string) ($c->result ?? '');

                    // report.hide=1 is auto-set on empty rows; only a hidden row WITH a value is a manual hide
                    $hide = $type === 'title'
                        ? (bool) $c->hide
                        : ((bool) $c->hide && trim($result) !== '');

                    $status = (string) ($c->status ?? '');
                    if (in_array($status, ['0', '1'], true)) {
                        $status = '';   // model default, not a real flag
                    }

                    $row = [
                        'id' => $c->id,
                        'rid' => $c->report_id,
                        'type' => $type,
                        'name' => (string) $c->name,
                        'unit' => (string) ($c->unit ?? ''),
                        'ref' => (string) ($c->reference_text ?? ''),
                        'status' => $status,
                        'color' => (string) ($c->color ?? ''),
                        'hide' => $hide,
                        'order' => (int) ($c->order ?? 0),
                    ];

                    if ($type === 'culture') {
                        $row['mic'] = (string) ($c->mic_value ?? '');
                        $row['zone'] = (string) ($c->zone_value ?? '');
                        $row['interp'] = $result;   // S / I / R / NT is stored in report.result
                    } else {
                        $row['result'] = $result;
                    }

                    $refs = $c->available_references ?? [];
                    if (is_array($refs) && count($refs) > 1) {
                        $row['refs'] = array_values($refs);
                    }

                    return $row;
                })
                ->values();

            return [
                'id' => $lt->id,
                'cat' => $lt->test?->category?->name ?? 'Uncategorized',
                'name' => $lt->test?->name ?? 'Unknown',
                'short' => $lt->test?->short_name ?? '',
                /* v26 combo: TEST CUSTOM FUNCTION — test_configs.custom_js, exactly what the
                   bulk-edit page renders as data-custom-js. Either a REGISTRY key (cbc_differentiation,
                   lipid_profile …) or an inline formula (Neutrophils+Lymphocytes=100). Empty string = no
                   custom logic for this test. The combo view forwards it as t.js / data-custom-js. */
                'js' => (string) ($lt->test?->config?->custom_js ?? ''),
                'done' => (bool) $lt->done,
                'comment' => (string) ($lt->comment ?? ''),
                'sp' => $label($lt->prepared_by),
                'sr' => $label($lt->reviewed_by),
                'sa' => $label($lt->authorized_by),
                'comps' => $comps,
            ];
        })->values()->all();

        return [
            'receipt' => [
                'id' => $receipt->id,
                'sl_no' => $receipt->sl_no,
                'barcode' => $receipt->barcode,
            ],
            'patient' => [
                'name' => $patient->name ?? 'No Patient',
                'age' => (string) ($patient->age_format ?? ''),
                'sex' => (string) ($patient->gender ?? ''),
                'id' => (string) ($patient->reg_no ?? $receipt->sl_no),
                'ref' => $receipt->referral?->name ?? '—',
            ],
            'users' => $users,
            'defaultSign' => ['p' => $label(auth()->id()), 'r' => '', 'a' => ''],
            'updateUrl' => route('lab.report.combo.update'),
            'tests' => $tests,
        ];
    }

    /* ═══════════════════════════════════════════════
       DELETE / RESTORE / FORCE DELETE
       ═══════════════════════════════════════════════ */

    public function destroy($id): JsonResponse
    {
        isAccess('delete_lab_report');

        try {
            $model = LabReceipt::find($id);
            if (! $model) {
                return response()->json(['type' => 'fail', 'note' => 'Can not Update', 'status' => 400], 200);
            }

            if ($model->labTest()->exists()) {
                $model->labTest()->delete();
            }
            if ($model->labExtra()->exists()) {
                $model->labExtra()->delete();
            }
            if ($model->payments()->exists()) {
                $model->payments()->delete();
            }

            $model->delete();

            return response()->json(['type' => 'success', 'note' => 'Update Success', 'status' => 400], 200);
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => 'Can not Update', 'status' => 400], 200);
        }
    }

    public function restore($id): JsonResponse
    {
        isAccess('restore_lab_report');

        try {
            $model = LabReceipt::withTrashed()->find($id);
            $model->restore();

            if ($model->labTest()->withTrashed()->exists()) {
                $model->labTest()->withTrashed()->restore();
            }
            if ($model->labExtra()->withTrashed()->exists()) {
                $model->labExtra()->withTrashed()->restore();
            }
            if ($model->payments()->withTrashed()->exists()) {
                $model->payments()->withTrashed()->restore();
            }

            return response()->json(['type' => 'success', 'note' => 'Restore Success', 'status' => 400], 200);
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => "Can't Restore ".$e->getMessage(), 'status' => 400], 200);
        }
    }

    public function forceDelete($id): JsonResponse
    {
        isAccess('force_delete_lab_report');

        try {
            $model = LabReceipt::withTrashed()->find($id);

            if ($model->labTest()->withTrashed()->exists()) {
                $model->labTest()->withTrashed()->forceDelete();
            }
            if ($model->labExtra()->withTrashed()->exists()) {
                $model->labExtra()->withTrashed()->forceDelete();
            }
            if ($model->payments()->withTrashed()->exists()) {
                $model->payments()->withTrashed()->forceDelete();
            }

            $model->forceDelete();

            return response()->json(['type' => 'success', 'note' => 'Force Delete Success', 'status' => 400], 200);
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => "Can't Force Delete", 'status' => 400], 200);
        }
    }

    /* ═══════════════════════════════════════════════
       PRINT — internal / outer / blank
       ═══════════════════════════════════════════════ */

    public function print($rid, $tid)
    {
        isAccess('edit_lab_report');

        return $this->renderPrint($rid, $tid, 'report', '');
    }

    public function reportPrint($rid, $tid)
    {
        // ⭐ outer route — no auth. Tenant = URL's subdomain tenant.
        return $this->renderPrint($rid, $tid, 'report-outer', 'A4');
    }

    public function blankPrint($rid, $tid)
    {
        isAccess('show_lab_receipt');

        return $this->renderPrint($rid, $tid, 'blank', 'A4');
    }

    private function renderPrint($rid, $tid, string $view, string $format)
    {
        try {
            $tidArray = array_filter(explode(',', (string) $tid));
            $isOuter = $view === 'report-outer';

            $tenantId = $isOuter
                ? webTenantId()
                : auth()->user()->tenant_id;

            $data = LabReceipt::select([
                'id', 'uuid', 'tenant_id', 'barcode', 'sl_no', 'date',
                'patient_id', 'referral_id', 'contract_id', 'visit_id',
                'subtotal', 'discount', 'total', 'paid', 'due', 'tax',
            ])
                ->with([
                    'labTest' => function ($q) use ($tidArray) {
                        $q->select([
                            'id', 'uuid', 'tenant_id', 'lab_id', 'test_id', 'done', 'comment',
                            'prepared_by', 'reviewed_by', 'authorized_by',
                            'prepared_at', 'reviewed_at', 'result_at',
                        ])
                            ->with([
                                'test:id,category_id,name,short_name,details',
                                'test.category:id,name',
                                'reports:model_id,model_type,test_id,component_id,type,result,result_text,reference_text,mic_value,zone_value,document,unit,status,order,color,hide,share',
                            ])
                            ->when(! empty($tidArray), fn ($q) => $q->whereIn('lab_tests.id', $tidArray));
                    },
                    'patient:id,tenant_id,name,reg_no,email,phone,nid_bc,age,dob,blood_group,gender,address,district,thana',
                    'referral:id,name,phone,designation',
                ])
                ->when($isOuter, fn ($q) => $q->whereAny(['uuid', 'id'], $rid))
                ->when(! $isOuter, fn ($q) => $q->where('id', $rid))
                ->first();

            if (! $data) {
                return $this->printNotify('Receipt Not Found', 'Invalid receipt.', $isOuter);
            }

            // ⭐ BLOCK outer print when due > 1 — no blade, no MPDF
            if ($isOuter && (float) ($data->due ?? 0) > 1) {

                return redirect()->back()->with([
                    'header' => 'Payment Required',
                    'message' => 'Outstanding due: ৳'.number_format((float) $data->due, 2).'. Please clear the due to view the report.',
                    'alert-type' => 'warning',
                ]);
            }

            if (! $data->labTest?->count()) {
                return $this->printNotify('No Test Report Found', 'Test Report is not ready, Please try later !', $isOuter);
            }

            $this->preloadTestConfigs($data->labTest, $tenantId);
            $this->preloadLabTestUsers($data->labTest);

            $testIds = $data->labTest->pluck('test_id')->unique()->values()->all();
            $resolvedMap = $this->resolvedComponentsBatch($testIds, $tenantId);

            $sorted = $data->labTest
                ->sortBy([
                    fn ($a, $b) => ($a?->test?->category?->id ?? 0) <=> ($b?->test?->category?->id ?? 0),
                    fn ($a, $b) => ($a?->test?->config?->is_same_page ? 1 : 0) <=> ($b?->test?->config?->is_same_page ? 1 : 0),
                ])
                ->map(function ($labTest) use ($resolvedMap, $view) {
                    if ($labTest->reports) {
                        $resolved = $resolvedMap[$labTest->test_id] ?? [];
                        $labTest->setRelation(
                            'reports',
                            $this->transformReports($labTest->reports, $resolved)
                        );
                    }

                    if ($view === 'blank') {
                        unset($labTest->components);
                    }

                    return $labTest;
                });

            $data->setRelation('labTest', $sorted);

            $fileName = $data->sl_no ? "{$data->sl_no}_report.pdf" : 'report.pdf';

            $config = ['title' => $fileName];
            if ($format) {
                $config['format'] = $format;
            }

            return PDF::loadView(
                "lab.theme.report.dynamic.{$view}",
                ['data' => $data],
                [],
                $config
            )->stream($fileName);

        } catch (\Throwable $e) {
            Log::error('renderPrint failed', [
                'rid' => $rid,
                'tid' => $tid,
                'view' => $view,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->printNotify('No Test Found', 'Invalid Receive, Please try correct on !', $view === 'report-outer');
        }
    }

    private function printNotify(string $header, string $message, bool $isOuter): mixed
    {
        $payload = [
            'header' => $header,
            'message' => $message,
            'alert-type' => 'warning',
        ];

        if (request()->ajax() || request()->wantsJson()) {
            return response()->json($payload, 200);
        }

        // Outer route — plain HTML response so patient sees the message
        if ($isOuter) {
            $safeHeader = htmlspecialchars($header, ENT_QUOTES, 'UTF-8');
            $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

            return response(
                "<!DOCTYPE html><html><head><meta charset='utf-8'><title>{$safeHeader}</title></head><body style='font-family:sans-serif;padding:40px;text-align:center;'><h2>{$safeHeader}</h2><p>{$safeMessage}</p></body></html>",
                200
            )->header('Content-Type', 'text/html; charset=UTF-8');
        }

        return redirect()->back()->with($payload);
    }

    public function forceComplete($id): JsonResponse
    {
        isAccess('show_lab_receipt');

        try {
            LabReceipt::where('id', $id)->update(['done' => 1]);

            return response()->json([
                'type' => 'success',
                'note' => 'Lab receipt marked as completed',
                'status' => 200,
            ], 200);
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return response()->json([
                'type' => 'fail',
                'note' => 'Cannot find or update data',
                'status' => 400,
            ], 500);
        }
    }

    public function debugPrintTimings($rid, $tid)
    {
        isAccess('edit_lab_report');

        $this->timings = [];
        $tidArr = explode(',', $tid);

        $this->time('receipt_load', function () use ($rid, $tidArr) {
            $data = LabReceipt::select([
                'id', 'uuid', 'tenant_id', 'barcode', 'sl_no', 'date',
                'patient_id', 'referral_id', 'contract_id', 'visit_id',
            ])
                ->with([
                    'labTest' => function ($q) use ($tidArr) {
                        $q->select([
                            'id', 'uuid', 'tenant_id', 'lab_id', 'test_id', 'done', 'comment',
                            'prepared_by', 'reviewed_by', 'authorized_by',
                            'prepared_at', 'reviewed_at', 'result_at',
                        ])
                            ->with([
                                'test:id,category_id,name,short_name,details',
                                'test.category:id,name',
                                'reports:model_id,model_type,test_id,component_id,type,result,result_text,reference_text,mic_value,zone_value,document,unit,status,order,color,hide,share',
                            ])
                            ->whereIn('lab_tests.id', $tidArr);
                    },
                    'patient:id,tenant_id,name,reg_no,email,phone,nid_bc,age,dob,blood_group,gender,address,district,thana',
                    'referral:id,name,phone,designation',
                ])
                ->findOrFail($rid);

            $this->preloadTestConfigs($data->labTest, auth()->user()->tenant_id);
            $this->preloadLabTestUsers($data->labTest);

            return $data;
        });

        return response()->json([
            'timings' => $this->timings,
            'hint' => 'receipt_load now includes config + user preloads. Should be under 200ms.',
        ]);
    }
}
