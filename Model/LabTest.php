<?php

namespace App\Models;

use App\Scopes\SelfTenantScope;
use App\Traits\BelongsToSelfTenant;
use App\Traits\CreatableTrait;
use App\Traits\IsAppend;
use App\Traits\ManageUploadFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class LabTest extends BaseModel
{
    use BelongsToSelfTenant, CreatableTrait, IsAppend, ManageUploadFile, SoftDeletes;

    protected $table = 'lab_tests';

    protected $fillable = [
        'id', 'uuid', 'sl_no', 'tenant_id', 'lab_id', 'test_id', 'patient_id', 'min_price', 'wr', 'from_lab_id', 'to_lab_id',
        'reporting_charge', 'referral_commission', 'contract_commission', 'price',
        'discount', 'amount', 'paid', 'note', 'duration', 'has_entered', 'has_result',
        'done', 'comment', 'collected_by', 'prepared_by', 'reviewed_by', 'authorized_by',
        'delivered_by', 'collected_at', 'result_at', 'prepared_at', 'reviewed_at',
        'authorized_at', 'delivered_at', 'created_by', 'updated_by', 'machine_id',

        // try bypass it not used later
        'machine_code', 'machine_assigned_at', 'machine_sent_at',
        'machine_result_at', 'machine_status', 'middleware_message_id', 'middleware_status',
        'middleware_retry_count', 'lab_status', 'is_retest', 'retest_of', 'retest_reason',
        'cancelled_at', 'priority', 'requested_at', 'started_at', 'completed_at',
        'verified_at', 'cancelled_by', 'cancellation_reason', 'verified_by', 'metadata',
        'status', 'processing_started_at', 'sent_at',
    ];

    protected $appends = ['test_name', 'status_label', 'progress'];

    protected $casts = [
        'done' => 'boolean',
        'has_entered' => 'boolean',
        'has_result' => 'boolean',
        'is_retest' => 'boolean',
        'collected_at' => 'datetime',
        'prepared_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'authorized_at' => 'datetime',
        'result_at' => 'datetime',
        'delivered_at' => 'datetime',
        'machine_assigned_at' => 'datetime',
        'machine_sent_at' => 'datetime',
        'machine_result_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'requested_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'verified_at' => 'datetime',
        'discount' => 'decimal:2',
        'price' => 'decimal:2',
        'amount' => 'decimal:2',
        'paid' => 'decimal:2',
        'min_price' => 'decimal:2',
        'metadata' => 'array',
        'middleware_retry_count' => 'integer',
    ];

    /**
     * Relationships
     */
    public function components(): HasMany
    {
        return $this->hasMany(TestComponent::class, 'test_id', 'test_id')->orderBy('order');
    }

    public function reports(): MorphMany
    {
        return $this->morphMany(TestReport::class, 'model');
    }

    public function test_samples(): BelongsToMany
    {
        return $this->belongsToMany(TestSample::class, 'test_sample_items', 'lab_test_id', 'test_sample_id')
            ->withPivot('suffix', 'status', 'collected_by', 'collected_at')   // REMOVED: 'sample_number' (column dropped); suffix KEPT (needed)
            ->withTimestamps();
    }

    public function sampleItems(): LabTest|HasMany
    {
        return $this->hasMany(TestSampleItem::class, 'lab_test_id', 'id');
    }

    // old sample
    //    public function sample(): HasOne
    //    {
    //        return $this->hasOne(Sample::class, 'lab_test_id', 'id');
    //    }

    // new sample is same as sampleItems

    public function sample(): HasOne
    {
        return $this->hasOne(TestSampleItem::class, 'lab_test_id', 'id')
            ->latestOfMany(); // orders by created_at desc, takes newest
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(LabReceipt::class, 'id', 'lab_id');
    }

    /**
     * Get the worklist entry for this lab test
     */
    public function worklist(): HasOne
    {
        return $this->hasOne(Worklist::class, 'lab_test_id', 'id');
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class, 'test_id', 'id');
    }

    public function collector(): HasOne
    {
        return $this->hasOne(User::class, 'id', 'collected_by')->withoutGlobalScope(SelfTenantScope::class);
    }

    public function prepared(): HasOne
    {
        return $this->hasOne(User::class, 'id', 'prepared_by')->withoutGlobalScope(SelfTenantScope::class);
    }

    public function reviewed(): HasOne
    {
        return $this->hasOne(User::class, 'id', 'reviewed_by')->withoutGlobalScope(SelfTenantScope::class);
    }

    public function authorized(): HasOne
    {
        return $this->hasOne(User::class, 'id', 'authorized_by')->withoutGlobalScope(SelfTenantScope::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function retestOf(): BelongsTo
    {
        return $this->belongsTo(LabTest::class, 'retest_of');
    }

    public function retests(): LabTest|Builder|HasMany
    {
        return $this->hasMany(LabTest::class, 'retest_of');
    }

    public function sampleLabTests(): LabTest|Builder|HasMany
    {
        return $this->hasMany(SampleLabTest::class);
    }

    /**
     * Accessors
     */
    public function getTestNameAttribute(): string
    {
        return $this->test->name ?? '';
    }

    public function getStatusLabelAttribute(): string
    {
        $labels = [
            'ordered' => 'Ordered',
            'sample_collected' => 'Sample Collected',
            'assigned_to_machine' => 'Assigned',
            'sent_to_machine' => 'Sent to Machine',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'verified' => 'Verified',
            'cancelled' => 'Cancelled',
            'failed' => 'Failed',
        ];

        return $labels[$this->lab_status] ?? ucfirst($this->lab_status);
    }

    public function getProgressAttribute(): float
    {
        if ($this->completed_at) {
            return 100;
        }
        if ($this->started_at) {
            return 50;
        }
        if ($this->machine_sent_at) {
            return 30;
        }
        if ($this->machine_assigned_at) {
            return 20;
        }
        if ($this->collected_at) {
            return 10;
        }

        return 0;
    }

    public function getIsCompletedAttribute(): bool
    {
        return $this->lab_status === 'completed' || $this->completed_at !== null;
    }

    public function getIsVerifiedAttribute(): bool
    {
        return $this->lab_status === 'verified' || $this->verified_at !== null;
    }

    /**
     * Methods
     */
    public function genSl($test_id): string
    {
        $prefix = Carbon::now()->format('ymd');
        $latestBarcode = LabTest::withTrashed()->where('sl_no', 'like', $prefix.'%')
            ->where('test_id', $test_id)
            ->max('sl_no');

        return $prefix.str_pad(((int) substr($latestBarcode ?? 0, 6) + 1), 4, '0', STR_PAD_LEFT);
    }

    public function assignToMachine($machineId, $userId = null)
    {
        $this->update([
            'machine_id' => $machineId,
            'lab_status' => 'assigned_to_machine',
            'machine_assigned_at' => now(),
            'machine_status' => 'assigned',
        ]);

        // Update pivot if exists
        if ($this->sampleLabTests()->exists()) {
            $this->sampleLabTests()->update([
                'machine_id' => $machineId,
                'status' => 'assigned',
                'assigned_at' => now(),
            ]);
        }

        event(new TestAssignedToMachineEvent($this, $userId));
    }

    public function markAsSent(): void
    {
        $this->update([
            'lab_status' => 'sent_to_machine',
            'machine_sent_at' => now(),
            'machine_status' => 'sent',
        ]);

        if ($this->sampleLabTests()->exists()) {
            $this->sampleLabTests()->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        }
    }

    public function markAsProcessing(): void
    {
        $this->update([
            'lab_status' => 'processing',
            'started_at' => now(),
            'machine_status' => 'processing',
        ]);

        if ($this->sampleLabTests()->exists()) {
            $this->sampleLabTests()->update([
                'status' => 'processing',
                'processing_started_at' => now(),
            ]);
        }
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'lab_status' => 'completed',
            'completed_at' => now(),
            'machine_status' => 'completed',
        ]);

        if ($this->sampleLabTests()->exists()) {
            $this->sampleLabTests()->update([
                'status' => 'completed',
                'result_at' => now(),
            ]);
        }
    }

    public function verify($userId): void
    {
        $this->update([
            'lab_status' => 'verified',
            'verified_at' => now(),
            'verified_by' => $userId,
        ]);
    }

    public function cancel($reason, $userId): void
    {
        $oldData = $this->getAttributes();

        $this->update([
            'lab_status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $userId,
            'cancellation_reason' => $reason,
        ]);

        TestCancellation::create([
            'tenant_id' => $this->tenant_id,
            'lab_test_id' => $this->id,
            'receipt_id' => $this->lab_id,
            'cancellation_type' => 'other',
            'reason' => $reason,
            'old_data' => $oldData,
            'cancelled_by' => $userId,
        ]);
    }

    public function createRetest($reason, $userId): LabTest
    {
        $newTest = $this->replicate();
        $newTest->is_retest = true;
        $newTest->retest_of = $this->id;
        $newTest->retest_reason = $reason;
        $newTest->lab_status = 'ordered';
        $newTest->cancelled_at = null;
        $newTest->cancelled_by = null;
        $newTest->cancellation_reason = null;
        $newTest->machine_assigned_at = null;
        $newTest->machine_sent_at = null;
        $newTest->machine_result_at = null;
        $newTest->started_at = null;
        $newTest->completed_at = null;
        $newTest->verified_at = null;
        $newTest->save();

        return $newTest;
    }

    public static function boot()
    {
        parent::boot();
        self::creating(function ($model) {
            // $model->uuid = (string) Str::uuid7();
            if (isset($model->test_id)) {
                $model->sl_no = $model->genSl($model->test_id);
            }
        });
    }
}
