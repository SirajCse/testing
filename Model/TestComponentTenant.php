<?php

namespace App\Models;

use App\Traits\BelongsToSelfTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class TestComponentTenant extends BaseModel
{
    use BelongsToSelfTenant;

    public $timestamps = false;

    protected $table = 'test_component_tenants';

    protected $fillable = [
        'id', 'tenant_id', 'test_component_id',
        'unit', 'convert_unit', 'factor',
        'reference_text',
        'default_value',
        'option_value',
        'mic_value',
        'zone_value',
        'hide',
        'order',
        'status',
    ];

    protected $casts = [
        'factor' => 'decimal:4',
        'order' => 'integer',
        'status' => 'integer',
        'hide' => 'integer',
    ];

    public function testComponent(): BelongsTo
    {
        return $this->belongsTo(TestComponent::class, 'test_component_id');
    }

    protected static function booted(): void
    {
        $forget = function (self $row) {
            Cache::forget("resolved_version_tenant_{$row->tenant_id}");
        };

        static::saved($forget);
        static::deleted($forget);
//        static::restored($forget);
//        static::forceDeleted($forget);
    }
}
