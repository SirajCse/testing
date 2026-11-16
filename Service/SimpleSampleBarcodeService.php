<?php

namespace App\Services\Barcode;

class SimpleSampleBarcodeService
{
    /**
     * Generate sample/recheck barcode with counter-based suffix.
     *
     * Rule:
     *   - Timing, 1st occurrence:    -<TIMING>       (e.g., -F, -R, -2H)
     *   - Timing, 2nd+ occurrence:   -<TIMING><N>    (e.g., -F2, -R3, -2H2)
     *   - No timing, 1st:            (no suffix)     (e.g., 2608281001)
     *   - No timing, 2nd+ occurrence: -C<N>          (e.g., -C, -C2, -C3)
     *
     * @param  string  $receiptBarcode  Base barcode
     * @param  string|null  $timing  F, R, 2H, PP (or null)
     * @param  int  $occurrence  1 = original, 2+ = recheck number
     */
    public function generateCounterSample(
        string $receiptBarcode,
        ?string $timing,
        int $occurrence = 1
    ): string {
        if (empty($timing)) {
            if ($occurrence <= 1) {
                return $receiptBarcode;
            }
            $n = $occurrence - 1;

            return $receiptBarcode.'-C'.($n > 1 ? $n : '');
        }

        $suffix = $this->normalizeTiming($timing);

        if ($suffix === '') {
            return $receiptBarcode;
        }

        if ($occurrence <= 1) {
            return $receiptBarcode.'-'.$suffix;
        }

        return $receiptBarcode.'-'.$suffix.$occurrence;
    }

    /**
     * Clean + uppercase timing value.
     */
    public function normalizeTiming(string $timing): string
    {
        if (empty($timing)) {
            return '';
        }

        $normalized = strtoupper(trim($timing));
        $normalized = ltrim($normalized, '-');
        $normalized = str_replace([' ', '_'], '', $normalized);

        return $normalized;
    }

    /**
     * Decode a sample barcode back into its parts.
     *
     * Supports:
     *   - 2608281001              (no timing)
     *   - 2608281001-F            (timing, original)
     *   - 2608281001-F2           (timing, recheck #1)
     *   - 2608281001-C            (no-timing recheck #1)
     *   - 2608281001-C2           (no-timing recheck #2)
     */
    public function decodeSample(string $barcode): array
    {
        $parts = explode('-', $barcode, 2);
        $baseBarcode = $parts[0];
        $suffix = $parts[1] ?? null;

        $dateStr = substr($baseBarcode, 0, 6);
        $generator = $baseBarcode[6] ?? '0';
        $sequenceStr = substr($baseBarcode, 7);

        $timing = null;
        $occurrence = 1;
        $isRecheck = false;
        $isGenericRecheck = false;

        if (! empty($suffix)) {
            // No-timing recheck: C, C2, C3
            if (preg_match('/^C([0-9]*)$/', $suffix, $m)) {
                $isRecheck = true;
                $isGenericRecheck = true;
                $n = ! empty($m[1]) ? (int) $m[1] : 1;
                $occurrence = $n + 1;
            }
            // Timing with number: F2, R3, 2H2
            elseif (preg_match('/^([A-Z0-9]+?)([0-9]+)$/', $suffix, $m)) {
                $timing = $m[1];
                $occurrence = (int) $m[2];
                $isRecheck = ($occurrence >= 2);
            }
            // Plain timing: F, R, 2H
            else {
                $timing = $suffix;
                $occurrence = 1;
                $isRecheck = false;
            }
        }

        return [
            'generator' => (int) $generator,
            'generator_str' => $generator,
            'generator_label' => $generator === '1' ? 'Online' : 'Offline',
            'date' => $dateStr,
            'date_formatted' => $this->parseDate($dateStr),
            'sequence' => (int) $sequenceStr,
            'sequence_str' => $sequenceStr,
            'suffix' => $suffix,
            'timing' => $timing,
            'timing_label' => $this->getTimingLabel($timing),
            'occurrence' => $occurrence,
            'is_recheck' => $isRecheck,
            'is_generic_recheck' => $isGenericRecheck,
            'full_barcode' => $barcode,
            'base_barcode' => $baseBarcode,
        ];
    }

    /**
     * Get human-readable label for a timing suffix.
     */
    public function getTimingLabel(?string $suffix): ?string
    {
        if (empty($suffix)) {
            return null;
        }

        // No-timing recheck: C, C2, C3
        if (preg_match('/^C([0-9]*)$/', $suffix, $m)) {
            $n = ! empty($m[1]) ? (int) $m[1] : 1;

            return $n === 1 ? 'Recheck' : "Recheck #{$n}";
        }

        // Timing with recheck number: F2, F3, 2H2
        if (preg_match('/^([A-Z0-9]+?)([0-9]+)$/', $suffix, $m)) {
            $timing = $m[1];
            $n = (int) $m[2];
            $baseLabel = $this->getTimingLabel($timing) ?? $timing;

            return $n >= 2 ? "{$baseLabel} Recheck #".($n - 1) : $baseLabel;
        }

        // Plain timing
        $map = [
            'F' => 'Fasting',
            'R' => 'Random',
            'E' => 'Extended',
            '1H' => '1 Hour',
            '2H' => '2 Hour',
            '3H' => '3 Hour',
            '4H' => '4 Hour',
            'PP' => 'Post Prandial',
        ];

        return $map[$suffix] ?? $suffix;
    }

    /**
     * Check if a sample barcode belongs to a receipt barcode.
     */
    public function belongsToReceipt(string $sampleBarcode, string $receiptBarcode): bool
    {
        $sampleBase = explode('-', $sampleBarcode)[0];
        $receiptBase = explode('-', $receiptBarcode)[0];

        return $sampleBase === $receiptBase;
    }

    /**
     * Validate a sample barcode format.
     */
    public function validateSample(string $barcode): bool
    {
        if (empty($barcode)) {
            return false;
        }

        // Full pattern: YYMMDDGSSS[-SUFFIX]
        return (bool) preg_match('/^[0-9]{10}(-[A-Z0-9]{1,4}[0-9]{0,2})?$/', $barcode);
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
