<?php

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Models\LabReceipt;
use App\Models\LabTest;
use App\Models\Machine;
use App\Models\Product;
use App\Models\Test;
use App\Models\TestSample;
use App\Models\TestSampleItem;
use App\Services\Barcode\SimpleBarcodeService;
use App\Services\Barcode\SimpleSampleBarcodeService;
use App\Services\WorklistService;
use App\Services\ZplService;
use DataTables;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SampleController extends Controller
{
    protected WorklistService $worklistService;

    protected SimpleBarcodeService $receiptBarcodeService;

    protected SimpleSampleBarcodeService $sampleBarcodeService;

    protected ZplService $zplService;

    public function __construct(
        WorklistService $worklistService,
        SimpleBarcodeService $receiptBarcodeService,
        SimpleSampleBarcodeService $sampleBarcodeService,
        ZplService $zplService
    ) {
        $this->worklistService = $worklistService;
        $this->receiptBarcodeService = $receiptBarcodeService;
        $this->sampleBarcodeService = $sampleBarcodeService;
        $this->zplService = $zplService;
    }

    // ============================================
    // SAMPLE INDEX WITH DATATABLE
    // ============================================

    public function index()
    {
        return view('lab.sample.index');
    }

    public function getSamplesData(Request $request)
    {
        $model = LabReceipt::with(['patient', 'labTest'])
            ->withCount(['labTest as total_tests'])
            ->withCount(['labTest as completed_tests' => function ($q) {
                $q->whereNotNull('collected_by');
            }])
            ->withCount(['labTest as processing_tests' => function ($q) {
                $q->whereHas('worklist', function ($w) {
                    $w->where('status', 'processing');
                });
            }])
            ->withCount(['labTest as worklist_count' => function ($q) {
                $q->whereHas('worklist');
            }]);

        $hasSearch = ($request->has('search') && ! empty($request->search)) ||
            ($request->has('patient_search') && ! empty($request->patient_search));

        if (! $hasSearch) {
            if ($request->has('date_range') && $request->date_range) {
                $dates = explode(' to ', $request->date_range);
                if (count($dates) == 2) {
                    $model->whereDate('date', '>=', $dates[0])
                        ->whereDate('date', '<=', $dates[1]);
                } elseif (count($dates) == 1) {
                    $model->whereDate('date', $dates[0]);
                }
            } elseif ($request->has('period') && $request->period && ! $request->date_range) {
                switch ($request->period) {
                    case 'all':
                        break;
                    case 'today':
                        $model->whereDate('date', today());
                        break;
                    case 'yesterday':
                        $model->whereDate('date', today()->subDay());
                        break;
                    case '7days':
                        $model->whereDate('date', '>=', today()->subDays(7));
                        break;
                    case '30days':
                        $model->whereDate('date', '>=', today()->subDays(30));
                        break;
                }
            } else {
                $model->whereDate('date', today());
            }

            if ($request->has('date_from') && $request->date_from) {
                $model->whereDate('date', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $model->whereDate('date', '<=', $request->date_to);
            }

            if ($request->has('status') && $request->status && $request->status !== 'all') {
                switch ($request->status) {
                    case 'pending':
                        $model->whereDoesntHave('labTest', function ($q) {
                            $q->whereNotNull('collected_by');
                        });
                        break;
                    case 'partial':
                        $model->whereHas('labTest', function ($q) {
                            $q->whereNotNull('collected_by');
                        })->whereHas('labTest', function ($q) {
                            $q->whereNull('collected_by');
                        });
                        break;
                    case 'completed':
                        $model->whereDoesntHave('labTest', function ($q) {
                            $q->whereNull('collected_by');
                        });
                        break;
                    case 'processing':
                        $model->whereHas('labTest.worklist', function ($q) {
                            $q->where('status', 'processing');
                        });
                        break;
                    case 'worklist':
                        $model->whereHas('labTest.worklist');
                        break;
                }
            }
        }

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $model->where(function ($q) use ($search) {
                $q->where('barcode', 'like', "%{$search}%")
                    ->orWhere('sl_no', 'like', "%{$search}%");
            });
        }

        if ($request->has('patient_search') && $request->patient_search) {
            $patientSearch = $request->patient_search;
            $model->whereHas('patient', function ($q) use ($patientSearch) {
                $q->where('name', 'like', "%{$patientSearch}%")
                    ->orWhere('phone', 'like', "%{$patientSearch}%")
                    ->orWhere('reg_no', 'like', "%{$patientSearch}%");
            });
        }

        if ($request->has('order')) {
            foreach ($request->order as $order) {
                $columnIndex = $order['column'];
                $columnName = $request->columns[$columnIndex]['data'];
                $direction = $order['dir'];

                switch ($columnName) {
                    case 'barcode':
                    case 'barcode_info':
                        $model->orderByRaw('CAST(barcode AS UNSIGNED) '.$direction);
                        break;
                    case 'date':
                        $model->orderBy('date', $direction);
                        break;
                    case 'total_tests':
                        $model->orderBy('total_tests', $direction);
                        break;
                    case 'sl_no':
                        $model->orderByRaw('CAST(sl_no AS UNSIGNED) '.$direction);
                        break;
                    default:
                        if (in_array($columnName, ['barcode', 'sl_no', 'date'])) {
                            $model->orderBy($columnName, $direction);
                        }
                        break;
                }
            }
        } else {
            $model->orderByRaw('CAST(barcode AS UNSIGNED) DESC');
        }

        return DataTables::eloquent($model)
            ->addIndexColumn()
            ->addColumn('checkbox', function ($row) {
                return '<input type="checkbox" class="receipt-checkbox" value="'.$row->id.'">';
            })
            ->addColumn('barcode', function ($row) {
                return $row->barcode;
            })
            ->addColumn('sl_no', function ($row) {
                return $row->sl_no;
            })
            ->addColumn('barcode_info', function ($row) {
                return '<div class="font-weight-bold">'.e($row->sl_no ?? '').'</div>
                <small class="text-muted">'.e($row->barcode ?? '').'</small>';
            })
            ->addColumn('patient_info', function ($row) {
                return '<div><strong>'.e($row->patient?->name ?? 'N/A').'</strong></div>
                <small class="text-muted">'.e($row->patient?->phone ?? '').' | '.e($row->patient?->reg_no ?? '').'</small>';
            })
            ->addColumn('patient_name', function ($row) {
                return $row->patient?->name ?? 'N/A';
            })
            ->addColumn('patient_phone', function ($row) {
                return $row->patient?->phone ?? '';
            })
            ->addColumn('patient_reg_no', function ($row) {
                return $row->patient?->reg_no ?? '';
            })
            ->addColumn('date', function ($row) {
                return $row->date ? $row->date->format('d-m-Y') : '-';
            })
            ->addColumn('total_tests', function ($row) {
                return $row->total_tests ?? 0;
            })
            ->addColumn('collection_status', function ($row) {
                $total = $row->total_tests;
                $collected = $row->completed_tests;
                $percentage = $total > 0 ? round(($collected / $total) * 100) : 0;

                if ($collected == 0) {
                    $badge = '<span class="status-badge status-pending">Pending</span>';
                } elseif ($collected < $total) {
                    $badge = '<span class="status-badge status-partial">Partial ('.$collected.'/'.$total.')</span>';
                } else {
                    $badge = '<span class="status-badge status-completed">Completed</span>';
                }

                return $badge;
            })
            ->addColumn('worklist_status', function ($row) {
                $worklistCount = $row->worklist_count;
                $processingCount = $row->processing_tests;

                if ($worklistCount == 0) {
                    return '<span class="status-badge status-pending"><i data-feather="clock"></i> Not Generated</span>';
                } elseif ($processingCount > 0) {
                    return '<span class="status-badge status-partial"><i data-feather="loader"></i> Processing ('.$processingCount.')</span>';
                }

                return '<span class="status-badge status-completed"><i data-feather="check"></i> Generated</span>';
            })
            ->addColumn('actions', function ($row) {
                return view('lab.sample.action', compact('row'));
            })
            ->rawColumns(['checkbox', 'barcode_info', 'patient_info', 'collection_status', 'worklist_status'])
            ->make(true);
    }

    public function getSampleStats(Request $request)
    {
        $query = LabReceipt::query();

        $hasSearch = ($request->has('search') && ! empty($request->search)) ||
            ($request->has('patient_search') && ! empty($request->patient_search));

        if (! $hasSearch) {
            if ($request->has('date_range') && $request->date_range) {
                $dates = explode(' to ', $request->date_range);
                if (count($dates) == 2) {
                    $query->whereDate('date', '>=', $dates[0])
                        ->whereDate('date', '<=', $dates[1]);
                } elseif (count($dates) == 1) {
                    $query->whereDate('date', $dates[0]);
                }
            } elseif ($request->has('period') && $request->period && $request->period !== 'all') {
                switch ($request->period) {
                    case 'today':
                        $query->whereDate('date', today());
                        break;
                    case 'yesterday':
                        $query->whereDate('date', today()->subDay());
                        break;
                    case '7days':
                        $query->whereDate('date', '>=', today()->subDays(7));
                        break;
                    case '30days':
                        $query->whereDate('date', '>=', today()->subDays(30));
                        break;
                }
            } elseif (! $request->has('date_range') && (! $request->has('period') || ! $request->period)) {
                $query->whereDate('date', today());
            }

            if ($request->has('status') && $request->status && $request->status !== 'all') {
                switch ($request->status) {
                    case 'pending':
                        $query->whereDoesntHave('labTest', function ($q) {
                            $q->whereNotNull('collected_by');
                        });
                        break;
                    case 'partial':
                        $query->whereHas('labTest', function ($q) {
                            $q->whereNotNull('collected_by');
                        })->whereHas('labTest', function ($q) {
                            $q->whereNull('collected_by');
                        });
                        break;
                    case 'completed':
                        $query->whereDoesntHave('labTest', function ($q) {
                            $q->whereNull('collected_by');
                        });
                        break;
                    case 'processing':
                        $query->whereHas('labTest.worklist', function ($q) {
                            $q->where('status', 'processing');
                        });
                        break;
                    case 'worklist':
                        $query->whereHas('labTest.worklist');
                        break;
                }
            }
        }

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('barcode', 'like', "%{$search}%")
                    ->orWhere('sl_no', 'like', "%{$search}%");
            });
        }

        if ($request->has('patient_search') && $request->patient_search) {
            $patientSearch = $request->patient_search;
            $query->whereHas('patient', function ($q) use ($patientSearch) {
                $q->where('name', 'like', "%{$patientSearch}%")
                    ->orWhere('phone', 'like', "%{$patientSearch}%")
                    ->orWhere('reg_no', 'like', "%{$patientSearch}%");
            });
        }

        $total = $query->count();
        $pending = (clone $query)->whereDoesntHave('labTest', function ($q) {
            $q->whereNotNull('collected_by');
        })->count();
        $completed = (clone $query)->whereDoesntHave('labTest', function ($q) {
            $q->whereNull('collected_by');
        })->whereHas('labTest')->count();
        $partial = $total - $pending - $completed;
        $worklistCount = (clone $query)->whereHas('labTest.worklist')->count();

        return response()->json([
            'total' => (int) $total,
            'pending' => (int) $pending,
            'partial' => (int) $partial,
            'completed' => (int) $completed,
            'worklist_count' => (int) $worklistCount,
        ]);
    }

    // ============================================
    // CORE LOGIC - PREPARE EXPANDED TESTS
    // ============================================

    public function prepareExpandedTests(
        int|string $labReceiptId,
        ?LabReceipt $labReceipt = null,
        $existingSamples = null
    ): array {
        if (! $labReceipt) {
            $labReceipt = LabReceipt::with([
                'labTest.test.config',
                'labTest.test.consumptions.product',
            ])->findOrFail($labReceiptId);
        }

        $receiptBarcode = $labReceipt->barcode;
        $tubeGroups = $this->groupByProductAndTiming($labReceipt->labTest);

        if ($existingSamples === null) {
            $existingSamples = TestSample::where('lab_receipt_id', $labReceiptId)
                ->with('items')
                ->get();
        }

        $samplesCollection = $existingSamples instanceof Collection
            ? $existingSamples
            : collect($existingSamples);

        $existingSamplesKeyed = $samplesCollection->keyBy(
            fn ($s) => $s->product_id.'_'.($s->timing ?? '')
        );

        $tubes = [];

        // Per-product barcode buckets — each product's collision check is independent
        $usedBarcodes = [];

        foreach ($tubeGroups as $groupKey => $group) {
            $key = ($group['product_id'] ?? 0).'_'.($group['timing'] ?? '');
            $timing = $group['timing'] ?? '';
            $productId = $group['product_id'];

            // Ensure per-product bucket exists
            if (! isset($usedBarcodes[$productId])) {
                $usedBarcodes[$productId] = [];
            }

            if (isset($existingSamplesKeyed[$key])) {
                $testSample = $existingSamplesKeyed[$key];
                $barcode = $testSample->barcode;
                $isRecheck = (bool) preg_match('/-(C[0-9]*|[A-Z0-9]+?[0-9]+)$/', $barcode);

                // Collision check scoped to this product only
                if (in_array($barcode, $usedBarcodes[$productId], true)) {
                    Log::warning('Duplicate existing barcode detected', [
                        'receipt_id' => $labReceiptId,
                        'barcode' => $barcode,
                        'product_id' => $productId,
                        'timing' => $timing,
                    ]);
                    $occurrence = 2;
                    do {
                        $barcode = $this->sampleBarcodeService->generateCounterSample(
                            $receiptBarcode,
                            $timing ?: null,
                            $occurrence
                        );
                        $occurrence++;
                    } while (in_array($barcode, $usedBarcodes[$productId], true));
                }
                $usedBarcodes[$productId][] = $barcode;

                $tubes[] = [
                    'barcode' => $barcode,
                    'product_id' => $productId,
                    'product_name' => $group['product_name'],
                    'tube_type' => $group['product_name'],
                    'timing' => $timing ?: null,
                    'tests' => $group['tests'],
                    'lab_test_ids' => array_column($group['tests'], 'lab_test_id'),
                    'is_collected' => $testSample->is_collected,
                    'is_recheck' => $isRecheck,
                    'items_collected' => $testSample->items->whereNotNull('collected_by')->count(),
                    'total_items' => $testSample->items->count(),
                    'has_label' => $group['has_label'] ?? true,
                    'has_box' => $group['has_box'] ?? false,
                    'barcode_source' => $testSample->barcode_source ?? 'system',
                    'shared_from_tenant_barcode' => $testSample->shared_from_tenant_barcode,
                    'original_barcode' => $testSample->barcode,
                    'timing_label' => $this->worklistService->getTimingLabel($timing),
                    'test_sample_id' => $testSample->id,
                    'is_existing' => true,
                ];
            } else {
                $occurrence = 1;
                do {
                    $barcode = $this->sampleBarcodeService->generateCounterSample(
                        $receiptBarcode,
                        $timing ?: null,
                        $occurrence
                    );
                    $occurrence++;
                } while (in_array($barcode, $usedBarcodes[$productId], true));
                $usedBarcodes[$productId][] = $barcode;

                $tubes[] = [
                    'barcode' => $barcode,
                    'product_id' => $productId,
                    'product_name' => $group['product_name'],
                    'tube_type' => $group['product_name'],
                    'timing' => $timing ?: null,
                    'tests' => $group['tests'],
                    'lab_test_ids' => array_column($group['tests'], 'lab_test_id'),
                    'is_collected' => false,
                    'is_recheck' => false,
                    'items_collected' => 0,
                    'total_items' => count($group['tests']),
                    'has_label' => $group['has_label'] ?? true,
                    'has_box' => $group['has_box'] ?? false,
                    'barcode_source' => 'system',
                    'shared_from_tenant_barcode' => null,
                    'original_barcode' => $barcode,
                    'timing_label' => $this->worklistService->getTimingLabel($timing),
                    'test_sample_id' => null,
                    'is_existing' => false,
                ];
            }
        }

        return $tubes;
    }

    private function groupByProductAndTiming($labTests): array
    {
        $timingPriority = [
            'F' => 100, 'FBS' => 100, 'FASTING' => 100,
            '1H' => 90,
            '2H' => 80,
            '3H' => 70,
            '4H' => 60,
            'PP' => 50, 'POSTPRANDIAL' => 50,
            'E' => 40, 'EXT' => 40, 'EXTENDED' => 40,
            'R' => 30, 'RBS' => 30, 'RANDOM' => 30,
        ];

        $timedGroups = [];
        $emptyTests = [];

        foreach ($labTests as $labTest) {
            foreach ($labTest->test->consumptions as $consumption) {
                $productId = $consumption->product_id;
                $productName = $consumption->product->name;

                $timings = $consumption->getTimingsArray();
                if (empty($timings)) {
                    $timings = [''];
                }

                $hasTiming = false;
                foreach ($timings as $t) {
                    if (! empty(trim($t))) {
                        $hasTiming = true;
                        break;
                    }
                }

                if (! $hasTiming) {
                    $emptyTests[] = [
                        'labTest' => $labTest,
                        'consumption' => $consumption,
                        'productId' => $productId,
                        'productName' => $productName,
                    ];

                    continue;
                }

                foreach ($timings as $timing) {
                    if (empty(trim($timing))) {
                        continue;
                    }

                    $normalized = strtoupper(trim($timing));
                    $key = $productId.'_'.$normalized;

                    if (! isset($timedGroups[$key])) {
                        $timedGroups[$key] = [
                            'product_id' => $productId,
                            'product_name' => $productName,
                            'timing' => $timing,
                            'timing_normalized' => $normalized,
                            'tests' => [],
                            'has_label' => $consumption->is_label ?? true,
                            'has_box' => $consumption->is_box ?? false,
                        ];
                    }

                    $timedGroups[$key]['tests'][] = [
                        'lab_test_id' => $labTest->id,
                        'test_name' => $labTest->test->name,
                        'display_name' => $labTest->test->short_name ?? $labTest->test->name,
                        'timing' => $timing,
                        'machine_id' => $labTest->machine_id ?? null,
                    ];
                }
            }
        }

        $emptyGroups = [];

        foreach ($emptyTests as $item) {
            $labTest = $item['labTest'];
            $consumption = $item['consumption'];
            $productId = $item['productId'];
            $productName = $item['productName'];

            $bestKey = null;
            $bestPriority = -1;
            foreach ($timedGroups as $key => $group) {
                if ($group['product_id'] != $productId) {
                    continue;
                }

                $norm = $group['timing_normalized'] ?? '';
                $priority = $timingPriority[$norm] ?? 0;

                if ($priority > $bestPriority) {
                    $bestPriority = $priority;
                    $bestKey = $key;
                }
            }

            if ($bestKey !== null) {
                $timedGroups[$bestKey]['tests'][] = [
                    'lab_test_id' => $labTest->id,
                    'test_name' => $labTest->test->name,
                    'display_name' => $labTest->test->short_name ?? $labTest->test->name,
                    'timing' => null,
                    'machine_id' => $labTest->machine_id ?? null,
                    'is_merged_no_timing' => true,
                ];
            } else {
                if (! isset($emptyGroups[$productId])) {
                    $emptyGroups[$productId] = [
                        'product_id' => $productId,
                        'product_name' => $productName,
                        'timing' => null,
                        'timing_normalized' => null,
                        'tests' => [],
                        'has_label' => $consumption->is_label ?? true,
                        'has_box' => $consumption->is_box ?? false,
                    ];
                }

                $emptyGroups[$productId]['tests'][] = [
                    'lab_test_id' => $labTest->id,
                    'test_name' => $labTest->test->name,
                    'display_name' => $labTest->test->short_name ?? $labTest->test->name,
                    'timing' => null,
                    'machine_id' => $labTest->machine_id ?? null,
                ];
            }
        }

        $finalGroups = $timedGroups;

        foreach ($emptyGroups as $productId => $group) {
            $finalGroups[$productId.'_default'] = $group;
        }

        return $finalGroups;
    }

    private function autoCreateTestSamplesOld(
        LabReceipt $labReceipt,
        array $expandedTests = [],
        $existingSamples = null
    ): void {
        if (empty($expandedTests)) {
            $expandedTests = $this->prepareExpandedTests($labReceipt->id);
        }

        if (empty($expandedTests)) {
            return;
        }

        if ($existingSamples === null) {
            $existingSamples = TestSample::where('lab_receipt_id', $labReceipt->id)->get();
        }

        $samplesCollection = $existingSamples instanceof Collection
            ? $existingSamples
            : collect($existingSamples);

        $existingSamplesKeyed = $samplesCollection->keyBy(
            fn ($s) => $s->product_id.'_'.($s->timing ?? '')
        );

        $existingBarcodes = $samplesCollection->pluck('barcode')->toArray();

        $newTubes = [];
        $batchBarcodes = [];
        foreach ($expandedTests as $tube) {
            $key = ($tube['product_id'] ?? 0).'_'.($tube['timing'] ?? '');
            $barcode = $tube['barcode'] ?? null;

            if (isset($existingSamplesKeyed[$key])) {
                continue;
            }
            if ($barcode && in_array($barcode, $existingBarcodes, true)) {
                continue;
            }
            if ($barcode && in_array($barcode, $batchBarcodes, true)) {
                continue;
            }

            $batchBarcodes[] = $barcode;
            $newTubes[] = $tube;
        }

        if (empty($newTubes)) {
            return;
        }

        $allLabTestIds = [];
        foreach ($newTubes as $tube) {
            $allLabTestIds = array_merge($allLabTestIds, $tube['lab_test_ids']);
        }
        $allLabTestIds = array_values(array_unique($allLabTestIds));

        $labTestsKeyed = empty($allLabTestIds)
            ? collect()
            : LabTest::whereIn('id', $allLabTestIds)->get()->keyBy('id');

        DB::transaction(function () use ($labReceipt, $newTubes, $labTestsKeyed) {
            foreach ($newTubes as $tube) {
                $barcode = $tube['barcode'];

                $testSample = TestSample::create([
                    'barcode' => $barcode,
                    'lab_receipt_id' => $labReceipt->id,
                    'patient_id' => $labReceipt->patient_id,
                    'tube_type' => $tube['tube_type'] ?? 'Standard',
                    'product_id' => $tube['product_id'] ?? null,
                    'timing' => $tube['timing'] ?? null,
                    'barcode_source' => 'system',
                    'tenant_id' => $labReceipt->tenant_id,
                    'collected_by' => null,
                    'collected_at' => null,
                    'note' => null,
                ]);

                $rows = [];
                foreach ($tube['lab_test_ids'] as $labTestId) {
                    $labTest = $labTestsKeyed[$labTestId] ?? null;
                    if (! $labTest) {
                        continue;
                    }

                    $rows[] = [
                        'test_sample_id' => $testSample->id,
                        'lab_test_id' => $labTestId,
                        'test_id' => $labTest->test_id,
                        'collected_by' => null,
                        'collected_at' => null,
                        'status' => 'pending',
                        'tenant_id' => $labReceipt->tenant_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if (! empty($rows)) {
                    TestSampleItem::insert($rows);
                }

                Log::info('Created new tube', [
                    'receipt_id' => $labReceipt->id,
                    'barcode' => $barcode,
                    'timing' => $tube['timing'],
                    'tube_type' => $tube['tube_type'],
                ]);
            }
        });
    }

    private function autoCreateTestSamples(
        LabReceipt $labReceipt,
        array $expandedTests = [],
        $existingSamples = null
    ): void {
        if (empty($expandedTests)) {
            $expandedTests = $this->prepareExpandedTests($labReceipt->id);
        }

        if (empty($expandedTests)) {
            return;
        }

        if ($existingSamples === null) {
            $existingSamples = TestSample::where('lab_receipt_id', $labReceipt->id)->get();
        }

        $samplesCollection = $existingSamples instanceof Collection
            ? $existingSamples
            : collect($existingSamples);

        $existingSamplesKeyed = $samplesCollection->keyBy(
            fn ($s) => $s->product_id.'_'.($s->timing ?? '')
        );

        // Dedup keys: (product_id, timing) — not barcode strings
        $existingKeys = $samplesCollection
            ->map(fn ($s) => $s->product_id.'_'.($s->timing ?? ''))
            ->values()
            ->all();

        $newTubes = [];
        $batchKeys = [];

        foreach ($expandedTests as $tube) {
            $key = ($tube['product_id'] ?? 0).'_'.($tube['timing'] ?? '');

            if (isset($existingSamplesKeyed[$key])) {
                continue; // already exists in DB for this product+timing
            }
            if (in_array($key, $batchKeys, true)) {
                continue; // duplicate within batch
            }

            $batchKeys[] = $key;
            $newTubes[] = $tube;
        }

        if (empty($newTubes)) {
            return;
        }

        $allLabTestIds = [];
        foreach ($newTubes as $tube) {
            $allLabTestIds = array_merge($allLabTestIds, $tube['lab_test_ids']);
        }
        $allLabTestIds = array_values(array_unique($allLabTestIds));

        $labTestsKeyed = empty($allLabTestIds)
            ? collect()
            : LabTest::whereIn('id', $allLabTestIds)->get()->keyBy('id');

        DB::transaction(function () use ($labReceipt, $newTubes, $labTestsKeyed) {
            foreach ($newTubes as $tube) {
                $barcode = $tube['barcode'];

                $testSample = TestSample::create([
                    'barcode' => $barcode,
                    'lab_receipt_id' => $labReceipt->id,
                    'patient_id' => $labReceipt->patient_id,
                    'tube_type' => $tube['tube_type'] ?? 'Standard',
                    'product_id' => $tube['product_id'] ?? null,
                    'timing' => $tube['timing'] ?? null,
                    'barcode_source' => 'system',
                    'tenant_id' => $labReceipt->tenant_id,
                    'collected_by' => null,
                    'collected_at' => null,
                    'note' => null,
                ]);

                $rows = [];
                foreach ($tube['lab_test_ids'] as $labTestId) {
                    $labTest = $labTestsKeyed[$labTestId] ?? null;
                    if (! $labTest) {
                        continue;
                    }

                    $rows[] = [
                        'test_sample_id' => $testSample->id,
                        'lab_test_id' => $labTestId,
                        'test_id' => $labTest->test_id,
                        'collected_by' => null,
                        'collected_at' => null,
                        'status' => 'pending',
                        'tenant_id' => $labReceipt->tenant_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if (! empty($rows)) {
                    TestSampleItem::insert($rows);
                }

                Log::info('Created new tube', [
                    'receipt_id' => $labReceipt->id,
                    'barcode' => $barcode,
                    'timing' => $tube['timing'],
                    'tube_type' => $tube['tube_type'],
                ]);
            }
        });
    }

    // ============================================
    // SAMPLE VIEW
    // ============================================

    public function sample($id = null)
    {
        isAccess('sample_collect');

        try {
            $data = null;
            $expandedTests = [];

            if ($id) {
                $data = LabReceipt::with([
                    'patient:id,name,phone,email,age,dob,gender,blood_group,religion,address',
                    'referral:id,name',
                    'labTest.test.config',
                    'labTest.test.consumptions.product',
                ])->findOrFail($id);

                Test::primeDefaultConfigCache(
                    $data->labTest->pluck('test.id')->filter()->unique()->values()->all(),
                    config('app.default_tenant_id')
                );

                $existingSamples = TestSample::where('lab_receipt_id', $data->id)
                    ->with(['collector', 'items'])
                    ->get();

                $expandedTests = $this->prepareExpandedTests($data->id, $data, $existingSamples);

                $this->autoCreateTestSamples($data, $expandedTests, $existingSamples);

                $anyNewTubes = collect($expandedTests)->contains(fn ($t) => ! ($t['is_existing'] ?? false));

                if ($anyNewTubes) {
                    $existingSamples = TestSample::where('lab_receipt_id', $data->id)
                        ->with(['collector', 'items'])
                        ->get();

                    $expandedTests = $this->prepareExpandedTests($data->id, $data, $existingSamples);
                }

                $sampleMap = $existingSamples->keyBy(fn ($s) => $s->product_id.'_'.($s->timing ?? ''));

                foreach ($expandedTests as &$tube) {
                    $key = ($tube['product_id'] ?? 0).'_'.($tube['timing'] ?? '');
                    $testSample = $sampleMap[$key] ?? null;

                    if ($testSample) {
                        $tube['barcode'] = $testSample->barcode;
                        $tube['barcode_source'] = $testSample->barcode_source ?? 'system';
                        $tube['shared_from_tenant_barcode'] = $testSample->shared_from_tenant_barcode;
                        $tube['tube_sequence'] = $testSample->tube_sequence ?? ($tube['tube_sequence'] ?? 1);
                        $tube['test_sample_id'] = $testSample->id;
                        $tube['is_existing'] = true;

                        if ($testSample->collected_by) {
                            $tube['collected_by'] = $testSample->collected_by;
                            $tube['collected_at'] = $testSample->collected_at;
                            $tube['collector_name'] = $testSample->collector?->name ?? null;
                            $tube['is_collected'] = true;
                        }

                        $tube['items_collected'] = $testSample->items->whereNotNull('collected_by')->count();
                        $tube['total_items'] = $testSample->items->count();
                    }
                }
                unset($tube);
            }

            return view('lab.sample.preview')->with([
                'data' => $data ?? [],
                'expandedTests' => $expandedTests,
                'machines' => Machine::where('is_active', true)->get(),
            ]);

        } catch (\Throwable $e) {
            Log::error('Sample view error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'id' => $id,
            ]);

            return redirect()->back()->with([
                'header' => 'Receipt Not Found',
                'message' => 'Invalid Receipt, Please try correct one!',
                'alert-type' => 'warning',
            ]);
        }
    }

    // ============================================
    // SAMPLE COLLECTION
    // ============================================

    public function sampleCollect(Request $request)
    {
        isAccess('sample_collect');

        $validator = Validator::make($request->all(), [
            'samples' => ['required', 'array'],
            'samples.*.check' => ['required', 'numeric'],
            'samples.*.barcode' => ['required', 'string'],
            'samples.*.tube_type' => ['required', 'string'],
            'samples.*.lab_test_ids' => ['required', 'string'],
            'samples.*.product_id' => ['nullable', 'string'],
            'samples.*.sample_type' => ['nullable', 'string'],
            'samples.*.timing' => ['nullable', 'string'],
            'samples.*.tube_sequence' => ['nullable', 'numeric'],
            'samples.*.barcode_source' => ['nullable', 'in:system,custom,shared'],
            'samples.*.shared_from_tenant_barcode' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'type' => 'invalid',
                'note' => $validator->messages(),
                'status' => 400,
            ], 200);
        }

        $data = $validator->validated();
        $tenantId = tenant()->getTenantKey();
        $collectedAt = Carbon::now();
        $userId = auth()->id();

        $processed = [];

        try {
            DB::transaction(function () use ($data, $tenantId, &$processed, $collectedAt, $userId) {
                foreach ($data['samples'] as $sample) {
                    if (($sample['check'] ?? 0) != 1) {
                        continue;
                    }

                    $timing = ($sample['timing'] ?? '') === '' ? null : $sample['timing'];
                    $productId = ($sample['product_id'] ?? '') === '' ? null : $sample['product_id'];

                    $labTestIds = json_decode($sample['lab_test_ids'], true);

                    if (! is_array($labTestIds)) {
                        $labTestIds = array_filter(array_map(
                            'trim',
                            explode(',', (string) $sample['lab_test_ids'])
                        ));
                    }

                    $labTestIds = array_values(array_unique(
                        array_filter(array_map('strval', $labTestIds))
                    ));

                    if (empty($labTestIds)) {
                        continue;
                    }

                    $firstLabTest = LabTest::with('receipt')->find($labTestIds[0]);
                    if (! $firstLabTest) {
                        continue;
                    }

                    $receiptId = $firstLabTest->lab_id;

                    $testSample = TestSample::updateOrCreate(
                        [
                            'barcode' => $sample['barcode'],
                            'product_id' => $productId,
                            'timing' => $timing,
                            'lab_receipt_id' => $receiptId,
                        ],
                        [
                            'tenant_id' => $tenantId,
                            'tube_type' => $sample['tube_type'],
                            'patient_id' => $firstLabTest->receipt->patient_id,
                            'tube_sequence' => $sample['tube_sequence'] ?? 1,
                            'collected_by' => $userId,
                            'collected_at' => $collectedAt,
                            'barcode_source' => $sample['barcode_source'] ?? 'system',
                            'shared_from_tenant_barcode' => $sample['shared_from_tenant_barcode'] ?? null,
                        ]
                    );

                    foreach ($labTestIds as $labTestId) {
                        $labTest = LabTest::with('test')->find($labTestId);
                        if (! $labTest) {
                            continue;
                        }

                        $itemPayload = [
                            'test_id' => $labTest->test_id,
                            'collected_by' => $userId,
                            'collected_at' => $collectedAt,
                            'status' => 'collected',
                        ];

                        $existingItem = TestSampleItem::withoutGlobalScopes()
                            ->where('test_sample_id', $testSample->id)
                            ->where('lab_test_id', $labTestId)
                            ->first();

                        if ($existingItem) {
                            $existingItem->fill($itemPayload)->save();
                        } else {
                            try {
                                TestSampleItem::create(array_merge($itemPayload, [
                                    'test_sample_id' => $testSample->id,
                                    'lab_test_id' => $labTestId,
                                    'tenant_id' => $tenantId,
                                ]));
                            } catch (QueryException $e) {
                                if ((string) $e->getCode() !== '23000') {
                                    throw $e;
                                }

                                TestSampleItem::withoutGlobalScopes()
                                    ->where('test_sample_id', $testSample->id)
                                    ->where('lab_test_id', $labTestId)
                                    ->update($itemPayload);
                            }
                        }

                        LabTest::where('id', $labTestId)->update([
                            'collected_by' => $userId,
                            'collected_at' => $collectedAt,
                        ]);
                    }

                    if (! in_array($receiptId, $processed, true)) {
                        $processed[] = $receiptId;
                    }
                }
            });

            foreach ($processed as $labReceiptId) {
                try {
                    $this->worklistService->createWorklistForGroup($labReceiptId);
                } catch (\Exception $e) {
                    Log::warning('Worklist creation failed', [
                        'receipt_id' => $labReceiptId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            session()->flash('success', __('Test Sample collected successfully'));

            return redirect()->back();

        } catch (\Throwable $e) {
            Log::error('Sample collection failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            session()->flash('error', __('Test Sample Failed: '.$e->getMessage()));

            return redirect()->back();
        }
    }

    // ============================================
    // WORKLIST GENERATION
    // ============================================

    public function generateWorklist($id)
    {
        $labReceipt = LabReceipt::with(['labTest' => function ($q) {
            $q->whereNotNull('collected_by');
        }, 'labTest.test'])->findOrFail($id);

        if ($labReceipt->labTest->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No collected tests found for this receipt',
            ], 400);
        }

        try {
            $worklists = $this->worklistService->createWorklistForGroup($id);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create worklist: '.$e->getMessage(),
            ], 500);
        }

        if (empty($worklists)) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create worklist. Check machine configurations.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => count($worklists).' worklist(s) created successfully',
            'count' => count($worklists),
        ]);
    }

    public function bulkGenerateWorklist(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'receipt_ids' => 'required|array',
            'receipt_ids.*' => 'exists:lab_receipts,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()], 400);
        }

        try {
            $successCount = 0;
            $failedCount = 0;
            $totalWorklists = 0;

            foreach ($request->receipt_ids as $receiptId) {
                $labReceipt = LabReceipt::with(['labTest' => function ($q) {
                    $q->whereNotNull('collected_by');
                }])->find($receiptId);

                if ($labReceipt && $labReceipt->labTest->isNotEmpty()) {
                    $worklists = $this->worklistService->createWorklistForGroup($receiptId);
                    if (! empty($worklists)) {
                        $successCount++;
                        $totalWorklists += count($worklists);
                    } else {
                        $failedCount++;
                    }
                } else {
                    $failedCount++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Worklist generated for {$successCount} receipt(s), {$totalWorklists} total tests",
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'total_worklists' => $totalWorklists,
            ]);

        } catch (\Exception $e) {
            Log::error('Bulk worklist generation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate worklists: '.$e->getMessage(),
            ], 500);
        }
    }

    // ============================================
    // PRINT METHODS
    // ============================================

    public function printSingleTube(Request $request, $rid)
    {
        try {
            $data = $request->isJson()
                ? $request->json()->all()
                : $request->all();

            if (isset($data['lab_test_ids']) && is_string($data['lab_test_ids'])) {
                $decoded = json_decode($data['lab_test_ids'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $data['lab_test_ids'] = $decoded;
                } else {
                    $data['lab_test_ids'] = array_filter(
                        array_map('trim', explode(',', $data['lab_test_ids']))
                    );
                }
            }

            $validator = Validator::make($data, [
                'product_id' => 'required|string',
                'lab_test_ids' => 'required|array',
                'lab_test_ids.*' => 'required|string',
                'timing' => 'nullable|string',
                'tube_sequence' => 'nullable|integer',
                'custom_barcode' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => $validator->errors()->first(),
                ], 400);
            }

            $labReceipt = LabReceipt::with(['patient'])->find($rid);
            if (! $labReceipt) {
                return response()->json([
                    'success' => false,
                    'error' => 'Receipt not found',
                ], 404);
            }

            if (! empty($data['custom_barcode'])) {
                $barcode = $data['custom_barcode'];
            } else {
                $timing = $data['timing'] ?? null;

                $existingSample = TestSample::where('lab_receipt_id', $rid)
                    ->where('product_id', $data['product_id'])
                    ->where(function ($q) use ($timing) {
                        if (empty($timing)) {
                            $q->whereNull('timing');
                        } else {
                            $q->where('timing', $timing);
                        }
                    })
                    ->orderByDesc('created_at')
                    ->first();

                if ($existingSample) {
                    $barcode = $existingSample->barcode;
                } else {
                    $barcode = $this->sampleBarcodeService->generateCounterSample(
                        $labReceipt->barcode,
                        $timing ?: null,
                        1
                    );
                }
            }

            $product = Product::find($data['product_id']);
            if (! $product) {
                return response()->json([
                    'success' => false,
                    'error' => 'Product not found',
                ], 404);
            }

            $labTests = LabTest::with('test')
                ->whereIn('id', $data['lab_test_ids'])
                ->get();

            if ($labTests->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'error' => 'No tests found',
                ], 404);
            }

            // ⭐ Prime default-config cache for these tests
            Test::primeDefaultConfigCache(
                $labTests->pluck('test_id')->filter()->unique()->values()->all(),
                config('app.default_tenant_id')
            );

            $testNames = [];
            foreach ($labTests as $labTest) {
                if ($labTest->test) {
                    $name = $labTest->test->short_name ?? $labTest->test->name;

                    if (! empty($data['timing'])) {
                        $timingLabel = $this->worklistService->getTimingLabel($data['timing']);
                        if ($timingLabel) {
                            $name .= ' ('.$timingLabel.')';
                        }
                    }

                    $testNames[] = $name;
                }
            }

            $zpl = $this->zplService->generateTubeZpl(
                $barcode,
                $labReceipt->patient,
                $testNames,
                $product->name,
                $labReceipt->tenant_id
            )."\r\n";

            return response($zpl, 200)
                ->header('Content-Type', 'text/plain')
                ->header('Content-Disposition', 'inline; filename="tube_'.$barcode.'.zpl"');

        } catch (\Exception $e) {
            Log::error('Print single tube failed', [
                'receipt_id' => $rid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate print: '.$e->getMessage(),
            ], 500);
        }
    }

    public function printAllTubes($rid)
    {
        isAccess('sample_collect');

        try {
            $labReceipt = LabReceipt::with([
                'patient',
                'labTest.test.config',
                'labTest.test.consumptions.product',
            ])->findOrFail($rid);

            // ⭐ Prime default-config cache
            Test::primeDefaultConfigCache(
                $labReceipt->labTest->pluck('test.id')->filter()->unique()->values()->all(),
                config('app.default_tenant_id')
            );

            $existingSamples = TestSample::where('lab_receipt_id', $rid)
                ->with('items')
                ->get();

            $expandedTests = $this->prepareExpandedTests($rid, $labReceipt, $existingSamples);

            if (empty($expandedTests)) {
                return response()->json([
                    'success' => false,
                    'error' => 'No tubes found for this receipt',
                ], 404);
            }

            $zpl = '';
            $processed = [];

            foreach ($expandedTests as $tube) {
                if (! ($tube['has_label'] ?? true)) {
                    continue;
                }

                $key = $tube['barcode'].'_'.$tube['product_id'].'_'.($tube['timing'] ?? '');
                if (in_array($key, $processed, true)) {
                    continue;
                }

                $testNames = array_map(
                    fn ($t) => $t['display_name']
                        .($tube['timing'] ? ' ('.$this->worklistService->getTimingLabel($tube['timing']).')' : ''),
                    $tube['tests']
                );

                $zpl .= $this->zplService->generateTubeZpl(
                    $tube['barcode'],
                    $labReceipt->patient,
                    $testNames,
                    $tube['product_name'],
                    $labReceipt->tenant_id
                )."\r\n";

                $processed[] = $key;
            }

            if (empty(trim($zpl))) {
                return response()->json([
                    'success' => false,
                    'error' => 'No ZPL generated - no labels to print',
                ], 404);
            }

            return response($zpl, 200)
                ->header('Content-Type', 'text/plain')
                ->header('Content-Disposition', 'inline; filename="tubes_'.$rid.'.zpl"');

        } catch (\Throwable $e) {
            Log::error('Print all tubes failed', [
                'error' => $e->getMessage(),
                'rid' => $rid,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate print: '.$e->getMessage(),
            ], 500);
        }
    }

    public function printBoxCover(Request $request, $rid)
    {
        try {
            $validator = Validator::make($request->all(), [
                'product_id' => 'required|string',
                'lab_test_ids' => 'required|array',
                'suffix' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 400);
            }

            $labReceipt = LabReceipt::with(['patient', 'referral'])->findOrFail($rid);
            $product = Product::find($request->product_id);

            if (! $product) {
                return response()->json(['error' => 'Product not found'], 404);
            }

            $labTests = LabTest::with('test')->whereIn('id', $request->lab_test_ids)->get();

            if ($labTests->isEmpty()) {
                return response()->json(['error' => 'No tests found'], 404);
            }

            // ⭐ Prime default-config cache
            Test::primeDefaultConfigCache(
                $labTests->pluck('test_id')->filter()->unique()->values()->all(),
                config('app.default_tenant_id')
            );

            $testNames = [];
            foreach ($labTests as $labTest) {
                if ($labTest->test) {
                    $name = $labTest->test->short_name ?? $labTest->test->name;
                    if ($request->suffix) {
                        $name .= ' ('.$this->worklistService->getTimingLabel($request->suffix).')';
                    }
                    $testNames[] = $name;
                }
            }

            $zpl = $this->zplService->generateBoxZpl(
                $labReceipt,
                $labReceipt->patient,
                implode(', ', array_unique($testNames)),
                $product->name,
                $labReceipt->tenant_id
            );

            return response($zpl, 200)
                ->header('Content-Type', 'text/plain');

        } catch (\Throwable $e) {
            Log::error('Print box cover failed', [
                'error' => $e->getMessage(),
                'rid' => $rid,
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function printAllBoxCovers($rid)
    {
        try {
            $labReceipt = LabReceipt::with([
                'patient',
                'referral',
                'labTest.test.config',
                'labTest.test.consumptions.product',
            ])->findOrFail($rid);

            // ⭐ Prime default-config cache
            Test::primeDefaultConfigCache(
                $labReceipt->labTest->pluck('test.id')->filter()->unique()->values()->all(),
                config('app.default_tenant_id')
            );

            $existingSamples = TestSample::where('lab_receipt_id', $rid)
                ->with('items')
                ->get();

            $expandedTests = $this->prepareExpandedTests($rid, $labReceipt, $existingSamples);

            $zpl = '';
            $processed = [];

            foreach ($expandedTests as $tube) {
                if (! $tube['has_box']) {
                    continue;
                }

                $key = $tube['product_id'].'_'.$tube['timing'];
                if (in_array($key, $processed)) {
                    continue;
                }

                $testNames = array_map(
                    fn ($t) => $t['display_name'].($tube['timing'] ? ' ('.$this->worklistService->getTimingLabel($tube['timing']).')' : ''),
                    $tube['tests']
                );

                $zpl .= $this->zplService->generateBoxZpl(
                    $labReceipt,
                    $labReceipt->patient,
                    implode(', ', array_unique($testNames)),
                    $tube['product_name'],
                    $labReceipt->tenant_id
                )."\r\n";

                $processed[] = $key;
            }

            return response($zpl, 200, ['Content-Type' => 'text/plain']);

        } catch (\Throwable $e) {
            Log::error('Print all box covers failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ============================================
    // RECHECK
    // ============================================

    public function recheckTest(Request $request, $labTestId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'reason' => 'required|string',
                'use_existing_sample' => 'nullable|boolean',
                'machine_id' => 'nullable|exists:machines,id',
                'custom_barcode' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 400);
            }

            DB::beginTransaction();

            try {
                $oldLabTest = LabTest::with([
                    'test',
                    'test.config',
                    'test.consumptions',
                    'receipt',
                    'sampleItems.testSample',
                ])->findOrFail($labTestId);

                $useExistingSample = $request->boolean('use_existing_sample', false);

                $newLabTest = $oldLabTest->replicate();
                $newLabTest->is_retest = true;
                $newLabTest->retest_of = $oldLabTest->id;
                $newLabTest->retest_reason = $request->reason;
                $newLabTest->collected_by = null;
                $newLabTest->collected_at = null;
                $newLabTest->status = 'pending';
                $newLabTest->lab_status = 'ordered';

                if ($request->filled('machine_id')) {
                    $newLabTest->machine_id = $request->machine_id;
                    $newLabTest->machine_assigned_at = now();
                    $newLabTest->machine_status = 'assigned';
                }

                $newLabTest->save();

                $testSample = null;
                $barcode = null;

                if ($useExistingSample && $oldLabTest->sampleItems->isNotEmpty()) {
                    $existingItem = $oldLabTest->sampleItems->first();
                    $testSample = $existingItem->testSample;
                    $barcode = $testSample->barcode;

                    TestSampleItem::create([
                        'test_sample_id' => $testSample->id,
                        'lab_test_id' => $newLabTest->id,
                        'test_id' => $newLabTest->test_id,
                        'collected_by' => null,
                        'collected_at' => null,
                        'status' => 'pending',
                        'notes' => "Recheck using existing sample. Original test: {$oldLabTest->id}. Reason: {$request->reason}",
                    ]);
                } else {
                    $timing = null;
                    $productId = null;

                    if ($oldLabTest->test && $oldLabTest->test->consumptions->isNotEmpty()) {
                        $firstConsumption = $oldLabTest->test->consumptions->first();
                        $timing = $firstConsumption->timing ?? null;
                        $productId = $firstConsumption->product_id ?? null;
                    }

                    $baseBarcode = $oldLabTest->receipt->barcode;
                    $cleanBarcode = preg_replace('/-[A-Za-z0-9]+$/', '', $baseBarcode);

                    if ($request->filled('custom_barcode')) {
                        $barcode = $request->custom_barcode;
                    } else {
                        if (! empty($timing)) {
                            $timingSuffix = $this->sampleBarcodeService->normalizeTiming($timing);

                            // Scope occurrence count by product_id so different products
                            // never interfere with each other's recheck counters
                            $occurrence = TestSample::where('lab_receipt_id', $oldLabTest->lab_id)
                                ->when($productId, fn ($q) => $q->where('product_id', $productId))
                                ->where(function ($q) use ($cleanBarcode, $timingSuffix) {
                                    $q->where('barcode', $cleanBarcode.'-'.$timingSuffix)
                                        ->orWhere('barcode', 'REGEXP',
                                            '^'.preg_quote($cleanBarcode, '/').'-'.preg_quote($timingSuffix, '/').'[0-9]+$');
                                })
                                ->count() + 1;
                        } else {
                            // Scope occurrence count by product_id
                            $occurrence = TestSample::where('lab_receipt_id', $oldLabTest->lab_id)
                                ->when($productId, fn ($q) => $q->where('product_id', $productId))
                                ->where(function ($q) use ($cleanBarcode) {
                                    $q->where('barcode', $cleanBarcode.'-C')
                                        ->orWhere('barcode', 'REGEXP',
                                            '^'.preg_quote($cleanBarcode, '/').'-C[0-9]+$');
                                })
                                ->count() + 2;
                        }

                        $barcode = $this->sampleBarcodeService->generateCounterSample(
                            $cleanBarcode,
                            $timing ?: null,
                            $occurrence
                        );
                    }

                    $tubeType = 'Recheck Tube';
                    $sampleType = 'Serum';

                    if ($oldLabTest->test && $oldLabTest->test->consumptions->isNotEmpty()) {
                        $consumption = $oldLabTest->test->consumptions->first();
                        if ($consumption->product) {
                            $tubeType = $consumption->product->name;
                        }
                        $sampleType = $oldLabTest->test->sample_type ?? 'Serum';
                    }

                    $testSample = TestSample::create([
                        'barcode' => $barcode,
                        'lab_receipt_id' => $oldLabTest->lab_id,
                        'patient_id' => $oldLabTest->receipt->patient_id,
                        'tube_type' => $tubeType,
                        'product_id' => $productId,
                        'timing' => $timing,
                        'barcode_source' => $request->filled('custom_barcode') ? 'custom' : 'system',
                        'tenant_id' => $oldLabTest->receipt->tenant_id,
                        'collected_by' => null,
                        'collected_at' => null,
                        'note' => "Recheck test. Original test ID: {$oldLabTest->id}. Reason: {$request->reason}",
                    ]);

                    TestSampleItem::create([
                        'test_sample_id' => $testSample->id,
                        'lab_test_id' => $newLabTest->id,
                        'test_id' => $newLabTest->test_id,
                        'collected_by' => null,
                        'collected_at' => null,
                        'status' => 'pending',
                        'notes' => "New sample for recheck. Original test: {$oldLabTest->id}. Reason: {$request->reason}",
                    ]);
                }

                $worklistResult = $this->worklistService->createRecheckWorklist(
                    $newLabTest->id,
                    $request->reason,
                    $barcode,
                    $request->machine_id ?? null
                );

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => $useExistingSample
                        ? 'Recheck scheduled using existing sample'
                        : 'New sample created for recheck',
                    'data' => [
                        'new_lab_test_id' => $newLabTest->id,
                        'barcode' => $barcode,
                        'use_existing_sample' => $useExistingSample,
                        'sample_id' => $testSample?->id,
                        'worklist_created' => $worklistResult['success'] ?? false,
                    ],
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Throwable $e) {
            Log::error('Recheck test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'lab_test_id' => $labTestId,
            ]);

            return response()->json([
                'error' => 'Failed to create recheck: '.$e->getMessage(),
            ], 500);
        }
    }
}
