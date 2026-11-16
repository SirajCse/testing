<?php

namespace App\Models;

use App\Enums\SerialModule;
use App\Traits\BelongsToSelfTenant;
use App\Traits\CreatableTrait;
use App\Traits\HasSimpleBarcode;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class LabReport extends BaseModel
{
    use BelongsToSelfTenant;
    use CreatableTrait;
    use SoftDeletes;
    use HasSimpleBarcode;

    //    use ManageUploadFile;

    protected $fillable = ['tenant_id', 'barcode', 'sl_no', 'patient_id', 'referral_id', 'referral_commission', 'contract_id', 'contract_commission', 'visit_id', 'discount', 'subtotal', 'total', 'paid', 'due', 'done', 'report_pdf', 'receipt_pdf', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'done' => 'boolean',
        ];
    }

    public $guarded = [];


    public function barcodeModule(): SerialModule
    {
        return SerialModule::LabReport;  // Returns enum
    }

    /**
     * Relationships
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function boot()
    {
        parent::boot();
        self::creating(function ($model) {
            if (! isset($model['referral_id'])) {
                $model['referral_id'] = Cache::rememberForever('default_referral_id', fn () => Referral::query()->value('id'));
            }
            if (! isset($model['contract_id'])) {
                $model['contract_id'] = Cache::rememberForever('default_referral_id', fn () => Contract::query()->value('id'));
            }
        });
    }
}
