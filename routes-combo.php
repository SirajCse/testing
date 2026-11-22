<?php

/* ═════════════════════════════════════════════════════════════════════════════
   COMBO ROUTES — paste these next to your existing lab.report.* routes
   (same group / middleware / prefix as the other lab report routes, e.g.
   right after the 'lab.report.bulk.update.receipt' route).

   If your lab routes group is NOT already prefixed with 'lab/report',
   prefix the URIs below accordingly (e.g. 'lab/report/report/combo-edit/...'
   → 'lab/report/combo-edit/...'). Keep the route NAMES exactly as they are —
   the blade file calls route('lab.report.combo.edit') etc.
   ═════════════════════════════════════════════════════════════════════════════ */

use App\Http\Controllers\Lab\LabReportController;

// Combo edit — one screen for every test of a receipt (v23 shell)
Route::get('report/combo-edit/{rid}', [LabReportController::class, 'comboEdit'])->name('lab.report.combo.edit');

// Combo update — AJAX save (JSON or multipart FormData), model-wise updateOrCreate
Route::post('report/combo-update', [LabReportController::class, 'comboUpdate'])->name('lab.report.combo.update');

// LIS edit map — lab_test wise AJAX loader for analyzer (worklist/lis_results) values
Route::get('report/combo-lis/{rid}', [LabReportController::class, 'comboLis'])->name('lab.report.combo.lis');

// Prev / next receipt, staying on the combo page
Route::get('report/combo-shift/{id}/{d}', [LabReportController::class, 'comboShift'])->name('lab.report.combo.shift');
