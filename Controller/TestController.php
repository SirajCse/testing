<?php

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Models\LabTest;
use App\Models\Product;
use App\Models\Test;
use App\Models\TestCategory;
use App\Models\TestComponent;
use App\Models\TestComponentTenant;
use App\Models\TestConfig;
use App\Models\TestConsumption;
use App\Models\User;
use Carbon\Carbon;
use DataTables;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TestController extends Controller
{
    public function index(): View|Application|Factory|\Illuminate\Contracts\Foundation\Application
    {
        isAccess('view_lab_test');

        return view('lab.test.index');
    }

    public function ajax(Request $request)
    {
        isAccess('view_lab_test');

        try {
            if (auth()->user()->hasAnyPermission(['restore_lab_test', 'force_delete_lab_test'])) {
                $model = Test::query()->with(['category:id,name', 'config', 'creator:id,name', 'updater:id,name'])->withCount('components')->withTrashed();
            } else {
                $model = Test::query()->with(['category:id,name', 'config', 'creator:id,name', 'updater:id,name'])->withCount('components');
            }
        } catch (\Throwable $e) {
            $model = Test::query()->with(['category:id,name', 'config', 'creator:id,name', 'updater:id,name'])->withCount('components');
        }

        $canEdit = isPermission('config_test');
        $authTenantId = auth()->user()->tenant_id;
        $isDefaultTenant = ($authTenantId == config('app.default_tenant_id'));

        $model = $model->selectRaw('tests.*');

        $datatable = DataTables::eloquent($model);
        $datatable
            ->filterColumn('updater.name', function ($query, $keyword) {
                $query->whereHas('updater', function ($query) use ($keyword) {
                    $query->where('users.name', 'LIKE', '%'.filter_var($keyword, FILTER_SANITIZE_STRING).'%');
                });
            })
            ->filterColumn('status', function ($query, $keyword) {
                $query->whereHas('config', function ($query) use ($keyword) {
                    $query->where('status', 'LIKE', '%'.filter_var($keyword, FILTER_SANITIZE_STRING).'%');
                });
            })
            ->filterColumn('category.name', function ($query, $keyword) {
                $query->where('category_id', 'LIKE', '%'.filter_var($keyword, FILTER_SANITIZE_STRING).'%');
            });

        return $datatable
            ->editColumn('config.price', fn ($row) => $row->config->price ?? 0)
            ->editColumn('config.min_price', fn ($row) => $row->config->min_price ?? 0)
            ->editColumn('status', function ($row) use ($isDefaultTenant, $canEdit) {
                $isActive = $isDefaultTenant
                    ? (bool) $row->getRawOriginal('status')
                    : (bool) ($row->config?->status ?? $row->getRawOriginal('status'));

                $checked = $isActive ? 'checked' : '';
                $disabled = $canEdit ? '' : 'disabled';
                $id = $row->id;

                return '<div class="custom-control custom-switch custom-switch-primary d-inline-block">'.
                    '<input type="checkbox" class="custom-control-input" id="status_switch_'.$id.'" onclick="statusToggle(\''.$id.'\', this)" '.$checked.' '.$disabled.'>'.
                    '<label class="custom-control-label" for="status_switch_'.$id.'">'.
                    '<span class="switch-icon-left"><i class="fas fa-check"></i></span>'.
                    '<span class="switch-icon-right"><i class="fas fa-times"></i></span>'.
                    '</label>'.
                    '</div>';
            })
            ->rawColumns(['name', 'action', 'status'])
            ->editColumn('details', fn ($row) => Str::limit(@$row->details, 20))
            ->editColumn('name', fn ($row) => $row->name.'('.$row->components_count.')')
            ->editColumn('short_name', fn ($row) => ($row->config->short_name ?? $row->short_name ?? '')
                .($row->code ? '('.$row->code.')' : '')
            )
            ->addColumn('action', fn ($row) => view('lab.test.action', compact('row')))
            ->rawColumns(['name', 'status'])
            ->toJson();
    }

    public function show($id): JsonResponse
    {
        isAccess('show_lab_test');

        try {
            $tenantId = auth()->user()->tenant_id ?? webTenant();
            $isDefaultTenant = ($tenantId == config('app.default_tenant_id'));

            $data = Test::with([
                'category:id,name',
                'config',
                'consumptions.product:id,name,tenant_id',
                'components' => function ($q) use ($tenantId) {
                    $q->select(
                        'id', 'tenant_id', 'test_id', 'name', 'short_name', 'type', 'option_value',
                        'default_value', 'mic_value', 'zone_value', 'hide',
                        'unit', 'convert_unit', 'factor', 'order', 'status',
                        'mode', 'align', 'class', 'used', 'pattern', 'style', 'reference_text'
                    )->with([
                        'references',
                        'override' => fn ($o) => $o->where('tenant_id', $tenantId),
                    ]);
                },
            ])->findOrFail($id);

            $data->components->each(function ($c) use ($isDefaultTenant) {
                $o = $c->override;

                $c->tenant_unit = $o->unit ?? null;
                $c->tenant_convert_unit = $o->convert_unit ?? null;
                $c->tenant_factor = $o->factor ?? null;
                $c->tenant_reference_text = $o->reference_text ?? null;
                $c->tenant_default_value = $o->default_value ?? null;
                $c->tenant_option_value = $o->option_value ?? null;
                $c->tenant_mic_value = $o->mic_value ?? null;
                $c->tenant_zone_value = $o->zone_value ?? null;
                $c->tenant_hide = $o->hide ?? null;
                $c->tenant_order = $o->order ?? null;
                $c->tenant_status = $o->status ?? 1;

                if (! $isDefaultTenant) {
                    $c->unit = $o->unit ?? $c->unit;
                    $c->convert_unit = $o->convert_unit ?? $c->convert_unit;
                    $c->factor = $o->factor ?? $c->factor;
                    $c->reference_text = $o->reference_text ?? $c->reference_text;
                    $c->default_value = $o->default_value ?? $c->default_value;
                    $c->option_value = $o->option_value ?? $c->option_value;
                    $c->mic_value = $o->mic_value ?? $c->mic_value;
                    $c->zone_value = $o->zone_value ?? $c->zone_value;
                    $c->hide = $o->hide ?? $c->hide;
                    $c->order = $o->order ?? $c->order;
                    $c->status = $o->status ?? $c->status;
                }
            });

            $data->setRelation(
                'components',
                $data->components
                    ->sortBy(fn ($c) => (int) ($c->order ?? 9999))
                    ->values()
            );

            $data->tenant_id = $tenantId;

            return response()->json($data);

        } catch (\Throwable $e) {
            \Log::error($e->getMessage());
        }

        return response()->json(['type' => 'fail', 'note' => 'Can not Found Data', 'status' => 400], 500);
    }

    public function store(Request $request): JsonResponse
    {
        isAccess('create_lab_test');

        $validator = Validator::make($request->all(), [
            'tests.name' => ['required', 'string', 'max:191', Rule::unique('tests')],
            'tests.details' => ['nullable', 'string', 'max:500'],
            'tests.category_id' => ['required', 'integer_or_uuid'],
            'tests.short_name' => ['nullable', 'string', 'max:100'],
            'tests.order' => ['nullable', 'numeric'],
            'tests.status' => ['nullable', 'numeric'],

            'test_configs.theme' => ['nullable', 'string', 'max:100'],
            'test_configs.precautions' => ['nullable', 'string', 'max:500'],
            'test_configs.price' => ['required', 'numeric'],
            'test_configs.lab_price' => ['nullable', 'numeric', 'min:0'],
            'test_configs.min_price' => ['nullable', 'numeric'],
            'test_configs.reporting_charge' => ['nullable', 'numeric'],
            'test_configs.referral_commission' => ['nullable', 'numeric'],
            'test_configs.contract_commission' => ['nullable', 'numeric'],
            'test_configs.sample_type' => ['nullable', 'string', 'max:191'],
            'test_configs.sample_collect_room' => ['nullable', 'string', 'max:191'],
            'test_configs.sample_collect_room_show' => ['nullable', 'numeric'],
            'test_configs.test_room_show' => ['nullable', 'numeric'],
            'test_configs.test_room' => ['nullable', 'string', 'max:191'],
            'test_configs.is_same_page' => ['nullable', 'numeric'],
            'test_configs.physically' => ['nullable', 'numeric'],
            'test_configs.thead' => ['nullable', 'numeric'],
            'test_configs.show_title' => ['nullable', 'numeric'],
            'test_configs.show_result' => ['nullable', 'numeric'],
            'test_configs.show_reference' => ['nullable', 'numeric'],
            'test_configs.show_status' => ['nullable', 'numeric'],
            'test_configs.auto_result' => ['nullable', 'numeric'],
            'test_configs.result_color' => ['nullable', 'numeric'],
            'test_configs.number_table' => ['nullable', 'numeric'],
            'test_configs.is_separated' => ['nullable', 'numeric'],
            'test_configs.culture_show_class' => ['nullable', 'numeric'],
            'test_configs.culture_show_mic' => ['nullable', 'numeric'],
            'test_configs.culture_show_zone' => ['nullable', 'numeric'],
            'test_configs.is_culture' => ['nullable', 'numeric'],
            'test_configs.custom_js' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['type' => 'invalid', 'note' => $validator->messages(), 'status' => 400], 200);
        }

        $data = $validator->validated();

        try {
            DB::transaction(function () use ($data) {
                $model = Test::create($data['tests']);
                $data['test_configs']['short_name'] = @$data['tests']['short_name'] ?? null;
                $data['test_configs']['status'] = @$data['tests']['status'] ?? 1;
                $data['test_configs']['order'] = @$data['tests']['order'] ?? 0;
                $data['test_configs']['sample_type'] = @$data['tests']['sample_type'] ?? '';
                $model->configWithoutDefault()->create($data['test_configs']);
            });

            return response()->json(['type' => 'success', 'note' => 'Test created successfully', 'status' => 400], 201);
        } catch (\Throwable $e) {
            \Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => 'Can not Create', 'status' => 400], 500);
        }
    }

    public function edit($id): View|Application|Factory|\Illuminate\Contracts\Foundation\Application|RedirectResponse
    {
        isAccess('config_test');

        $tenantId = auth()->user()->tenant_id ?? webTenant();
        $isDefaultTenant = ($tenantId == config('app.default_tenant_id'));

        try {
            $test = Test::with([
                'category:id,name',
                'config',
                'components' => function ($q) use ($tenantId) {
                    $q->with([
                        'references' => fn ($r) => $r->withTrashed(),
                        'override' => fn ($o) => $o->where('tenant_id', $tenantId),
                    ]);
                },
                'consumptions.product:id,name',
            ])->withTrashed()->findOrFail($id);

            $test->components->each(function ($c) {
                $o = $c->override;

                $c->tenant_unit = $o->unit ?? null;
                $c->tenant_convert_unit = $o->convert_unit ?? null;
                $c->tenant_factor = $o->factor ?? null;
                $c->tenant_reference_text = $o->reference_text ?? null;
                $c->tenant_default_value = $o->default_value ?? null;
                $c->tenant_option_value = $o->option_value ?? null;
                $c->tenant_mic_value = $o->mic_value ?? null;
                $c->tenant_zone_value = $o->zone_value ?? null;
                $c->tenant_hide = $o->hide ?? null;
                $c->tenant_order = $o->order ?? null;
                $c->tenant_status = $o->status ?? 1;
            });

            $test->setRelation(
                'components',
                $test->components
                    ->sortBy(fn ($c) => (int) ($c->order ?? 9999))
                    ->values()
            );

            return view('lab.test.edit', [
                'test' => $test,
                'isDefaultTenant' => $isDefaultTenant,
                'tenantId' => $tenantId,
            ]);

        } catch (\Throwable $e) {
            \Log::error($e->getMessage());

            return redirect()->route('lab.test.index')->with([
                'header' => 'Not Found',
                'message' => 'Test not found.',
                'alert-type' => 'warning',
            ]);
        }
    }

    public function editUpdate(Request $request, $id): JsonResponse
    {
        isAccess('config_test');

        $tenantId = auth()->user()->tenant_id ?? webTenant();
        $isDefaultTenant = ($tenantId == config('app.default_tenant_id'));

        $hasAddComponent = isPermission('add_component');
        $hasEditComponent = isPermission('edit_component');
        $canConsumption = isPermission('consumption_lab_test');

        $test = Test::withTrashed()->findOrFail($id);
        $isTestEmpty = ! $test->components()->exists();

        /* ───────────── PERMISSION FLAGS ───────────── */

        /* Components — master (test_components) */
        $canEditMasterComponents = $isDefaultTenant;
        $canAddMasterComponents = $isDefaultTenant && $hasAddComponent;
        $canBootstrapMasterComponents = ! $isDefaultTenant && $hasAddComponent && $isTestEmpty;

        /* Components — tenant overrides (test_component_tenants) */
        $canEditTenantOverrides = $hasAddComponent || $hasEditComponent;

        /* Consumptions + test/config */
        $canEditConsumptions = $canConsumption;

        $canEditTestRow = $isDefaultTenant;
        $canEditTestConfig = isPermission('config_test');

        /* ───────────── NORMALIZE PAYLOAD ───────────── */

        if (! $canEditTestRow) {
            $request->request->remove('tests');
        }

        if (! $canEditTestConfig) {
            $request->request->remove('test_configs');
        }

        if (! $isDefaultTenant) {
            $sanitized = [];

            foreach ($request->input('component', []) as $idx => $c) {

                $sanitized[$idx] = [
                    'id' => $c['id'] ?? null,

                    'tenant_unit' => $c['tenant_unit'] ?? $c['unit'] ?? null,
                    'tenant_convert_unit' => $c['tenant_convert_unit'] ?? $c['convert_unit'] ?? null,
                    'tenant_factor' => $c['tenant_factor'] ?? $c['factor'] ?? null,
                    'tenant_reference_text' => $c['tenant_reference_text'] ?? $c['reference_text'] ?? null,
                    'tenant_default_value' => $c['tenant_default_value'] ?? $c['default_value'] ?? null,
                    'tenant_option_value' => $c['tenant_option_value'] ?? $c['option_value'] ?? null,
                    'tenant_mic_value' => $c['tenant_mic_value'] ?? $c['mic_value'] ?? null,
                    'tenant_zone_value' => $c['tenant_zone_value'] ?? $c['zone_value'] ?? null,
                    'tenant_hide' => $c['tenant_hide'] ?? $c['hide'] ?? 0,
                    'tenant_order' => $c['order'] ?? 0,
                    'tenant_status' => $c['status'] ?? 1,
                ];

                /* Bootstrap: keep master-level fields so we can write into test_components */
                if ($canBootstrapMasterComponents) {
                    $sanitized[$idx]['name'] = $c['name'] ?? '';
                    $sanitized[$idx]['type'] = $c['type'] ?? 'number';
                    $sanitized[$idx]['unit'] = $c['unit'] ?? null;
                    $sanitized[$idx]['convert_unit'] = $c['convert_unit'] ?? null;
                    $sanitized[$idx]['factor'] = $c['factor'] ?? null;
                    $sanitized[$idx]['reference_text'] = $c['reference_text'] ?? null;
                    $sanitized[$idx]['default_value'] = $c['default_value'] ?? null;
                    $sanitized[$idx]['option_value'] = $c['option_value'] ?? null;
                    $sanitized[$idx]['mic_value'] = $c['mic_value'] ?? null;
                    $sanitized[$idx]['zone_value'] = $c['zone_value'] ?? null;
                    $sanitized[$idx]['hide'] = $c['hide'] ?? 0;
                    $sanitized[$idx]['order'] = $c['order'] ?? 0;
                    $sanitized[$idx]['status'] = $c['status'] ?? 1;

                    $sanitized[$idx]['mode'] = $c['mode'] ?? null;
                    $sanitized[$idx]['align'] = $c['align'] ?? null;
                    $sanitized[$idx]['class'] = $c['class'] ?? null;
                    $sanitized[$idx]['style'] = $c['style'] ?? null;
                    $sanitized[$idx]['used'] = $c['used'] ?? null;
                    $sanitized[$idx]['pattern'] = $c['pattern'] ?? null;
                }
            }

            $request->merge(['component' => $sanitized]);
        }

        /* Master tenant without add permission → only existing IDs allowed */
        if ($isDefaultTenant && ! $canAddMasterComponents) {
            $existing = collect($request->input('component', []))
                ->filter(fn ($c) => ! empty($c['id']))
                ->values()
                ->all();

            $request->merge(['component' => $existing]);
        }

        /* Non-default tenant without override permission → drop components entirely */
        if (! $isDefaultTenant && ! $canEditTenantOverrides && ! $canBootstrapMasterComponents) {
            $request->request->remove('component');
        }

        if (! $canEditConsumptions) {
            $request->request->remove('consumption');
        }

        /* ───────────── VALIDATION RULES ───────────── */

        $rules = [];

        /* 1. tests table — master tenant only */
        if ($canEditTestRow) {
            $rules = array_merge($rules, [
                'tests.id' => ['required', 'integer_or_uuid'],
                'tests.name' => ['nullable', 'string', 'max:191', Rule::unique('tests')->ignore($id)],
                'tests.category_id' => ['nullable', 'integer_or_uuid'],
                'tests.short_name' => ['nullable', 'string', 'max:50'],
                'tests.order' => ['nullable', 'numeric'],
                'tests.status' => ['nullable', 'numeric'],
                'tests.details' => ['nullable', 'string', 'max:500'],
            ]);
        }

        /* 2. test_configs — anyone with config_test permission (tenant-scoped row) */
        if ($canEditTestConfig) {
            $rules = array_merge($rules, [
                'test_configs.short_name' => ['nullable', 'string', 'max:100'],
                'test_configs.order' => ['nullable', 'numeric'],
                'test_configs.status' => ['nullable', 'numeric'],
                'test_configs.theme' => ['nullable', 'string', 'max:100'],
                'test_configs.precautions' => ['nullable', 'string', 'max:500'],
                'test_configs.price' => ['nullable', 'numeric'],
                'test_configs.min_price' => ['nullable', 'numeric'],
                'test_configs.lab_price' => ['nullable', 'numeric', 'min:0'],
                'test_configs.reporting_charge' => ['nullable', 'numeric'],
                'test_configs.referral_commission' => ['nullable', 'numeric'],
                'test_configs.contract_commission' => ['nullable', 'numeric'],
                'test_configs.sample_type' => ['nullable', 'string', 'max:191'],
                'test_configs.sample_collect_room' => ['nullable', 'string', 'max:191'],
                'test_configs.sample_collect_room_show' => ['nullable', 'numeric'],
                'test_configs.test_room' => ['nullable', 'string', 'max:191'],
                'test_configs.test_room_show' => ['nullable', 'numeric'],
                'test_configs.is_same_page' => ['nullable', 'numeric'],
                'test_configs.physically' => ['nullable', 'numeric'],
                'test_configs.thead' => ['nullable', 'numeric'],
                'test_configs.show_title' => ['nullable', 'numeric'],
                'test_configs.show_result' => ['nullable', 'numeric'],
                'test_configs.show_reference' => ['nullable', 'numeric'],
                'test_configs.show_status' => ['nullable', 'numeric'],
                'test_configs.auto_result' => ['nullable', 'numeric'],
                'test_configs.result_color' => ['nullable', 'numeric'],
                'test_configs.number_table' => ['nullable', 'numeric'],
                'test_configs.is_separated' => ['nullable', 'numeric'],
                'test_configs.culture_show_class' => ['nullable', 'numeric'],
                'test_configs.culture_show_mic' => ['nullable', 'numeric'],
                'test_configs.culture_show_zone' => ['nullable', 'numeric'],
                'test_configs.is_culture' => ['nullable', 'numeric'],
                'test_configs.custom_js' => ['nullable', 'string', 'max:5000'],
            ]);
        }

        /* 3. Master components — default tenant */
        if ($isDefaultTenant) {
            $rules = array_merge($rules, [
                'component' => ['nullable', 'array'],
                'component.*.id' => ['nullable', 'integer_or_uuid'],
                'component.*.order' => ['nullable', 'numeric'],
                'component.*.name' => ['required', 'string', 'max:191'],
                'component.*.type' => ['nullable', 'string', 'max:191'],
                'component.*.option_value' => ['nullable', 'string'],
                'component.*.default_value' => ['nullable', 'string', 'max:191'],
                'component.*.mic_value' => ['nullable', 'string', 'max:191'],
                'component.*.zone_value' => ['nullable', 'string', 'max:191'],
                'component.*.hide' => ['nullable', 'numeric'],
                'component.*.unit' => ['nullable', 'string', 'max:191'],
                'component.*.convert_unit' => ['nullable', 'string', 'max:191'],
                'component.*.factor' => ['nullable', 'numeric'],
                'component.*.mode' => ['nullable', 'string', 'max:191'],
                'component.*.align' => ['nullable', 'string', 'max:191'],
                'component.*.class' => ['nullable', 'string', 'max:191'],
                'component.*.style' => ['nullable', 'string', 'max:191'],
                'component.*.used' => ['nullable', 'string', 'max:191'],
                'component.*.reference_text' => ['nullable', 'string', 'max:500'],

                'component.*.reference' => ['nullable', 'array'],
                'component.*.reference.*.id' => ['nullable', 'string', 'max:191'],
                'component.*.reference.*.type' => ['nullable', 'string', 'max:191'],
                'component.*.reference.*.gender' => ['nullable', 'string', 'max:191'],
                'component.*.reference.*.unit' => ['nullable', 'string', 'max:191'],
                'component.*.reference.*.age_min' => ['nullable', 'numeric'],
                'component.*.reference.*.age_max' => ['nullable', 'numeric'],
                'component.*.reference.*.age_unit' => ['nullable', 'string', 'max:191'],
                'component.*.reference.*.min' => ['nullable', 'string', 'max:191'],
                'component.*.reference.*.max' => ['nullable', 'string', 'max:191'],
                'component.*.reference.*.status' => ['nullable', 'string', 'max:191'],
                'component.*.reference.*.color' => ['nullable', 'string', 'max:191'],
            ]);
        }

        /* 4. Bootstrap components — non-default tenant, empty test */
        if ($canBootstrapMasterComponents) {
            $rules = array_merge($rules, [
                'component' => ['nullable', 'array'],
                'component.*.id' => ['nullable', 'integer_or_uuid'],
                'component.*.order' => ['nullable', 'numeric'],
                'component.*.name' => ['required', 'string', 'max:191'],
                'component.*.type' => ['nullable', 'string', 'max:191'],
                'component.*.option_value' => ['nullable', 'string'],
                'component.*.default_value' => ['nullable', 'string', 'max:191'],
                'component.*.mic_value' => ['nullable', 'string', 'max:191'],
                'component.*.zone_value' => ['nullable', 'string', 'max:191'],
                'component.*.hide' => ['nullable', 'numeric'],
                'component.*.unit' => ['nullable', 'string', 'max:191'],
                'component.*.convert_unit' => ['nullable', 'string', 'max:191'],
                'component.*.factor' => ['nullable', 'numeric'],
                'component.*.mode' => ['nullable', 'string', 'max:191'],
                'component.*.align' => ['nullable', 'string', 'max:191'],
                'component.*.class' => ['nullable', 'string', 'max:191'],
                'component.*.style' => ['nullable', 'string', 'max:191'],
                'component.*.used' => ['nullable', 'string', 'max:191'],
                'component.*.reference_text' => ['nullable', 'string', 'max:500'],

                'component.*.reference' => ['nullable', 'array'],
                'component.*.reference.*.id' => ['nullable', 'string', 'max:191'],
                'component.*.reference.*.type' => ['nullable', 'string', 'max:191'],
                'component.*.reference.*.gender' => ['nullable', 'string', 'max:191'],
                'component.*.reference.*.unit' => ['nullable', 'string', 'max:191'],
                'component.*.reference.*.age_min' => ['nullable', 'numeric'],
                'component.*.reference.*.age_max' => ['nullable', 'numeric'],
                'component.*.reference.*.age_unit' => ['nullable', 'string', 'max:191'],
                'component.*.reference.*.min' => ['nullable', 'string', 'max:191'],
                'component.*.reference.*.max' => ['nullable', 'string', 'max:191'],
                'component.*.reference.*.status' => ['nullable', 'string', 'max:191'],
                'component.*.reference.*.color' => ['nullable', 'string', 'max:191'],
            ]);
        }

        /* 5. Tenant overrides — always */
        $rules = array_merge($rules, [
            'component' => ['nullable', 'array'],
            'component.*.id' => ['nullable', 'integer_or_uuid'],
            'component.*.tenant_unit' => ['nullable', 'string', 'max:50'],
            'component.*.tenant_convert_unit' => ['nullable', 'string', 'max:50'],
            'component.*.tenant_factor' => ['nullable', 'numeric'],
            'component.*.tenant_reference_text' => ['nullable', 'string', 'max:500'],
            'component.*.tenant_default_value' => ['nullable', 'string', 'max:191'],
            'component.*.tenant_option_value' => ['nullable', 'string'],
            'component.*.tenant_mic_value' => ['nullable', 'string', 'max:191'],
            'component.*.tenant_zone_value' => ['nullable', 'string', 'max:191'],
            'component.*.tenant_hide' => ['nullable', 'numeric'],
            'component.*.tenant_order' => ['nullable', 'numeric'],
            'component.*.tenant_status' => ['nullable', 'numeric'],
        ]);

        /* 6. Consumptions — anyone with permission (any tenant) */
        if ($canEditConsumptions) {
            $rules = array_merge($rules, [
                'consumption' => ['nullable', 'array'],
                'consumption.*.id' => ['nullable', 'integer_or_uuid'],
                'consumption.*.product_id' => ['required', 'integer_or_uuid'],
                'consumption.*.quantity' => ['required', 'numeric'],
                'consumption.*.chargeable' => ['nullable', 'numeric'],
                'consumption.*.discountable' => ['nullable', 'numeric'],
                'consumption.*.individual' => ['nullable', 'numeric'],
                'consumption.*.shareable' => ['nullable', 'numeric'],
                'consumption.*.is_label' => ['nullable', 'numeric'],
                'consumption.*.is_box' => ['nullable', 'numeric'],
                'consumption.*.timing' => ['nullable'],
            ]);
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'type' => 'invalid',
                'note' => $validator->messages(),
                'status' => 400,
            ], 200);
        }

        $data = $validator->validated();

        /* ───────────── SAVE ───────────── */

        try {
            DB::transaction(function () use (
                $data,
                $test,
                $tenantId,
                $isDefaultTenant,
                $canEditTestRow,
                $canEditTestConfig,
                $canEditMasterComponents,
                $canAddMasterComponents,
                $canBootstrapMasterComponents,
                $canEditTenantOverrides,
                $canEditConsumptions
            ) {

                /* 1. TESTS row — master tenant only */
                if ($canEditTestRow && ! empty($data['tests'])) {
                    $test->update($data['tests']);
                }

                /* 2. TEST CONFIG — tenant-scoped row */
                if ($canEditTestConfig && ! empty($data['test_configs'])) {

                    if ($isDefaultTenant) {
                        $test->configWithoutDefault()->updateOrCreate(
                            ['test_id' => $test->id],
                            $data['test_configs']
                        );
                    } else {
                        TestConfig::updateOrCreate(
                            [
                                'test_id' => $test->id,
                                'tenant_id' => $tenantId,
                            ],
                            $data['test_configs']
                        );
                    }
                }

                /* 3. MASTER COMPONENTS + REFERENCES */
                $masterWriteAllowed = $canEditMasterComponents || $canBootstrapMasterComponents;

                if ($masterWriteAllowed && isset($data['component'])) {

                    $componentsExist = [];

                    foreach ($data['component'] as $c) {

                        $componentId = $c['id'] ?? null;

                        /* Block new rows unless allowed */
                        if (empty($componentId) && ! $canAddMasterComponents && ! $canBootstrapMasterComponents) {
                            continue;
                        }

                        $c['test_id'] = $test->id;
                        $c['deleted_at'] = null;

                        /* Bootstrap tenant: force ownership to default tenant */
                        if ($canBootstrapMasterComponents && ! $isDefaultTenant) {
                            $c['tenant_id'] = config('app.default_tenant_id');
                        } else {
                            $c['tenant_id'] = $tenantId;
                        }

                        $model = TestComponent::withTrashed()->updateOrCreate(
                            ['id' => $componentId],
                            Arr::except($c, [
                                'reference',
                                'tenant_unit', 'tenant_convert_unit',
                                'tenant_factor', 'tenant_reference_text',
                                'tenant_default_value', 'tenant_option_value',
                                'tenant_mic_value', 'tenant_zone_value',
                                'tenant_hide',
                                'tenant_order', 'tenant_status',
                            ])
                        );

                        $componentsExist[] = $model->id;

                        /* References */
                        if (isset($c['reference']) && is_array($c['reference'])) {
                            $refsExist = [];

                            foreach ($c['reference'] as $ref) {
                                $ref['deleted_at'] = null;

                                if (isset($ref['age_min']) || isset($ref['age_max'])) {
                                    $ref['age_unit'] = $ref['age_unit'] ?? 'Year';
                                }

                                $r = $model->references()->withTrashed()->updateOrCreate(
                                    ['id' => $ref['id'] ?? null],
                                    $ref
                                );

                                $refsExist[] = $r->id;
                            }

                            $model->references()->whereNotIn('id', $refsExist)->delete();
                        }
                    }

                    /* Only master tenant deletes missing components */
                    if ($isDefaultTenant) {
                        TestComponent::where('test_id', $test->id)
                            ->whereNotIn('id', $componentsExist)
                            ->delete();
                    }
                }

                /* 4. TENANT OVERRIDES */
                if (! $isDefaultTenant && $canEditTenantOverrides && isset($data['component'])) {

                    foreach ($data['component'] as $c) {

                        if (empty($c['id'])) {
                            continue;
                        }

                        $componentId = $c['id'];

                        $values = [
                            'unit' => $c['tenant_unit'] ?? $c['unit'] ?? null,
                            'convert_unit' => $c['tenant_convert_unit'] ?? $c['convert_unit'] ?? null,
                            'factor' => $c['tenant_factor'] ?? $c['factor'] ?? null,
                            'reference_text' => $c['tenant_reference_text'] ?? $c['reference_text'] ?? null,
                            'default_value' => $c['tenant_default_value'] ?? $c['default_value'] ?? null,
                            'option_value' => $c['tenant_option_value'] ?? $c['option_value'] ?? null,
                            'mic_value' => $c['tenant_mic_value'] ?? $c['mic_value'] ?? null,
                            'zone_value' => $c['tenant_zone_value'] ?? $c['zone_value'] ?? null,
                            'hide' => $c['tenant_hide'] ?? $c['hide'] ?? 0,
                            'order' => $c['tenant_order'] ?? $c['order'] ?? 0,
                            'status' => $c['tenant_status'] ?? $c['status'] ?? 1,
                        ];

                        $isEmpty = $values['unit'] === null
                            && $values['convert_unit'] === null
                            && $values['factor'] === null
                            && empty($values['reference_text'])
                            && empty($values['default_value'])
                            && empty($values['option_value'])
                            && empty($values['mic_value'])
                            && empty($values['zone_value'])
                            && (int) $values['hide'] === 0
                            && (int) $values['order'] === 0
                            && (int) $values['status'] === 1;

                        if ($isEmpty) {
                            TestComponentTenant::where('tenant_id', $tenantId)
                                ->where('test_component_id', $componentId)
                                ->delete();

                            continue;
                        }

                        TestComponentTenant::updateOrCreate(
                            [
                                'tenant_id' => $tenantId,
                                'test_component_id' => $componentId,
                            ],
                            $values
                        );
                    }
                }

                /* 5. CONSUMPTIONS */
                if ($canEditConsumptions && isset($data['consumption'])) {

                    $consumptionsExist = [];

                    foreach ($data['consumption'] as $cs) {

                        if (is_string($cs['timing'] ?? null)) {
                            $decoded = json_decode($cs['timing'], true);
                            $cs['timing'] = json_last_error() === JSON_ERROR_NONE
                                ? $decoded
                                : array_map('trim', explode(',', $cs['timing']));
                        }

                        $cs['test_id'] = $test->id;
                        $cs['chargeable'] = isset($cs['chargeable']) ? 1 : 0;
                        $cs['discountable'] = isset($cs['discountable']) ? 1 : 0;
                        $cs['individual'] = isset($cs['individual']) ? 1 : 0;
                        $cs['shareable'] = isset($cs['shareable']) ? 1 : 0;
                        $cs['is_label'] = isset($cs['is_label']) ? 1 : 0;
                        $cs['is_box'] = isset($cs['is_box']) ? 1 : 0;
                        $cs['deleted_at'] = null;

                        $m = TestConsumption::withTrashed()->updateOrCreate(
                            ['id' => $cs['id'] ?? null],
                            $cs
                        );

                        $consumptionsExist[] = $m->id;
                    }

                    TestConsumption::where('test_id', $test->id)
                        ->whereNotIn('id', $consumptionsExist)
                        ->delete();
                }
            });

            return response()->json([
                'type' => 'success',
                'note' => 'Test updated successfully',
                'status' => 200,
            ], 200);

        } catch (\Throwable $e) {
            \Log::error('editUpdate error: '.$e->getMessage(), [
                'test_id' => $id,
                'tenant' => $tenantId,
            ]);

            return response()->json([
                'type' => 'fail',
                'note' => 'Save failed: '.$e->getMessage(),
                'status' => 500,
            ], 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        isAccess('config_test');

        $commonRules = [
            'tests.id' => ['required', 'integer_or_uuid'],
            'test_configs.short_name' => ['nullable', 'string', 'max:100'],
            'test_configs.order' => ['nullable', 'numeric'],
            'test_configs.status' => ['nullable', 'numeric'],
            'test_configs.theme' => ['nullable', 'string', 'max:100'],
            'test_configs.precautions' => ['nullable', 'string', 'max:500'],
            'test_configs.price' => ['required', 'numeric'],
            'test_configs.min_price' => ['nullable', 'numeric'],
            'test_configs.lab_price' => ['nullable', 'numeric', 'min:0'],
            'test_configs.reporting_charge' => ['nullable', 'numeric'],
            'test_configs.referral_commission' => ['nullable', 'numeric'],
            'test_configs.contract_commission' => ['nullable', 'numeric'],
            'test_configs.sample_type' => ['nullable', 'string', 'max:191'],
            'test_configs.sample_collect_room' => ['nullable', 'string', 'max:191'],
            'test_configs.test_room' => ['nullable', 'string', 'max:191'],
            'test_configs.sample_collect_room_show' => ['nullable', 'numeric'],
            'test_configs.test_room_show' => ['nullable', 'numeric'],
            'test_configs.physically' => ['nullable', 'numeric'],
            'test_configs.is_same_page' => ['nullable', 'numeric'],
            'test_configs.thead' => ['nullable', 'numeric'],
            'test_configs.show_title' => ['nullable', 'numeric'],
            'test_configs.show_result' => ['nullable', 'numeric'],
            'test_configs.show_reference' => ['nullable', 'numeric'],
            'test_configs.show_status' => ['nullable', 'numeric'],
            'test_configs.auto_result' => ['nullable', 'numeric'],
            'test_configs.result_color' => ['nullable', 'numeric'],
            'test_configs.number_table' => ['nullable', 'numeric'],
            'test_configs.is_separated' => ['nullable', 'numeric'],
            'test_configs.culture_show_class' => ['nullable', 'numeric'],
            'test_configs.culture_show_mic' => ['nullable', 'numeric'],
            'test_configs.culture_show_zone' => ['nullable', 'numeric'],
            'test_configs.is_culture' => ['nullable', 'numeric'],
            'test_configs.custom_js' => ['nullable', 'string', 'max:5000'],
        ];

        if (config('app.admin_domain') == request()->getHost()) {
            $commonRules['tests.name'] = ['nullable', 'string', 'max:191', Rule::unique('tests')->ignore($id)];
            $commonRules['tests.category_id'] = ['nullable', 'integer_or_uuid'];
            $commonRules['tests.short_name'] = ['nullable', 'string', 'max:50'];
            $commonRules['tests.order'] = ['nullable', 'numeric'];
            $commonRules['tests.status'] = ['nullable', 'numeric'];
            $commonRules['tests.details'] = ['nullable', 'string', 'max:500'];
        }

        $validator = Validator::make($request->all(), $commonRules);

        if ($validator->fails()) {
            return response()->json(['type' => 'invalid', 'note' => $validator->messages(), 'status' => 400], 200);
        }

        $data = $validator->validated();

        try {
            DB::transaction(function () use ($data) {
                if (config('app.admin_domain') == request()->getHost()) {
                    $model = Test::updateOrCreate(['id' => $data['tests']['id']], $data['tests']);
                } else {
                    $model = Test::findOrFail($data['tests']['id']);
                }

                $data['test_configs']['short_name'] = $data['tests']['short_name'] ?? null;
                $data['test_configs']['status'] = $data['tests']['status'] ?? 1;
                $data['test_configs']['order'] = $data['tests']['order'] ?? 0;
                $data['test_configs']['sample_type'] = $data['tests']['sample_type'] ?? '';

                $model->configWithoutDefault()->UpdateOrCreate(['test_id' => $data['tests']['id']], $data['test_configs']);
            });

            return response()->json(['type' => 'success', 'note' => 'Test Config update successfully', 'status' => 400], 201);
        } catch (\Throwable $e) {
            \Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => 'Can not Create', 'status' => 400], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        isAccess('delete_lab_test');
        try {
            $model = Test::find($id);
            if ($model->delete()) {
                return response()->json(['type' => 'success', 'note' => 'Update Success', 'status' => 400], 200);
            } else {
                $model->restore();

                return response()->json(['type' => 'fail', 'note' => 'Can not Update', 'status' => 400], 200);
            }
        } catch (\Throwable $e) {
            \Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => 'Can not Update', 'status' => 400], 200);
        }
    }

    public function restore($id): JsonResponse
    {
        isAccess('restore_lab_test');
        try {
            $model = Test::withTrashed()->find($id);
            if ($model->restore()) {
                return response()->json(['type' => 'success', 'note' => 'Restore Success', 'status' => 400], 200);
            }

            return response()->json(['type' => 'fail', 'note' => 'Can\'t Restore', 'status' => 400], 200);
        } catch (\Throwable $e) {
            \Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => 'Can\'t Restore '.$e, 'status' => 400], 200);
        }
    }

    public function forceDelete($id): JsonResponse
    {
        isAccess('force_delete_lab_test');
        try {
            $model = Test::withTrashed()->find($id);
            $model->children()->delete();

            if ($model->forceDelete()) {
                return response()->json(['type' => 'success', 'note' => 'Force Delete Success', 'status' => 400], 200);
            }

            return response()->json(['type' => 'fail', 'note' => 'Can\'t Force Delete', 'status' => 400], 200);
        } catch (\Throwable $e) {
            \Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => 'Can\'t Force Delete', 'status' => 400], 200);
        }
    }

    public function componentUpdate(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id ?? webTenant();
        $isDefaultTenant = ($tenantId == config('app.default_tenant_id'));

        $tenantBranch = ! $isDefaultTenant || $request->input('component_child');

        /* ═══════════════════════════════════════════════════════════
           BRANCH 1 — TENANT OVERRIDE
           ═══════════════════════════════════════════════════════════ */
        if ($tenantBranch) {

            $validator = Validator::make($request->all(), [
                'test_id' => ['required', 'integer_or_uuid'],
                'component' => ['required', 'array'],
                'component.*.id' => ['required', 'integer_or_uuid'],
                'component.*.unit' => ['nullable', 'string', 'max:50'],
                'component.*.convert_unit' => ['nullable', 'string', 'max:50'],
                'component.*.factor' => ['nullable', 'numeric'],
                'component.*.reference_text' => ['nullable', 'string', 'max:500'],
                'component.*.default_value' => ['nullable', 'string', 'max:191'],
                'component.*.option_value' => ['nullable', 'string'],
                'component.*.mic_value' => ['nullable', 'string', 'max:191'],
                'component.*.zone_value' => ['nullable', 'string', 'max:191'],
                'component.*.hide' => ['nullable', 'numeric'],
                'component.*.order' => ['nullable', 'numeric'],
                'component.*.status' => ['nullable', 'numeric'],
            ]);

            if ($validator->fails()) {
                return response()->json(['type' => 'invalid', 'note' => $validator->messages(), 'status' => 400], 200);
            }

            try {
                foreach ($validator->validated()['component'] as $component) {

                    if (empty($component['id'])) {
                        continue;
                    }

                    $values = [
                        'unit' => $component['unit'] ?? null,
                        'convert_unit' => $component['convert_unit'] ?? null,
                        'factor' => $component['factor'] ?? null,
                        'reference_text' => $component['reference_text'] ?? null,
                        'default_value' => $component['default_value'] ?? null,
                        'option_value' => $component['option_value'] ?? null,
                        'mic_value' => $component['mic_value'] ?? null,
                        'zone_value' => $component['zone_value'] ?? null,
                        'hide' => $component['hide'] ?? 0,
                        'order' => $component['order'] ?? 0,
                        'status' => $component['status'] ?? 1,
                    ];

                    $allBlank = $values['unit'] === null
                        && $values['convert_unit'] === null
                        && $values['factor'] === null
                        && empty($values['reference_text'])
                        && empty($values['default_value'])
                        && empty($values['option_value'])
                        && empty($values['mic_value'])
                        && empty($values['zone_value'])
                        && (int) $values['hide'] === 0
                        && (int) $values['order'] === 0
                        && (int) $values['status'] === 1;

                    if ($allBlank) {
                        TestComponentTenant::where('tenant_id', $tenantId)
                            ->where('test_component_id', $component['id'])
                            ->delete();

                        continue;
                    }

                    TestComponentTenant::updateOrCreate(
                        [
                            'tenant_id' => $tenantId,
                            'test_component_id' => $component['id'],
                        ],
                        $values
                    );
                }

                return response()->json([
                    'type' => 'success',
                    'note' => 'Tenant overrides saved',
                    'status' => 200,
                ], 201);

            } catch (\Throwable $e) {
                \Log::error('componentUpdate tenant: '.$e->getMessage());

                return response()->json([
                    'type' => 'fail',
                    'note' => 'Cannot save: '.$e->getMessage(),
                    'status' => 500,
                ], 500);
            }
        }

        /* ═══════════════════════════════════════════════════════════
           BRANCH 2 — MASTER COMPONENT  (default tenant ONLY)
           ═══════════════════════════════════════════════════════════ */
        if (! $isDefaultTenant) {
            abort(403, 'Only default tenant can edit master components');
        }

        $validator = Validator::make($request->all(), [
            'test_id' => ['required', 'integer_or_uuid'],
            'component.*.id' => ['nullable', 'integer_or_uuid'],
            'component.*.order' => ['nullable', 'numeric'],
            'component.*.unit' => ['nullable', 'string'],
            'component.*.convert_unit' => ['nullable', 'string'],
            'component.*.factor' => ['nullable', 'numeric'],
            'component.*.type' => ['nullable', 'string', 'max:191'],
            'component.*.name' => ['required', 'string', 'max:191'],
            'component.*.option_value' => ['nullable', 'string'],
            'component.*.default_value' => ['nullable', 'string', 'max:191'],
            'component.*.mic_value' => ['nullable', 'string', 'max:191'],
            'component.*.zone_value' => ['nullable', 'string', 'max:191'],
            'component.*.hide' => ['nullable', 'numeric'],
            'component.*.mode' => ['nullable', 'string', 'max:191'],
            'component.*.align' => ['nullable', 'string', 'max:191'],
            'component.*.class' => ['nullable', 'string', 'max:191'],
            'component.*.style' => ['nullable', 'string', 'max:191'],
            'component.*.used' => ['nullable', 'string', 'max:191'],
            'component.*.reference_text' => ['nullable', 'string', 'max:500'],
            'component.*.reference.*.id' => ['nullable', 'string', 'max:191'],
            'component.*.reference.*.type' => ['nullable', 'string', 'max:191'],
            'component.*.reference.*.gender' => ['nullable', 'string', 'max:191'],
            'component.*.reference.*.unit' => ['nullable', 'string', 'max:191'],
            'component.*.reference.*.age_min' => ['nullable', 'numeric'],
            'component.*.reference.*.age_max' => ['nullable', 'numeric'],
            'component.*.reference.*.age_unit' => ['nullable', 'string', 'max:191'],
            'component.*.reference.*.min' => ['nullable', 'string', 'max:191'],
            'component.*.reference.*.max' => ['nullable', 'string', 'max:191'],
            'component.*.reference.*.status' => ['nullable', 'string', 'max:191'],
            'component.*.reference.*.color' => ['nullable', 'string', 'max:191'],
        ]);

        if ($validator->fails()) {
            return response()->json(['type' => 'invalid', 'note' => $validator->messages(), 'status' => 400], 200);
        }

        try {
            $data = $validator->validated();
            $componentsExist = [];

            foreach ($data['component'] as $component) {

                $component['test_id'] = $data['test_id'];
                $component['tenant_id'] = $tenantId;
                $component['deleted_at'] = null;

                $model = TestComponent::withTrashed()->updateOrCreate(
                    ['id' => $component['id']],
                    $component
                );

                $componentsExist[] = $model->id;
                $referencesExist = [];

                if (isset($component['reference'])) {
                    foreach ($component['reference'] as $reference) {
                        $reference['deleted_at'] = null;

                        if (isset($reference['age_min']) || isset($reference['age_max'])) {
                            $reference['age_unit'] = $reference['age_unit'] ?? 'Year';
                        }

                        $refer = $model->references()->withTrashed()->updateOrCreate(
                            ['id' => $reference['id']],
                            $reference
                        );

                        $referencesExist[] = $refer->id;
                    }

                    $model->references()->whereNotIn('id', $referencesExist)->delete();
                }
            }

            TestComponent::where('test_id', $data['test_id'])
                ->whereNotIn('id', $componentsExist)
                ->delete();

            return response()->json([
                'type' => 'success',
                'note' => 'Test component Update successfully',
                'status' => 400,
            ], 201);

        } catch (\Throwable $e) {
            \Log::error('componentUpdate master: '.$e->getMessage());

            return response()->json([
                'type' => 'fail',
                'note' => 'Can not Create'.$e->getMessage(),
                'status' => 400,
            ], 500);
        }
    }

    public function consumptionUpdate(Request $request): JsonResponse
    {
        isAccess('consumption_lab_test');

        $validator = Validator::make($request->all(), [
            'test_id' => ['required', 'integer_or_uuid'],
            'consumption' => ['nullable', 'array'],
            'consumption.*.id' => ['nullable', 'integer_or_uuid'],
            'consumption.*.product_id' => ['required', 'integer_or_uuid'],
            'consumption.*.quantity' => ['required', 'numeric'],
            'consumption.*.chargeable' => ['nullable', 'numeric'],
            'consumption.*.discountable' => ['nullable', 'numeric'],
            'consumption.*.individual' => ['nullable', 'numeric'],
            'consumption.*.shareable' => ['nullable', 'numeric'],
            'consumption.*.is_label' => ['nullable', 'numeric'],
            'consumption.*.is_box' => ['nullable', 'numeric'],
            'consumption.*.timing' => ['nullable'],
        ]);

        if ($validator->fails()) {
            return response()->json(['type' => 'invalid', 'note' => $validator->messages(), 'status' => 400], 200);
        }

        $data = $validator->validated();

        try {
            DB::transaction(function () use ($data) {
                $consumptionsExist = [];
                $consumptions = $data['consumption'] ?? [];

                foreach ($consumptions as $consumption) {
                    if (isset($consumption['timing']) && is_string($consumption['timing'])) {
                        $decoded = json_decode($consumption['timing'], true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $consumption['timing'] = $decoded;
                        } else {
                            $consumption['timing'] = array_map('trim', explode(',', $consumption['timing']));
                        }
                    }

                    $consumption['test_id'] = $data['test_id'];
                    $consumption['chargeable'] = isset($consumption['chargeable']) ? 1 : 0;
                    $consumption['discountable'] = isset($consumption['discountable']) ? 1 : 0;
                    $consumption['individual'] = isset($consumption['individual']) ? 1 : 0;
                    $consumption['shareable'] = isset($consumption['shareable']) ? 1 : 0;
                    $consumption['is_label'] = isset($consumption['is_label']) ? 1 : 0;
                    $consumption['is_box'] = isset($consumption['is_box']) ? 1 : 0;
                    $consumption['deleted_at'] = null;

                    $model = TestConsumption::withTrashed()->updateOrCreate(
                        ['id' => $consumption['id']],
                        $consumption
                    );

                    $consumptionsExist[] = $model->id;
                }

                TestConsumption::where('test_id', $data['test_id'])
                    ->whereNotIn('id', $consumptionsExist)
                    ->delete();
            });

            return response()->json(['type' => 'success', 'note' => 'Test consumption updated successfully', 'status' => 200], 200);
        } catch (\Throwable $e) {
            \Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => 'Can not Update: '.$e->getMessage(), 'status' => 400], 500);
        }
    }

    public function referenceStatus($lab_test_id, $component_id, Request $request): JsonResponse
    {
        try {
            $data = LabTest::with('test.reference', 'patient:id,dob,gender')->find($lab_test_id);
            $number = 70;
            $dob = Carbon::parse($data['patient']['dob']);

            foreach ($data['test']['reference'] as $condition) {
                try {
                    $format = $condition['age_unit'] ? strtolower(trim($condition['age_unit'])) : 'year';
                    if (($condition['gender'] === null || $data['patient']['gender'] == $condition['gender']) &&
                        ($condition['age'] === null || (($dob->{$format} >= $condition['min_age']) && ($dob->{$format} <= $condition['max_age']))) &&
                        ($condition['min'] === null || $number >= $condition['min']) && ($condition['max'] === null || $number <= $condition['max'])
                    ) {
                        return response()->json(['status' => $condition['status'], 'color' => $condition['color']]);
                    }
                } catch (\Throwable $e) {
                }
            }
        } catch (\Throwable $e) {
        }

        return response()->json(['status' => '', 'color' => '']);
    }

    public function bulkAssignOld(Request $request)
    {
        isAccess('update_bulk_test');

        try {
            $perPage = 50;

            $data = Test::with([
                'configWithoutDefault:id,tenant_id,test_id,short_name,precautions,theme,order,status,price,min_price,tax,reporting_charge,referral_commission,contract_commission',
                'config:id,tenant_id,test_id,short_name,precautions,theme,order,status,price,min_price,tax,reporting_charge,referral_commission,contract_commission',
            ])->paginate($perPage);

            $mappedData = $data->map(function ($test) {
                return [
                    'test_id' => $test->id,
                    'name' => $test->name ?? '',
                    'short_name' => $test->config->short_name ?? $test->configWithoutDefault->short_name ?? '',
                    'theme' => $test->config->theme ?? $test->configWithoutDefault->theme ?? 'Default',
                    'status' => $test->config->status ?? $test->configWithoutDefault->status ?? 1,
                    'order' => $test->config->order ?? $test->configWithoutDefault->order ?? 1,
                    'price' => $test->config->price ?? $test->configWithoutDefault->price ?? 0,
                    'min_price' => $test->config->min_price ?? $test->configWithoutDefault->min_price ?? 0,
                    'tax' => $test->config->tax ?? $test->configWithoutDefault->tax ?? 0,
                    'reporting_charge' => $test->config->reporting_charge ?? $test->configWithoutDefault->reporting_charge ?? 0,
                    'referral_commission' => $test->config->referral_commission ?? $test->configWithoutDefault->referral_commission ?? 0,
                    'contract_commission' => $test->config->contract_commission ?? $test->configWithoutDefault->contract_commission ?? 0,
                ];
            });

            return view('lab.test.bulk-test', [
                'data' => $mappedData,
                'pagination' => $data,
            ]);
        } catch (\Throwable $e) {
            \Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => 'Problem fetching data: '.$e->getMessage(), 'status' => 400], 200);
        }
    }

    public function bulkPriceAssign(Request $request)
    {
        isAccess('update_bulk_test');

        try {
            $perPage = $request->get('per_page', 50);
            $perPage = in_array($perPage, [20, 50, 100]) ? $perPage : 50;

            $query = Test::with([
                'configWithoutDefault:id,tenant_id,test_id,short_name,precautions,theme,order,status,price,min_price,lab_price,tax,reporting_charge,referral_commission,contract_commission',
                'config:id,tenant_id,test_id,short_name,precautions,theme,order,status,price,min_price,lab_price,tax,reporting_charge,referral_commission,contract_commission',
                'category:id,name',
            ]);

            if ($request->has('search') && ! empty($request->search)) {
                $searchTerm = $request->search;
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('name', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('short_name', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('id', 'LIKE', "%{$searchTerm}%")
                        ->orWhereHas('category', fn ($q) => $q->where('name', 'LIKE', "%{$searchTerm}%"))
                        ->orWhereHas('config', fn ($q) => $q->where('short_name', 'LIKE', "%{$searchTerm}%"))
                        ->orWhereHas('configWithoutDefault', fn ($q) => $q->where('short_name', 'LIKE', "%{$searchTerm}%"));
                });
            }

            if ($request->has('category') && ! empty($request->category)) {
                $query->whereHas('category', fn ($q) => $q->where('id', $request->category));
            }

            if ($request->has('status') && $request->status !== null) {
                $query->where(function ($q) use ($request) {
                    $q->whereHas('config', fn ($q) => $q->where('status', $request->status))
                        ->orWhereHas('configWithoutDefault', fn ($q) => $q->where('status', $request->status));
                });
            }

            $data = $query->paginate($perPage)->appends($request->all());

            $mappedData = $data->map(function ($test) {
                $labPrice = $test->config->lab_price ?? $test->configWithoutDefault->lab_price ?? 0;
                $minPrice = $test->config->min_price ?? $test->configWithoutDefault->min_price ?? 0;

                if ($labPrice == 0 && $minPrice > 0) {
                    $labPrice = $minPrice;
                }

                return [
                    'test_id' => $test->id,
                    'name' => $test->name ?? '',
                    'code' => $test->code ?? '',
                    'category_name' => $test->category->name ?? '',
                    'short_name' => $test->config->short_name ?? $test->configWithoutDefault->short_name ?? '',
                    'theme' => $test->config->theme ?? $test->configWithoutDefault->theme ?? 'Default',
                    'status' => $test->config->status ?? $test->configWithoutDefault->status ?? 1,
                    'order' => $test->config->order ?? $test->configWithoutDefault->order ?? 1,
                    'price' => $test->config->price ?? $test->configWithoutDefault->price ?? 0,
                    'min_price' => $minPrice,
                    'lab_price' => $labPrice,
                    'tax' => $test->config->tax ?? $test->configWithoutDefault->tax ?? 0,
                    'reporting_charge' => $test->config->reporting_charge ?? $test->configWithoutDefault->reporting_charge ?? 0,
                    'referral_commission' => $test->config->referral_commission ?? $test->configWithoutDefault->referral_commission ?? 0,
                    'contract_commission' => $test->config->contract_commission ?? $test->configWithoutDefault->contract_commission ?? 0,
                ];
            });

            return view('lab.test.bulk-test', [
                'data' => $mappedData,
                'pagination' => $data,
                'search' => $request->search ?? '',
                'category_filter' => $request->category ?? '',
                'status_filter' => $request->status ?? '',
                'per_page' => $perPage,
            ]);
        } catch (\Throwable $e) {
            \Log::error($e->getMessage());

            return response()->json(['type' => 'fail', 'note' => 'Problem fetching data: '.$e->getMessage(), 'status' => 400], 200);
        }
    }

    public function storeBulkPriceAssign(Request $request)
    {
        isAccess('update_bulk_test');

        $validator = Validator::make($request->all(), [
            'tests.*.test_id' => 'required|integer_or_uuid',
            'tests.*.status' => 'nullable|numeric',
            'tests.*.short_name' => 'nullable|string|max:50',
            'tests.*.order' => 'nullable|numeric',
            'tests.*.price' => 'required|numeric',
            'tests.*.min_price' => 'nullable|numeric',
            'tests.*.lab_price' => 'nullable|numeric',
            'tests.*.reporting_charge' => 'nullable|numeric',
            'tests.*.contract_commission' => 'nullable|numeric',
            'tests.*.referral_commission' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withInput()->with([
                'header' => 'Validation Error',
                'message' => $validator->messages(),
                'alert-type' => 'warning',
            ]);
        }

        $data = $validator->validated();

        try {
            foreach ($data['tests'] as $test) {
                $model = Test::find($test['test_id']);
                if ($model) {
                    if (! isset($test['lab_price']) || $test['lab_price'] === null || $test['lab_price'] === '') {
                        $test['lab_price'] = $test['min_price'] ?? 0;
                    }
                    $model->configWithoutDefault()->UpdateOrCreate(['test_id' => $test['test_id']], $test);
                }
            }

            return redirect()->back()->with(['header' => 'Success', 'message' => 'Update Success', 'alert-type' => 'success']);
        } catch (\Throwable $e) {
            \Log::error($e->getMessage());
        }

        return redirect()->back()->with(['header' => 'Problem', 'message' => 'Not Success', 'alert-type' => 'warning']);
    }

    public function bulkPriceUpdateChunk(Request $request)
    {
        $chunk = $request->input('chunk', []);

        foreach ($chunk as $i => &$item) {
            if (! empty($item['short_name']) && mb_strlen($item['short_name']) > 20) {
                $item['short_name'] = $this->makeUniqueShortName($item['short_name'], $item['test_id'] ?? null);
            }
        }
        unset($item);
        $request->merge(['chunk' => $chunk]);

        $validator = Validator::make($request->all(), [
            'chunk' => 'required|array',
            'chunk.*.test_id' => 'required|integer_or_uuid|exists:tests,id',
            'chunk.*.status' => 'nullable|numeric',
            'chunk.*.short_name' => 'nullable|string|max:20',
            'chunk.*.order' => 'nullable|numeric',
            'chunk.*.price' => 'required|numeric',
            'chunk.*.min_price' => 'nullable|numeric',
            'chunk.*.lab_price' => 'nullable|numeric',
            'chunk.*.reporting_charge' => 'nullable|numeric',
            'chunk.*.contract_commission' => 'nullable|numeric',
            'chunk.*.referral_commission' => 'nullable|numeric',
            'chunk.*.tax' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $chunk = $request->input('chunk');

        foreach ($chunk as $testData) {
            $model = Test::find($testData['test_id']);
            if ($model) {
                if (! isset($testData['lab_price']) || $testData['lab_price'] === '' || $testData['lab_price'] == 0) {
                    $testData['lab_price'] = $testData['min_price'] ?? 0;
                }
                $model->configWithoutDefault()->updateOrCreate(['test_id' => $testData['test_id']], $testData);
            }
        }

        session()->put('last_activity', time());

        return response()->json(['success' => true]);
    }

    private function makeUniqueShortName(string $name, $excludeTestId = null): string
    {
        $words = preg_split('/\s+/', trim($name));
        $initials = '';
        foreach ($words as $w) {
            $w = preg_replace('/[^\p{L}\p{N}]/u', '', $w);
            if ($w === '') {
                continue;
            }
            $initials .= mb_strtoupper(mb_substr($w, 0, 1));
        }

        if ($initials === '') {
            $initials = mb_strtoupper(mb_substr(preg_replace('/\s+/', '', $name), 0, 20));
        }

        $initials = mb_substr($initials, 0, 20);

        $base = $initials;
        $suffix = 1;
        $candidate = $base;

        while (TestConfig::where('short_name', $candidate)
            ->when($excludeTestId, fn ($q) => $q->where('test_id', '!=', $excludeTestId))
            ->exists()
        ) {
            $suffixStr = (string) $suffix;
            $maxBaseLen = 20 - mb_strlen($suffixStr);
            $candidate = mb_substr($base, 0, $maxBaseLen).$suffixStr;
            $suffix++;
        }

        return $candidate;
    }

    public function testAccess(Request $request)
    {
        isAccess('test_access');

        $userId = $request->input('user_id');

        $request->validate([
            'user_id' => 'nullable|integer_or_uuid|exists:users,id',
        ]);

        $categories = TestCategory::with([
            'tests' => fn ($query) => $query->select('id', 'name', 'category_id', 'deleted_at')->whereNull('deleted_at'),
        ])
            ->whereNull('deleted_at')
            ->select('id', 'name', 'deleted_at')
            ->get();

        $selectedUser = null;
        $userTestIds = [];

        if ($userId) {
            $selectedUser = User::select('id', 'name', 'phone', 'deleted_at')->find($userId);

            if ($selectedUser) {
                $userTestIds = $selectedUser->testAccess()
                    ->wherePivot('tenant_id', auth()->user()->tenant_id)
                    ->pluck('tests.id')
                    ->toArray();
            }
        }

        return view('lab.test.test-access', compact('categories', 'selectedUser', 'userTestIds'));
    }

    public function testAccessUpdate(Request $request)
    {
        isAccess('test_access');
        try {
            $user = User::findOrFail($request->user_id);
            $tenantId = auth()->user()->tenant_id ?? webTenant();

            Cache::forget("user_tests_{$user->id}_tenant_id{$tenantId}");

            $syncData = [];
            foreach ($request->tests ?? [] as $testId) {
                $syncData[$testId] = ['tenant_id' => $tenantId];
            }

            $user->testAccess()->wherePivot('tenant_id', $tenantId)->sync($syncData);

            return back()->with('success', 'User test access updated successfully.');
        } catch (\Exception $e) {
            \Log::error('Test access update error: '.$e->getMessage());

            return back()->with('error', 'Failed to update test access: '.$e->getMessage());
        }
    }

    public function testStatusToggle($id)
    {
        isAccess('config_test');

        $authTenantId = auth()->user()->tenant_id ?? webTenant();
        $isDefaultTenant = ($authTenantId == config('app.default_tenant_id'));

        $test = Test::with('config')->findOrFail($id);

        if ($isDefaultTenant) {
            $currentStatus = (int) $test->getRawOriginal('status');
            $newStatus = $currentStatus === 1 ? 0 : 1;

            $test->status = $newStatus;
            $test->save();
        } else {
            $currentStatus = (int) ($test->config?->status ?? $test->getRawOriginal('status'));
            $newStatus = $currentStatus === 1 ? 0 : 1;
        }

        $test->config()->updateOrCreate(
            ['tenant_id' => $authTenantId],
            ['status' => $newStatus]
        );

        return response()->json([
            'success' => true,
            'message' => 'Status updated successfully.',
            'new_status' => $newStatus,
        ]);
    }

    public function cloneComponents(Request $request): JsonResponse
    {
        isAccess('clone_component_lab_test');

        $validator = Validator::make($request->all(), [
            'source_test_id' => ['required', 'integer_or_uuid', 'exists:tests,id'],
            'target_test_id' => ['required', 'integer_or_uuid', 'exists:tests,id', 'different:source_test_id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['type' => 'invalid', 'note' => $validator->messages()], 200);
        }

        try {
            DB::transaction(function () use ($request) {
                $sourceTestId = $request->input('source_test_id');
                $targetTestId = $request->input('target_test_id');
                $tenantId = auth()->user()->tenant_id ?? webTenant();

                $sourceComponents = TestComponent::with('references')->where('test_id', $sourceTestId)->get();

                if ($sourceComponents->isEmpty()) {
                    throw new \Exception('Selected template test has no components to clone.');
                }

                $existingComponents = TestComponent::where('test_id', $targetTestId)->get();

                foreach ($existingComponents as $existingComp) {
                    $existingComp->references()->forceDelete();
                    $existingComp->forceDelete();
                }

                foreach ($sourceComponents as $sourceComponent) {
                    $newComponent = $sourceComponent->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
                    $newComponent->test_id = $targetTestId;
                    $newComponent->tenant_id = $tenantId;
                    $newComponent->save();

                    foreach ($sourceComponent->references as $sourceRef) {
                        $newRef = $sourceRef->replicate(['id', 'test_component_id', 'created_at', 'updated_at', 'deleted_at']);
                        $newRef->test_component_id = $newComponent->id;
                        $newRef->save();
                    }
                }
            });

            return response()->json(['type' => 'success', 'note' => 'Test components cloned successfully.'], 200);
        } catch (\Throwable $e) {
            return response()->json(['type' => 'fail', 'note' => 'Cloning failed: '.$e->getMessage()], 200);
        }
    }

    public function bulkConsumption(Request $request)
    {
        isAccess('consumption_lab_test');

        $perPage = $request->get('per_page', 50);
        $perPage = in_array($perPage, [20, 50, 100]) ? $perPage : 50;

        $productId = $request->input('product_id');
        $product = null;

        if (! empty($productId)) {
            $product = Product::find($productId);
        }

        $query = Test::with(['category:id,name'])->whereNull('deleted_at');

        if ($request->has('search') && ! empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('short_name', 'LIKE', "%{$search}%");
            });
        }

        if ($request->has('category') && ! empty($request->category)) {
            $query->whereHas('category', fn ($q) => $q->where('id', $request->category));
            $category = TestCategory::find($request->category);
        }

        if ($request->has('status') && $request->status !== null && $request->status !== '') {
            $status = (int) $request->status;
            $query->whereHas('config', fn ($q) => $q->where('status', $status))
                ->orWhere(function ($q) use ($status) {
                    $q->whereDoesntHave('config')->where('status', $status);
                });
        }

        $assignmentStatus = $request->input('assignment_status', 'all');
        if ($productId && $assignmentStatus !== 'all') {
            if ($assignmentStatus === 'assigned') {
                $query->whereHas('consumptions', fn ($q) => $q->where('product_id', $productId));
            } elseif ($assignmentStatus === 'not_assigned') {
                $query->whereDoesntHave('consumptions', fn ($q) => $q->where('product_id', $productId));
            } elseif ($assignmentStatus === 'assigned_any') {
                $query->has('consumptions');
            } elseif ($assignmentStatus === 'not_assigned_any') {
                $query->doesntHave('consumptions');
            }
        }

        $tests = $query->paginate($perPage)->appends($request->all());
        $testIds = $tests->pluck('id')->toArray();

        $selectedProductConsumptions = collect();
        if ($productId) {
            $selectedProductConsumptions = TestConsumption::where('product_id', $productId)
                ->whereIn('test_id', $testIds)
                ->get()
                ->keyBy('test_id');
        }

        $allConsumptions = TestConsumption::with('product:id,name')
            ->whereIn('test_id', $testIds)
            ->get()
            ->groupBy('test_id');

        $mappedTests = $tests->map(function ($test) use ($selectedProductConsumptions, $allConsumptions) {
            $consumption = $selectedProductConsumptions->get($test->id);

            $assignedProducts = collect();
            if ($allConsumptions->has($test->id)) {
                $assignedProducts = $allConsumptions->get($test->id)
                    ->map(fn ($c) => $c->product->name ?? 'Unknown')
                    ->unique()
                    ->implode(', ');
            }

            return [
                'test_id' => $test->id,
                'name' => $test->name,
                'short_name' => $test->short_name ?? $test->config->short_name ?? '',
                'category_name' => $test->category->name ?? '',
                'status' => $test->config->status ?? $test->status ?? 1,
                'order' => $test->config->order ?? $test->order ?? 1,
                'price' => $test->config->price ?? 0,
                'has_consumption' => $consumption ? true : false,
                'quantity' => $consumption->quantity ?? 1,
                'chargeable' => $consumption->chargeable ?? 0,
                'discountable' => $consumption->discountable ?? 0,
                'individual' => $consumption->individual ?? 0,
                'shareable' => $consumption->shareable ?? 0,
                'is_label' => $consumption->is_label ?? 0,
                'is_box' => $consumption->is_box ?? 0,
                'timing' => $consumption->timing ?? '',
                'assigned_products' => $assignedProducts,
            ];
        });

        return view('lab.test.bulk-consumption-assign', [
            'product' => $product,
            'category' => $category ?? null,
            'selectedProductId' => $productId,
            'tests' => $mappedTests,
            'pagination' => $tests,
            'search' => $request->search ?? '',
            'category_filter' => $request->category ?? '',
            'status_filter' => $request->status ?? '',
            'assignment_status' => $assignmentStatus,
            'per_page' => $perPage,
        ]);
    }

    public function bulkConsumptionUpdate(Request $request)
    {
        isAccess('consumption_lab_test');

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'page_test_ids' => 'nullable|array',
            'page_test_ids.*' => 'required|exists:tests,id',
            'tests' => 'nullable|array',
            'tests.*.test_id' => 'required|exists:tests,id',
            'tests.*.quantity' => 'nullable|numeric|min:0',
            'tests.*.chargeable' => 'nullable|boolean',
            'tests.*.discountable' => 'nullable|boolean',
            'tests.*.individual' => 'nullable|boolean',
            'tests.*.shareable' => 'nullable|boolean',
            'tests.*.is_label' => 'nullable|boolean',
            'tests.*.is_box' => 'nullable|boolean',
            'tests.*.timing' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $productId = $request->input('product_id');
        $pageTestIds = $request->input('page_test_ids', []);
        $submittedTests = $request->input('tests', []);

        $submittedMap = [];
        foreach ($submittedTests as $data) {
            if (isset($data['test_id'])) {
                $submittedMap[$data['test_id']] = $data;
            }
        }

        foreach ($pageTestIds as $testId) {
            if (isset($submittedMap[$testId])) {
                $data = $submittedMap[$testId];
                TestConsumption::updateOrCreate(
                    ['product_id' => $productId, 'test_id' => $testId],
                    [
                        'quantity' => $data['quantity'] ?? 1,
                        'chargeable' => isset($data['chargeable']) ? 1 : 0,
                        'discountable' => isset($data['discountable']) ? 1 : 0,
                        'individual' => isset($data['individual']) ? 1 : 0,
                        'shareable' => isset($data['shareable']) ? 1 : 0,
                        'is_label' => isset($data['is_label']) ? 1 : 0,
                        'is_box' => isset($data['is_box']) ? 1 : 0,
                        'timing' => $data['timing'] ?? null,
                    ]
                );
            } else {
                TestConsumption::where('product_id', $productId)->where('test_id', $testId)->delete();
            }
        }

        return redirect()->route('lab.test.bulk.consumption', [
            'product_id' => $productId,
            'page' => $request->input('page', 1),
            'per_page' => $request->input('per_page', 50),
        ])->with('success', 'Product test assignments updated successfully for the current page.');
    }
}
