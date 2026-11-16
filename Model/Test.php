<?php

namespace App\Models;

use App\Scopes\SelfTenantScope;
use App\Traits\CreatableTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Test extends BaseModel
{
    use CreatableTrait, SoftDeletes;


    const CODE_LENGTH = 4;
    protected $fillable = ['tenant_id', 'category_id', 'name', 'short_name', 'code', 'details', 'unit', 'order', 'status', 'theme', 'comment', 'created_by', 'updated_by'];

    /**
     * ⭐ Static cache for default-tenant TestConfig models.
     * Key: "default_test_config_{test_id}"
     * Value: TestConfig instance OR null
     */
    public static array $defaultConfigCache = [];

    public function setOrderAttribute($value): void
    {
        $this->attributes['order'] = $value ?? 0;
    }

    // ============================================
    // ACCESSORS — fallback-aware via effectiveConfig
    // ============================================

    protected function shortName(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => @$this->effectiveConfig->short_name ?: @$value
        );
    }

    protected function order(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => @$this->effectiveConfig->order ?? $value ?? 0
        );
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->effectiveConfig?->status ?? $value
        );
    }

    protected function customJs(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->effectiveConfig?->custom_js ?? null
        );
    }

    // ============================================
    // RELATIONS
    // ============================================

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_tests', 'test_id', 'user_id')
            ->using(UserTest::class)
            ->wherePivot('tenant_id', auth()->user()->tenant_id)
            ->withPivot('tenant_id')
            ->withTimestamps();
    }

    public function scopeAccessibleByUser($query, $userId = null, $tenantId = null)
    {
        $userId = $userId ?? auth()->user()->id;
        $tenantId = $tenantId ?? auth()->user()->tenant_id ?? webTenant();

        return $query
            ->where('category_id', '!=', config('app.none_test_category'))
            ->whereHas('users', function ($q) use ($userId, $tenantId) {
                $q->where('user_tests.user_id', $userId)
                    ->where('user_tests.tenant_id', $tenantId);
            });
    }

    public function bookmarks(): MorphMany
    {
        return $this->morphMany(Bookmark::class, 'bookmarkable');
    }

    public function admission(): BelongsToMany
    {
        return $this->belongsToMany(Admission::class, 'all_test', 'test_id', 'model_id')
            ->where('model_type', Admission::class);
    }

    public function prescription(): BelongsToMany
    {
        return $this->belongsToMany(Prescription::class, 'all_test', 'test_id', 'model_id')
            ->where('model_type', Prescription::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TestCategory::class, 'category_id', 'id');
    }

    public function configWithoutDefault(): HasOne
    {
        return $this->hasOne(TestConfig::class, 'test_id');
    }

    /**
     * ⭐ Plain hasOne. Fallback now handled by `effectiveConfig` accessor.
     */
    public function config(): HasOne
    {
        return $this->hasOne(TestConfig::class, 'test_id');
    }

    /**
     * ⭐ Fallback-aware config accessor.
     *
     * Priority:
     *   1. Eager-loaded current-tenant config
     *   2. Primed static cache (default tenant)
     *   3. Lazy query (last resort)
     *   4. Empty TestConfig (matches withDefault behavior when both missing)
     */
    protected function effectiveConfig(): Attribute
    {
        return Attribute::make(
            get: function () {
                // Admin domain — no fallback
                if (config('app.admin_domain') == request()->getHost()) {
                    return $this->getRelation('config') ?: new TestConfig;
                }

                // 1. Current-tenant config
                $current = $this->getRelation('config');
                if ($current) {
                    return $current;
                }

                // 2. Primed cache
                $cacheKey = self::defaultConfigCacheKey($this->id);
                if (array_key_exists($cacheKey, self::$defaultConfigCache)) {
                    $cached = self::$defaultConfigCache[$cacheKey];

                    if ($cached instanceof TestConfig) {
                        return $cached;
                    }

                    // Cached as null → return empty (matches withDefault)
                    $empty = new TestConfig;
                    $empty->test_id = $this->id;
                    $empty->tenant_id = config('app.default_tenant_id');

                    return $empty;
                }

                // 3. Not primed → query once
                $defaultConfig = TestConfig::withoutGlobalScope(SelfTenantScope::class)
                    ->where('test_id', $this->id)
                    ->where('tenant_id', config('app.default_tenant_id'))
                    ->first();

                self::$defaultConfigCache[$cacheKey] = $defaultConfig;

                if ($defaultConfig) {
                    return $defaultConfig;
                }

                // 4. Fallback to empty
                $empty = new TestConfig;
                $empty->test_id = $this->id;
                $empty->tenant_id = config('app.default_tenant_id');

                return $empty;
            }
        );
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(TestConsumption::class, 'test_id')->orderBy('product_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(TestComponent::class, 'test_id')->orderBy('order');
    }

    public function children()
    {
        return $this->hasMany(TestComponent::class, 'test_id')->orderBy('order');
    }

    // ============================================
    // STATIC CACHE API
    // ============================================

    public static function defaultConfigCacheKey(string $testId): string
    {
        return 'default_test_config_'.$testId;
    }

    public static function primeDefaultConfigCache(array $testIds, ?string $defaultTenantId = null): void
    {
        $defaultTenantId = $defaultTenantId ?? config('app.default_tenant_id');

        if (empty($testIds) || ! $defaultTenantId) {
            return;
        }

        $testIds = array_values(array_unique($testIds));

        $uncachedTestIds = array_filter($testIds, function ($testId) {
            return ! array_key_exists(self::defaultConfigCacheKey($testId), self::$defaultConfigCache);
        });

        if (empty($uncachedTestIds)) {
            return;
        }

        $configs = TestConfig::withoutGlobalScope(SelfTenantScope::class)
            ->whereIn('test_id', $uncachedTestIds)
            ->where('tenant_id', $defaultTenantId)
            ->get();

        $found = [];
        foreach ($configs as $config) {
            $found[$config->test_id] = $config;
            self::$defaultConfigCache[self::defaultConfigCacheKey($config->test_id)] = $config;
        }

        foreach ($uncachedTestIds as $testId) {
            if (! isset($found[$testId])) {
                self::$defaultConfigCache[self::defaultConfigCacheKey($testId)] = null;
            }
        }
    }

    public static function forgetDefaultConfigCache(string $testId): void
    {
        unset(self::$defaultConfigCache[self::defaultConfigCacheKey($testId)]);
    }

    public static function flushDefaultConfigCache(): void
    {
        self::$defaultConfigCache = [];
    }
}
