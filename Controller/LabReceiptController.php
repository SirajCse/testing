<?php

namespace App\Http\Controllers\Lab;

use App\Helpers\LabPricingHelper;
use App\Http\Controllers\Controller;
use App\Models\LabExtra;
use App\Models\LabReceipt;
use App\Models\LabTest;
use App\Models\LabToLabPrice;
use App\Models\Patient;
use App\Models\Product;
use App\Models\Test;
use App\Support\PriceCache;
use DataTables;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PDF;

class LabReceiptController extends Controller
{
    /** @var string[] Per-request memo — avoids re-priming same test configs. */
    private static array $primedTestIds = [];

    /* ═══════════════════════════════════════════════════════════
       INDEX
       ═══════════════════════════════════════════════════════════ */
    public function index()
    {
        isAccess('view_lab_receipt');

        return view('lab.lab_receipt.index');
    }

    /* ═══════════════════════════════════════════════════════════
       AJAX DATATABLE
       ═══════════════════════════════════════════════════════════ */
    public function ajax(Request $request)
    {
        isAccess('view_lab_receipt');

        $barcode = $request->get('barcode');
        $dateRaw = $request->get('date');
        $patientId = $request->get('patient_id');
        $contractId = $request->get('contract_id');
        $referralId = $request->get('referral_id');
        $testId = $request->get('test_id');
        $due = $request->get('due');

        $user = auth()->user();
        $canRestore = $user->hasAnyPermission([
            'restore_lab_receipt',
            'force_delete_lab_receipt',
        ]);

        $model = LabReceipt::query()
            ->withCount(['labTest', 'versions'])
            ->select('lab_receipts.*')
            ->with([
                'labTest:id,lab_id,test_id,collected_at,prepared_at,reviewed_at,result_at,delivered_by,reviewed_by,prepared_by,collected_by',
                'patient:id,name,phone',
                'contract:id,name,short_name',
                'referral:id,name,designation',
                'creator:id,name',
                'updater:id,name',
            ]);

        if ($canRestore) {
            $model->withTrashed();
        }

        // ─── Date range parsing ───
        $dateStart = null;
        $dateEnd = null;

        if ($dateRaw) {
            $parts = explode('to', $dateRaw, 2);
            $dateStart = Carbon::parse(trim($parts[0]))->startOfDay();
            $dateEnd = Carbon::parse(trim($parts[1] ?? $parts[0]))->endOfDay();
        }

        return DataTables::eloquent($model)
            ->addIndexColumn()
            ->filter(function ($query) use (
                $barcode, $dateStart, $dateEnd, $patientId, $contractId,
                $referralId, $testId, $due
            ) {
                if ($dateStart && $dateEnd) {
                    $query->whereBetween('lab_receipts.date', [$dateStart, $dateEnd]);
                } elseif (! $barcode && ! $patientId && ! $contractId && ! $referralId && ! $testId) {
                    $query->whereBetween('lab_receipts.date', [
                        Carbon::today()->startOfDay(),
                        Carbon::today()->endOfDay(),
                    ]);
                }

                if ($barcode) {
                    $query->whereAny(['lab_receipts.barcode', 'lab_receipts.sl_no'], 'LIKE', $barcode.'%');
                }

                if ($patientId) {
                    $query->where('lab_receipts.patient_id', $patientId);
                }

                if ($contractId) {
                    $query->whereIn('lab_receipts.contract_id', (array) $contractId);
                }

                if ($referralId) {
                    $query->whereIn('lab_receipts.referral_id', (array) $referralId);
                }

                if ($testId) {
                    $query->whereHas('labTest', fn ($q) => $q->whereIn('lab_tests.test_id', (array) $testId));
                }

                if ($due) {
                    if (str_contains($due, '-')) {
                        [$min, $max] = array_map('trim', explode('-', $due, 2));
                        if (is_numeric($min)) {
                            $query->where('lab_receipts.due', '>=', (float) $min);
                        }
                        if (is_numeric($max)) {
                            $query->where('lab_receipts.due', '<=', (float) $max);
                        }
                    } elseif (str_starts_with($due, '>')) {
                        $query->where('lab_receipts.due', '>', (float) ltrim($due, '>'));
                    } elseif (str_starts_with($due, '<')) {
                        $query->where('lab_receipts.due', '<', (float) ltrim($due, '<'));
                    } elseif (is_numeric($due)) {
                        $query->where('lab_receipts.due', (float) $due);
                    }
                }
            }, true)
            ->filterColumn('barcode', function ($query, $keyword) {
                $query->whereAny(['lab_receipts.barcode', 'lab_receipts.sl_no'], 'LIKE', $keyword.'%');
            })
            ->filterColumn('patient', function ($query, $keyword) {
                $query->whereHas('patient', function ($q) use ($keyword) {
                    $q->where('patients.name', 'LIKE', $keyword.'%')
                        ->orWhere('patients.phone', 'LIKE', $keyword.'%');
                });
            })
            ->addColumn('details', fn ($row) => view('lab.lab_receipt.link', compact('row')))
            ->editColumn('barcode', fn ($row) => e($row->sl_no).'<br>BR:'.e($row->barcode))
            ->editColumn('patient', fn ($row) => e($row?->patient?->name ?? '').' <br>'.e($row?->patient?->phone ?? ''))
            ->editColumn('contract', fn ($row) => e($row?->contract?->name ?? 'Self')
                .($row?->contract?->short_name ? '<br>'.e($row->contract->short_name) : ''))
            ->editColumn('referral', fn ($row) => e(str()->limit($row?->referral?->name ?? 'Self', 50, '')))
            ->editColumn('created_at', fn ($row) => $row->created_at?->format('Y-m-d H:i'))
            ->editColumn('updated_at', fn ($row) => $row->updated_at?->format('Y-m-d H:i'))
            ->rawColumns(['barcode', 'patient', 'contract', 'referral'])
            ->addColumn('action', fn ($row) => view('lab.lab_receipt.action', compact('row')))
            ->toJson();
    }

    /* ═══════════════════════════════════════════════════════════
       CREATE / STORE
       ═══════════════════════════════════════════════════════════ */
    public function create()
    {
        isAccess('create_lab_receipt');

        return request()->input('dx')
            ? view('lab.lab_receipt.create-dx')
            : view('lab.lab_receipt.create');
    }

