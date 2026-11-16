<?php

namespace App\Traits;

use App\Enums\SerialGenerator;
use App\Enums\SerialModule;
use App\Services\Barcode\SimpleBarcodeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

trait HasSimpleBarcode
{
    public static function bootHasSimpleBarcode(): void
    {
        static::creating(function ($model) {
            if (! empty($model->barcode)) {
                return;
            }
            $model->generateBarcode();
        });

        static::saving(function ($model) {
            if ($model->exists && $model->isDirty('barcode')) {
                $original = $model->getOriginal('barcode');
                if (! empty($original)) {
                    throw new \RuntimeException('Barcode is immutable once set.');
                }
            }
        });
    }

    protected function generateBarcode(): void
    {
        $service = app(SimpleBarcodeService::class);

        $tenantId = $this->getBarcodeTenantId();       // UUID — identity
        $tenantCode = $this->getBarcodeTenantCodeRef();  // "0001" — reference only
        $module = $this->barcodeModule();
        $generator = SerialGenerator::current();
        $date = $this->record_date ?? Carbon::now();

        $this->barcode = $service->generateReceipt(
            $tenantCode,
            $module,
            $generator,
            $date,
            $tenantId
        );

        $this->sl_no = $service->generateSl($module, $this->barcode);
    }

    /**
     * Tenant UUID — the counter identity.
     * Must never be null.
     */
    protected function getBarcodeTenantId(): string
    {
        if (! empty($this->tenant_id)) {
            return (string) $this->tenant_id;
        }

        if (function_exists('webTenant')) {
            $id = webTenant();
            if ($id) {
                return (string) $id;
            }
        }

        if (function_exists('tenant') && tenant()) {
            return (string) tenant()->getTenantKey();
        }

        throw new \RuntimeException('Cannot generate barcode without tenant context.');
    }

    /**
     * varchar(4) readable code — stored on the counter row, never keyed.
     * Returns null if unavailable; never throws.
     */
    protected function getBarcodeTenantCodeRef(): ?string
    {
        if (! empty($this->tenant_code)) {
            return (string) $this->tenant_code;
        }

        $tenantId = $this->getBarcodeTenantId();

        $code = DB::table('tenants')
            ->where('id', $tenantId)
            ->value('tenant_code');

        return $code !== null && $code !== '' ? (string) $code : null;
    }

    abstract public function barcodeModule(): SerialModule;

    public function decodeBarcode(): array
    {
        return app(SimpleBarcodeService::class)->decodeReceipt($this->barcode);
    }

    public function save(array $options = [])
    {
        return DB::transaction(fn () => parent::save($options));
    }
}
