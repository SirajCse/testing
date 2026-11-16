<?php

namespace App\Models;

use App\Traits\BelongsToSelfTenant;
use Illuminate\Database\Eloquent\SoftDeletes;

class TestConsumption extends BaseModel
{
    use BelongsToSelfTenant, SoftDeletes;

    protected $table = 'test_consumptions';

    protected $fillable = [
        'id',
        'tenant_id',
        'test_id',
        'product_id',
        'individual',
        'shareable',
        'timing',
        'is_label',
        'is_box',
        'chargeable',
        'discountable',
        'quantity',
    ];

    public $appends = ['product_name', 'price', 'min_price'];

    protected $casts = [
        'timing' => 'array',  // JSON cast for OGTT timing array
        'individual' => 'boolean',
        'shareable' => 'boolean',
        'is_label' => 'boolean',
        'is_box' => 'boolean',
        'chargeable' => 'boolean',
        'discountable' => 'boolean',
        'quantity' => 'decimal:2',
    ];

    protected $attributes = [
        'is_label' => 1,
        'is_box' => 0,
        'individual' => 0,
        'shareable' => 1,
    ];

    public function getProductNameAttribute(): string
    {
        return $this->product->name ?? '';
    }

    public function getPriceAttribute(): string
    {
        return $this->product->sale_price ?? '';
    }

    public function getMinPriceAttribute(): string
    {
        return $this->product->min_price ?? '';
    }

    public function test()
    {
        return $this->belongsTo(Test::class, 'test_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    // Helper method to check if this consumption requires label printing
    public function requiresLabel(): bool
    {
        return (bool) $this->is_label;
    }

    // Helper method to check if this consumption requires box printing
    public function requiresBox(): bool
    {
        return (bool) $this->is_box;
    }

    // Helper method to get timings as array (always returns array)
    public function getTimingsArray(): array
    {
        if (empty($this->timing)) {
            return [''];
        }

        if (is_string($this->timing)) {
            $decoded = json_decode($this->timing, true);
            if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
            return [$this->timing];
        }

        if (is_array($this->timing)) {
            return $this->timing;
        }

        return [''];
    }

    // Helper method to check if this is an OGTT test (multiple timings)
    public function isOgtt(): bool
    {
        return count($this->getTimingsArray()) > 1;
    }

    // Helper method to get effective quantity (count of timings or quantity)
    public function getEffectiveQuantity(): int
    {
        $timings = $this->getTimingsArray();

        if (count($timings) > 1) {
            return count($timings);
        }

        return (int) ($this->quantity ?? 1);
    }
}
