<?php

namespace App\Models;

use App\Traits\CreatableTrait;
use App\Traits\ManageUploadFile;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class TestReport extends BaseModel
{
    use CreatableTrait, ManageUploadFile, SoftDeletes;

    protected $table = 'test_reports';

    protected $fillable = [
        'id', 'model_id', 'model_type', 'patient_id', 'test_id', 'component_id',
        'date', 'type', 'result', 'result_text', 'reference_text', 'mic_value', 'zone_value', 'document', 'unit', 'unit_id',
        'status', 'order', 'color', 'hide', 'share', 'created_by', 'updated_by',
        'deleted_by', 'verified_at', 'verified_by', 'tenant_id',
    ];

    protected $appends = ['date_formatted', 'result_display', 'flag'];

    protected $casts = [
        'date' => 'datetime',
        'verified_at' => 'datetime',
        //        'is_verified' => 'boolean',
        'hide' => 'boolean',
        'share' => 'boolean',
        'order' => 'integer',
        //        'raw_import_data' => 'array',
    ];

    public function component(): BelongsTo
    {
        return $this->belongsTo(TestComponent::class, 'component_id', 'id')->orderBy('order', 'asc');
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class, 'test_id', 'id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'id');
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    protected function dateFormatted(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->date ? $this->date->format('Y-m-d h:i A') : null
        );
    }

    public function getResultDisplayAttribute()
    {
        if ($this->type === 'textarea') {
            return $this->result_text;
        }

        return $this->result;
    }

    public function getFlagAttribute()
    {
        if (! $this->component) {
            return null;
        }

        $ref = $this->component->getReferenceRange(
            $this->patient?->gender,
            $this->patient ? $this->patient->age_months : null
        );

        if ($ref && is_numeric($this->result)) {
            if ($ref->min && $this->result < $ref->min) {
                return 'L';
            }
            if ($ref->max && $this->result > $ref->max) {
                return 'H';
            }
        }

        return null;
    }

    public function getIsAbnormalAttribute(): bool
    {
        return $this->flag !== null;
    }

    private function formatDate($date): ?string
    {
        if (! $date) {
            return null;
        }
        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    public function verify($userId)
    {
        $this->update([
            //            'is_verified' => true,
            'verified_at' => now(),
            'verified_by' => $userId,
        ]);
    }

    public function markAsPrinted(): void
    {
        $this->update(['share' => true]);
    }
}
