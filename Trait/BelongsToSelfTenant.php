<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Company;
use App\Models\Tenant;
use App\Scopes\SelfTenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Tenant $tenant
 */
trait BelongsToSelfTenant
{
    public static string $tenantIdColumn = 'tenant_id';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('tenancy.tenant_model'), self::$tenantIdColumn);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, self::$tenantIdColumn, 'id');
    }

    public static function bootBelongsToSelfTenant(): void
    {
        static::addGlobalScope(new SelfTenantScope);

        static::creating(function ($model) {
            if (! $model->getAttribute(self::$tenantIdColumn) && ! $model->relationLoaded('tenant')) {
                if (tenancy()->initialized) {
                    $model->setAttribute(self::$tenantIdColumn, tenant()->getTenantKey());
                    $model->setRelation('tenant', tenant());
                } else {
                    if (auth()->user()->tenant_id) {
                        $model->setAttribute(self::$tenantIdColumn, auth()->user()->tenant_id);
                        $model->setRelation('tenant', Tenant::with('domains')->find(auth()->user()->tenant_id));
                    } elseif (webTenant()) {
                        $model->setAttribute(self::$tenantIdColumn, webTenant());
                        $model->setRelation('tenant', Tenant::with('domains')->find(webTenant()));
                    }
                }
            }
        });
    }
}
