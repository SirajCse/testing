<?php

namespace App\Traits;

use App\Models\LabReceipt;
use App\Models\TestSample;
use App\Services\Barcode\SimpleSampleBarcodeService;
use Illuminate\Support\Facades\DB;

trait HasSimpleSampleBarcode
{
    public static function bootHasSimpleSampleBarcode(): void
    {
        static::creating(function ($model) {
            if (! empty($model->barcode)) {
                return;
            }
            $model->generateSampleBarcode();
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

    /**
     * Generate sample barcode on creation.
     *
     * Rule:
     *   - Timing, 1st occurrence:    -<TIMING>       (e.g., -F, -R, -2H)
     *   - Timing, 2nd+ occurrence:   -<TIMING><N>    (e.g., -F2, -R3, -2H2)
     *   - No timing, 1st:            (no suffix)     (e.g., 2608281001)
     *   - No timing, 2nd+ occurrence: -C<N>          (e.g., -C, -C2, -C3)
     */
    protected function generateSampleBarcode(): void
    {
        $service = app(SimpleSampleBarcodeService::class);

        $parentBarcode = $this->getParentReceiptBarcode();

        if (! $parentBarcode) {
            throw new \RuntimeException('Cannot generate sample barcode without parent receipt.');
        }

        $timing = $this->timing ?? null;

        // ⭐ Determine occurrence count from DB
        $occurrence = $this->calculateOccurrence($timing);

        $this->barcode = $service->generateCounterSample(
            $parentBarcode,
            $timing,
            $occurrence
        );
    }

    /**
     * Count existing tubes for the same receipt + timing to determine occurrence.
     */
    protected function calculateOccurrence(?string $timing): int
    {
        $labReceiptId = $this->lab_receipt_id ?? null;
        if (! $labReceiptId) {
            return 1;
        }

        $service = app(SimpleSampleBarcodeService::class);
        $parentBarcode = $this->getParentReceiptBarcode();

        if (! $parentBarcode) {
            return 1;
        }

        // Clean base barcode (strip any existing suffix)
        $cleanBarcode = preg_replace('/-[A-Za-z0-9]+$/', '', $parentBarcode);

        if (!empty($timing)) {
            // Match: -F, -F2, -F3, ...
            $timingSuffix = $service->normalizeTiming($timing);

            $existingCount = TestSample::where('lab_receipt_id', $labReceiptId)
                ->where(function ($q) use ($cleanBarcode, $timingSuffix) {
                    $q->where('barcode', $cleanBarcode.'-'.$timingSuffix)
                        ->orWhere('barcode', 'REGEXP',
                            '^'.preg_quote($cleanBarcode, '/').'-'.preg_quote($timingSuffix, '/').'[0-9]+$');
                })
                ->count();

            return $existingCount + 1;
        }

        // No timing → count rechecks: -C, -C2, -C3
        $recheckCount = TestSample::where('lab_receipt_id', $labReceiptId)
            ->where(function ($q) use ($cleanBarcode) {
                $q->where('barcode', $cleanBarcode.'-C')
                    ->orWhere('barcode', 'REGEXP',
                        '^'.preg_quote($cleanBarcode, '/').'-C[0-9]+$');
            })
            ->count();

        // No-timing: original has no suffix, so count starts at 0
        // occurrence = 1 (original) if no rechecks yet
        return $recheckCount + 1;
    }

    protected function getParentReceiptBarcode(): ?string
    {
        if (method_exists($this, 'parentBarcode')) {
            return $this->parentBarcode();
        }

        if (method_exists($this, 'labReceipt')) {
            $receipt = $this->labReceipt;
            if ($receipt && isset($receipt->barcode)) {
                return $receipt->barcode;
            }
        }

        if (! empty($this->lab_receipt_id)) {
            $receipt = LabReceipt::find($this->lab_receipt_id);
            if ($receipt && isset($receipt->barcode)) {
                return $receipt->barcode;
            }
        }

        return null;
    }

    public function decodeSampleBarcode(): array
    {
        $service = app(SimpleSampleBarcodeService::class);

        return $service->decodeSample($this->barcode);
    }

    public function save(array $options = [])
    {
        return DB::transaction(fn () => parent::save($options));
    }
}