    public function store(Request $request)
    {
        isAccess('create_lab_receipt');

        $validator = Validator::make($request->all(), [
            'patient_id' => [
                'required', 'integer_or_uuid',
                Rule::unique('lab_receipts')->where(function ($query) use ($request) {
                    return $query->where('total', $request->total)
                        ->where('subtotal', $request->subtotal)
                        ->where('paid', $request->paid)
                        ->when(
                            $request->filled('note'),
                            fn ($q) => $q->where('note', $request->note),
                            fn ($q) => $q->whereNull('note')
                        )
                        ->where('created_at', '>=', Carbon::now()->subMinutes(2));
                }),
            ],
            'referral_id' => ['nullable', 'integer_or_uuid'],
            'contract_id' => ['nullable', 'integer_or_uuid'],
            'from_lab_id' => ['nullable', 'integer_or_uuid'],
            'to_lab_id'   => ['nullable', 'integer_or_uuid'],
            'date'        => ['required', 'date'],
            'note'        => ['nullable', 'string', 'max:500'],

            'subtotal' => ['nullable', 'numeric'],
            'total'    => ['nullable', 'numeric'],
            'due'      => ['nullable', 'numeric'],
            'tax'      => ['nullable', 'numeric'],
            'discount' => ['required', 'numeric'],

            'paid' => [
                'required', 'numeric',
                function ($attribute, $value, $fail) {
                    $v = (float) $value;
                    if (! isPermission('return_lab_receipt') && $v < 0) {
                        $fail($attribute.' - You have no permission to return.');
                    }
                },
            ],
            'contract_commission' => ['nullable', 'numeric'],
            'payment_type'        => ['required', 'integer_or_uuid'],
            'payment_note'        => ['nullable', 'string', 'max:500'],

            'test.*.test_id'             => ['required', 'integer_or_uuid'],
            'test.*.price'               => ['required', 'numeric'],
            'test.*.min_price'           => ['required', 'numeric'],
            'test.*.discount'            => ['nullable', 'numeric'],
            'test.*.amount'              => ['required', 'numeric'],
            'test.*.note'                => ['nullable', 'string', 'max:500'],
            'test.*.duration'            => ['nullable', 'string', 'max:191'],
            'test.*.wr'                  => ['nullable', 'numeric'],
            'test.*.reporting_charge'    => ['nullable', 'numeric'],
            'test.*.referral_commission' => ['nullable', 'numeric'],
            'test.*.contract_commission' => ['nullable', 'numeric'],

            'extra.*.product_id' => ['required', 'integer_or_uuid'],
            'extra.*.min_price'  => ['required', 'numeric'],
            'extra.*.price'      => ['required', 'numeric'],
            'extra.*.quantity'   => ['required', 'numeric'],
            'extra.*.discount'   => ['nullable', 'numeric'],
            'extra.*.amount'     => ['required', 'numeric'],
        ]);

        if ($validator->fails()) {
            return response()->json(['type' => 'invalid', 'note' => $validator->messages(), 'status' => 400], 200);
        }

        $data = $validator->validated();

        try {
            // ─── Server-side pricing (single source of truth) ───
            $priced = $this->calculateReceiptPrices(
                $data['test'] ?? [],
                $data['extra'] ?? [],
                $request->input('from_lab_id'),
                (float) ($data['tax'] ?? 0),
                (float) ($data['discount'] ?? 0),
                0.0,
                (bool) $request->input('over_discount', 0) && isPermission('pro_edit_lab_receipt')
            );

            // ⭐ Round everything to integer at write time
            $data['subtotal'] = (int) round($priced['subtotal']);
            $data['discount'] = (int) round($priced['discount']);
            $data['total']    = (int) round($priced['total']);
            $data['tax']      = (int) round((float) ($data['tax'] ?? 0));

            // ⭐ Symmetric round of the user's paid input
            $paidRaw   = (float) $data['paid'];
            $paidDelta = $paidRaw < 0
                ? -(int) round(abs($paidRaw))
                :  (int) round($paidRaw);

            // ⭐ On create there is no prepaid — cap to FLOOR of total
            $allowedDelta = (int) floor((float) $data['total']);
            if ($paidDelta > 0 && $paidDelta > $allowedDelta) {
                $paidDelta = $allowedDelta;
            }
            if ($paidDelta < 0) {
                // No refund on create (nothing was ever paid)
                $paidDelta = 0;
            }

            $data['paid'] = $paidDelta;
            $data['due']  = (int) max(0, (int) $data['total'] - $paidDelta);

            $data['test']  = $priced['tests'];
            $data['extra'] = $priced['extras'];

            // ⭐ Separate variable — never pollute $data with non-columns
            $payData = [
                'total'        => (int) $data['total'],
                'paid'         => $paidDelta,
                'discount'     => (int) $data['discount'],
                'tax'          => (int) $data['tax'],
                'due'          => (int) $data['due'],
                'payment_type' => $data['payment_type'],
                'verified'     => ($data['payment_type'] == config('default.payment_type')) ? 1 : 0,
                'note'         => $data['payment_note'] ?? '',
            ];

            $labId = DB::transaction(function () use ($data, $payData, $paidDelta) {
                /** @var LabReceipt $receipt */
                $receipt = LabReceipt::create($data);

                // ⭐ Distribute paid across test + extra rows (skip when 0)
                $testRows  = $data['test'] ?? [];
                $extraRows = $data['extra'] ?? [];

                $allRows = array_merge(
                    array_map(fn ($r) => ['kind' => 'test',  'row' => $r], $testRows),
                    array_map(fn ($r) => ['kind' => 'extra', 'row' => $r], $extraRows)
                );

                if ($paidDelta != 0) {
                    $allRows = $this->distributePaid($allRows, $paidDelta, (int) $data['total']);
                }

                foreach ($allRows as $item) {
                    $row = $item['row'];
                    $row['lab_id']    = $receipt->id;
                    $row['tenant_id'] = $receipt->tenant_id;

                    if ($item['kind'] === 'test') {
                        LabTest::create($row);
                    } else {
                        LabExtra::create($row);
                    }
                }

                // ⭐ Only create a payment row for a non-zero delta
                if ($paidDelta != 0) {
                    $receipt->payments()->create($payData);
                }

                return $receipt->id;
            });

            $link = getSetting($data['tenant_id'] ?? webTenant(), 'lab_receipt_pdf_link')
                ?: '?client=1&lab=1&office=1';

            return response()->json([
                'type'     => 'success',
                'note'     => $labId,
                'lab_id'   => $labId,
                'pdf_link' => [route('lab.receipt.pdf.receipt', $labId).$link],
                'status'   => 400,
            ], 201);

        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => 'Can not Create', 'status' => 400], 500);
        }
    }

    /* ═══════════════════════════════════════════════════════════
       PRICE CALCULATION
       ═══════════════════════════════════════════════════════════ */
    private function calculateReceiptPrices(
        array $testRows,
        array $extraRows,
        ?string $fromLabId,
        float $tax,
        float $discount,
        float $prepaid,
        bool $overDiscount
    ): array {
        $testIds = collect($testRows)->pluck('test_id')->filter()->all();

        $testPrices = $testIds
            ? LabPricingHelper::getBatchPrices($testIds, $fromLabId)
            : [];

        $extraProductIds = collect($extraRows)->pluck('product_id')->filter()->all();

        $productPrices = $extraProductIds
            ? Product::whereIn('id', $extraProductIds)
                ->get(['id', 'sale_price', 'min_price'])
                ->keyBy('id')
            : collect();

        $subtotal = 0.0;
        $maxDiscount = 0.0;

        $tests = [];
        foreach ($testRows as $row) {
            $tid = $row['test_id'];
            $p = $testPrices[$tid] ?? ['price' => 0, 'min_price' => 0];

            $price = (float) $p['price'];
            $minP = (float) $p['min_price'];
            $reporting = ! empty($row['wr']) ? 0 : (float) ($row['reporting_charge'] ?? 0);

            $subtotal += $price;
            $maxDiscount += max(0, $price - $minP + $reporting);

            $tests[] = array_merge($row, [
                'price' => $price,
                'min_price' => $minP,
                'amount' => $price,
                'discount' => 0,
            ]);
        }

        $extras = [];
        foreach ($extraRows as $row) {
            $pp = $productPrices->get($row['product_id'] ?? null);
            if (! $pp) {
                continue;
            }

            $price = (float) $pp->sale_price;
            $minP = (float) $pp->min_price;
            $qty = (float) ($row['quantity'] ?? 1);

            $subtotal += $price * $qty;
            $maxDiscount += max(0, ($price - $minP) * $qty);

            $extras[] = array_merge($row, [
                'price' => $price,
                'min_price' => $minP,
                'amount' => $price * $qty,
                'discount' => 0,
            ]);
        }

        if (! $overDiscount) {
            $discount = min($discount, $maxDiscount);
        }
        $discount = max(0.0, min($discount, $subtotal + $tax - $prepaid));

        // ⭐ Round ONLY the final values — never intermediate math.
        $roundedDiscount = round($discount, 2);
        $roundedTotal = max(0.0, round($subtotal + $tax - $discount, 2));

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => $roundedDiscount,
            'total' => $roundedTotal,
            'tests' => $tests,
            'extras' => $extras,
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       PAID DISTRIBUTION — deterministic, last-row absorbs remainder
       ═══════════════════════════════════════════════════════════ */

    /**
     * Distribute a receipt-level paid amount across rows proportional to `amount`.
     * The LAST row absorbs any rounding remainder so the sum of `paid` equals
     * `$totalPaid` to the paisa. Eliminates the "1 taka drift" bug.
     *
     * @param  array  $rows  Rows with at least an 'amount' key.
     * @param  float  $totalPaid  Target sum (receipt's paid).
     * @param  float  $totalAmount  Sum of amounts (receipt's total).
     * @return array Same rows with 'paid' set (rounded to 2 decimals).
     */
    private function distributePaid(array $rows, float $totalPaid, float $totalAmount): array
    {
        $count = count($rows);

        if ($count === 0) {
            return $rows;
        }

        if ($totalAmount <= 0) {
            foreach ($rows as &$r) {
                $r['paid'] = 0.0;
            }
            unset($r);

            return $rows;
        }

        $allocated = 0.0;
        $i = 0;

        foreach ($rows as &$row) {
            $i++;

            if ($i === $count) {
                // Last row absorbs remainder — guarantees exact sum.
                $row['paid'] = round($totalPaid - $allocated, 2);
            } else {
                $row['paid'] = round(((float) ($row['amount'] ?? 0) * $totalPaid) / $totalAmount, 2);
            }

            if ($row['paid'] < 0) {
                $row['paid'] = 0.0;
            }

            $allocated = round($allocated + $row['paid'], 2);
        }
        unset($row);

        return $rows;
    }

    /* ═══════════════════════════════════════════════════════════
       DETAILS
       ═══════════════════════════════════════════════════════════ */
    public function details($id): JsonResponse
    {
        isAccess('show_lab_receipt');

        try {
            $data = LabReceipt::with([
                'labTest' => fn ($q) => $q->withAppend(),
                'labExtra' => fn ($q) => $q->withAppend(),
                'patient:id,name,tenant_id,phone,email,age,dob,gender,blood_group,religion,nid_bc,address,district,thana',
                'payment',
            ])->findOrFail($id);

            $this->primeTestConfigs($data);

            return response()->json($data);
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => 'Can not Found Data', 'status' => 400], 500);
        }
    }

    /* ═══════════════════════════════════════════════════════════
       EDIT
       ═══════════════════════════════════════════════════════════ */
    public function edit($id = null): View|Application|Factory|RedirectResponse|\Illuminate\Contracts\Foundation\Application
    {
        isAccess('edit_lab_receipt');

        $data = $id ? LabReceipt::findOrFail($id) : null;

        return view('lab.lab_receipt.edit')->with(['data' => $data]);
    }

    /* ═══════════════════════════════════════════════════════════
       UPDATE
       ═══════════════════════════════════════════════════════════ */
    public function update($id, Request $request): JsonResponse
    {
        isAccess('edit_lab_receipt');

        $isProEdit = isPermission('pro_edit_lab_receipt');

        // ─── Non-pro: only patient_id + referral_id + note ───
        if (! $isProEdit) {
            $validator = Validator::make($request->all(), [
                'id' => ['required', 'integer_or_uuid'],
                'patient_id' => ['required', 'integer_or_uuid'],
                'referral_id' => ['nullable', 'integer_or_uuid'],
                'note' => ['nullable', 'string', 'max:500'],
            ]);

            if ($validator->fails()) {
                return response()->json(['type' => 'invalid', 'note' => $validator->messages(), 'status' => 400], 200);
            }

            $data = $validator->validated();

            try {
                LabReceipt::findOrFail($data['id'])->update([
                    'patient_id' => $data['patient_id'],
                    'referral_id' => $data['referral_id'] ?? null,
                ]);

                return response()->json(['type' => 'success', 'note' => 'Lab Receipt Reference Updated successfully', 'status' => 400], 201);
            } catch (\Throwable $e) {
                Log::error($e->getMessage());

                return response()->json(['type' => 'fail', 'note' => 'Can not Update', 'status' => 400], 500);
            }
        }

        // ─── Pro edit: full update ───
        $overDiscount = $request->boolean('over_discount') && $isProEdit;

        $validator = Validator::make($request->all(), [
            'id' => [
                'required', 'integer_or_uuid',
                Rule::unique('lab_receipts')->where(function ($query) use ($request) {
                    return $query->where('paid', $request->paid)
                        ->when(
                            $request->filled('note'),
                            fn ($q) => $q->where('note', $request->note),
                            fn ($q) => $q->whereNull('note')
                        )
                        ->where('created_at', '>=', Carbon::now()->subMinute(1));
                }),
            ],
            'patient_id' => ['required', 'integer_or_uuid'],
            'referral_id' => ['nullable', 'integer_or_uuid'],
            'contract_id' => ['nullable', 'integer_or_uuid'],
            'from_lab_id' => ['nullable', 'integer_or_uuid'],
            'to_lab_id' => ['nullable', 'integer_or_uuid'],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],

            'subtotal' => ['required', 'numeric'],
            'total' => ['required', 'numeric'],
            'tax' => ['required', 'numeric'],
            'discount' => ['required', 'numeric'],

            'paid' => [
                'required', 'numeric',
                function ($attribute, $value, $fail) {
                    $v = (float) $value;

                    // Negative = return; requires permission
                    if ($v < 0 && ! isPermission('return_lab_receipt')) {
                        $fail($attribute.' - You have no permission to return.');
                    }
                },
            ],
            'due' => ['required', 'numeric', 'lte:total'],
            'contract_commission' => ['nullable', 'numeric'],
            'payment_type' => ['required', 'integer_or_uuid'],
            'payment_note' => ['nullable', 'string', 'max:500'],

            'test.*.id' => ['nullable', 'integer_or_uuid'],
            'test.*.test_id' => ['required', 'integer_or_uuid'],
            'test.*.min_price' => ['required', 'numeric'],
            'test.*.price' => ['required', 'numeric'],
            'test.*.discount' => ['nullable', 'numeric'],
            'test.*.amount' => ['required', 'numeric'],
            'test.*.note' => ['nullable', 'string', 'max:500'],
            'test.*.duration' => ['nullable', 'string', 'max:191'],
            'test.*.wr' => ['nullable', 'numeric'],
            'test.*.reporting_charge' => ['nullable', 'numeric'],
            'test.*.referral_commission' => ['nullable', 'numeric'],
            'test.*.contract_commission' => ['nullable', 'numeric'],

            'extra.*.id' => ['nullable', 'integer_or_uuid'],
            'extra.*.product_id' => ['required', 'integer_or_uuid'],
            'extra.*.min_price' => ['required', 'numeric'],
            'extra.*.price' => ['required', 'numeric'],
            'extra.*.quantity' => ['required', 'numeric'],
            'extra.*.discount' => ['nullable', 'numeric'],
            'extra.*.amount' => ['required', 'numeric'],
        ]);

        if ($validator->fails()) {
            return response()->json(['type' => 'invalid', 'note' => $validator->messages(), 'status' => 400], 200);
        }

        $data = $validator->validated();

        try {
            $receiptForCaps = LabReceipt::findOrFail($data['id']);
            $alreadyPaid = (int) round((float) $receiptForCaps->paid);

            // ⭐ User input — symmetric round to nearest integer
            $paidRaw = (float) $data['paid'];
            $paidDelta = $paidRaw < 0
                ? -(int) round(abs($paidRaw))
                : (int) round($paidRaw);

            $priced = $this->calculateReceiptPrices(
                $data['test'] ?? [],
                $data['extra'] ?? [],
                $request->input('from_lab_id'),
                (float) ($data['tax'] ?? 0),
                (float) ($data['discount'] ?? 0),
                $alreadyPaid,
                $overDiscount
            );

            $subtotal = $priced['subtotal'];
            $tax = (float) ($data['tax'] ?? 0);
            $hardCeiling = $subtotal + $tax;
            $maxAllowed = max(0, $hardCeiling - $alreadyPaid);

            if (! $overDiscount && (float) $data['discount'] > $maxAllowed + 0.01) {
                return response()->json([
                    'type' => 'invalid',
                    'note' => ['discount' => ['Discount exceeds the allowed maximum (৳'.number_format($maxAllowed, 2).').']],
                    'status' => 400,
                ], 200);
            }

            $discount = max(0, min((float) $data['discount'], $hardCeiling));

            $data['subtotal'] = (int) round($subtotal);
            $data['discount'] = (int) round($discount);
            $data['total'] = (int) round(max(0, $hardCeiling - $discount));
            $data['test'] = $priced['tests'];
            $data['extra'] = $priced['extras'];

            // ⭐ Server-side cap: never let paidDelta exceed payable (floor of allowed)
            $allowedDelta = (int) floor((float) $data['total'] - $alreadyPaid);
            if ($paidDelta > 0 && $paidDelta > $allowedDelta) {
                $paidDelta = $allowedDelta;
            }
            // ⭐ Server-side return cap: never return more than already paid
            if ($paidDelta < 0 && abs($paidDelta) > $alreadyPaid) {
                $paidDelta = -$alreadyPaid;
            }

            DB::transaction(function () use ($data, $alreadyPaid, $paidDelta, $receiptForCaps) {
                /** @var LabReceipt $receipt */
                $receipt = $receiptForCaps;

                $newPaidTotal = (int) max(0, min($alreadyPaid + $paidDelta, (int) $data['total']));
                $newDue = (int) max(0, (int) $data['total'] - $newPaidTotal);

                $data['paid'] = $newPaidTotal;
                $data['due'] = $newDue;

                if ($data['due'] > $data['total']) {
                    throw new \Exception('Due Amount Not Greater then Total Amount');
                }

                $testRows = $data['test'] ?? [];
                $extraRows = $data['extra'] ?? [];

                $allRows = array_merge(
                    array_map(fn ($r) => ['kind' => 'test',  'row' => $r], $testRows),
                    array_map(fn ($r) => ['kind' => 'extra', 'row' => $r], $extraRows)
                );

                if ($paidDelta != 0) {
                    $allRows = $this->distributePaid($allRows, $paidDelta, (int) $data['total']);
                }

                $adjustTestIds = [];
                $adjustExtraIds = [];

                foreach ($allRows as $item) {
                    $row = $item['row'];
                    $row['lab_id'] = $receipt->id;
                    $row['tenant_id'] = $row['tenant_id'] ?? $receipt->tenant_id;

                    if ($item['kind'] === 'test') {
                        $row['from_lab_id'] = $data['from_lab_id'] ?? null;
                        $row['to_lab_id'] = $data['to_lab_id'] ?? null;

                        $existingId = $row['id'] ?? null;
                        if ($existingId && ! Str::isUuid($existingId)) {
                            unset($row['id']);
                            $existingId = null;
                        }

                        if (isset($row['id'])) {
                            unset($row['contract_commission'], $row['referral_commission']);
                        }

                        if ($existingId) {
                            $saved = LabTest::findOrFail($existingId);
                            $saved->update($row);
                        } else {
                            unset($row['id']);
                            $saved = LabTest::create($row);
                        }
                        $adjustTestIds[] = $saved->id;

                    } else {
                        $existingId = $row['id'] ?? null;
                        if ($existingId && ! Str::isUuid($existingId)) {
                            unset($row['id']);
                            $existingId = null;
                        }

                        if ($existingId) {
                            $saved = LabExtra::findOrFail($existingId);
                            $saved->update($row);
                        } else {
                            unset($row['id']);
                            $saved = LabExtra::create($row);
                        }
                        $adjustExtraIds[] = $saved->id;
                    }
                }

                $receipt->labTest()->whereNotIn('id', $adjustTestIds)->delete();
                $receipt->labExtra()->whereNotIn('id', $adjustExtraIds)->delete();

                if ($paidDelta != 0) {
                    $receipt->payments()->create([
                        'paid'         => $paidDelta,
                        'total'        => (int) $data['total'],
                        'discount'     => (int) $data['discount'],
                        'tax'          => (int) round($data['tax']),
                        'due'          => $newDue,
                        'payment_type' => $data['payment_type'],
                        'note'         => $data['payment_note'] ?? '',
                        'verified'     => ($data['payment_type'] == config('default.payment_type')) ? 1 : 0,
                    ]);
                }

                $receipt->update($data);
            });

            return response()->json(['type' => 'success', 'note' => 'Lab Receipt Update successfully', 'status' => 400], 201);

        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => 'Can not Update', 'status' => 400], 500);
        }
    }

    /* ═══════════════════════════════════════════════════════════
       DELETE / RESTORE / FORCE-DELETE
       ═══════════════════════════════════════════════════════════ */
    public function destroy($id): JsonResponse
    {
        isAccess('delete_lab_receipt');

        try {
            $ok = DB::transaction(function () use ($id) {
                $model = LabReceipt::find($id);
                if (! $model) {
                    return false;
                }

                // ⭐ No ->exists() — delete() on empty relation is a no-op
                $model->labTest()->delete();
                $model->labExtra()->delete();
                $model->payments()->delete();

                return $model->delete();
            });

            return $ok
                ? response()->json(['type' => 'success', 'note' => 'Update Success', 'status' => 400], 200)
                : response()->json(['type' => 'fail', 'note' => 'Can not Update', 'status' => 400], 200);

        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => 'Can not Update', 'status' => 400], 200);
        }
    }

    public function restore($id): JsonResponse
    {
        isAccess('restore_lab_receipt');

        try {
            DB::transaction(function () use ($id) {
                $model = LabReceipt::withTrashed()->findOrFail($id);
                $model->restore();

                $model->labTest()->withTrashed()->restore();
                $model->labExtra()->withTrashed()->restore();
                $model->payments()->withTrashed()->restore();
            });

            return response()->json(['type' => 'success', 'note' => 'Restore Success', 'status' => 400], 200);
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => 'Can\'t Restore '.$e->getMessage(), 'status' => 400], 200);
        }
    }

    public function forceDelete($id): JsonResponse
    {
        isAccess('force_delete_lab_receipt');

        try {
            DB::transaction(function () use ($id) {
                $model = LabReceipt::withTrashed()->findOrFail($id);

                $model->labTest()->withTrashed()->forceDelete();
                $model->labExtra()->withTrashed()->forceDelete();
                $model->payments()->withTrashed()->forceDelete();

                $model->forceDelete();
            });

            return response()->json(['type' => 'success', 'note' => 'Force Delete Success', 'status' => 400], 200);
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => 'Can\'t Force Delete', 'status' => 400], 200);
        }
    }

    /* ═══════════════════════════════════════════════════════════
       COLLECT (receipt view)
       ═══════════════════════════════════════════════════════════ */
    public function collect($id = null)
    {
        isAccess('show_lab_collect');

        try {
            $canDeliver = isPermission('deliver_lab_report');
            $canPay = isPermission('pay_lab_receipt');
            $canReturn = isPermission('return_lab_receipt');

            $data = $id
                ? LabReceipt::with([
                    'labTest' => fn ($e) => $e->withAppend(),
                    'labExtra' => fn ($e) => $e->withAppend(),
                    'patient:id,name,tenant_id,phone,email,age,dob,gender,blood_group,religion,nid_bc,address,district,thana',
                    'payments' => fn ($p) => $p->with(['creator:id,name,phone', 'method:id,name'])->orderByDesc('id'),
                    'referral:id,name,phone,designation',
                    'contract:id,name,phone,designation',
                    'fromLab:id,name',
                    'toLab:id,name',
                ])->findOrFail($id)
                : null;

            if ($data) {
                $this->primeTestConfigs($data);
            }

            return view('lab.lab_receipt.receipt', compact('data', 'canDeliver', 'canPay', 'canReturn'));
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return redirect()->back()->with([
                'header' => 'Receive Not Found',
                'message' => 'Invalid receipt. Please check the ID and try again!',
                'alert-type' => 'warning',
            ]);
        }
    }

    public function collectOld($id = null)
    {
        isAccess('show_lab_collect');

        try {
            $data = $id
                ? LabReceipt::with([
                    'labTest' => fn ($e) => $e->withAppend(),
                    'labExtra' => fn ($e) => $e->withAppend(),
                    'patient:id,name,tenant_id,phone,email,age,dob,gender,blood_group,religion,nid_bc,address,district,thana',
                    'payments' => fn ($p) => $p->with(['creator:id,name,phone', 'method:id,name'])->orderByDesc('id'),
                    'referral:id,name,phone,designation',
                    'contract:id,name,phone,designation',
                ])->findOrFail($id)
                : null;

            if ($data) {
                $this->primeTestConfigs($data);
            }

            return view('lab.lab_receipt.receipt', compact('data'));
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return redirect()->back()->with([
                'header' => 'Receive Not Found',
                'message' => 'Invalid receipt. Please check the ID and try again!',
                'alert-type' => 'warning',
            ]);
        }
    }

    /* ═══════════════════════════════════════════════════════════
       PAY
       ═══════════════════════════════════════════════════════════ */
    public function pay($id, Request $request): JsonResponse
    {
        isAccess('pay_lab_receipt');

        $canReturn = isPermission('return_lab_receipt');

        $validator = Validator::make($request->all(), [
            'paid' => [
                'required', 'numeric',
                function ($attribute, $value, $fail) use ($canReturn) {
                    $v = (float) $value;

                    if (! $canReturn && $v < 0) {
                        $fail($attribute.' - You have no permission to return.');
                    }
                },
            ],
            'due' => ['nullable', 'numeric'],
            'discount' => ['nullable', 'numeric'],
            'tax' => ['nullable', 'numeric'],
            'contract_commission' => ['nullable', 'numeric'],
            'payment_type' => ['required', 'integer_or_uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json(['type' => 'invalid', 'note' => $validator->messages(), 'status' => 400], 200);
        }

        $data = $validator->validated();

        try {
            /** @var LabReceipt $receipt */
            $receipt = LabReceipt::findOrFail($id);
            $alreadyPaid = (int) round((float) $receipt->paid);
            $total = (int) round((float) $receipt->total);
            $dueFromDb = max(0, $total - $alreadyPaid);

            // ⭐ User input — symmetric round
            $paidRaw = (float) $data['paid'];
            $paidDelta = $paidRaw < 0
                ? -(int) round(abs($paidRaw))
                : (int) round($paidRaw);

            // ⭐ Server-side cap: positive payment cannot exceed FLOOR of due
            if ($paidDelta > 0 && $paidDelta > $dueFromDb) {
                return response()->json([
                    'type' => 'invalid',
                    'note' => ['paid' => ['Pay amount cannot exceed due (৳'.number_format($dueFromDb).').']],
                    'status' => 400,
                ], 200);
            }

            // ⭐ Server-side return cap: cannot return more than already paid
            if ($paidDelta < 0 && abs($paidDelta) > $alreadyPaid) {
                return response()->json([
                    'type' => 'invalid',
                    'note' => ['paid' => ['Return amount cannot exceed the paid amount (৳'.number_format($alreadyPaid).').']],
                    'status' => 400,
                ], 200);
            }

            DB::transaction(function () use ($receipt, $data, $paidDelta, $alreadyPaid, $total, $request) {
                $newPaidTotal = (int) max(0, min($alreadyPaid + $paidDelta, $total));
                $newDue = (int) max(0, $total - $newPaidTotal);

                $receipt->update([
                    'paid' => $newPaidTotal,
                    'due' => $newDue,
                ]);

                // ⭐ Only insert payment row for non-zero delta
                if ($paidDelta != 0) {
                    $receipt->payments()->create([
                        'paid' => $paidDelta,   // ← delta, not cumulative
                        'total' => $total,
                        'discount' => (int) round($data['discount'] ?? $receipt->discount),
                        'tax' => (int) round($data['tax'] ?? $receipt->tax),
                        'due' => $newDue,
                        'payment_type' => $data['payment_type'],
                        'note' => $data['note'] ?? '',
                        'verified' => ($data['payment_type'] == config('default.payment_type')) ? 1 : 0,
                    ]);
                }

                // ─── Delivery handling (preserve your original behaviour) ───
                $deliver = $request->input('deliver', []);
                if (! empty($deliver)) {
                    $deliverIds = collect($deliver)
                        ->filter(fn ($row) => ! empty($row['check']))
                        ->keys()
                        ->all();

                    if (! empty($deliverIds)) {
                        $receipt->labTest()
                            ->whereIn('id', $deliverIds)
                            ->whereNull('delivered_by')
                            ->update([
                                'delivered_by' => auth()->id(),
                                'delivered_at' => now(),
                            ]);
                    }
                }
            });

            return response()->json(['type' => 'success', 'note' => 'Payment updated successfully', 'status' => 400], 201);

        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => 'Can not Update', 'status' => 400], 500);
        }
    }

    private function markDelivery(LabReceipt $receipt, array $deliverRows): void
    {
        try {
            $now = Carbon::now();
            $userId = auth()->id();

            foreach ($deliverRows as $row) {
                if (empty($row['check'])) {
                    continue;
                }

                $labTest = $receipt->labTest->firstWhere('id', $row['id']);
                if ($labTest && (! $labTest->delivered_by || ! $labTest->delivered_at)) {
                    $labTest->update([
                        'delivered_by' => $userId,
                        'delivered_at' => $now,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // silent — matches original behavior
        }
    }

    private function redistributePaid($items, float $totalPaidBefore, float $newTotalPaid, float $total): void
    {
        if (! $items || $items->isEmpty()) {
            return;
        }

        $count = $items->count();
        $allocated = 0.0;
        $index = 0;

        foreach ($items as $item) {
            $index++;
            $amount = (float) $item->amount;
            $oldPaid = (float) $item->paid;
            $hasIndivDiscount = ((float) $item->discount) > 0;

            if ($hasIndivDiscount && $totalPaidBefore > 0 && $oldPaid > 0) {
                $rawShare = ($oldPaid / $totalPaidBefore) * $newTotalPaid;
            } else {
                $rawShare = $total > 0 ? ($amount * $newTotalPaid) / $total : 0.0;
            }

            $newPaid = ($index === $count)
                ? round($newTotalPaid - $allocated, 2)
                : round($rawShare, 2);

            if ($newPaid < 0) {
                Log::warning('Negative paid redistribution', [
                    'new_total_paid' => $newTotalPaid,
                    'allocated' => $allocated,
                    'index' => $index,
                ]);
                $newPaid = 0.0;
            }

            $item->update(['paid' => $newPaid]);
            $allocated = round($allocated + $newPaid, 2);
        }
    }

    /* ═══════════════════════════════════════════════════════════
       REPORT TRACKING
       ═══════════════════════════════════════════════════════════ */
    public function reportTracking($uuid)
    {
        try {
            $data = LabReceipt::select(['id', 'uuid', 'tenant_id', 'barcode', 'sl_no', 'due', 'date', 'patient_id', 'referral_id', 'contract_id', 'visit_id'])
                ->with([
                    'labTest' => function ($q) {
                        $q->withAppend()->with(
                            'test:id,name',
                            'collector:id,name,profile_photo,designation',
                            'prepared:id,name,profile_photo,designation',
                            'reviewed:id,name,profile_photo,designation',
                            'creator:id,name,profile_photo,designation',
                            'sample',
                            'test.config:id,test_id,sample_type,physically,sample_collect_room'
                        );
                    },
                    'patient:id,name,tenant_id,phone,email,age,dob,gender,blood_group,religion,nid_bc,address,district,thana',
                ])
                ->whereAny(['uuid', 'id'], '=', $uuid)
                ->first();

            if ($data) {
                $this->primeTestConfigs($data);
            }

            return view('lab.lab_receipt.report-tracking')->with(['data' => $data ?? []]);
        } catch (\Throwable $e) {
            return redirect()->back()->with([
                'header' => 'Receive Not Found',
                'message' => 'Invalid Receive, Please try correct on !',
                'alert-type' => 'warning',
            ]);
        }
    }

    /* ═══════════════════════════════════════════════════════════
       PDF
       ═══════════════════════════════════════════════════════════ */

    /**
     * ⭐ Shared eager-load for all receipt PDFs.
     */
    private function receiptPdfRelations(bool $withCategory = false): array
    {
        $testWith = [
            'test:id,name'.($withCategory ? ',category_id' : ''),
            'test.config:id,test_id,sample_type,sample_collect_room_show,sample_collect_room,test_room,test_room_show',
        ];

        if ($withCategory) {
            $testWith[] = 'test.category:id,name';
        }

        return [
            'labTest' => fn ($q) => $q->with($testWith)->withAppend(),
            'labExtra' => fn ($q) => $q->withAppend(),
            'patient:id,name,tenant_id,reg_no,phone,email,age,dob,gender,blood_group,religion,nid_bc,address,district,thana',
            'payments',
            'payments.creator:id,name,phone',
            'payments.method:id,name',
            'referral:id,name,phone,designation',
            'contract:id,name,short_name,phone',
        ];
    }

    public function receiptPdf(Request $request, $id, $format = 'a5')
    {
        isAccess('show_lab_receipt');

        try {
            $data = LabReceipt::with($this->receiptPdfRelations(true))->findOrFail($id);

            $this->primeTestConfigs($data);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->back()->with([
                'header' => 'Receipt Not Found',
                'message' => 'Invalid Receipt, Please try again!',
                'alert-type' => 'warning',
            ]);
        }

        $copyFlags = [
            'duplicate_copy' => $request->boolean('duplicate'),
            'client_copy' => $request->boolean('client'),
            'lab_copy' => $request->boolean('lab'),
            'office_copy' => $request->boolean('office'),
            'category_wise_copy' => $request->boolean('category_wise'),
        ];

        if (! array_filter($copyFlags)) {
            $copyFlags['client_copy'] = true;
        }

        foreach ($copyFlags as $key => $value) {
            $data->{$key} = $value;
        }

        $data->skip_category_id = config('app.none_test_category');

        $configFromDb = json_decode(getSetting($data->tenant_id, 'lab_receipt_pdf') ?? '', true);

        $fileName = $data->sl_no ? "{$data->sl_no}_receipt.pdf" : 'receipt.pdf';

        $config = array_replace([
            'title' => $fileName,
            'format' => 'A5',
            'orientation' => 'P',
            'show_watermark' => true,
            'watermark' => ((float) ($data->due ?? 0)) > 0 ? 'DUE' : 'PAID',
            'watermark_font' => 'app_font',
        ], is_array($configFromDb) ? $configFromDb : []);

        return PDF::loadView('lab.theme.receipt.default.lab-receipt-dynamic', compact('data'), [], $config)
            ->stream($fileName);
    }

    public function labWorkPdf(Request $request, $id, $format = 'a5')
    {
        isAccess('show_lab_receipt');

        $all = $request->has('type') && $request->type == 'all';
        $physically = $request->has('physically') ? filter_var($request->physically, FILTER_VALIDATE_BOOLEAN) : false;
        $specific = $request->has('specific') ? (array) $request->specific : [];

        $data = LabReceipt::with([
            'labTest' => function ($q) use ($all, $physically, $specific) {
                $q->with([
                    'test:id,name',
                    'test.config:id,test_id,physically,sample_type,sample_collect_room_show,sample_collect_room,test_room,test_room_show',
                ])->withAppend();

                if (! empty($specific)) {
                    $q->whereIn('id', $specific);
                } elseif ($physically && ! $all) {
                    $q->whereHas('test.config', fn ($x) => $x->where('physically', 1));
                } elseif (! $all) {
                    $q->whereHas('test.config', fn ($x) => $x->where('physically', 0));
                }
            },
            'labExtra' => fn ($q) => $q->withAppend(),
            'patient:id,name,tenant_id,reg_no,phone,email,age,dob,gender,blood_group,religion,nid_bc,address,district,thana',
            'payments',
            'payments.creator:id,name,phone',
            'payments.method:id,name',
            'referral:id,name,phone,designation',
            'contract:id,name,phone,designation',
        ])->find($id);

        if (! $data) {
            return redirect()->back()->with([
                'header' => 'Receipt Not Found',
                'message' => 'The receipt you are trying to access does not exist.',
                'alert-type' => 'warning',
            ]);
        }

        $this->primeTestConfigs($data);

        if ($data->labTest->isEmpty()) {
            return redirect()->back()->with([
                'header' => 'No Tests Found',
                'message' => 'No Work List found with the selected filter criteria.',
                'alert-type' => 'warning',
            ]);
        }

        $fileName = $data->sl_no ? $data->sl_no.'_receipt_work.pdf' : 'receipt_work.pdf';

        return PDF::loadView('lab.theme.receipt.default.lab-receipt-work', compact('data'), [], [
            'title' => $fileName,
            'format' => 'A5',
            'orientation' => 'P',
        ])->stream($fileName);
    }

    public function receiptPdfDownload(Request $request, $uuid, $format = 'a5')
    {
        try {
            $data = LabReceipt::with($this->receiptPdfRelations(false))
                ->whereAny(['uuid', 'id'], '=', $uuid)
                ->firstOrFail();

            $this->primeTestConfigs($data);
        } catch (\Throwable $e) {
            return redirect()->back()->with([
                'header' => 'Receipt Not Found',
                'message' => 'Invalid Receipt, Please try again!',
                'alert-type' => 'warning',
            ]);
        }

        $configFromDb = json_decode(getSetting($data->tenant_id, 'lab_receipt_pdf') ?? '', true);

        $fileName = $data->sl_no ? "{$data->sl_no}_receipt.pdf" : 'receipt.pdf';

        $config = array_replace([
            'title' => $fileName,
            'format' => strtoupper($format),
            'orientation' => 'P',
            'show_watermark' => true,
            'watermark' => ((float) ($data->due ?? 0)) > 0 ? 'DUE' : 'PAID',
            'watermark_font' => 'app_font',
        ], is_array($configFromDb) ? $configFromDb : []);

        return PDF::loadView('lab.theme.receipt.default.lab-receipt-dynamic-outer', compact('data'), [], $config)
            ->stream($fileName);
    }

    /* ═══════════════════════════════════════════════════════════
       SEARCH
       ═══════════════════════════════════════════════════════════ */
    public function searchPatient(Request $request): JsonResponse
    {
        $query = Patient::query()->withoutAppend();

        if ($request->filled('district')) {
            $query->where('district', $request->district);
        }
        if ($request->filled('thana')) {
            $query->where('thana', $request->thana);
        }

        $search = trim((string) $request->input('term'));

        if ($search !== '') {
            // FULLTEXT term: প্রতিটা word এর শুরু match (min 3 char)
            $ftTerm = collect(preg_split('/[\s.\-]+/u', $search, -1, PREG_SPLIT_NO_EMPTY))
                ->map(fn ($w) => preg_replace('/[^\p{L}\p{N}]/u', '', $w))
                ->filter(fn ($w) => mb_strlen($w) >= 3)
                ->map(fn ($w) => '+'.$w.'*')
                ->implode(' ');

            $query->where(function ($q) use ($search, $ftTerm) {
                if ($ftTerm !== '') {
                    $q->whereRaw('MATCH(name) AGAINST (? IN BOOLEAN MODE)', [$ftTerm]);
                } else {
                    $q->where('name', 'LIKE', $search.'%');
                }

                $q->orWhere('email', 'LIKE', $search.'%')
                    ->orWhere('phone', 'LIKE', $search.'%')
                    ->orWhere('nid_bc', 'LIKE', $search.'%')
                    ->orWhere('reg_no', 'LIKE', $search.'%');
            });
        }

        $patients = $query->take(20)->select(['id', 'name', 'reg_no', 'email', 'phone'])->get();

        if ($patients->isEmpty() && $search !== '') {
            $receipts = LabReceipt::with([
                'patient' => fn ($q) => $q->withoutAppend()->select('id', 'name', 'reg_no', 'email', 'phone'),
            ])
                ->where(function ($q) use ($search) {
                    $q->where('barcode', 'LIKE', $search.'%')
                        ->orWhere('sl_no', 'LIKE', $search.'%');
                })
                ->take(20)
                ->get();

            $patients = $receipts->map->patient->filter()->values();

            if ($patients->isEmpty()) {
                $patients = [['name' => 'Add New', 'reg_no' => '', 'phone' => '', 'guardian_name' => '', 'id' => '0']];
            }
        }

        return response()->json($patients);
    }

    // ============================================================
    //  TEXT SEARCH (autocomplete)
    // ============================================================

    public function searchTest(Request $request): JsonResponse
    {
        return $this->runTestSearch($request, (int) $request->input('self', 1));
    }

    private function runTestSearch(Request $request, int $self): JsonResponse
    {
        $term = trim((string) $request->input('term', ''));

        // ⭐ Empty term → cached default list
        if ($term === '') {
            return response()->json($this->defaultTestList($self));
        }

        $tenantId = auth()->user()->tenant_id;
        $fromLabId = $request->input('from_lab_id');
        $normalized = mb_strtolower($term);
        $useCache = mb_strlen($normalized) >= 3;
        $cacheKey = 'search:'.md5($self.'|'.$tenantId.'|'.($fromLabId ?? 'null').'|'.$normalized);
        $cacheTtl = 600;

        $build = function () use ($term, $self, $tenantId, $fromLabId) {
            $words = collect(preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY))
                ->take(5)
                ->map(fn ($w) => preg_replace('/[+\-><()~*"@]/', ' ', $w))
                ->filter()
                ->map(fn ($w) => '+'.$w.'*')
                ->implode(' ');

            $query = Test::query()
                ->leftJoin('test_configs as tc', function ($j) use ($tenantId) {
                    $j->on('tc.test_id', '=', 'tests.id')
                        ->where('tc.tenant_id', $tenantId)
                        ->whereNull('tc.deleted_at');
                });

            if ($self === 1) {
                $query->where('tc.status', 1);
            } else {
                $query->whereRaw('COALESCE(tc.status, tests.status) = 1');
            }

            $rows = $query
                ->where(function ($q) use ($words, $term) {
                    $q->whereRaw('MATCH(tests.name, tests.short_name, tests.code) AGAINST (? IN BOOLEAN MODE)', [$words])
                        ->orWhereRaw('MATCH(tc.short_name) AGAINST (? IN BOOLEAN MODE)', [$words])
                        ->orWhere('tests.name', 'like', $term.'%')
                        ->orWhere('tests.short_name', 'like', $term.'%')
                        ->orWhere('tests.code', 'like', $term.'%');
                })
                ->orderByRaw('MATCH(tests.name, tests.short_name, tests.code) AGAINST (? IN BOOLEAN MODE) DESC', [$words])
                ->orderBy('tests.name')
                ->limit(20)
                ->get([
                    'tests.id',
                    'tests.code',
                    'tests.name',
                    DB::raw("COALESCE(NULLIF(tc.short_name, ''), tests.short_name) as short_name"),
                    DB::raw('COALESCE(tc.price, 0)     as config_price'),
                    DB::raw('COALESCE(tc.min_price, 0) as config_min_price'),
                    DB::raw('COALESCE(tc.lab_price, 0) as config_lab_price'),
                ]);

            $l2lPrices = [];
            if ($fromLabId && $rows->isNotEmpty()) {
                $l2lPrices = LabToLabPrice::where('lab_id', $fromLabId)
                    ->whereIn('test_id', $rows->pluck('id')->all())
                    ->active()
                    ->pluck('given_price', 'test_id')
                    ->all();
            }

            return $rows->map(function ($t) use ($l2lPrices, $fromLabId) {
                $short = trim((string) ($t->short_name ?? ''));
                $price = (float) $t->config_price;
                $min = (float) $t->config_min_price;

                if ($fromLabId) {
                    $l2l = $l2lPrices[$t->id] ?? null;
                    if ($l2l !== null) {
                        $price = $min = (float) $l2l;
                    } elseif ((float) $t->config_lab_price > 0) {
                        $price = $min = (float) $t->config_lab_price;
                    } elseif ($min > 0) {
                        $price = $min;
                    }
                }

                $display = $t->name;
                if ($short !== '') {
                    $pattern = '/\(\s*'.preg_quote($short, '/').'\s*\)\s*$/iu';
                    if (! preg_match($pattern, $display)) {
                        $display .= ' ('.$short.')';
                    }
                }
                if ($price > 0) {
                    $display .= ' - '.number_format($price, 2);
                }

                return [
                    'id' => $t->id,
                    'test_id' => $t->id,
                    'code' => $t->code,
                    'name' => $display,
                    'short_name' => $short,
                    'full_name' => $t->name,
                    'price' => round($price, 2),
                    'min_price' => round($min, 2),
                ];
            })->all();
        };

        $rows = $useCache
            ? PriceCache::rememberForTenant($tenantId, $cacheKey, $cacheTtl, $build)
            : $build();

        return response()->json($rows);
    }

    /**
     * ⭐ Cached default list for empty focus — 40 tests.
     * Shared across all users of the same tenant (key includes tenant + self).
     */
    private function defaultTestList(int $self): array
    {
        $tenantId = auth()->user()->tenant_id;
        $cacheKey = 'default_tests:'.md5($self.'|'.$tenantId);
        $cacheTtl = 600;   // 10 min — long enough to be fast, short enough to pick up edits

        return PriceCache::rememberForTenant($tenantId, $cacheKey, $cacheTtl, function () use ($tenantId, $self) {
            $q = Test::query()
                ->leftJoin('test_configs as tc', function ($j) use ($tenantId) {
                    $j->on('tc.test_id', '=', 'tests.id')
                        ->where('tc.tenant_id', $tenantId)
                        ->whereNull('tc.deleted_at');
                });

            if ($self === 1) {
                $q->where('tc.status', 1);
            } else {
                $q->whereRaw('COALESCE(tc.status, tests.status) = 1');
            }

            return $q->orderBy('tests.name')
                ->limit(40)
                ->get([
                    'tests.id',
                    'tests.code',
                    'tests.name',
                    DB::raw("COALESCE(NULLIF(tc.short_name, ''), tests.short_name) as short_name"),
                    DB::raw('COALESCE(tc.price, 0)     as config_price'),
                    DB::raw('COALESCE(tc.min_price, 0) as config_min_price'),
                ])
                ->map(function ($t) {
                    $short = trim((string) ($t->short_name ?? ''));
                    $price = (float) $t->config_price;
                    $min = (float) $t->config_min_price;

                    $display = $t->name;
                    if ($short !== '') {
                        $pattern = '/\(\s*'.preg_quote($short, '/').'\s*\)\s*$/iu';
                        if (! preg_match($pattern, $display)) {
                            $display .= ' ('.$short.')';
                        }
                    }
                    if ($price > 0) {
                        $display .= ' - '.number_format($price, 2);
                    }

                    return [
                        'id' => $t->id,
                        'test_id' => $t->id,
                        'code' => $t->code,
                        'name' => $display,
                        'short_name' => $short,
                        'full_name' => $t->name,
                        'price' => round($price, 2),
                        'min_price' => round($min, 2),
                    ];
                })
                ->all();
        });
    }

    // ============================================================
    //  GET ONE TEST (autocomplete select)
    // ============================================================

    public function getTest($id): JsonResponse
    {
        isAccess('search_test');

        $fromLabId = request()->input('from_lab_id');
        $tenantId = auth()->user()->tenant_id;

        $payload = PriceCache::rememberForTenant(
            $tenantId,
            "test:{$id}:from:".($fromLabId ?? 'null'),
            PriceCache::DEFAULT_TTL,
            fn () => $this->buildTestPayload($id, $fromLabId)
        );

        if ($payload === null) {
            return response()->json(['message' => 'Test not found'], 404);
        }

        $payload['v'] = PriceCache::versionForTenant($tenantId);
        $payload['id'] = $payload['id'] ?? $payload['test_id'] ?? $id;

        return response()->json($payload);
    }

    // ============================================================
    //  GET TEST BY CODE (length-aware)
    // ============================================================

    public function getTestByCode(Request $request): JsonResponse
    {
        isAccess('search_test');

        $code = trim((string) $request->input('code', ''));
        $max = Test::CODE_LENGTH;

        // Accept 1..N digits — '23', '023', '0023' all valid.
        if (! preg_match('/^\d{1,'.$max.'}$/', $code)) {
            return response()->json(['message' => 'Invalid code'], 422);
        }

        $padded = str_pad($code, $max, '0', STR_PAD_LEFT);

        $tenantId = auth()->user()->tenant_id;
        $fromLabId = $request->input('from_lab_id');
        $self = (int) $request->input('self', 1);

        $testId = Test::query()
            ->leftJoin('test_configs as tc', function ($j) use ($tenantId) {
                $j->on('tc.test_id', '=', 'tests.id')
                    ->where('tc.tenant_id', $tenantId)
                    ->whereNull('tc.deleted_at');
            })
            ->when($self === 1,
                fn ($q) => $q->where('tc.status', 1),
                fn ($q) => $q->whereRaw('COALESCE(tc.status, tests.status) = 1'))
            ->where(function ($q) use ($padded, $code) {
                $q->where('tests.code', $padded)
                    ->orWhere('tests.code', $code);   // legacy unpadded
            })
            ->value('tests.id');

        if (! $testId) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $payload = PriceCache::rememberForTenant(
            $tenantId,
            "test:{$testId}:from:".($fromLabId ?? 'null'),
            PriceCache::DEFAULT_TTL,
            fn () => $this->buildTestPayload($testId, $fromLabId)
        );

        if ($payload === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $payload['v'] = PriceCache::versionForTenant($tenantId);
        $payload['code'] = $padded;
        $payload['id'] = $payload['id'] ?? $testId;

        return response()->json($payload);
    }

    // ============================================================
    //  GET TESTS BY MULTIPLE CODES (batch paste)
    // ============================================================

    public function getTestsByCodes(Request $request): JsonResponse
    {
        isAccess('search_test');

        $max = Test::CODE_LENGTH;

        $codes = collect($request->input('codes', []))
            ->map(fn ($c) => trim((string) $c))
            ->filter(fn ($c) => preg_match('/^\d{1,'.$max.'}$/', $c))
            ->map(fn ($c) => str_pad($c, $max, '0', STR_PAD_LEFT))   // ⭐ normalize
            ->unique()
            ->take(50)
            ->values();

        if ($codes->isEmpty()) {
            return response()->json(['items' => [], 'missing' => []]);
        }

        $tenantId = auth()->user()->tenant_id;
        $fromLabId = $request->input('from_lab_id');
        $self = (int) $request->input('self', 1);

        $idsByCode = Test::query()
            ->leftJoin('test_configs as tc', function ($j) use ($tenantId) {
                $j->on('tc.test_id', '=', 'tests.id')
                    ->where('tc.tenant_id', $tenantId)
                    ->whereNull('tc.deleted_at');
            })
            ->when($self === 1,
                fn ($q) => $q->where('tc.status', 1),
                fn ($q) => $q->whereRaw('COALESCE(tc.status, tests.status) = 1'))
            ->whereIn('tests.code', $codes)
            ->pluck('tests.id', 'tests.code');

        $items = [];
        $missing = [];

        foreach ($codes as $code) {
            $id = $idsByCode[$code] ?? null;
            if (! $id) {
                $missing[] = $code;

                continue;
            }

            $payload = PriceCache::rememberForTenant(
                $tenantId,
                "test:{$id}:from:".($fromLabId ?? 'null'),
                PriceCache::DEFAULT_TTL,
                fn () => $this->buildTestPayload($id, $fromLabId)
            );

            if ($payload) {
                $payload['v'] = PriceCache::versionForTenant($tenantId);
                $payload['code'] = $code;
                $payload['id'] = $payload['id'] ?? $id;
                $items[] = $payload;
            } else {
                $missing[] = $code;
            }
        }

        return response()->json(['items' => $items, 'missing' => $missing]);
    }

    private function buildTestPayload(string $id, ?string $fromLabId): ?array
    {
        $test = Test::with([
            'config:id,tenant_id,test_id,short_name,price,min_price,lab_price,reporting_charge,referral_commission,contract_commission,sample_collect_room,sample_collect_room_show,sample_type',
            'consumptions:id,tenant_id,test_id,product_id,chargeable,discountable,quantity,individual,shareable',
            'consumptions.product:id,name,tenant_id,sale_price,min_price',
        ])->find($id);

        if (! $test) {
            return null;
        }

        $config = $test->config;
        $configPrice = (float) ($config?->price ?? 0);
        $configMin = (float) ($config?->min_price ?? 0);
        $configLab = $config?->lab_price !== null ? (float) $config->lab_price : null;

        $price = $configPrice;
        $min = $configMin;
        $src = 'regular';

        if ($fromLabId) {
            $l2l = LabToLabPrice::where('lab_id', $fromLabId)
                ->where('test_id', $id)
                ->active()
                ->first();

            if ($l2l && $l2l->given_price !== null) {
                $price = $min = (float) $l2l->given_price;
                $src = 'given_price';
            } elseif ($configLab !== null) {
                $price = $min = $configLab;
                $src = 'lab_price';
            } elseif ($configMin > 0) {
                $price = $min = $configMin;
                $src = 'min_price';
            }
        }

        $consumptions = $test->consumptions
            ->where('chargeable', true)
            ->map(fn ($c) => [
                'id' => '',
                'tenant_id' => $c->tenant_id,
                'test_id' => $c->test_id,
                'product_id' => $c->product_id,
                'quantity' => $c->quantity ?? 0,
                'individual' => $c->individual ?? 0,
                'shareable' => $c->shareable ?? 0,
                'discountable' => $c->discountable ?? 0,
                'product_name' => $c->product_name,
                'price' => $c->price,
                'min_price' => $c->min_price,
            ])
            ->values();

        return [
            'test_id' => $test->id,
            'tenant_id' => $config?->tenant_id ?? $test->tenant_id,
            'test_name' => $test->name,
            'short_name' => $config?->short_name ?? $test->short_name,
            'price' => $price,
            'min_price' => $min,
            'price_source' => $src,
            'config_price' => $configPrice,
            'config_min_price' => $configMin,
            'config_lab_price' => $configLab,
            'reporting_charge' => (float) ($config?->reporting_charge ?? 0),
            'referral_commission' => (float) ($config?->referral_commission ?? 0),
            'contract_commission' => (float) ($config?->contract_commission ?? 0),
            'consumptions' => $consumptions,
        ];
    }

    public function batchTestPrices(Request $request): JsonResponse
    {
        $ids = array_values(array_filter((array) $request->input('test_ids', [])));
        $from = $request->input('from_lab_id');
        $tenantId = auth()->user()->tenant_id;

        if (empty($ids)) {
            return response()->json([
                'prices' => [],
                'v' => PriceCache::versionForTenant($tenantId),
            ]);
        }

        sort($ids, SORT_STRING);
        $hash = md5(implode(',', $ids));

        $prices = PriceCache::rememberForTenant(
            $tenantId,
            "batch:{$hash}:from:{$from}",
            PriceCache::DEFAULT_TTL,
            fn () => LabPricingHelper::getBatchPrices($ids, $from)
        );

        return response()->json([
            'prices' => $prices,
            'v' => PriceCache::versionForTenant($tenantId),
        ]);
    }

    public function searchReceipt(Request $request): JsonResponse
    {
        isAccess('search_test');

        $search = trim((string) $request->input('term'));

        try {
            $map = fn ($model) => [
                'id' => $model->id,
                'name' => Str::limit($model->patient->name ?? 'Unknown', 20, '').' '.
                    ($model->barcode ?? '').' '.
                    ($model->total ?? 0).'TK Due:'.($model->due ?? 0).'Tk',
                'barcode' => $model->barcode ?? '',
                'sl_no' => $model->sl_no ?? '',
            ];

            // ⭐ Only select what `$map` actually uses
            $query = LabReceipt::select(['id', 'patient_id', 'barcode', 'sl_no', 'total', 'due'])
                ->with('patient:id,name,reg_no')
                ->latest()
                ->take(20);

            if (strlen($search) >= 3) {
                $isName = preg_match('/^\p{L}[\p{L}\s.\-]{4,}$/u', $search) && mb_strlen($search) >= 4;
                $isPhone = preg_match('/^\d{11}$/', $search);
                $isReg = strlen($search) >= 7;

                $query->where(function ($q) use ($search, $isName, $isPhone, $isReg) {
                    $q->where('barcode', 'like', $search.'%')->orWhere('barcode_rev', 'like', strrev($search).'%');

                    if ($isName || $isPhone || $isReg) {
                        $q->orWhereHas('patient', function ($p) use ($search, $isName, $isPhone, $isReg) {
                            $p->where(function ($w) use ($search, $isName, $isPhone, $isReg) {
                                if ($isName) {
                                    $w->orWhere('name', 'like', $search.'%');
                                }
                                if ($isPhone) {
                                    $w->orWhere('phone', $search);
                                }      // exact — indexed
                                if ($isReg) {
                                    $w->orWhere('reg_no', $search);
                                }     // exact — indexed
                            });
                        });
                    }
                });
            }

            return response()->json($query->get()->map($map));
        } catch (\Throwable $e) {
            \Log::error('searchReceipt failed', ['error' => $e->getMessage()]);

            return response()->json([], 500);
        }
    }

    public function getReceipt($id): JsonResponse
    {
        isAccess('show_lab_receipt');

        try {
            $data = LabReceipt::with([
                'labTest' => fn ($q) => $q
                    ->select('id', 'sl_no', 'tenant_id', 'lab_id', 'test_id', 'price', 'discount', 'amount', 'paid', 'min_price', 'wr', 'reporting_charge', 'referral_commission', 'contract_commission', 'note', 'duration')
                    ->withAppend(),
                'labExtra' => fn ($q) => $q
                    ->select('id', 'tenant_id', 'lab_id', 'product_id', 'quantity', 'min_price', 'price', 'discount', 'amount', 'paid', 'note')
                    ->withAppend(),
                'patient' => fn ($q) => $q
                    ->select('id', 'name', 'tenant_id', 'phone', 'email', 'age', 'dob', 'gender', 'blood_group', 'religion', 'nid_bc', 'address', 'district', 'thana')
                    ->withAppend(),
                'labTest.test:id,category_id,name,details,status',
                'labTest.test.config:id,tenant_id,test_id,short_name,reporting_charge',
                'referral:id,name,phone,short_name',
                'contract:id,name,phone,short_name',
                'payments:id,payable_id,payable_type,tenant_id,total,discount,tax,paid,due,payment_type,note,verified,created_by,created_at',
                'payments.creator:id,tenant_id,name',
                'payments.method:id,name,tenant_id,status',
            ])->findOrFail($id);

            $this->primeTestConfigs($data);

            return response()->json($data);
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => 'Can not Found Data', 'status' => 400], 500);
        }
    }

    public function revision($id)
    {
        isAccess('revision_lab_receipt');

        $modelData = LabReceipt::withCount('versions')->findOrFail($id);

        if ($modelData->versions_count <= 1) {
            abort(404, 'No revisions found.');
        }

        return view('revision.revisions', [
            'modelData' => $modelData,
            'title' => 'Lab Receipt',
        ]);
    }

    public function updatePC($id): void
    {
        try {
            $receipt = LabReceipt::find($id);

            if (! $receipt) {
                return;
            }

            // ⭐ SQL aggregation — no full model hydration
            $pcSum = (float) $receipt->children()->sum('contract_commission');
            $rtcSum = (float) $receipt->children()->where('wr', 0)->sum('reporting_charge');

            $receipt->update([
                'contract_commission' => round($pcSum - $rtcSum - ((float) ($receipt->due ?? 0)), 2),
            ]);
        } catch (\Throwable $e) {
            // silent — matches original behavior
        }
    }

    /* ═══════════════════════════════════════════════════════════
       HELPER — PRIME TEST CONFIGS
       ═══════════════════════════════════════════════════════════ */

    /**
     * ⭐ Prime the default-tenant TestConfig cache for all tests in a receipt.
     * MUST be called before any accessor that reads `$test->config`.
     * Per-request memoized to avoid re-priming the same IDs.
     */
    private function primeTestConfigs(LabReceipt $receipt): void
    {
        if (! $receipt->relationLoaded('labTest')) {
            return;
        }

        $testIds = $receipt->labTest
            ->pluck('test_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($testIds)) {
            return;
        }

        $new = array_values(array_diff($testIds, self::$primedTestIds));

        if (empty($new)) {
            return;
        }

        Test::primeDefaultConfigCache($new, config('app.default_tenant_id'));

        self::$primedTestIds = array_merge(self::$primedTestIds, $new);
    }
}
