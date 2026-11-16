<?php

namespace App\Services\Barcode;

use App\Enums\SerialGenerator;
use App\Enums\SerialModule;
use App\Models\BarcodeCounter;
use Carbon\Carbon;

class SimpleBarcodeService
{
    /**
     * Generate receipt barcode.
     *
     * Format: YYMMDD G SSS
     * G = 0 (Offline) or 1 (Online)
     * SSS grows past 999 automatically.
     *
     * Example: 260828 1 001 → 2608281001
     */
    public function generateReceipt(
        string $tenantCode,
        SerialModule $module,
        SerialGenerator $generator,
        Carbon $date,
        ?string $tenantId = null
    ): string {
        $sequence = BarcodeCounter::next(
            $tenantCode,
            $module->value,
            $generator->value,
            $date->format('Y-m-d'),
            $tenantId
        );

        $seqStr = (string) $sequence;
        if (strlen($seqStr) < 3) {
            $seqStr = str_pad($seqStr, 3, '0', STR_PAD_LEFT);
        }

        return $date->format('ymd').$generator->value.$seqStr;
    }

    public function generateSl(SerialModule $module, string $receiptBarcode): string
    {
        return $module->label().$receiptBarcode;
    }

    /**
     * Decode receipt barcode.
     *
     * Format: YYMMDD G SSS
     * Example: 260828 1 001
     */
    public function decodeReceipt(string $barcode): array
    {
        $base = explode('-', $barcode)[0];

        $dateStr = substr($base, 0, 6);
        $generator = $base[6] ?? '0';
        $sequenceStr = substr($base, 7);

        return [
            'generator' => (int) $generator,
            'generator_str' => $generator,
            'generator_label' => $generator === '1' ? 'Online' : 'Offline',
            'date' => $dateStr,
            'date_formatted' => $this->parseDate($dateStr),
            'sequence' => (int) $sequenceStr,
            'sequence_str' => $sequenceStr,
            'full_barcode' => $barcode,
        ];
    }

    protected function parseDate(string $ymd): string
    {
        $year = (int) substr($ymd, 0, 2);
        $month = (int) substr($ymd, 2, 2);
        $day = (int) substr($ymd, 4, 2);
        $pivot = 70;
        $fullYear = $year < $pivot ? 2000 + $year : 1900 + $year;

        return sprintf('%04d-%02d-%02d', $fullYear, $month, $day);
    }
}
