<?php

namespace App\Models;

use App\Traits\BelongsToSelfTenant;
use App\Traits\HasSimpleSampleBarcode;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestSample extends BaseModel
{
    use BelongsToSelfTenant;
    use HasSimpleSampleBarcode;

    protected $table = 'test_samples';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'barcode',
        // REMOVED: 'sl_no' — column dropped (receipt-level sl_no lives on lab_receipts and is kept)
        'tenant_id',
        'lab_receipt_id',
        'patient_id',
        'tube_type',
        'product_id',
        'timing',
        'tube_sequence',
        'barcode_source',
        'shared_from_tenant_barcode',
        'collected_by',
        'collected_at',
        'note',
    ];

    protected $casts = [
        'collected_at' => 'datetime',
        'tube_sequence' => 'integer',
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    public function labReceipt(): BelongsTo
    {
        return $this->belongsTo(LabReceipt::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by', 'id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TestSampleItem::class, 'test_sample_id', 'id');
    }

    public function labTests(): BelongsToMany
    {
        return $this->belongsToMany(LabTest::class, 'test_sample_items', 'test_sample_id', 'lab_test_id')
            ->withTimestamps();
    }

    // ============================================
    // OVERRIDE PARENT METHODS FROM TRAIT
    // ============================================

    /**
     * Get parent receipt barcode
     * This is called by HasSimpleSampleBarcode trait
     */
    protected function getParentReceiptBarcode(): ?string
    {
        return $this->labReceipt?->barcode;
    }

    /**
     * Get lab receipt ID for tube counter
     * This is called by HasSimpleSampleBarcode trait
     */
    protected function getLabReceiptId(): string
    {
        return $this->lab_receipt_id;
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

    public function scopeByBarcode($query, string $barcode)
    {
        return $query->where('barcode', $barcode);
    }

    public function scopeByReceipt($query, string $labReceiptId)
    {
        return $query->where('lab_receipt_id', $labReceiptId);
    }
}
