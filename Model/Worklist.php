<?php

namespace App\Models;

use App\Traits\BelongsToSelfTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Worklist extends BaseModel
{
    use BelongsToSelfTenant;
    protected $table = 'worklists';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'test_sample_item_id',
        'lab_receipt_id',
        'lab_test_id',
        'test_id',
        'machine_id',
        'machine_mapping_id',   // NEW — machine_test_mappings(id) used for this row (was inside parameters JSON)
        'patient_id',

        // Barcode fields
        'barcode',              // Tube barcode (scannable) - G YYMMDD SSS TT
        'receipt_barcode',      // Receipt barcode - V M G YYMMDD SSS

        // Patient fields (denormalized for API performance)
        'patient_name',
        'patient_age',
        'patient_gender',

        // Test fields
        'test_code',            // Machine-specific test code from MachineTestMapping
        'test_name',            // Display name

        // Sample fields
        'sample_type',
        'tube_type',
        'timing',

        // Worklist fields
        'priority',
        'status',
        'cancelled_reason',     // NEW — replaces parameters.cancellation_reason
        'collection_date',
        'started_at',
        'completed_at',
        'pushed_at',

        // Multi-version result pointer (lis_results)
        'latest_result_id',     // NEW — newest lis_results(id) for this row
        'result_count',         // NEW — how many result versions exist
        'source',               // NEW — fanout | manual | mirth | recheck

        // Legacy fields (backward compatibility)
        'suffix',
        'test_sample_id',

        // REMOVED: order_id, order_date, parameters, result_data,
        //          reassignment_history, sample_number (columns dropped by
        //          migration 2026_11_18_000001_column_reduction_worklist_chain)
    ];

    protected $casts = [
        'collection_date' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'pushed_at' => 'datetime',
        'result_count' => 'integer',
        'patient_age' => 'integer',
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_LOCKED = 'locked';

    // Priority constants
    const PRIORITY_HIGH = 'high';
    const PRIORITY_ROUTINE = 'routine';
    const PRIORITY_LOW = 'low';

    // ============================================
    // RELATIONSHIPS
    // ============================================

    public function testSampleItem(): BelongsTo
    {
        return $this->belongsTo(TestSampleItem::class, 'test_sample_item_id', 'id');
    }

    public function labReceipt(): BelongsTo
    {
        return $this->belongsTo(LabReceipt::class);
    }

    public function labTest(): BelongsTo
    {
        return $this->belongsTo(LabTest::class);
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'machine_id', 'id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    // Legacy relationships (backward compatibility)
    public function testSample()
    {
        return $this->belongsTo(TestSample::class, 'test_sample_id', 'id');
    }

    public function samples()
    {
        return $this->hasMany(TestSample::class, 'lab_receipt_id', 'lab_receipt_id');
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function scopeLocked($query)
    {
        return $query->where('status', self::STATUS_LOCKED);
    }

    public function scopeByMachine($query, string $machineId)
    {
        return $query->where('machine_id', $machineId);
    }

    public function scopeByTestSampleItem($query, string $testSampleItemId)
    {
        return $query->where('test_sample_item_id', $testSampleItemId);
    }

    public function scopeByBarcode($query, string $barcode)
    {
        return $query->where('barcode', $barcode);
    }

    public function scopeByReceiptBarcode($query, string $receiptBarcode)
    {
        return $query->where('receipt_barcode', $receiptBarcode);
    }

    public function scopeByReceipt($query, string $labReceiptId)
    {
        return $query->where('lab_receipt_id', $labReceiptId);
    }

    public function scopeByTest($query, string $testId)
    {
        return $query->where('test_id', $testId);
    }

    public function scopeByPatient($query, string $patientId)
    {
        return $query->where('patient_id', $patientId);
    }

    public function scopeByPatientName($query, string $search)
    {
        return $query->where('patient_name', 'like', "%{$search}%");
    }

    public function scopeHighPriority($query)
    {
        return $query->where('priority', self::PRIORITY_HIGH);
    }

    public function scopeAvailable($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }

    public function scopeNotPushed($query)
    {
        return $query->whereNull('pushed_at');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    public function scopeDateRange($query, string $start, string $end)
    {
        return $query->whereDate('created_at', '>=', $start)
            ->whereDate('created_at', '<=', $end);
    }

    // ============================================
    // WORKLIST LOCKING
    // ============================================

    public function canProcess(): bool
    {
        if ($this->status === self::STATUS_COMPLETED) {
            return false;
        }

        if ($this->status === self::STATUS_LOCKED) {
            return false;
        }

        $isCompletedElsewhere = self::where('test_sample_item_id', $this->test_sample_item_id)
            ->where('status', self::STATUS_COMPLETED)
            ->exists();

        if ($isCompletedElsewhere) {
            self::where('test_sample_item_id', $this->test_sample_item_id)
                ->where('status', '!=', self::STATUS_COMPLETED)
                ->update(['status' => self::STATUS_LOCKED]);
            return false;
        }

        return true;
    }

    public function markAsProcessing(): bool
    {
        return DB::transaction(function () {
            if (!$this->canProcess()) {
                return false;
            }

            $this->status = self::STATUS_PROCESSING;
            $this->started_at = now();
            $this->save();

            self::where('test_sample_item_id', $this->test_sample_item_id)
                ->where('id', '!=', $this->id)
                ->where('status', self::STATUS_PENDING)
                ->update(['status' => self::STATUS_LOCKED]);

            return true;
        });
    }

    public function markAsCompleted(): bool
    {
        return DB::transaction(function () {
            if (!in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING])) {
                return false;
            }

            $this->status = self::STATUS_COMPLETED;
            $this->completed_at = now();
            $this->save();

            self::where('test_sample_item_id', $this->test_sample_item_id)
                ->where('id', '!=', $this->id)
                ->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING])
                ->update(['status' => self::STATUS_LOCKED]);

            if ($this->lab_test_id) {
                LabTest::where('id', $this->lab_test_id)->update([
                    'has_result' => true,
                    'result_at' => now(),
                ]);
            }

            return true;
        });
    }

    public function markAsCancelled(?string $reason = null): bool
    {
        $this->status = self::STATUS_CANCELLED;
        if ($reason) {
            $this->cancelled_reason = $reason;   // NEW — dedicated column (was parameters.cancellation_reason)
        }
        return $this->save();
    }

    public function markAsPending(): bool
    {
        $this->status = self::STATUS_PENDING;
        $this->started_at = null;
        $this->completed_at = null;
        return $this->save();
    }

    public function markAsPushed(): bool
    {
        $this->pushed_at = now();
        return $this->save();
    }

    public static function hasCompletedResult(string $testSampleItemId): bool
    {
        return self::where('test_sample_item_id', $testSampleItemId)
            ->where('status', self::STATUS_COMPLETED)
            ->exists();
    }

    public static function getForTest(string $testSampleItemId): array
    {
        return self::where('test_sample_item_id', $testSampleItemId)
            ->with('machine')
            ->get()
            ->toArray();
    }

    public function changeMachine(string $newMachineId, ?string $reason = null): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        $oldMachineId = $this->machine_id;

        $this->machine_id = $newMachineId;
        $this->status = self::STATUS_PENDING;

        // Audit moved to the worklist_reassignments table
        // (see WorklistService::reassignWorklist)
        return $this->save();
    }

    // ============================================
    // ACCESSORS
    // ============================================

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_LOCKED => 'Locked (Completed on another machine)',
            default => ucfirst($this->status),
        };
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_PROCESSING => 'info',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_CANCELLED => 'danger',
            self::STATUS_LOCKED => 'secondary',
            default => 'secondary',
        };
    }

    public function getPriorityLabelAttribute(): string
    {
        return match ($this->priority) {
            self::PRIORITY_HIGH => 'High',
            self::PRIORITY_ROUTINE => 'Routine',
            self::PRIORITY_LOW => 'Low',
            default => ucfirst($this->priority),
        };
    }

    public function getPriorityBadgeAttribute(): string
    {
        return match ($this->priority) {
            self::PRIORITY_HIGH => 'danger',
            self::PRIORITY_ROUTINE => 'info',
            self::PRIORITY_LOW => 'secondary',
            default => 'secondary',
        };
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function getIsProcessingAttribute(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function getIsCompletedAttribute(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function getIsCancelledAttribute(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function getIsLockedAttribute(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }

    public function getTubeBarcodeAttribute(): ?string
    {
        return $this->testSampleItem?->testSample?->barcode ?? $this->barcode;
    }

    public function getReceiptBarcodeAttribute(): ?string
    {
        return $this->labReceipt?->barcode ?? $this->receipt_barcode;
    }

    public function getPatientNameAttribute(): ?string
    {
        return $this->patient?->name ?? $this->patient_name;
    }

    public function getPatientAgeAttribute(): ?int
    {
        return $this->patient?->age ?? $this->patient_age;
    }

    public function getPatientGenderAttribute(): ?string
    {
        return $this->patient?->gender ?? $this->patient_gender;
    }

    public function getTubeTypeAttribute(): ?string
    {
        return $this->testSampleItem?->testSample?->tube_type ?? $this->tube_type;
    }

    public function getTimingAttribute(): ?string
    {
        return $this->testSampleItem?->testSample?->timing ?? $this->timing;
    }

    public function getSampleNumberAttribute(): ?int
    {
        // worklists.sample_number was dropped — always derive from the tube
        return $this->testSampleItem?->testSample?->tube_sequence;
    }

    public function getSlNoAttribute(): ?string
    {
        return $this->labReceipt?->sl_no;
    }

    public function getFormattedPatientAttribute(): string
    {
        $parts = [];
        if ($this->patient_name) {
            $parts[] = $this->patient_name;
        }
        if ($this->patient_age !== null) {
            $parts[] = $this->patient_age . 'Y';
        }
        if ($this->patient_gender) {
            $parts[] = $this->patient_gender;
        }
        return implode(' ', $parts);
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }

    public function getPatientInfo(): array
    {
        return [
            'id' => $this->patient_id,
            'name' => $this->patient_name,
            'age' => $this->patient_age,
            'gender' => $this->patient_gender,
            'phone' => $this->patient?->phone,
        ];
    }

    public function getBarcodeInfo(): array
    {
        return [
            'receipt_barcode' => $this->receipt_barcode,
            'tube_barcode' => $this->barcode,
            'sl_no' => $this->sl_no,
        ];
    }

    public function getTestInfo(): array
    {
        return [
            'id' => $this->test_id,
            'name' => $this->test_name,
            'code' => $this->test_code,
            'sample_type' => $this->sample_type,
            'tube_type' => $this->tube_type,
            'timing' => $this->timing,
        ];
    }

    public function getMachineInfo(): array
    {
        return [
            'id' => $this->machine_id,
            'name' => $this->machine?->display_name,
            'device_model' => $this->machine?->device_model,
        ];
    }

    public function getStatusInfo(): array
    {
        return [
            'status' => $this->status,
            'status_label' => $this->status_label,
            'status_badge' => $this->status_badge,
            'priority' => $this->priority,
            'priority_label' => $this->priority_label,
            'collection_date' => $this->collection_date?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }

    // REMOVED: getParameter(), getResult(), hasResult(), generateOrderId()
    // (parameters / result_data / order_id columns are dropped)

    // ============================================
    // API Response Helper
    // ============================================

    public function toApiResponse(): array
    {
        return [
            'worklist_id' => $this->id,

            // Barcodes
            'receipt_barcode' => $this->receipt_barcode,
            'tube_barcode' => $this->barcode,
            'sl_no' => $this->sl_no,

            // Patient
            'patient' => [
                'id' => $this->patient_id,
                'name' => $this->patient_name,
                'age' => $this->patient_age,
                'gender' => $this->patient_gender,
                'phone' => $this->patient?->phone,
            ],

            // Test
            'test' => [
                'id' => $this->test_id,
                'name' => $this->test_name,
                'code' => $this->test_code,
                'sample_type' => $this->sample_type,
                'tube_type' => $this->tube_type,
                'timing' => $this->timing,
            ],

            // Machine
            'machine' => [
                'id' => $this->machine_id,
                'name' => $this->machine?->display_name,
                'device_model' => $this->machine?->device_model,
            ],

            // Status
            'status' => $this->status,
            'status_label' => $this->status_label,
            'priority' => $this->priority,
            'priority_label' => $this->priority_label,

            // Dates
            'collection_date' => $this->collection_date?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'pushed_at' => $this->pushed_at?->toISOString(),

            // Result pointer + lifecycle (replaces parameters/result_data blobs)
            'machine_mapping_id' => $this->machine_mapping_id,
            'latest_result_id' => $this->latest_result_id,
            'result_count' => $this->result_count,
            'source' => $this->source,
            'cancelled_reason' => $this->cancelled_reason,

            // REMOVED: order_id, order_date, parameters, result_data,
            //          reassignment_history (columns dropped)
        ];
    }

    // ============================================
    // BOOT
    // ============================================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid7();
            }
            // REMOVED: order_id generation (column dropped)
        });
    }
}
