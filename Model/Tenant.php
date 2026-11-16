<?php

namespace App\Models;

use App\Traits\HasUUIDSettings;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains, SoftDeletes;
    use HasUUIDSettings;

    protected $fillable = ['id', 'name', 'email', 'tenant_code', 'code', 'api', 'status'];

    //    protected $casts = ['full_name'];

    protected function casts(): array
    {
        return [
            'full_name' => 'string',
        ];
    }

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'email',
            'status',
            'tenant_code',
            'api',
        ];
    }

    public function domain()
    {
        return $this->hasOne(config('tenancy.domain_model'), 'tenant_id');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'tenant_id');
    }

    public function configs()
    {
        return $this->hasOne(TenantConfig::class, 'tenant_id');
    }

    public function modules()
    {
        return $this->hasMany(TenantModule::class, 'tenant_id');
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'model_has_roles')
//            ->wherePivot('model_type', User::class)
            ->withPivot('tenant_id', 'model_id'); // Include the pivot columns
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $this->uniqueTenantName($value),
        );
    }

    public function uniqueTenantName($name, $count = ''): string
    {
        try {
            if ($count == '') {
                $count = Tenant::selectRaw('REPLACE(name,"'.$name.'","") as value')
                    ->where('name', 'regexp', $name.'[0-9]+')
                    ->pluck('value')
                    ->toArray();
                if (@$count && is_array($count)) {
                    $count = max($count);
                    $count++;
                } else {
                    $count = '';
                }
            }
            if (Tenant::where('name', $name.$count)->exists()) {
                return $this->uniqueTenantName($name, ++$count);
            } else {
                return $name.$count;
            }
        } catch (\Throwable $e) {
            \Log::error($e->getMessage());

            return substr(md5(microtime().rand()), 0, 10);
        }
    }

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            if (empty($tenant->tenant_code)) {
                $tenant->tenant_code = static::nextTenantCodeHex();
            }
        });

        static::updated(function (Tenant $tenant) {
            if ($tenant->wasChanged('tenant_code')) {
                Cache::forget("tenant_code:{$tenant->id}");
            }
        });
    }

    /**
     * Next tenant_code, locked to avoid two concurrent creates
     * racing to the same value.
     */
    protected static function nextTenantCodeHex(): string
    {
        return DB::transaction(function () {
            $tenants = static::withTrashed()->lockForUpdate()->pluck('tenant_code')->filter();
            $maxDecimal = $tenants->map(fn ($hex) => hexdec($hex))->max() ?? -1;
            $next = $maxDecimal + 1;

            if ($next > 0xFFFF) {
                throw new \RuntimeException('tenant_code space exhausted (max 0xFFFF)');
            }

            $width = $next > 0xFF ? 4 : 2;

            return str_pad(dechex($next), $width, '0', STR_PAD_LEFT);
        });
    }
}
