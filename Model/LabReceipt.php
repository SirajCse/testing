<?php

namespace App\Models;

use App\Enums\SerialModule;
use App\Traits\BelongsToSelfTenant;
use App\Traits\CreatableTrait;
use App\Traits\HasSimpleBarcode;
use App\Traits\IsAppend;
use App\Traits\ManageUploadFile;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Overtrue\LaravelVersionable\Versionable;
use Overtrue\LaravelVersionable\VersionStrategy;
use Str;

class LabReceipt extends BaseModel
{
    use BelongsToSelfTenant;
    use CreatableTrait;
    use HasSimpleBarcode;
    use IsAppend;
    use ManageUploadFile;
    use SoftDeletes;
    use Versionable;

    protected $versionable = ['date', 'patient_id', 'referral_id', 'referral_commission', 'contract_id', 'from_lab_id', 'to_lab_id', 'contract_commission',
        'visit_id', 'discount', 'subtotal', 'total', 'paid', 'due', 'tax', 'done', 'note', 'pay_note', 'pay_status', 'test_names'];

    protected $dontVersionable = ['created_at', 'updated_at'];

    protected $versionStrategy = VersionStrategy::DIFF;

    protected $fillable = [
        'id', 'uuid', 'tenant_id', 'barcode', 'sl_no', 'date', 'patient_id', 'referral_id', 'from_lab_id', 'to_lab_id',
        'referral_commission', 'contract_id', 'contract_commission', 'visit_id', 'discount',
        'subtotal', 'total', 'paid', 'due', 'tax', 'done', 'note', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'date' => 'datetime',
        'done' => 'boolean',
        'discount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
        'paid' => 'decimal:2',
        'due' => 'decimal:2',
        'tax' => 'decimal:2',
    ];

    public function barcodeModule(): SerialModule
    {
        return SerialModule::LabReceipt;  // Returns LAB
    }

    /**
     * Relationships
     */
    public function payments()
    {
        return $this->morphMany(Payment::class, 'payable')
            ->oldest('id');
    }

    public function patient()
    {
        return $this->hasOne(Patient::class, 'id', 'patient_id')->withoutAppend()->withDefault(['name' => 'N/A']);
    }

    public function pcPaid(): HasOne
    {
        return $this->hasOne(PersonnelCommissionHead::class, 'model_id', 'id')->withDefault();
    }

    public function fromLab(): HasOne
    {
        return $this->hasOne(Lab::class, 'id', 'from_lab_id');
    }

    public function toLab(): HasOne
    {
        return $this->hasOne(Lab::class, 'id', 'to_lab_id');
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class, 'id', 'contract_id');
    }

    public function referral(): HasOne
    {
        return $this->hasOne(Referral::class, 'id', 'referral_id');
    }

    public function labTest(): HasMany
    {
        return $this->hasMany(LabTest::class, 'lab_id', 'id');
    }


    public function samples()
    {
        return $this->hasMany(TestSample::class, 'lab_receipt_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(LabTest::class, 'lab_id', 'id');
    }

    public function labExtra(): HasMany
    {
        return $this->hasMany(LabExtra::class, 'lab_id', 'id');
    }

    //    public function sample(): HasMany
    //    {
    //        return $this->hasMany(Sample::class, 'receipt_id', 'id');
    //    }
    //
    //    public function samples(): HasMany
    //    {
    //        return $this->hasMany(Sample::class, 'receipt_id', 'id');
    //    }

    /**
     * Accessors
     */
    public function getTotalAmountAttribute()
    {
        return $this->total;
    }

    public function getDueAmountAttribute()
    {
        return $this->due;
    }

    public function getStatusLabelAttribute(): string
    {
        $labels = [
            'pending' => 'Pending',
            'collected' => 'Collected',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'verified' => 'Verified',
            'cancelled' => 'Cancelled',
            'partial' => 'Partial',
        ];

        return $labels[$this->status] ?? ucfirst($this->status);
    }

    public function getCollectionStatusAttribute($value)
    {
        $total = $this->samples()->count();
        $collected = $this->samples()->whereNotNull('collected_at')->count();

        if ($total === 0) {
            return 'pending';
        }
        if ($collected === 0) {
            return 'pending';
        }
        if ($collected < $total) {
            return 'partial';
        }

        return 'completed';
    }

    public function addTest($testId, $price = null)
    {
        return LabTest::create([
            'lab_id' => $this->id,
            'test_id' => $testId,
            'tenant_id' => $this->tenant_id,
            'patient_id' => $this->patient_id,
            'price' => $price ?? Test::find($testId)->config->price ?? 0,
            'amount' => $price ?? Test::find($testId)->config->price ?? 0,
        ]);
    }

    public function removeTest($labTestId): void
    {
        $labTest = LabTest::find($labTestId);
        if ($labTest && $labTest->lab_id == $this->id) {
            $labTest->delete();
            $this->recalculateTotals();
        }
    }

    public function recalculateTotals(): void
    {
        $total = $this->labTest()->sum('amount');
        $this->update([
            'subtotal' => $total,
            'total' => $total - $this->discount,
            'due' => ($total - $this->discount) - $this->paid,
        ]);
    }

    public function genBarcode(int $attempt = 1): string
    {
        try {
            $latestBarcode = LabReceipt::withTrashed()->max('barcode');

            return str_pad(((int) substr($latestBarcode ?? 0, -6) + 1), 6, '0', STR_PAD_LEFT);
        } catch (\Throwable $e) {
            if ($attempt <= 3) {
                sleep(random_int(1, 5));
                Str::sleep(random_int(1, 5));

                return $this->genBarcode($attempt + 1);
            }

            return '000000';
        }
    }

    public function genSl(int $attempt = 1): string
    {
        try {
            $prefix = date('ymd');
            $latestBarcode = LabReceipt::withTrashed()->where('sl_no', 'like', $prefix.'%')->max('sl_no');

            return $prefix.str_pad(((int) substr($latestBarcode ?? 0, 6) + 1), 4, '0', STR_PAD_LEFT);
        } catch (\Throwable $e) {
            try {
                return $this->genSl();
            } catch (\Throwable $e) {
                if ($attempt <= 3) {
                    sleep(random_int(1, 5));

                    return $this->genSl($attempt + 1);
                }

                return $this->genBarcode();
            }
        }
    }

    public static function boot()
    {
        parent::boot();
        self::creating(static function ($model) {

            if (! isset($model['referral_id'])) {
                $model['referral_id'] = Cache::rememberForever('default_referral_id', fn () => Referral::query()->value('id'));
            }
            if (! isset($model['contract_id'])) {
                $model['contract_id'] = Cache::rememberForever('default_referral_id', fn () => Contract::query()->value('id'));
            }

            //            if (! isset($model['barcode'])) {
            //                $model['barcode'] = $model->genBarcode();
            //            }
            //
            //            if (! isset($model['sl_no'])) {
            //                $model['sl_no'] = $model->genSl();
            //            }

            // $model->uuid = (string) Str::uuid7();
        });
    }
}
