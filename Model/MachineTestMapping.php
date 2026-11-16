<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineTestMapping extends BaseModel
{
    protected $table = 'machine_test_mappings';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'machine_id',
        'test_id',
        'test_code',
        'is_multi_component',
        'sample_type',
        'is_active',
        'priority',
        'parameters',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
        'parameters' => 'array',
    ];

    /**
     * Get the machine for this mapping.
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'machine_id', 'id');
    }

    /**
     * Get the test for this mapping.
     */
    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class, 'test_id', 'id');
    }

    /**
     * Check if mapping is active.
     */
    public function isActive(): bool
    {
        return (bool)$this->is_active;
    }
}
