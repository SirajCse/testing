<?php

namespace App\Models;

use App\Services\WorklistService;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Machine extends BaseModel
{
    protected $table = 'machines';

    /**
     * Disable auto-incrementing for UUID primary key.
     */
    public $incrementing = false;

    /**
     * Primary key type (string for UUID).
     */
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'id',
        'name',
        'display_name',
        'device_model',
        'ip_address',
        'port',
        'protocol',
        'protocol_version',
        'auto_push_enabled',
        'auto_push_direction',
        'direction', // need add view and table
        'api_key',
        'is_active',
        'priority',
        'settings',
        'last_received_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'auto_push_enabled' => 'boolean',
        'is_active' => 'boolean',
        'priority' => 'integer',
        'port' => 'integer',
        'settings' => 'array',
        'direction' => 'string',
        'last_received_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'api_key',
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    /**
     * Get the test mappings for this machine.
     */
    public function testMappings(): HasMany
    {
        return $this->hasMany(MachineTestMapping::class, 'machine_id', 'id');
    }

    /**
     * Get the worklists for this machine.
     */
    public function worklists(): HasMany
    {
        return $this->hasMany(Worklist::class, 'machine_id', 'id');
    }

    /**
     * Get the pending worklists for this machine.
     */
    public function pendingWorklists()
    {
        return $this->worklists()->where('status', WorklistService::STATUS_PENDING);
    }

    /**
     * Get the processing worklists for this machine.
     */
    public function processingWorklists()
    {
        return $this->worklists()->where('status', WorklistService::STATUS_PROCESSING);
    }

    /**
     * Get the completed worklists for this machine.
     */
    public function completedWorklists()
    {
        return $this->worklists()->where('status', WorklistService::STATUS_COMPLETED);
    }

    // ============================================
    // SCOPES
    // ============================================

    /**
     * Scope to only include active machines.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to only include inactive machines.
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope to only include machines with auto-push enabled.
     */
    public function scopeAutoPushEnabled($query)
    {
        return $query->where('auto_push_enabled', true);
    }

    /**
     * Scope to filter by protocol.
     */
    public function scopeByProtocol($query, string $protocol)
    {
        return $query->where('protocol', $protocol);
    }

    /**
     * Scope to order by priority (highest first).
     */
    public function scopeOrderByPriority($query)
    {
        return $query->orderBy('priority', 'desc');
    }

    // ============================================
    // ACCESSORS & MUTATORS
    // ============================================

    public function getProtocolVersionLabelAttribute(): string
    {
        $versions = [
            '2.1' => 'HL7 v2.1',
            '2.2' => 'HL7 v2.2',
            '2.3' => 'HL7 v2.3',
            '2.3.1' => 'HL7 v2.3.1',
            '2.4' => 'HL7 v2.4',
            '2.5' => 'HL7 v2.5',
            '2.5.1' => 'HL7 v2.5.1',
            '2.6' => 'HL7 v2.6',
            '2.7' => 'HL7 v2.7',
            '2.8' => 'HL7 v2.8',
            'astm-e1381' => 'ASTM E1381',
            'astm-e1394' => 'ASTM E1394',
        ];

        return $versions[$this->protocol_version] ?? $this->protocol_version ?? 'Default';
    }

    /**
     * Get the priority level label.
     */
    public function getPriorityLevelAttribute(): string
    {
        if ($this->priority >= 90) {
            return 'Critical';
        } elseif ($this->priority >= 70) {
            return 'High';
        } elseif ($this->priority >= 50) {
            return 'Medium';
        } elseif ($this->priority >= 30) {
            return 'Low';
        }

        return 'Very Low';
    }

    /**
     * Get the priority badge class.
     */
    public function getPriorityBadgeAttribute(): string
    {
        if ($this->priority >= 90) {
            return 'danger';
        } elseif ($this->priority >= 70) {
            return 'warning';
        } elseif ($this->priority >= 50) {
            return 'info';
        } elseif ($this->priority >= 30) {
            return 'secondary';
        }

        return 'light';
    }

    /**
     * Get the status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return $this->is_active ? 'Active' : 'Inactive';
    }

    /**
     * Get the status badge class.
     */
    public function getStatusBadgeAttribute(): string
    {
        return $this->is_active ? 'success' : 'danger';
    }

    /**
     * Get the protocol label.
     */
    public function getProtocolLabelAttribute(): string
    {
        $labels = [
            'hl7' => 'HL7',
            'rest' => 'REST API',
            'mllp' => 'MLLP',
            'tcp' => 'TCP Socket',
        ];

        return $labels[$this->protocol] ?? strtoupper($this->protocol);
    }

    /**
     * Set the API key (auto-hash if needed).
     */
    public function setApiKeyAttribute($value)
    {
        // Store as-is or hash based on security requirements
        $this->attributes['api_key'] = $value;
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    /**
     * Check if machine is active.
     */
    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * Check if machine has auto-push enabled.
     */
    public function hasAutoPush(): bool
    {
        return (bool) $this->auto_push_enabled;
    }

    /**
     * Check if machine can run a specific test.
     */
    public function canRunTest(string $testId): bool
    {
        return $this->testMappings()
            ->where('test_id', $testId)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Get test configuration for a specific test.
     */
    public function getTestConfig(string $testId): ?MachineTestMapping
    {
        return $this->testMappings()
            ->where('test_id', $testId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Update last received timestamp.
     */
    public function updateLastReceived(): bool
    {
        $this->last_received_at = now();

        return $this->save();
    }

    /**
     * Activate the machine.
     */
    public function activate(): bool
    {
        $this->is_active = true;

        return $this->save();
    }

    /**
     * Deactivate the machine.
     */
    public function deactivate(): bool
    {
        $this->is_active = false;

        return $this->save();
    }

    /**
     * Check if machine can operate bidirectionally.
     */
    public function isBidirectional(): bool
    {
        return $this->direction === 'bidirectional';
    }

    /**
     * Check if machine is unidirectional.
     */
    public function isUnidirectional(): bool
    {
        return $this->direction === 'unidirectional' || $this->direction === null;
    }

    /**
     * Can this machine pull worklists from the server?
     * - Bidirectional: yes
     * - Unidirectional with auto_push: yes (they can also poll)
     * - Unidirectional without auto_push: no
     */
    public function canPullWorklist(): bool
    {
        return $this->isBidirectional() || $this->auto_push_enabled;
    }

    /**
     * Can this machine submit results back?
     * Only bidirectional machines.
     */
    public function canReceiveResults(): bool
    {
        return $this->isBidirectional();
    }

    /**
     * Get connection string for the machine.
     */
    public function getConnectionString(): string
    {
        return match ($this->protocol) {
            'hl7', 'mllp' => "{$this->protocol}://{$this->ip_address}:{$this->port}",
            'rest' => "https://{$this->ip_address}:{$this->port}/api",
            'tcp' => "tcp://{$this->ip_address}:{$this->port}",
            default => "{$this->ip_address}:{$this->port}",
        };
    }

    /**
     * Get a setting value from settings JSON.
     */
    public function getSetting(string $key, $default = null)
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Set a setting value in settings JSON.
     */
    public function setSetting(string $key, $value): void
    {
        $settings = $this->settings ?? [];
        $settings[$key] = $value;
        $this->settings = $settings;
    }

    /**
     * Get pending worklist count.
     */
    public function getPendingCount(): int
    {
        return $this->pendingWorklists()->count();
    }

    /**
     * Get processing worklist count.
     */
    public function getProcessingCount(): int
    {
        return $this->processingWorklists()->count();
    }

    /**
     * Get completed today count.
     */
    public function getCompletedTodayCount(): int
    {
        return $this->completedWorklists()
            ->whereDate('completed_at', today())
            ->count();
    }
}
