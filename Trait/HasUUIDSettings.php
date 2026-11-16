<?php

declare(strict_types=1);

namespace App\Traits;

use App\Support\UuidPolicy;
use Illuminate\Support\Str;

trait HasUUIDSettings
{
    public function initializeHasUUIDSettings(): void
    {
        $isInteger = UuidPolicy::isIntegerModel($this);

        $this->incrementing = $isInteger;
        $this->keyType = $isInteger ? 'int' : 'string';
    }

    public static function bootHasUUIDSettings(): void
    {
        static::creating(static function (self $model): void {
            if (UuidPolicy::isIntegerModel($model)) {
                return;
            }

            $keyName = $model->getKeyName();

            if (empty($model->{$keyName})) {
                $model->{$keyName} = (string) Str::uuid7();
            }
        });
    }
}
