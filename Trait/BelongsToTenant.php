<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Company;
use App\Models\Tenant;
use App\Scopes\GlobalTenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Tenant $tenant
 */
trait BelongsToTenant
{
    public static string $tenantIdColumn = 'tenant_id';

    public function tenant()
    {
        return $this->belongsTo(config('tenancy.tenant_model'), self::$tenantIdColumn);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, self::$tenantIdColumn, 'id');
    }

    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new GlobalTenantScope);

        static::creating(function ($model) {
            if (! $model->getAttribute(self::$tenantIdColumn) && ! $model->relationLoaded('tenant')) {
                if (tenancy()->initialized) {
                    $model->setAttribute(self::$tenantIdColumn, tenant()->getTenantKey());
                    $model->setRelation('tenant', tenant());
                } elseif (! tenancy()->initialized && isset(auth()->user()->tenant_id)) {
                    $model->setAttribute(self::$tenantIdColumn, auth()->user()->tenant_id);
                    $model->setRelation('tenant', Tenant::with('domains')->find(auth()->user()->tenant_id));
                }
            }
        });
    }
}
