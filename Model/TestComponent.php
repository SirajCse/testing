<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class TestComponent extends BaseModel
{
    use SoftDeletes;

    protected $table = 'test_components';

    protected $fillable = [
        'id', 'tenant_id', 'test_id', 'name', 'short_name', 'type', 'option',
        'option_value', 'default_value', 'mic_value', 'zone_value',
        'unit', 'convert_unit', 'factor', 'order',
        'status', 'hide', 'mode', 'align', 'class', 'used', 'style', 'pattern',
        'reference_text',
    ];

    protected $appends = ['base_unit'];

    protected $casts = [
        'order' => 'integer',
        'hide' => 'integer',
        'factor' => 'decimal:4',
    ];

    /** @var array<string, TestComponentTenant|null> keyed by "{componentId}:{tenantId}" */
    protected static array $overrideRequestCache = [];

    protected ?TestComponentTenant $cachedOverride = null;

    protected bool $overrideLoaded = false;

    public function suppressOverride(): static
    {
        $this->overrideLoaded = true;
        $this->cachedOverride = null;

        return $this;
    }

    protected function overrideRow(): ?TestComponentTenant
    {
        if ($this->overrideLoaded) {
            return $this->cachedOverride;
        }

        $this->overrideLoaded = true;
        $tenantId = auth()->user()->tenant_id ?? webTenant();

        if (empty($tenantId)) {
            return $this->cachedOverride = null;
        }

        $memoKey = "{$this->id}:{$tenantId}";

        if (array_key_exists($memoKey, self::$overrideRequestCache)) {
            return $this->cachedOverride = self::$overrideRequestCache[$memoKey];
        }

        if ($this->relationLoaded('override')) {
            $related = $this->override;

            if ($related && (string) $related->tenant_id === (string) $tenantId) {
                return $this->cachedOverride = self::$overrideRequestCache[$memoKey] = $related;
            }

            return $this->cachedOverride = self::$overrideRequestCache[$memoKey] = null;
        }

        $row = $this->override()->where('tenant_id', $tenantId)->first();

        return $this->cachedOverride = self::$overrideRequestCache[$memoKey] = $row;
    }

    /* ═══════════════════════════════════════════════
       RELATIONS
       ═══════════════════════════════════════════════ */

    public function test(): HasOne
    {
        return $this->hasOne(Test::class, 'id', 'test_id');
    }

    public function override(): HasOne
    {
        return $this->hasOne(TestComponentTenant::class, 'test_component_id');
    }

    public function references(): HasMany
    {
        return $this->hasMany(TestComponentReference::class, 'test_component_id', 'id')
            ->withTrashed();
    }

    /* ═══════════════════════════════════════════════
       ACCESSORS — override-aware
       ═══════════════════════════════════════════════ */

    protected function baseUnit(): Attribute
    {
        return Attribute::make(get: fn () => $this->attributes['unit'] ?? '');
    }

    protected function unit(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->overrideRow()?->unit ?? $value
        );
    }

    protected function convertUnit(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->overrideRow()?->convert_unit ?? $value
        );
    }

    protected function factor(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->overrideRow()?->factor ?? $value
        );
    }

    protected function order(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->overrideRow()?->order ?? $value
        );
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->overrideRow()?->status ?? $value
        );
    }

    protected function referenceText(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $tenant = $this->overrideRow()?->reference_text;

                return ! empty($tenant) ? $tenant : $value;
            }
        );
    }

    protected function defaultValue(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $tenant = $this->overrideRow()?->default_value;

                return ! empty($tenant) ? $tenant : $value;
            }
        );
    }

    protected function optionValue(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $tenant = $this->overrideRow()?->option_value;

                return ! empty($tenant) ? $tenant : $value;
            }
        );
    }

    protected function micValue(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $tenant = $this->overrideRow()?->mic_value;

                return ! empty($tenant) ? $tenant : $value;
            }
        );
    }

    protected function zoneValue(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $tenant = $this->overrideRow()?->zone_value;

                return ! empty($tenant) ? $tenant : $value;
            }
        );
    }

    protected function hide(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $tenant = $this->overrideRow()?->hide;

                return $tenant !== null ? (int) $tenant : (int) $value;
            }
        );
    }

    /* ═══════════════════════════════════════════════
       HELPERS
       ═══════════════════════════════════════════════ */

    public function masterUnit(): string
    {
        return (string) ($this->attributes['unit'] ?? '');
    }

    public function resolvedUnit(): string
    {
        return (string) ($this->unit ?? $this->masterUnit());
    }

    public function hasUnitOverride(): bool
    {
        $u = $this->overrideRow()?->unit;

        return ! empty($u) && $u !== $this->masterUnit();
    }

    public function hasReferenceOverride(): bool
    {
        return ! empty($this->overrideRow()?->reference_text);
    }

    public function isHiddenForTenant(): bool
    {
        $override = $this->overrideRow();

        if ($override === null) {
            return (int) ($this->attributes['hide'] ?? 0) === 1;
        }

        return (int) $override->status === 0 || (int) $override->hide === 1;
    }

    public function referenceLines(): array
    {
        $raw = (string) ($this->reference_text ?? '');

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(';', $raw))));
    }

    public function baseResult($value): float|int|string
    {
        if (! is_numeric($value) || ! $this->hasUnitOverride()) {
            return is_numeric($value) ? (float) $value : $value;
        }

        $factor = $this->factor;

        return $factor ? (float) $value * (float) $factor : (float) $value;
    }

    public function convertValue($value): float|int
    {
        $row = $this->overrideRow();

        if ($row && $row->factor && $row->convert_unit) {
            return $value * (float) $row->factor;
        }

        if ($this->factor && ($this->attributes['convert_unit'] ?? null)) {
            return $value * (float) $this->factor;
        }

        return (float) $value;
    }

    public function formatResult($value): string
    {
        if ($this->type === 'textarea' || $this->type === 'text') {
            return (string) $value;
        }

        if (is_numeric($value)) {
            return number_format((float) $value, $this->type === 'float' ? 2 : 0, '.', '');
        }

        return (string) $value;
    }

    /* ═══════════════════════════════════════════════
       CACHE INVALIDATION
       ═══════════════════════════════════════════════ */

    protected static function booted(): void
    {
        $forget = function (self $row) {
            self::bumpVersionForComponent($row->id);
        };

        static::saved($forget);
        static::deleted($forget);
        static::restored($forget);
        static::forceDeleted($forget);
    }

    private static function bumpVersionForComponent(string $componentId): void
    {
        $tenantIds = TestComponentTenant::query()
            ->where('test_component_id', $componentId)
            ->pluck('tenant_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $defaultTenantId = config('app.default_tenant_id');
        if ($defaultTenantId) {
            $tenantIds[] = $defaultTenantId;
        }

        foreach (array_unique($tenantIds) as $tenantId) {
            Cache::forget("resolved_version_tenant_{$tenantId}");
        }
    }
}
