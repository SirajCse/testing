<?php

namespace App\Models;

use App\Traits\BelongsToSelfTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestSampleItem extends BaseModel
{
    use BelongsToSelfTenant;

    protected $table = 'test_sample_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'test_sample_id',
        'lab_test_id',
        'test_id',
        'collected_by',
        'collected_at',
        'notes',
        'suffix',           // KEPT — needed (tube identity; column stays on test_sample_items)
        // REMOVED: 'sample_number' — column dropped (derive from testSample.tube_sequence)
        'status',
    ];

    protected $casts = [
        'collected_at' => 'datetime',
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    public function testSample(): BelongsTo
    {
        return $this->belongsTo(TestSample::class, 'test_sample_id', 'id');
    }

    public function labTest(): BelongsTo
    {
        return $this->belongsTo(LabTest::class);
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by', 'id');
    }

    public function worklist()
    {
        return $this->hasOne(Worklist::class, 'test_sample_item_id', 'id')
            ->whereIn('status', ['pending', 'processing']);
    }

    public function worklists()
    {
        return $this->hasMany(Worklist::class, 'test_sample_item_id', 'id');
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeCollected($query)
    {
        return $query->whereNotNull('collected_by');
    }

    public function scopeUncollected($query)
    {
        return $query->whereNull('collected_by');
    }

    public function scopeByTest($query, string $testId)
    {
        return $query->where('test_id', $testId);
    }

    public function scopeByTube($query, string $testSampleId)
    {
        return $query->where('test_sample_id', $testSampleId);
    }

    // ============================================
    // ACCESSORS
    // ============================================

    public function getIsCollectedAttribute(): bool
    {
        return ! is_null($this->collected_by) && ! is_null($this->collected_at);
    }

    public function getIsCompletedAttribute(): bool
    {
        $worklist = $this->worklist;

        return $worklist && $worklist->status === 'completed';
    }

    public function getIsProcessingAttribute(): bool
    {
        $worklist = $this->worklist;

        return $worklist && $worklist->status === 'processing';
    }

    public function getIsPendingAttribute(): bool
    {
        $worklist = $this->worklist;

        return ! $worklist || $worklist->status === 'pending';
    }

    public function getStatusAttribute(): string
    {
        if ($this->is_collected) {
            if ($this->is_completed) {
                return 'completed';
            }
            if ($this->is_processing) {
                return 'processing';
            }

            return 'collected';
        }

        return 'pending';
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Pending',
            'collected' => 'Collected',
            'processing' => 'Processing',
            'completed' => 'Completed',
            default => ucfirst($this->status),
        };
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'secondary',
            'collected' => 'primary',
            'processing' => 'warning',
            'completed' => 'success',
            default => 'secondary',
        };
    }

    public function getTestNameAttribute(): string
    {
        return $this->test?->name ?? $this->labTest?->test?->name ?? 'Unknown';
    }

    public function getShortNameAttribute(): string
    {
        return $this->test?->short_name ?? $this->labTest?->test?->short_name ?? $this->getTestNameAttribute();
    }

    public function getTimingAttribute(): ?string
    {
        return $this->testSample?->timing;
    }

    public function getTubeBarcodeAttribute(): ?string
    {
        return $this->testSample?->barcode;
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    public function markAsCollected(string $userId): bool
    {
        $this->collected_by = $userId;
        $this->collected_at = now();

        return $this->save();
    }

    public function markAsUncollected(): bool
    {
        $this->collected_by = null;
        $this->collected_at = null;

        return $this->save();
    }

    public function getBestMachine(): ?Machine
    {
        return Machine::whereHas('testMappings', function ($q) {
            $q->where('test_id', $this->test_id)->where('is_active', true);
        })
            ->where('is_active', true)
            ->orderBy('priority', 'desc')
            ->first();
    }

    public function getAvailableMachines(): array
    {
        return Machine::whereHas('testMappings', function ($q) {
            $q->where('test_id', $this->test_id)->where('is_active', true);
        })
            ->where('is_active', true)
            ->orderBy('priority', 'desc')
            ->get()
            ->toArray();
    }

    protected static function booted(): void
    {
        static::creating(function (self $item) {
            // Auto-resolve test_id from lab_test if missing
            if (empty($item->test_id) && ! empty($item->lab_test_id)) {
                $labTest = LabTest::find($item->lab_test_id);
                if ($labTest) {
                    $item->test_id = $labTest->test_id ?? $labTest->test?->id;
                }
            }
        });
    }
}
