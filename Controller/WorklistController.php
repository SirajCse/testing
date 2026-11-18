<?php

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Models\LabReceipt;
use App\Models\Machine;
use App\Models\Worklist;
use App\Services\WorklistService;
use DataTables;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class WorklistController extends Controller
{
    protected $worklistService;

    public function __construct(WorklistService $worklistService)
    {
        $this->worklistService = $worklistService;
    }

    // ============================================
    // WEB METHODS - VIEWS
    // ============================================

    /**
     * Display worklists index page
     * GET /lab/worklists
     */
    public function index(Request $request)
    {
        $machineId = $request->get('machine_id');
        $status = $request->get('status', 'pending');
        $machines = Machine::orderBy('priority', 'desc')->pluck('display_name', 'id');

        return view('lab.worklists.index', compact('machines', 'machineId', 'status'));
    }

    /**
     * Show single worklist details
     * GET /lab/worklists/{id}
     */
    public function show($id)
    {
        $worklist = Worklist::with([
            'machine',
            'patient',
            'labReceipt',
            'labReceipt.labTest',
            'testSample',
            'testSampleItem',
        ])->findOrFail($id);

        return view('lab.worklists.show', compact('worklist'));
    }

    /**
     * Get worklists by lab receipt
     * GET /lab/worklists/receipt/{receiptId}
     */
    public function getWorklistsByReceipt($receiptId)
    {
        $labReceipt = LabReceipt::with(['patient', 'labTest.test'])->findOrFail($receiptId);

        $worklists = Worklist::with(['machine'])
            ->where('lab_receipt_id', $receiptId)
            ->orderBy('created_at', 'desc')
            ->get();

        return view('lab.worklists.receipt_worklists', compact('worklists', 'labReceipt'));
    }

    /**
     * Get worklists by barcode for a receipt
     * GET /lab/worklists/receipt/{receiptId}/by-barcode
     */
    public function getWorklistsByBarcode($receiptId)
    {
        $labReceipt = LabReceipt::findOrFail($receiptId);

        $worklists = Worklist::where('lab_receipt_id', $receiptId)
            ->with(['machine'])
            ->orderBy('barcode')
            ->get()
            ->groupBy('barcode');

        return view('lab.worklists.barcode_group', compact('worklists', 'labReceipt'));
    }

    // ============================================
    // DATATABLES METHODS
    // ============================================

    /**
     * Get worklists data for DataTables
     * GET /lab/worklists/data/{id?}
     */
    public function getWorklistsData(Request $request)
    {
        $model = Worklist::with([
            'machine:id,display_name,device_model',
            'labReceipt:id,barcode,sl_no,patient_id',
            'labReceipt.patient:id,name,phone,reg_no',
            'testSample:id,barcode,tube_type,sample,collected_at',
        ])->withCount([
            'samples as sample_count',
            'samples as collected_count' => function ($q) {
                $q->whereNotNull('collected_at');
            },
        ]);

        // Machine filter
        if ($request->has('machine_id') && $request->machine_id) {
            $model->where('machine_id', $request->machine_id);
        }

        // Status filter
        if ($request->has('status') && $request->status && $request->status !== 'all') {
            $model->where('status', $request->status);
        }

        // Priority filter
        if ($request->has('priority') && $request->priority) {
            $priorityMap = [
                'high' => 'high',
                'routine' => 'routine',
                'low' => 'low',
            ];
            $priority = $priorityMap[$request->priority] ?? $request->priority;
            $model->where('priority', $priority);
        }

        // Search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $model->where(function ($q) use ($search) {
                $q->where('worklists.barcode', 'like', "%{$search}%")
                    // REMOVED: orWhere('worklists.order_id', ...) — column dropped
                    ->orWhere('worklists.test_name', 'like', "%{$search}%")
                    ->orWhere('worklists.test_code', 'like', "%{$search}%")
                    ->orWhere('worklists.receipt_barcode', 'like', "%{$search}%")
                    ->orWhere('worklists.patient_name', 'like', "%{$search}%")
                    ->orWhereHas('labReceipt', function ($rq) use ($search) {
                        $rq->where('barcode', 'like', "%{$search}%")
                            ->orWhere('sl_no', 'like', "%{$search}%");
                    })
                    ->orWhereHas('labReceipt.patient', function ($pq) use ($search) {
                        $pq->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('reg_no', 'like', "%{$search}%");
                    });
            });
        }

        // Date filters - ONLY if no search is active
        $hasSearch = ($request->has('search') && ! empty($request->search));

        if (! $hasSearch) {
            // Period filter
            if ($request->has('period') && $request->period && $request->period !== 'all') {
                switch ($request->period) {
                    case 'today':
                        $model->whereDate('created_at', today());
                        break;
                    case 'yesterday':
                        $model->whereDate('created_at', today()->subDay());
                        break;
                    case '7days':
                        $model->whereDate('created_at', '>=', today()->subDays(7));
                        break;
                    case '30days':
                        $model->whereDate('created_at', '>=', today()->subDays(30));
                        break;
                }
            }

            // Date range filters
            if ($request->has('start_date') && $request->start_date) {
                $model->whereDate('created_at', '>=', $request->start_date);
            }
            if ($request->has('end_date') && $request->end_date) {
                $model->whereDate('created_at', '<=', $request->end_date);
            }
        }

        // Handle DataTable ordering
        if ($request->has('order')) {
            foreach ($request->order as $order) {
                $columnIndex = $order['column'];
                $columnName = $request->columns[$columnIndex]['data'] ?? '';
                $direction = $order['dir'];

                switch ($columnName) {
                    case 'barcode':
                        $model->orderBy('worklists.barcode', $direction);
                        break;
                    // REMOVED: case 'order_id' — worklists.order_id column is dropped
                    case 'created_at':
                        $model->orderBy('worklists.created_at', $direction);
                        break;
                    case 'patient_name':
                        $model->orderBy('worklists.patient_name', $direction);
                        break;
                    case 'status':
                        $model->orderBy('worklists.status', $direction);
                        break;
                    default:
                        break;
                }
            }
        } else {
            $model->orderBy('worklists.created_at', 'desc');
        }

        return DataTables::eloquent($model)
            ->addIndexColumn()
            ->addColumn('checkbox', function ($row) {
                if ($row->status === 'pending') {
                    return '<input type="checkbox" class="worklist-checkbox" value="'.$row->id.'">';
                }
                return '';
            })
            ->addColumn('patient_name', function ($row) {
                return $row->patient_name ?? $row->labReceipt?->patient?->name ?? 'N/A';
            })
            ->addColumn('patient_info', function ($row) {
                $name = $row->patient_name ?? $row->labReceipt?->patient?->name ?? 'N/A';
                $age = $row->patient_age ?? $row->labReceipt?->patient?->age ?? '';
                $gender = $row->patient_gender ?? $row->labReceipt?->patient?->gender ?? '';
                return '<div><strong>'.e($name).'</strong></div>
                        <small class="text-muted">'.e($age).'Y '.e($gender).'</small>';
            })
            ->addColumn('barcode_info', function ($row) {
                return '<div><code>'.e($row->barcode).'</code></div>
                        <small class="text-muted">Receipt: '.e($row->receipt_barcode ?? $row->labReceipt?->barcode ?? '').'</small>';
            })
            ->addColumn('machine_badge', function ($row) {
                return $row->machine?->display_name ?? $row->device_model ?? 'Unknown';
            })
            ->addColumn('priority_badge', function ($row) {
                $priority = $row->priority ?? 'routine';
                $class = $priority === 'high' ? 'priority-high' : ($priority === 'low' ? 'priority-low' : 'priority-regular');
                $text = ucfirst($priority);
                return '<span class="priority-badge '.$class.'">'.$text.'</span>';
            })
            ->addColumn('status_badge', function ($row) {
                switch ($row->status) {
                    case 'pending':
                        return '<span class="status-badge status-pending"><i class="fas fa-clock"></i> Pending</span>';
                    case 'processing':
                        return '<span class="status-badge status-processing"><i class="fas fa-spinner fa-spin"></i> Processing</span>';
                    case 'completed':
                        return '<span class="status-badge status-completed"><i class="fas fa-check-circle"></i> Completed</span>';
                    case 'cancelled':
                        return '<span class="status-badge status-cancelled"><i class="fas fa-times-circle"></i> Cancelled</span>';
                    case 'locked':
                        return '<span class="status-badge status-locked"><i class="fas fa-lock"></i> Locked</span>';
                    default:
                        return '<span class="status-badge">'.ucfirst($row->status).'</span>';
                }
            })
            ->addColumn('actions', function ($row) {
                return view('lab.worklists.action', compact('row'));
            })
            ->editColumn('test_name', function ($row) {
                return '<span title="'.e($row->test_name).'">'.\Str::limit($row->test_name, 35).'</span>';
            })
            ->editColumn('created_at', function ($row) {
                return '<span title="'.$row->created_at->format('Y-m-d H:i:s').'">'.$row->created_at->format('d-m-Y H:i').'</span>';
            })
            ->rawColumns(['checkbox', 'patient_info', 'barcode_info', 'machine_badge', 'priority_badge', 'status_badge', 'actions', 'test_name', 'created_at'])
            ->make(true);
    }

    /**
     * Get worklist statistics for dashboard
     * GET /lab/worklists/stats
     */
    public function getWorklistStats(Request $request)
    {
        $query = Worklist::with(['labReceipt.patient']);

        // Machine filter
        if ($request->has('machine_id') && $request->machine_id) {
            $query->where('machine_id', $request->machine_id);
        }

        // Status filter
        if ($request->has('status') && $request->status && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Priority filter
        if ($request->has('priority') && $request->priority) {
            $query->where('priority', $request->priority);
        }

        // Search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('barcode', 'like', "%{$search}%")
                    // REMOVED: orWhere('order_id', ...) — column dropped
                    ->orWhere('test_name', 'like', "%{$search}%")
                    ->orWhere('patient_name', 'like', "%{$search}%")
                    ->orWhereHas('labReceipt', function ($rq) use ($search) {
                        $rq->where('barcode', 'like', "%{$search}%")
                            ->orWhere('sl_no', 'like', "%{$search}%");
                    })
                    ->orWhereHas('labReceipt.patient', function ($pq) use ($search) {
                        $pq->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        // Period filter - ONLY if no search is active
        $hasSearch = ($request->has('search') && ! empty($request->search));

        if (! $hasSearch) {
            if ($request->has('period') && $request->period) {
                switch ($request->period) {
                    case 'today':
                        $query->whereDate('created_at', today());
                        break;
                    case 'yesterday':
                        $query->whereDate('created_at', today()->subDay());
                        break;
                    case '7days':
                        $query->whereDate('created_at', '>=', today()->subDays(7));
                        break;
                    case '30days':
                        $query->whereDate('created_at', '>=', today()->subDays(30));
                        break;
                    case 'all':
                        break;
                }
            }
        }

        // Date range filter
        if ($request->has('start_date') && $request->start_date) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        if ($request->has('end_date') && $request->end_date) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        return response()->json([
            'total' => (clone $query)->count(),
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'processing' => (clone $query)->where('status', 'processing')->count(),
            'completed' => (clone $query)->where('status', 'completed')->count(),
            'cancelled' => (clone $query)->where('status', 'cancelled')->count(),
            'locked' => (clone $query)->where('status', 'locked')->count(),
        ]);
    }

    /**
     * Get worklist summary (pending by machine)
     * GET /lab/worklists/summary
     */
    public function getWorklistSummary()
    {
        $summary = $this->worklistService->getWorklistSummary();

        return response()->json($summary);
    }

    /**
     * Get worklist statistics (simple)
     * GET /lab/worklists/stats/simple
     */
    public function stats()
    {
        $stats = Worklist::selectRaw("
            COUNT(*) as total,
            SUM(status = 'pending') as pending,
            SUM(status = 'processing') as processing,
            SUM(status = 'completed') as completed,
            SUM(status = 'cancelled') as cancelled,
            SUM(status = 'locked') as locked
        ")->first();

        return response()->json([
            'total' => (int) $stats->total,
            'pending' => (int) $stats->pending,
            'processing' => (int) $stats->processing,
            'completed' => (int) $stats->completed,
            'cancelled' => (int) $stats->cancelled,
            'locked' => (int) $stats->locked,
        ]);
    }

    // ============================================
    // BASIC ACTIONS
    // ============================================

    /**
     * Cancel a worklist
     * POST /lab/worklists/cancel/{id}
     */
    public function cancel($id)
    {
        try {
            $result = $this->worklistService->cancelWorklist($id);

            if (!$result['success']) {
                return response()->json(['success' => false, 'message' => $result['message']], 400);
            }

            if (request()->ajax()) {
                return response()->json(['success' => true, 'message' => 'Worklist cancelled successfully']);
            }

            return redirect()->back()->with('success', 'Worklist cancelled');

        } catch (\Exception $e) {
            Log::error('Worklist cancellation failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to cancel'], 500);
        }
    }

    /**
     * Retry a worklist (reset to pending)
     * POST /lab/worklists/retry/{id}
     */
    public function retry($id)
    {
        try {
            $result = $this->worklistService->retryWorklist($id);

            if (!$result['success']) {
                return response()->json(['success' => false, 'message' => $result['message']], 400);
            }

            if (request()->ajax()) {
                return response()->json(['success' => true, 'message' => 'Worklist reset to pending']);
            }

            return redirect()->back()->with('success', 'Worklist reset to pending');

        } catch (\Exception $e) {
            Log::error('Worklist retry failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to retry'], 500);
        }
    }

    /**
     * Update worklist
     * POST /lab/worklists/update/{id}
     */
    public function updateWorklist(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'machine_id' => 'nullable|exists:machines,id',
            'priority' => 'nullable|in:high,routine,low',
            'sample_type' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()], 400);
        }

        try {
            $worklist = Worklist::findOrFail($id);

            if ($worklist->status !== 'pending') {
                return response()->json(['success' => false, 'message' => 'Only pending worklists can be updated'], 400);
            }

            $worklist->update($validator->validated());

            return response()->json(['success' => true, 'message' => 'Worklist updated']);

        } catch (\Exception $e) {
            Log::error('Worklist update failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to update'], 500);
        }
    }

    /**
     * Delete a worklist (only pending)
     * DELETE /lab/worklists/delete/{id}
     */
    public function deleteWorklist($id)
    {
        try {
            $result = $this->worklistService->deleteWorklist($id);

            if (!$result['success']) {
                return response()->json(['success' => false, 'message' => $result['message']], 400);
            }

            return response()->json(['success' => true, 'message' => 'Worklist deleted']);

        } catch (\Exception $e) {
            Log::error('Worklist deletion failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to delete'], 500);
        }
    }

    /**
     * Get worklist by ID (API)
     * GET /lab/worklists/get/{id}
     */
    public function getWorklistById($id)
    {
        try {
            $worklist = Worklist::with([
                'machine',
                'labReceipt.patient',
                'testSample',
                'testSampleItem',
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $worklist,
                'sample_info' => [
                    'barcode' => $worklist->testSample?->barcode,
                    'tube_type' => $worklist->testSample?->tube_type,
                    'sample_number' => $worklist->testSample?->tube_sequence,   // CHANGED — worklists.sample_number dropped, derive from the tube
                    'suffix' => $worklist->suffix,   // KEPT (needed)
                    'timing' => $worklist->timing,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Worklist not found'], 404);
        }
    }

    // ============================================
    // WORKLIST REASSIGNMENT METHODS
    // ============================================

    /**
     * Get available machines for a worklist
     * GET /lab/worklists/available-machines/{id}
     */
    public function getAvailableMachinesForWorklist($id)
    {
        try {
            $machines = $this->worklistService->getAvailableMachinesForWorklist($id);

            return response()->json(['success' => true, 'machines' => $machines]);

        } catch (\Exception $e) {
            Log::error('Get available machines failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to get machines'], 500);
        }
    }

    /**
     * Get available machines for multiple worklists
     * POST /lab/worklists/available-machines
     */
    public function getAvailableMachinesForWorklists(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'worklist_ids' => 'required|array',
            'worklist_ids.*' => 'exists:worklists,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        try {
            $worklists = Worklist::whereIn('id', $request->worklist_ids)->get();
            $testIds = $worklists->pluck('test_id')->unique()->toArray();

            $machines = Machine::where('is_active', true)->get();
            $availableMachines = [];

            foreach ($machines as $machine) {
                $canRunAll = true;
                foreach ($testIds as $testId) {
                    if (! $machine->canRunTest($testId)) {
                        $canRunAll = false;
                        break;
                    }
                }
                if ($canRunAll) {
                    $availableMachines[] = [
                        'id' => $machine->id,
                        'name' => $machine->display_name,
                        'device_model' => $machine->device_model,
                        'priority' => $machine->priority,
                    ];
                }
            }

            return response()->json(['success' => true, 'machines' => $availableMachines, 'test_ids' => $testIds]);

        } catch (\Exception $e) {
            Log::error('Get available machines failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to get machines'], 500);
        }
    }

    /**
     * Reassign a single worklist
     * POST /lab/worklists/reassign/{id}
     */
    public function reassignSingleWorklist(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'machine_id' => 'required|exists:machines,id',
            'reason' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        try {
            $result = $this->worklistService->reassignWorklist(
                $id,
                $request->machine_id,
                $request->reason
            );

            if (!$result['success']) {
                return response()->json(['success' => false, 'message' => $result['message']], 400);
            }

            Log::info('Worklist reassigned', [
                'worklist_id' => $id,
                'machine_id' => $request->machine_id,
                'reason' => $request->reason,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Worklist reassigned',
                'data' => [
                    'worklist_id' => $result['worklist']->id,
                    'barcode' => $result['worklist']->barcode,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Worklist reassignment failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to reassign'], 500);
        }
    }

    /**
     * Reassign worklist to best machine (auto)
     * POST /lab/worklists/reassign-best/{id}
     */
    public function reassignToBestMachine($id)
    {
        try {
            $worklist = Worklist::findOrFail($id);

            if ($worklist->status !== 'pending') {
                return response()->json(['success' => false, 'message' => 'Only pending worklists can be reassigned'], 400);
            }

            $bestMachine = Machine::where('is_active', true)
                ->whereHas('testMappings', function ($q) use ($worklist) {
                    $q->where('test_id', $worklist->test_id)->where('is_active', true);
                })
                ->orderBy('priority', 'desc')
                ->first();

            if (! $bestMachine) {
                return response()->json(['success' => false, 'message' => 'No suitable machine found'], 404);
            }

            $result = $this->worklistService->reassignWorklist(
                $id,
                $bestMachine->id,
                'Auto-assigned to best machine'
            );

            if (!$result['success']) {
                return response()->json(['success' => false, 'message' => $result['message']], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Worklist reassigned to best machine',
                'machine' => $bestMachine->display_name,
            ]);

        } catch (\Exception $e) {
            Log::error('Auto reassignment failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to reassign'], 500);
        }
    }

    /**
     * Reassign all worklists by barcode (tube)
     * POST /lab/worklists/reassign-by-barcode
     */
    public function reassignByBarcode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcode' => 'required|string',
            'machine_id' => 'nullable|exists:machines,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        try {
            $result = $this->worklistService->reassignWorklistByBarcode(
                $request->barcode,
                $request->machine_id
            );

            if (!$result['success']) {
                return response()->json(['success' => false, 'message' => $result['message']], 400);
            }

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'updated' => $result['updated'],
                'total' => $result['total'],
            ]);

        } catch (\Exception $e) {
            Log::error('Barcode reassignment failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to reassign'], 500);
        }
    }

    /**
     * Reassign all worklists from a machine
     * POST /lab/worklists/reassign-from-machine
     */
    public function reassignAllFromMachine(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'source_machine_id' => 'required|exists:machines,id',
            'target_machine_id' => 'nullable|exists:machines,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        try {
            $result = $this->worklistService->reassignAllFromMachine(
                $request->source_machine_id,
                $request->target_machine_id
            );

            if (!$result['success']) {
                return response()->json(['success' => false, 'message' => $result['message']], 400);
            }

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'updated' => $result['updated'] ?? 0,
            ]);

        } catch (\Exception $e) {
            Log::error('Machine reassignment failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to reassign'], 500);
        }
    }

    /**
     * Reassign worklists by date range
     * POST /lab/worklists/reassign-by-date
     */
    public function reassignWorklists(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'machine_id' => 'nullable|exists:machines,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        try {
            $result = $this->worklistService->reassignWorklistsByDateRange(
                $request->start_date,
                $request->end_date,
                $request->machine_id
            );

            if (!$result['success']) {
                return response()->json(['success' => false, 'message' => $result['message']], 400);
            }

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'updated' => $result['updated'] ?? 0,
            ]);

        } catch (\Exception $e) {
            Log::error('Date range reassignment failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to reassign'], 500);
        }
    }

    /**
     * Bulk reassign multiple worklists
     * POST /lab/worklists/bulk-reassign
     */
    public function bulkReassignWorklists(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'worklist_ids' => 'required|array',
            'worklist_ids.*' => 'exists:worklists,id',
            'machine_id' => 'required|exists:machines,id',
            'reason' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        try {
            $result = $this->worklistService->reassignMultipleWorklists(
                $request->worklist_ids,
                $request->machine_id
            );

            if (!$result['success']) {
                return response()->json(['success' => false, 'message' => $result['message']], 400);
            }

            Log::info('Bulk worklist reassignment', [
                'updated' => $result['updated'],
                'failed' => $result['failed'],
                'target_machine' => $request->machine_id,
                'reason' => $request->reason,
            ]);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'updated' => $result['updated'],
                'failed' => $result['failed'],
            ]);

        } catch (\Exception $e) {
            Log::error('Bulk reassignment failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to reassign'], 500);
        }
    }

    // ============================================
    // BULK OPERATIONS
    // ============================================

    /**
     * Bulk delete worklists
     * POST /lab/worklists/bulk-delete
     */
    public function bulkDeleteWorklists(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'worklist_ids' => 'required|array',
            'worklist_ids.*' => 'exists:worklists,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        try {
            $deleted = 0;
            foreach ($request->worklist_ids as $worklistId) {
                $result = $this->worklistService->deleteWorklist($worklistId);
                if ($result['success']) {
                    $deleted++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => "{$deleted} worklist(s) deleted",
                'deleted' => $deleted,
            ]);

        } catch (\Exception $e) {
            Log::error('Bulk deletion failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to delete'], 500);
        }
    }

    /**
     * Bulk export worklists to CSV
     * POST /lab/worklists/bulk-export
     */
    public function bulkExportWorklists(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'worklist_ids' => 'required|array',
            'worklist_ids.*' => 'exists:worklists,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $worklists = Worklist::with(['machine', 'labReceipt.patient', 'testSample'])
            ->whereIn('id', $request->worklist_ids)
            ->get();

        $filename = 'worklists_export_'.date('Ymd_His').'.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($worklists) {
            $file = fopen('php://output', 'w');
            fputcsv($file, [
                'ID', 'Barcode', 'Receipt Barcode', 'SL No',
                'Patient Name', 'Patient Age', 'Patient Gender',
                'Test Name', 'Test Code', 'Sample Type', 'Tube Type', 'Timing',
                'Machine', 'Priority', 'Status',
                'Created At', 'Collection Date', 'Started At', 'Completed At'
            ]);

            foreach ($worklists as $worklist) {
                fputcsv($file, [
                    $worklist->id,
                    $worklist->barcode,
                    $worklist->receipt_barcode,
                    $worklist->sl_no,
                    $worklist->patient_name ?? $worklist->labReceipt?->patient?->name,
                    $worklist->patient_age ?? $worklist->labReceipt?->patient?->age,
                    $worklist->patient_gender ?? $worklist->labReceipt?->patient?->gender,
                    $worklist->test_name,
                    $worklist->test_code,
                    $worklist->sample_type,
                    $worklist->tube_type,
                    $worklist->timing,
                    $worklist->machine?->display_name,
                    ucfirst($worklist->priority ?? 'routine'),
                    ucfirst($worklist->status),
                    $worklist->created_at,
                    $worklist->collection_date,
                    $worklist->started_at,
                    $worklist->completed_at,
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export worklists with filters
     * GET /lab/worklists/export
     */
    public function exportWorklists(Request $request)
    {
        $query = Worklist::with(['machine', 'labReceipt.patient', 'testSample']);

        if ($request->has('machine_id') && $request->machine_id) {
            $query->where('machine_id', $request->machine_id);
        }

        if ($request->has('status') && $request->status && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('barcode', 'like', "%{$search}%")
                    ->orWhere('test_name', 'like', "%{$search}%")
                    ->orWhere('patient_name', 'like', "%{$search}%");
            });
        }

        $worklists = $query->orderBy('created_at', 'desc')->get();

        $filename = 'worklists_export_'.date('Ymd_His').'.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($worklists) {
            $file = fopen('php://output', 'w');
            fputcsv($file, [
                'ID', 'Barcode', 'Receipt Barcode', 'SL No',
                'Patient Name', 'Patient Age', 'Patient Gender',
                'Test Name', 'Test Code', 'Sample Type', 'Tube Type', 'Timing',
                'Machine', 'Priority', 'Status',
                'Created At', 'Collection Date', 'Started At', 'Completed At'
            ]);

            foreach ($worklists as $worklist) {
                fputcsv($file, [
                    $worklist->id,
                    $worklist->barcode,
                    $worklist->receipt_barcode,
                    $worklist->sl_no,
                    $worklist->patient_name ?? $worklist->labReceipt?->patient?->name,
                    $worklist->patient_age ?? $worklist->labReceipt?->patient?->age,
                    $worklist->patient_gender ?? $worklist->labReceipt?->patient?->gender,
                    $worklist->test_name,
                    $worklist->test_code,
                    $worklist->sample_type,
                    $worklist->tube_type,
                    $worklist->timing,
                    $worklist->machine?->display_name,
                    ucfirst($worklist->priority ?? 'routine'),
                    ucfirst($worklist->status),
                    $worklist->created_at,
                    $worklist->collection_date,
                    $worklist->started_at,
                    $worklist->completed_at,
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Create a new worklist manually
     * POST /lab/worklists/create
     */
    public function createWorklist(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcode' => 'required|string|max:100',
            'test_code' => 'required|string|max:50',
            'test_name' => 'required|string|max:255',
            'test_id' => 'nullable|exists:tests,id',
            'sample_type' => 'nullable|string|max:100',
            'priority' => 'nullable|in:high,routine,low',
            'lab_receipt_id' => 'nullable|exists:lab_receipts,id',
            'machine_id' => 'nullable|exists:machines,id',
            'patient_id' => 'nullable|exists:patients,id',
            'collection_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()], 400);
        }

        try {
            $data = $validator->validated();
            // REMOVED: $data['order_id'] = $this->worklistService->generateOrderId(); — column dropped
            $data['status'] = 'pending';

            $worklist = Worklist::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Worklist created',
                'data' => $worklist
            ]);

        } catch (\Exception $e) {
            Log::error('Worklist creation failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to create'], 500);
        }
    }

    // ============================================
    // API METHODS FOR MACHINES (MIRTH CONNECT)
    // ============================================

    /**
     * Get worklist for Mirth Connect (by device model)
     * GET /api/mirth/worklist
     */
    public function getWorklistForMirth(Request $request)
    {
        $deviceModel = $request->input('device_model');

        if (!$deviceModel) {
            return response()->json(['success' => false, 'message' => 'device_model required'], 400);
        }

        $worklists = Worklist::query()
            ->whereHas('machine', function ($q) use ($deviceModel) {
                $q->where('device_model', $deviceModel);
            })
            ->where('status', 'pending')
            ->whereNull('pushed_at')
            ->with(['labReceipt.patient', 'machine', 'testSample'])
            ->orderByRaw("FIELD(priority, 'high', 'routine', 'low')")
            ->orderBy('created_at', 'asc')
            ->get();

        $data = $worklists->map(function ($worklist) {
            return [
                'worklist_id' => $worklist->id,
                'barcode' => $worklist->barcode,
                'receipt_barcode' => $worklist->receipt_barcode,
                // REMOVED: 'order_id', 'sample_number' — columns dropped;
                //          tube number comes from tube_sequence, suffix rides in receipt_barcode
                'suffix' => $worklist->suffix,   // KEPT (needed)
                'timing' => $worklist->timing,
                'test_code' => $worklist->test_code,
                'test_name' => $worklist->test_name,
                'sample_type' => $worklist->sample_type,
                'tube_type' => $worklist->tube_type,
                'priority' => $worklist->priority,
                'patient_name' => $worklist->patient_name ?? $worklist->labReceipt?->patient?->name,
                'patient_age' => $worklist->patient_age ?? $worklist->labReceipt?->patient?->age,
                'patient_gender' => $worklist->patient_gender ?? $worklist->labReceipt?->patient?->gender,
                'collection_date' => $worklist->collection_date?->toISOString(),
                // REMOVED: 'order_date' => $worklist->order_date?->toISOString()
                'tube_barcode' => $worklist->testSample?->barcode,
                'tube_type' => $worklist->testSample?->tube_type,
            ];
        });

        return response()->json([
            'success' => true,
            'worklist' => $data,
            'total' => $data->count()
        ]);
    }

    /**
     * Acknowledge worklist from Mirth Connect
     * POST /api/mirth/acknowledge
     */
    public function acknowledgeWorklist(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcode' => 'required|string',
            'machine_key' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()], 400);
        }

        $worklist = Worklist::where('barcode', $request->barcode)
            ->whereHas('machine', function ($q) use ($request) {
                $q->where('device_model', $request->machine_key);
            })
            ->where('status', 'pending')
            ->first();

        if (! $worklist) {
            return response()->json(['success' => false, 'message' => 'Worklist not found'], 404);
        }

        $result = $this->worklistService->acknowledgeWorklist($worklist->id);

        if (!$result['success']) {
            return response()->json(['success' => false, 'message' => $result['message']], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Worklist acknowledged',
            'worklist_id' => $worklist->id,
            'status' => 'processing',
            'started_at' => now()->toISOString(),
        ]);
    }

    /**
     * Receive HL7/ASTM results from Mirth Connect
     * POST /api/mirth/results
     */
    public function receiveHl7Results(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcode' => 'required|string',
            'machine_key' => 'required|string',
            // REMOVED: 'result_data' => 'required|array' — results live in lis_results
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()], 400);
        }

        $worklist = Worklist::where('barcode', $request->barcode)
            ->whereHas('machine', function ($q) use ($request) {
                $q->where('device_model', $request->machine_key);
            })
            ->where('status', 'processing')
            ->first();

        if (! $worklist) {
            return response()->json(['success' => false, 'message' => 'Worklist not found'], 404);
        }

        $result = $this->worklistService->submitResult($worklist->id);

        if (!$result['success']) {
            return response()->json(['success' => false, 'message' => $result['message']], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Results received',
            'worklist_id' => $worklist->id,
            'status' => 'completed',
            'completed_at' => now()->toISOString(),
        ]);
    }

    /**
     * Check if there are pending worklists for a machine
     * GET /api/mirth/pending-check
     */
    public function checkPendingWorklist(Request $request)
    {
        $deviceModel = $request->get('device_model');

        if (!$deviceModel) {
            return response()->json(['success' => false, 'message' => 'device_model required'], 400);
        }

        $count = Worklist::whereHas('machine', function ($q) use ($deviceModel) {
            $q->where('device_model', $deviceModel);
        })
            ->where('status', 'pending')
            ->whereNull('pushed_at')
            ->count();

        return response()->json([
            'success' => true,
            'has_pending' => $count > 0,
            'count' => $count
        ]);
    }

    // ============================================
    // API METHODS FOR MACHINES (NEW)
    // ============================================

    /**
     * Get pending worklist for a machine (API)
     * GET /api/worklist
     * Header: X-Machine-Key: device_model
     */
    public function getWorklistForMachineApi(Request $request)
    {
        $machineKey = $request->header('X-Machine-Key');

        if (!$machineKey) {
            return response()->json([
                'success' => false,
                'error' => 'X-Machine-Key header required'
            ], 400);
        }

        $machine = Machine::where('device_model', $machineKey)
            ->where('is_active', true)
            ->first();

        if (!$machine) {
            return response()->json([
                'success' => false,
                'error' => 'Machine not found'
            ], 404);
        }

        $worklist = $this->worklistService->getNextWorklistForMachine($machine->id);

        if (!$worklist) {
            return response()->json([
                'success' => true,
                'has_worklist' => false,
                'message' => 'No pending worklist available',
            ]);
        }

        return response()->json([
            'success' => true,
            'has_worklist' => true,
            'worklist' => [
                'worklist_id' => $worklist->id,
                'barcode' => $worklist->barcode,
                'receipt_barcode' => $worklist->receipt_barcode,
                'test_code' => $worklist->test_code,
                'test_name' => $worklist->test_name,
                'sample_type' => $worklist->sample_type,
                'tube_type' => $worklist->tube_type,
                'timing' => $worklist->timing,
                'priority' => $worklist->priority,
                'priority_label' => $worklist->priority_label,
                'patient_name' => $worklist->patient_name,
                'patient_age' => $worklist->patient_age,
                'patient_gender' => $worklist->patient_gender,
                'collection_date' => $worklist->collection_date?->toISOString(),
                'machine_mapping_id' => $worklist->machine_mapping_id,   // NEW — replaces parameters
                // REMOVED: 'order_id' and 'parameters' (columns dropped)
            ],
        ]);
    }

    /**
     * Acknowledge worklist (API)
     * POST /api/worklist/acknowledge
     */
    public function acknowledgeWorklistApi(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'worklist_id' => 'required|string',
            'machine_key' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => $validator->errors()
            ], 400);
        }

        $machine = Machine::where('device_model', $request->machine_key)
            ->where('is_active', true)
            ->first();

        if (!$machine) {
            return response()->json([
                'success' => false,
                'error' => 'Machine not found'
            ], 404);
        }

        $result = $this->worklistService->acknowledgeWorklist($request->worklist_id);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['message']
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'worklist_id' => $request->worklist_id,
            'status' => 'processing',
            'started_at' => now()->toISOString(),
        ]);
    }

    /**
     * Submit result (API)
     * POST /api/worklist/submit-result
     */
    public function submitResultApi(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'worklist_id' => 'required|string',
            'machine_key' => 'required|string',
            // REMOVED: 'result_data' => 'required|array' — results live in lis_results
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => $validator->errors()
            ], 400);
        }

        $machine = Machine::where('device_model', $request->machine_key)
            ->where('is_active', true)
            ->first();

        if (!$machine) {
            return response()->json([
                'success' => false,
                'error' => 'Machine not found'
            ], 404);
        }

        $result = $this->worklistService->submitResult(
            $request->worklist_id
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['message']
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'worklist_id' => $request->worklist_id,
            'status' => 'completed',
            'completed_at' => now()->toISOString(),
        ]);
    }

    /**
     * Health check for machine
     * GET /api/worklist/health
     */
    public function healthCheck(Request $request)
    {
        $machineKey = $request->header('X-Machine-Key');

        if (!$machineKey) {
            return response()->json([
                'status' => 'error',
                'message' => 'X-Machine-Key header required'
            ], 400);
        }

        $machine = Machine::where('device_model', $machineKey)
            ->where('is_active', true)
            ->first();

        if (!$machine) {
            return response()->json([
                'status' => 'error',
                'message' => 'Machine not found'
            ], 404);
        }

        $stats = $this->worklistService->getMachineStatistics($machine->id);

        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'machine' => [
                'id' => $machine->id,
                'name' => $machine->display_name,
                'device_model' => $machine->device_model,
                'is_active' => $machine->is_active,
            ],
            'pending' => $stats['pending'],
            'processing' => $stats['processing'],
            'completed_today' => $stats['completed_today'],
        ]);
    }

    /**
     * Get pending worklists for a machine (batch)
     * GET /api/worklist/pending
     */
    public function getPendingWorklistsApi(Request $request)
    {
        $machineKey = $request->header('X-Machine-Key');

        if (!$machineKey) {
            return response()->json([
                'success' => false,
                'error' => 'X-Machine-Key header required'
            ], 400);
        }

        $machine = Machine::where('device_model', $machineKey)
            ->where('is_active', true)
            ->first();

        if (!$machine) {
            return response()->json([
                'success' => false,
                'error' => 'Machine not found'
            ], 404);
        }

        $worklists = $this->worklistService->getPendingWorklistsForMachine($machine->id);

        return response()->json([
            'success' => true,
            'total' => $worklists->count(),
            'worklists' => $worklists->map(function ($worklist) {
                return [
                    'worklist_id' => $worklist->id,
                    'barcode' => $worklist->barcode,
                    // REMOVED: 'order_id' — column dropped
                    'test_code' => $worklist->test_code,
                    'test_name' => $worklist->test_name,
                    'priority' => $worklist->priority,
                    'patient_name' => $worklist->patient_name,
                    'tube_type' => $worklist->tube_type,
                    'timing' => $worklist->timing,
                ];
            }),
        ]);
    }
}
