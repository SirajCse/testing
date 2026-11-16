@extends('layouts/contentLayoutHideNavbar')
@section('title', 'Sample Collection Lab Receipt')

@section('vendor-style')
    <style>
        .sample-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .sample-card {
            background: #fff;
            border-radius: 10px;
            border: 1px solid #8BC34A;
            transition: all .2s;
            overflow: hidden;
        }

        .sample-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, .08);
            transform: translateY(-2px);
        }

        .sample-card {
            border-left: 4px solid #17a3c8;
        }

        .sample-card.collected {
            background: #f0fff4;
            border-left-color: #28a745;
            opacity: 0.9;
        }

        .sample-card.custom-barcode-enabled {
            border-right: 3px solid #ffc107;
        }

        .sample-card.shared-barcode-enabled {
            border-right: 3px solid #9b59b6;
        }

        .sample-card.bg-fasting {
            border-left-color: #17a2b8;
        }

        .sample-card.bg-1h {
            border-left-color: #ffc107;
        }

        .sample-card.bg-2h {
            border-left-color: #fd7e14;
        }

        .sample-card.bg-random {
            border-left-color: #28a745;
        }

        .card-header-custom {
            padding: 10px 12px;
            background: #f8f9fc;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .barcode-code {
            font-family: monospace;
            font-size: 16px;
            font-weight: 700;
            background: #fff;
            padding: 4px 10px;
            border-radius: 6px;
        }

        .barcode-input-edit {
            font-family: monospace;
            font-size: 16px;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 6px;
            border: 2px solid #ffc107;
            background: #fff;
            width: 180px;
            text-align: center;
        }

        .timing-badge {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 600;
        }

        .timing-fasting {
            background: #17a2b8;
            color: #fff;
        }

        .timing-1h {
            background: #ffc107;
            color: #333;
        }

        .timing-2h {
            background: #fd7e14;
            color: #fff;
        }

        .timing-random {
            background: #28a745;
            color: #fff;
        }

        .timing-pp {
            background: #6f42c1;
            color: #fff;
        }

        .card-body-custom {
            padding: 10px 12px;
        }

        .test-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 10px;
        }

        .test-tag {
            background: #f0f0f0;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .test-resample-icon {
            color: #fd7e14;
            font-size: 11px;
            opacity: 0.7;
            cursor: pointer;
        }

        .test-resample-icon:hover {
            opacity: 1;
        }

        .product-group {
            background: #f8f9fc;
            border-radius: 8px;
            padding: 8px;
            margin-bottom: 10px;
            border-left: 3px solid #7367f0;
        }

        .product-title {
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #7367f0;
        }

        .recheck-badge {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
            margin-left: 5px;
            background: #fd7e14;
            color: #fff;
        }

        .quantity-control {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .quantity-btn {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            border: 1px solid #ddd;
            background: #fff;
            cursor: pointer;
            font-size: 16px;
            font-weight: 700;
        }

        .quantity-btn:hover {
            background: #7367f0;
            color: #fff;
        }

        .quantity-input {
            width: 55px;
            text-align: center;
            height: 32px;
            border-radius: 6px;
            border: 1px solid #ddd;
            background: #fff;
        }

        .single-checkbox {
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
        }

        .single-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            margin: 0;
        }

        .single-checkbox input[type="checkbox"][data-collected="true"] {
            accent-color: #28a745;
        }

        .single-checkbox .collected-badge {
            font-size: 9px;
            color: #28a745;
            margin-left: 4px;
        }

        .btn-icon-sm {
            padding: 4px 8px;
            font-size: 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
        }

        .btn-tube {
            background: #17a2b8;
            color: #fff;
        }

        .btn-tube:hover {
            background: #138496;
        }

        .btn-box {
            background: #28a745;
            color: #fff;
        }

        .btn-box:hover {
            background: #1e7e34;
        }

        .btn-recheck {
            background: #fd7e14;
            color: #fff;
        }

        .btn-recheck:hover {
            background: #e66a0e;
        }

        .btn-toggle {
            background: #6c757d;
            color: #fff;
            font-size: 10px;
            padding: 3px 8px;
            margin-left: 3px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-toggle.active {
            background: #ffc107;
            color: #333;
        }

        .btn-toggle-box {
            background: #28a745;
            color: #fff;
            font-size: 10px;
            padding: 3px 8px;
            margin-left: 3px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-toggle-box.active {
            background: #ffc107;
            color: #333;
        }

        .btn-toggle-custom {
            background: #17a2b8;
            color: #fff;
            font-size: 10px;
            padding: 3px 8px;
            margin-left: 3px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-toggle-custom.active {
            background: #ffc107;
            color: #333;
        }

        .btn-toggle-shared {
            background: #9b59b6;
            color: #fff;
            font-size: 10px;
            padding: 3px 8px;
            margin-left: 3px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-toggle-shared.active {
            background: #8e44ad;
        }

        .collected-status {
            font-size: 11px;
            color: #28c76f;
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px dashed #e9ecef;
        }

        .print-status {
            font-size: 11px;
            margin-top: 8px;
            padding: 4px;
            border-radius: 4px;
            text-align: center;
        }

        .print-status.printed {
            background: #d4edda;
            color: #155724;
        }

        .print-status.pending {
            background: #fff3cd;
            color: #856404;
        }

        .progress-bar-custom {
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: #28a745;
            transition: width 0.3s ease;
        }

        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #7367f0;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }

        .print-loading {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            flex-direction: column;
        }

        .source-badge {
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 600;
        }

        .source-system {
            background: #e7f5ec;
            color: #1e7e34;
            border: 1px solid #28a745;
        }

        .source-custom {
            background: #fff8e1;
            color: #8a6d00;
            border: 1px solid #ffc107;
        }

        .source-shared {
            background: #f3e8ff;
            color: #6f42c1;
            border: 1px solid #9b59b6;
        }

        .shared-from-label {
            font-size: 9px;
            color: #9b59b6;
            margin-top: 4px;
            padding: 2px 6px;
            background: #f3e8ff;
            border-radius: 4px;
            display: inline-block;
        }

        .action-bar {
            padding: 8px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .selection-count {
            background: #7367f0;
            color: #fff;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
        }

        .search-container {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .search-input {
            flex: 1;
            font-family: monospace;
            font-size: 14px;
        }

        .btn-group-responsive {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        @media (max-width: 768px) {
            .sample-grid {
                grid-template-columns: 1fr;
            }

            .action-bar {
                flex-direction: column;
            }

            .btn-group-responsive {
                display: flex;
                flex-wrap: wrap;
                gap: 5px;
                width: 100%;
            }

            .btn-group-responsive .btn {
                flex: 1;
                min-width: 80px;
            }

            .search-container {
                width: 100%;
            }

            .search-input {
                width: 100%;
            }

            .card-header-custom {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
@endsection

@section('content')
    <section class="invoice-add-wrapper">
        <div class="row invoice-add">
            <div class="col-12">
                <div class="card invoice-preview-card">
                    <!-- Card Header -->
                    <div class="card-header py-h border-top-primary bg-light">
                        <div class="row w-100">
                            @if($data)
                                <div class="col-12 mb-2">
                                    <b>{{ $data?->patient?->name ?? 'N/A' }} | {{ $data?->patient?->gender ?? 'N/A' }}
                                        | {{ $data?->patient?->age_format ?? 'N/A' }}Y
                                        | {{ $data?->patient?->phone ?? 'N/A' }}</b>
                                    <b class="float-right">SL: {{ $data?->sl_no ?? 'N/A' }} |
                                        BR: {{ $data?->barcode ?? 'N/A' }}</b>
                                </div>
                            @endif

                            <div class="col-12">
                                <div class="search-container">
                                    <input type="text" class="form-control form-control-sm search-receipt search-input"
                                           id="search_receipt"
                                           data-redirect="lab/sample/"
                                           autocomplete="off"
                                           placeholder="Search by patient name, phone, or receipt number...">
                                    <select id="selected_device" class="form-control form-control-sm"
                                            style="width: 120px;">
                                        <option value="">Select Printer</option>
                                    </select>
                                    <button type="button" class="btn btn-sm btn-primary" id="globalAutoPrintBtn"
                                            title="Auto Print">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-warning" id="globalCustomBarcodeBtn"
                                            title="Custom Barcode Mode">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a class="btn btn-sm btn-info" href="{{ route('lab.sample.view') }}">
                                        <i class="fa fa-home"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Patient Details -->
                    @if(@$data)
                        <div class="card-content collapse" id="viewPatientDetail">
                            <div class="card-body py-0">
                                <div class="row small">
                                    <div class="col-md-6">
                                        <b>Name:</b> {{ $data?->patient?->name ?? 'N/A' }}<br>
                                        <b>Phone:</b> {{ $data?->patient?->phone ?? 'N/A' }}
                                    </div>
                                    <div class="col-md-6">
                                        <b>Age:</b> {{ $data?->patient?->age_format ?? 'N/A' }}<br>
                                        <b>Gender:</b> {{ $data?->patient?->gender ?? 'N/A' }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <hr class="my-1">

                        <div id="receiptData"
                             data-id="{{ $data->id }}"
                             data-barcode="{{ $data->barcode }}"
                             style="display: none;">
                        </div>

                        <!-- Action Bar -->
                        <div class="action-bar">
                            <div class="btn-group-responsive">
                                <button type="button" class="btn btn-sm btn-secondary" id="selectAllBtn">
                                    <i class="fas fa-check-square"></i> Select All
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" id="deselectAllBtn">
                                    <i class="fas fa-square"></i> Deselect
                                </button>
                                <button type="button" class="btn btn-sm btn-primary" id="printSelectedBtn">
                                    <i class="fas fa-barcode"></i> Print Selected
                                </button>
                                <button type="button" class="btn btn-sm btn-success" id="printAllTubes">
                                    <i class="fas fa-barcode"></i> All Tubes
                                </button>
                            </div>
                            <div>
                                <span class="selection-count" id="selectedCount">0 selected</span>
                            </div>
                        </div>

                        <!-- Sample Grid -->
                        <div class="sample-block px-1">
                            @if(isset($expandedTests) && count($expandedTests) > 0)
                                <form action="{{ route('lab.sample.collect') }}" method="post" id="sampleCollectForm">
                                    @csrf
                                    <div class="sample-grid">
                                        @foreach($expandedTests as $tubeKey => $tube)
                                            @php
                                                $timing = $tube['timing'] ?? '';
                                                $timingClass = $timing == 'F' || $timing == 'Fasting' ? 'bg-fasting' :
                                                              ($timing == '1h' ? 'bg-1h' :
                                                              ($timing == '2h' ? 'bg-2h' :
                                                              ($timing == 'R' || $timing == 'Random' ? 'bg-random' : '')));
                                                $timingBadgeClass = $timing == 'F' || $timing == 'Fasting' ? 'timing-fasting' :
                                                                   ($timing == '1h' ? 'timing-1h' :
                                                                   ($timing == '2h' ? 'timing-2h' :
                                                                   ($timing == 'R' || $timing == 'Random' ? 'timing-random' :
                                                                   ($timing == 'PP' ? 'timing-pp' : ''))));
                                                $isCollected = $tube['is_collected'] ?? false;
                                                $isRecheck = $tube['is_recheck'] ?? false;
                                                $tubeSequence = $tube['tube_sequence'] ?? 1;
                                                $tubeSequencePadded = str_pad((string) $tubeSequence, 2, '0', STR_PAD_LEFT);
                                                $barcode = $tube['barcode'] ?? '';
                                                $uniqueKey = 'sample_' . ($tube['barcode'] ?? '') . '_' . ($tube['product_id'] ?? 0);
                                                $itemsCollected = $tube['items_collected'] ?? 0;
                                                $totalItems = $tube['total_items'] ?? count($tube['lab_test_ids'] ?? []);
                                                $progressPercent = ($totalItems > 0) ? round(($itemsCollected / $totalItems) * 100) : 0;
//                                                $tubeId = 'tube_' . preg_replace('/[^a-zA-Z0-9]/', '_', $tube['barcode'] ?? '');
                                                $tubeId = 'tube_' . preg_replace('/[^a-zA-Z0-9]/', '_', $tube['barcode'] ?? '') . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $tube['product_id'] ?? '');
                                                $barcodeSource = $tube['barcode_source'] ?? 'system';
                                                $sharedFromTenant = $tube['shared_from_tenant_barcode'] ?? null;
                                                $isShared = !empty($sharedFromTenant);
                                            @endphp
                                            <div
                                                class="sample-card {{ $timingClass }} {{ $isCollected ? 'collected' : '' }} {{ $isShared ? 'shared-barcode-enabled' : '' }}"
                                                data-barcode="{{ $barcode }}"
                                                data-original-barcode="{{ $tube['original_barcode'] ?? $barcode }}"
                                                data-lab-test-ids="{{ json_encode($tube['lab_test_ids'] ?? []) }}"
                                                data-product-id="{{ $tube['product_id'] ?? 0 }}"
                                                data-timing="{{ $timing }}"
                                                data-tube-sequence="{{ $tubeSequence }}"
                                                data-has-label="{{ $tube['has_label'] ?? 1 }}"
                                                data-has-box="{{ $tube['has_box'] ?? 0 }}"
                                                data-barcode-source="{{ $barcodeSource }}"
                                                data-is-shared="{{ $isShared ? 'true' : 'false' }}">

                                                <div class="card-header-custom">
                                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                                        <label class="single-checkbox">
                                                            <input type="hidden"
                                                                   name="samples[{{ $uniqueKey }}][check]"
                                                                   value="0">
                                                            <input type="checkbox"
                                                                   class="collect-check"
                                                                   name="samples[{{ $uniqueKey }}][check]"
                                                                   value="1"
                                                                   checked
                                                                   data-unique="{{ $uniqueKey }}"
                                                                   data-collected="{{ $isCollected ? 'true' : 'false' }}">
                                                            <span></span>
                                                            @if($isCollected)
                                                                <span class="collected-badge">
                                                                    <i class="fas fa-check-circle"></i>
                                                                </span>
                                                            @endif
                                                        </label>

                                                        <div class="barcode-container">
                                                            <code class="barcode-code">{{ $barcode }}</code>
                                                            <small
                                                                class="text-muted d-block">#{{ $tubeSequencePadded }}</small>
                                                        </div>
                                                        <input type="hidden" class="barcode-hidden-input"
                                                               name="samples[{{ $uniqueKey }}][barcode]"
                                                               value="{{ $barcode }}">

                                                        <input type="hidden" class="barcode-source-input"
                                                               name="samples[{{ $uniqueKey }}][barcode_source]"
                                                               value="{{ $barcodeSource }}">

                                                        <span class="source-badge
                                                            {{ $isShared ? 'source-shared' : ($barcodeSource === 'custom' ? 'source-custom' : 'source-system') }}">
                                                            {{ $isShared ? 'SHARED' : strtoupper($barcodeSource) }}
                                                        </span>

                                                        @if($timing)
                                                            <span
                                                                class="timing-badge {{ $timingBadgeClass }}">{{ $timing }}</span>
                                                        @endif

                                                        @if($isRecheck)
                                                            <span class="recheck-badge">Recheck</span>
                                                        @endif

                                                        @if($isShared)
                                                            <span class="shared-from-label">
                                                                <i class="fas fa-share-alt"></i> From: {{ $sharedFromTenant }}
                                                            </span>
                                                        @endif

                                                        <button type="button" class="btn-icon-sm btn-toggle-custom"
                                                                onclick="toggleCustomBarcode(this, '{{ $barcode }}')"
                                                                title="Enable custom barcode editing">
                                                            <i class="fas fa-edit"></i>
                                                        </button>

                                                        <button type="button"
                                                                class="btn-icon-sm btn-toggle-shared {{ $isShared ? 'active' : '' }}"
                                                                onclick="toggleSharedBarcode(this, '{{ $barcode }}')"
                                                                title="Mark as shared from another lab">
                                                            <i class="fas fa-share-alt"></i>
                                                        </button>

                                                        <button type="button" class="btn-icon-sm btn-toggle"
                                                                onclick="toggleTubeAutoLabel(this, '{{ $barcode }}')"
                                                                title="Auto Label Print">
                                                            <i class="fas fa-tag"></i>
                                                        </button>

                                                        @if($tube['has_box'] ?? false)
                                                            <button type="button" class="btn-icon-sm btn-toggle-box"
                                                                    onclick="toggleTubeAutoBox(this, '{{ $barcode }}')"
                                                                    title="Auto Box Print">
                                                                <i class="fas fa-box"></i>
                                                            </button>
                                                        @endif
                                                    </div>

                                                    <div class="d-flex gap-1">

                                                        @authorize('edit_lab_report')
                                                        <a class="btn btn-sm" title="Report"
                                                           href="{{ route('lab.report.edit', $data?->id) }}">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        @endauthorize

                                                        @if($tube['has_label'] ?? false)
                                                            <button type="button" class="btn-icon-sm btn-tube"
                                                                    onclick="printSingleTube(this, '{{ $barcode }}')">
                                                                <i class="fas fa-barcode"></i>
                                                            </button>
                                                        @endif
                                                        @if($tube['has_box'] ?? false)
                                                            <button type="button" class="btn-icon-sm btn-box"
                                                                    onclick="printSingleBox(this, '{{ $barcode }}')">
                                                                <i class="fas fa-box"></i>
                                                            </button>
                                                        @endif
                                                        <button type="button" class="btn-icon-sm btn-recheck"
                                                                title="Resample tests on this tube"
                                                                onclick="showRecheckModal('{{ $barcode }}', null, null)">
                                                            <i class="fas fa-vial"></i>
                                                        </button>
                                                    </div>
                                                </div>

                                                <div class="card-body-custom">
                                                    <div class="product-group">
                                                        <div class="product-title">
                                                            <i class="fas fa-flask"></i>
                                                            {{ $tube['tube_type'] ?? $tube['product_name'] ?? 'Standard' }}
                                                        </div>
                                                    </div>

                                                    <div class="test-list">
                                                        @foreach($tube['tests'] ?? [] as $test)
                                                            @php
                                                                $labTestId = $test['lab_test_id'] ?? null;
                                                                $testName = $test['display_name'] ?? $test['test_name'] ?? 'Unknown';
                                                            @endphp

                                                            <span class="test-tag">
                                                                <i class="fas fa-vial"></i>
                                                                {{ str()->limit($testName, 30) }}

                                                                @if($labTestId)
                                                                    {{-- Resample this single test --}}
                                                                    <a href="javascript:void(0)"
                                                                       class="test-resample-icon"
                                                                       title="Resample this test only"
                                                                       onclick="showRecheckModal('{{ $barcode }}', {{ $labTestId }}, '{{ addslashes($testName) }}')">
                                                                            <i class="fas fa-repeat"></i>
                                                                        </a>

                                                                    @authorize('edit_lab_report')
                                                                    {{-- Print blank report for this test --}}
                                                                    <a href="{{ route('lab.report.blank.print', ['rid' => $data?->id, 'tid' => $labTestId]) }}"
                                                                       class="test-resample-icon"
                                                                       title="Print blank report for this test"
                                                                       target="_blank">
                                                                            <i class="fas fa-print"></i>
                                                                        </a>
                                                                    @endauthorize
                                                                @endif
                                                            </span>
                                                        @endforeach
                                                    </div>

                                                    <div class="row">
                                                        <div class="col-6">
                                                            <div class="quantity-control">
                                                                <button type="button" class="quantity-btn"
                                                                        onclick="changeQty(this, -1, '{{ $uniqueKey }}')">
                                                                    -
                                                                </button>
                                                                <input type="number" class="quantity-input"
                                                                       id="qty_{{ $uniqueKey }}"
                                                                       name="samples[{{ $uniqueKey }}][quantity]"
                                                                       value="{{ $tube['quantity'] ?? 1 }}"
                                                                       min="1" max="5" readonly>
                                                                <button type="button" class="quantity-btn"
                                                                        onclick="changeQty(this, 1, '{{ $uniqueKey }}')">
                                                                    +
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <div class="col-6">
                                                            <div id="print-status-{{ $tubeId }}"
                                                                 class="print-status pending">
                                                                <small><i class="fas fa-clock"></i> Label Not
                                                                    printed</small>
                                                            </div>
                                                            @if($tube['has_box'] ?? false)
                                                                <div id="print-status-{{ $tubeId }}_box"
                                                                     class="print-status pending mt-1">
                                                                    <small><i class="fas fa-clock"></i> Box Not printed</small>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </div>

                                                    <input type="hidden" name="samples[{{ $uniqueKey }}][product_id]"
                                                           value="{{ $tube['product_id'] ?? 0 }}">
                                                    <input type="hidden" name="samples[{{ $uniqueKey }}][tube_type]"
                                                           value="{{ $tube['tube_type'] ?? '' }}">
                                                    <input type="hidden" name="samples[{{ $uniqueKey }}][sample_type]"
                                                           value="{{ $tube['sample_type'] ?? 'Serum' }}">
                                                    <input type="hidden" name="samples[{{ $uniqueKey }}][lab_test_ids]"
                                                           value="{{ json_encode($tube['lab_test_ids'] ?? []) }}">
                                                    <input type="hidden" name="samples[{{ $uniqueKey }}][timing]"
                                                           value="{{ $timing }}">
                                                    <input type="hidden" name="samples[{{ $uniqueKey }}][tube_sequence]"
                                                           value="{{ $tubeSequence }}">
                                                    <input type="hidden" name="samples[{{ $uniqueKey }}][is_shared]"
                                                           value="{{ $isShared ? 1 : 0 }}">
                                                    <input type="hidden"
                                                           name="samples[{{ $uniqueKey }}][shared_from_tenant_barcode]"
                                                           value="{{ $sharedFromTenant }}">

                                                    @if($progressPercent > 0 && $progressPercent < 100)
                                                        <div class="progress-bar-custom mt-2">
                                                            <div class="progress-fill"
                                                                 style="width: {{ $progressPercent }}%"></div>
                                                        </div>
                                                        <small>{{ $itemsCollected }}/{{ $totalItems }}</small>
                                                    @endif

                                                    @if($isCollected)
                                                        <div class="collected-status">
                                                            <i class="fas fa-check-circle"></i> Previously Collected
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="text-center mt-3 mb-3">
                                        <button type="submit" class="btn btn-primary" id="submitBtn">
                                            <i class="fas fa-save"></i> Save Collection
                                        </button>
                                    </div>
                                </form>
                            @elseif(isset($data))
                                <div class="alert alert-warning text-center">No tubes found.</div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <!-- Recheck Modal -->
    <div class="modal fade" id="recheckModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-repeat"></i> Resample Test</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="recheck_barcode">
                    <input type="hidden" id="recheck_lab_test_ids">
                    <div class="alert alert-info py-2 px-3 mb-3" id="recheck_scope_label"></div>
                    <div class="form-group">
                        <label>Reason:</label>
                        <textarea id="recheck_reason" class="form-control" rows="3"
                                  placeholder="Reason for resample..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Option:</label>
                        <select id="recheck_option" class="form-control">
                            <option value="existing">Use Existing Sample (re-run same tube)</option>
                            <option value="new">New Sample Collection (fresh tube)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Route to machine (optional):</label>
                        <select id="recheck_machine_id" class="form-control">
                            <option value="">-- Keep unassigned --</option>
                            @foreach($machines ?? [] as $machine)
                                <option value="{{ $machine->id }}">{{ $machine->name }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">Pick the analyzer this recheck should run on. Leave blank to assign
                            later from the worklist.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" onclick="submitRecheck()">Create Resample</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Print Loading Overlay -->
    <div id="printLoading"
         style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.7); z-index:10000; align-items:center; justify-content:center; color:#fff; flex-direction:column;">
        <div class="spinner-border text-light mb-3"></div>
        <h5>Printing...</h5>
    </div>
@endsection

@section('vendor-script')
    <script type="text/javascript" src="{{ asset('vendors/js/printer/BrowserPrint.min.js') }}"></script>
@endsection

@section('page-script')
    <script src="{{ asset(mix('js/scripts/pages/sample-preview.js')) }}"></script>
@endsection
