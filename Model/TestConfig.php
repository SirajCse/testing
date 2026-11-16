<?php

namespace App\Models;

use App\Traits\BelongsToSelfTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TestConfig extends BaseModel
{
    use BelongsToSelfTenant, SoftDeletes;

    protected $table = 'test_configs';

    protected $fillable = [
        'id', 'tenant_id', 'test_id', 'name', 'short_name', 'precautions', 'price', 'min_price', 'lab_price',
        'tax', 'reporting_charge', 'referral_commission', 'contract_commission', 'theme',
        'status', 'order', 'test_room', 'test_room_show', 'sample_collect_room',
        'sample_collect_room_show', 'sample_type', 'physically', 'is_same_page', 'is_separated', 'custom_js', 'thead',
        'show_title', 'show_result', 'show_reference', 'show_status', 'auto_result',
        'result_color', 'number_table', 'is_culture', 'culture_show_class', 'culture_show_mic', 'culture_show_zone', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'min_price' => 'decimal:2',
        'lab_price' => 'decimal:2',
        'tax' => 'decimal:2',
        'reporting_charge' => 'decimal:2',
        'referral_commission' => 'decimal:2',
        'contract_commission' => 'decimal:2',
        'order' => 'integer',
        'is_same_page' => 'boolean',
        'is_separated' => 'boolean',
        'show_title' => 'boolean',
        'show_result' => 'boolean',
        'show_reference' => 'boolean',
        'show_status' => 'boolean',
        'auto_result' => 'boolean',
        'culture_show_class' => 'boolean',
        'culture_show_mic' => 'boolean',
        'culture_show_zone' => 'boolean',
        'is_culture' => 'boolean',
    ];

    /**
     * ⭐ Auto-invalidate Test::$defaultConfigCache whenever this model changes.
     */
    protected static function booted(): void
    {
        static::created(function (TestConfig $config) {
            if ($config->test_id) {
                Test::forgetDefaultConfigCache($config->test_id);
            }
        });

        static::updated(function (TestConfig $config) {
            if ($config->test_id) {
                Test::forgetDefaultConfigCache($config->test_id);
            }
        });

        static::deleted(function (TestConfig $config) {
            if ($config->test_id) {
                Test::forgetDefaultConfigCache($config->test_id);
            }
        });

        // Soft delete restore
        static::restored(function (TestConfig $config) {
            if ($config->test_id) {
                Test::forgetDefaultConfigCache($config->test_id);
            }
        });

        // Force delete
        static::forceDeleted(function (TestConfig $config) {
            if ($config->test_id) {
                Test::forgetDefaultConfigCache($config->test_id);
            }
        });
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }

    public function getPrice()
    {
        return $this->price ?? 0;
    }

    public function getMinPrice()
    {
        return $this->min_price ?? 0;
    }

    public function getFinalPrice($quantity = 1): float|int
    {
        return ($this->price ?? 0) * $quantity;
    }
}
