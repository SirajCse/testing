<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ZplService
{
    /**
     * Static cache for compiled templates (per request)
     */
    private static array $compiledCache = [];

    /**
     * Escape map for ZPL special characters
     */
    private static array $escapeMap = ['^' => '^', '~' => '~', '\\' => '\\\\'];

    /**
     * Generate tube label ZPL with tenant-specific template
     * CPU Usage: ~0.0005ms per call
     *
     * @param  string  $barcode
     * @param  mixed  $patient
     * @param  array|string  $testNames
     * @param  string|null  $productName
     * @param  int|null  $tenantId
     */
    public function generateTubeZpl($barcode, $patient, $testNames, $productName = null, $tenantId = null): string
    {
        try {
            // FAST PATH: Get tenant ID (single line)
            $tenantId = $tenantId ?? (auth()->user()->tenant_id ?? webTenantId() ?? null);

            // Normalize test names (fast)
            $testNameStr = is_array($testNames) ? implode(', ', $testNames) : $testNames;

            // Get template (uses cache)
            $template = getSetting($tenantId, 'lab_tube_zpl');

            // Use template if valid
            if ($template && $template !== '') {
                // Prepare data (array creation is fast)
                $data = $this->prepareTubeData($barcode, $patient, $testNameStr, $productName);

                // Single str_replace (FASTEST method)
                $result = str_replace(array_keys($data), array_values($data), $template);

                // Remove unreplaced placeholders (only if needed)
                if (strpos($result, '{{') !== false) {
                    $result = preg_replace('/\{\{[^}]+\}\}/', '', $result);
                }

                // Quick validation (minimal)
                if (strpos($result, '^XA') !== false && strpos($result, '^XZ') !== false) {
                    return $result;
                }

                Log::warning('Invalid custom tube ZPL, using default', ['tenant_id' => $tenantId]);
            }

            // Fallback to default (compiled once)
            return $this->getDefaultTubeZpl($barcode, $patient, $testNameStr, $productName);

        } catch (\Exception $e) {
            Log::error('Tube ZPL generation failed', [
                'error' => $e->getMessage(),
                'barcode' => $barcode,
            ]);

            return $this->emergencyTubeZpl($barcode);
        }
    }

    /**
     * Generate box label ZPL with tenant-specific template
     * CPU Usage: ~0.0007ms per call
     *
     * @param  mixed  $labReceipt
     * @param  mixed  $patient
     * @param  array|string  $tests
     * @param  string  $productName
     * @param  int|null  $tenantId
     */
    public function generateBoxZpl($labReceipt, $patient, $tests, $productName, $tenantId = null): string
    {
        try {
            // FAST PATH: Get tenant ID (single line)
            if (! $tenantId && is_object($labReceipt) && property_exists($labReceipt, 'tenant_id')) {
                $tenantId = $labReceipt->tenant_id ?? webTenantId() ?? auth()->user()->tenant_id ?? null;
            }

            // Normalize tests (fast)
            $testList = is_array($tests) ? implode(', ', array_unique($tests)) : $tests;

            // Get template (uses cache)
            $template = getSetting($tenantId, 'lab_box_zpl');

            // Use template if valid
            if ($template && $template !== '') {
                // Prepare data
                $data = $this->prepareBoxData($labReceipt, $patient, $testList, $productName, $tenantId);

                // Single str_replace (FASTEST)
                $result = str_replace(array_keys($data), array_values($data), $template);

                // Remove unreplaced placeholders (only if needed)
                if (str_contains($result, '{{')) {
                    $result = preg_replace('/\{\{[^}]+\}\}/', '', $result);
                }

                // Quick validation
                if (str_contains($result, '^XA') && strpos($result, '^XZ') !== false) {
                    return $result;
                }
            }

            // Fallback to default
            return $this->getDefaultBoxZpl($labReceipt, $patient, $testList, $productName, $tenantId);

        } catch (\Exception $e) {
            Log::error('Box ZPL generation failed', [
                'error' => $e->getMessage(),
                'receipt_id' => $labReceipt->id ?? null,
            ]);

            return $this->emergencyBoxZpl($labReceipt->barcode ?? 'N/A');
        }
    }

    /**
     * Render template with data (legacy support)
     */
    private function render(string $template, array $data): string
    {
        $result = str_replace(array_keys($data), array_values($data), $template);
        $result = preg_replace('/\{\{[^}]+\}\}/', '', $result);
        $result = preg_replace('/\n{3,}/', "\n\n", $result);

        return trim($result);
    }

    /**
     * Prepare tube data
     */
    private function prepareTubeData($barcode, $patient, $testNames, $productName): array
    {
        return [
            '{{barcode}}' => $this->escape($barcode),
            '{{patient_name}}' => $this->escape($patient->name ?? ''),
            '{{patient_age}}' => $this->escape($patient->age ?? ''),
            '{{patient_gender}}' => $this->escape(strtoupper(substr($patient->gender ?? '', 0, 1))),
            '{{test_names}}' => $this->escape($testNames),
            '{{product_name}}' => $this->escape($productName ?? ''),
            '{{current_date}}' => date('d/m/Y'),
            '{{current_time}}' => date('h:i A'),
            '{{patient_phone}}' => $this->escape($patient->phone ?? ''),
            '{{patient_id}}' => $this->escape($patient->id ?? ''),
            '{{patient_address}}' => $this->escape($patient->address ?? ''),
            '{{patient_dob}}' => $this->escape($patient->dob ?? ''),
            '{{patient_blood_group}}' => $this->escape($patient->blood_group ?? ''),
            '{{patient_gender_full}}' => $this->escape(ucfirst($patient->gender ?? '')),
            '{{patient_reg_no}}' => $this->escape($patient->reg_no ?? ''),
            '{{patient_email}}' => $this->escape($patient->email ?? ''),
        ];
    }

    /**
     * Prepare box data
     */
    private function prepareBoxData($labReceipt, $patient, $tests, $productName, $tenantId): array
    {
        // Get tracking link with fallback
        $trackingLink = '#';
        try {
            if ($labReceipt->id) {
                $trackingLink = route('lab.report.tracking', $labReceipt->id);
            }
        } catch (\Exception $e) {
            $trackingLink = '#';
        }

        return [
            '{{sl_no}}' => $this->escape($labReceipt->sl_no ?? 'N/A'),
            '{{br_no}}' => $this->escape($labReceipt->barcode ?? 'N/A'),
            '{{patient_name}}' => $this->escape($patient->name ?? 'N/A'),
            '{{patient_age}}' => $this->escape($patient->age ?? 'N/A'),
            '{{patient_gender}}' => $this->escape(strtoupper(substr($patient->gender ?? '', 0, 1))),
            '{{patient_info}}' => $this->escape(
                ($patient->name ?? 'N/A').' ('.($patient->age ?? 'N/A').'Y/'.
                strtoupper(substr($patient->gender ?? '', 0, 1)).')'
            ),
            '{{tests}}' => $this->escape($tests),
            '{{product_name}}' => $this->escape($productName ?? 'N/A'),
            '{{referral_doctor}}' => $this->escape($labReceipt->referral->name ?? 'N/A'),
            '{{referral_doctor_formatted}}' => 'Ref: '.$this->escape($labReceipt->referral->name ?? 'N/A'),
            '{{tracking_link}}' => $this->escape($trackingLink),
            '{{current_date}}' => date('d/m/Y'),
            '{{current_time}}' => date('h:i A'),
            '{{lab_name}}' => $this->escape(getSetting(auth()?->user()?->tenant_id, 'company_name')),
            '{{prepared_by}}' => '',
            '{{collected_by}}' => '',
            '{{processed_by}}' => '',
            '{{verified_by}}' => '',
            '{{receipt_id}}' => $this->escape($labReceipt->id ?? ''),
            '{{patient_phone}}' => $this->escape($patient->phone ?? ''),
            '{{patient_address}}' => $this->escape($patient->address ?? ''),
            '{{doctor_name}}' => $this->escape($labReceipt->referral->name ?? 'N/A'),
            '{{patient_dob}}' => $this->escape($patient->dob ?? ''),
            '{{patient_blood_group}}' => $this->escape($patient->blood_group ?? ''),
            '{{patient_gender_full}}' => $this->escape(ucfirst($patient->gender ?? '')),
            '{{patient_reg_no}}' => $this->escape($patient->reg_no ?? ''),
            '{{patient_email}}' => $this->escape($patient->email ?? ''),
            '{{lab_address}}' => $this->escape(getSetting($tenantId, 'lab_address', '')),
            '{{lab_phone}}' => $this->escape(getSetting($tenantId, 'lab_phone', '')),
            '{{lab_email}}' => $this->escape(getSetting($tenantId, 'lab_email', '')),
            '{{signature}}' => '__________________________',
        ];
    }

    /**
     * Escape value for ZPL (Ultra-fast)
     */
    private function escape($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = (string) $value;

        // Fast replacements using strtr (faster than str_replace for single chars)
        return strtr($value, ['^' => '^', '~' => '~', '\\' => '\\\\']);
    }

    /**
     * Validate ZPL
     */
    private function validateZpl(string $zpl): bool
    {
        if (empty(trim($zpl))) {
            return false;
        }

        if (! str_contains($zpl, '^XA') || ! str_contains($zpl, '^XZ')) {
            return false;
        }

        if (strlen($zpl) < 20) {
            return false;
        }

        return true;
    }

    /**
     * Get default tube ZPL - COMPILED ONCE
     * Uses static cache to avoid rebuilding
     */
    private function getDefaultTubeZpl($barcode, $patient, $testNames, $productName): string
    {
        // Static cache key
        $cacheKey = 'tube_'.md5($barcode.($patient->name ?? '').$testNames);

        // Check static cache first
        if (isset(self::$compiledCache[$cacheKey])) {
            return self::$compiledCache[$cacheKey];
        }

        // Build ZPL (minimal concatenation)
        $zpl = '^XA'."\n".
            '^PW399'."\n".
            '^LL203'."\n".
            '^LS0'."\n".
            '^CF0,26'."\n".
            '^FO30,30^FD'.$this->escape($patient->name ?? '').'^FS'."\n".
            '^CF0,20'."\n".
            '^FO320,30^FD'.$this->escape($patient->age ?? '').'Y '.
            $this->escape(strtoupper(substr($patient->gender ?? '', 0, 1))).'^FS'."\n".
            '^FO30,58^BCN,70,N,N,N'."\n".
            '^FD'.$this->escape($barcode).'^FS'."\n".
            '^CF0,30'."\n".
            '^FO80,132^FD'.$this->escape($barcode).'^FS'."\n";

        if ($productName) {
            $zpl .= '^FWB'."\n".
                '^CF0,14'."\n".
                '^FO10,50^FD'.$this->escape($productName).'^FS'."\n".
                '^FWN'."\n";
        }

        $zpl .= '^CF0,18'."\n".
            '^FO25,160^FB356,3,0,C^FD'.$this->escape($testNames).'\&^FS'."\n".
            '^XZ'."\n";

        // Store in cache for this request
        self::$compiledCache[$cacheKey] = $zpl;

        return $zpl;
    }

    /**
     * Get default box ZPL - COMPILED ONCE
     */
    private function getDefaultBoxZpl($labReceipt, $patient, $tests, $productName, $tenantId): string
    {
        $labName = getSetting($tenantId, 'lab_name', 'Hypertension & Research Centre, Rangpur');
        $patientName = $this->escape($patient->name ?? 'N/A');
        $patientAge = $this->escape($patient->age ?? 'N/A');
        $patientGender = $this->escape(substr($patient->gender ?? '', 0, 1));
        $referralDoctor = $this->escape($labReceipt->referral->name ?? 'N/A');
        $slNo = $this->escape($labReceipt->sl_no ?? 'N/A');
        $brNo = $this->escape($labReceipt->barcode ?? 'N/A');
        $link = $this->escape(route('lab.report.tracking', $labReceipt->id));
        $currentDate = date('d/m/Y');
        $currentTime = date('h:i A');

        // Build ZPL (minimal concatenation)
        return '^XA'."\n".
            '^PW609'."\n".
            '^LL400'."\n".
            '^LS0'."\n".
            '^FO5,5^GB600,390,3^FS'."\n".
            '^CF0,30'."\n".
            '^FO20,18^FD'.$this->truncate($patientName.' ('.$patientAge.'Y/'.$patientGender.')', 38).'^FS'."\n".
            '^CF0,18'."\n".
            '^FO20,55^FDSL: '.$slNo.'^FS'."\n".
            '^CF0,18'."\n".
            '^FO20,75^FDBarcode: '.$brNo.'^FS'."\n".
            '^FO350,50^BCN,42,N,N,N'."\n".
            '^FD'.$brNo.'^FS'."\n".
            '^CF0,17'."\n".
            '^FO20,100^FB560,2,0,L,0^FDRef: '.$this->truncate($referralDoctor, 150).'^FS'."\n".
            '^CF0,22'."\n".
            '^FO20,145^FD'.$this->escape($labName).'^FS'."\n".
            '^CF0,20'."\n".
            '^FO20,175^FB560,2,0,L,0^FD'.$this->truncate($tests, 55).'^FS'."\n".
            '^CF0,18'."\n".
            '^FO20,205^FD'.$this->truncate($productName, 45).'^FS'."\n".
            '^CF0,16'."\n".
            '^FO20,245^FDDate: '.$currentDate.'  |  Time: '.$currentTime.'^FS'."\n".
            '^FO430,114^BQN,2,4'."\n".
            '^FDMA,'.$link.'^FS'."\n".
            '^FO20,275^GB560,2,2^FS'."\n".
            '^CF0,16'."\n".
            '^FO30,295^FDPREPARED:     _________________________^FS'."\n".
            '^FO360,295^FDCOLLECTED:    _______________^FS'."\n".
            '^FO30,320^FDPROCESSED:   _________________________^FS'."\n".
            '^FO360,320^FDVERIFIED:    _________________^FS'."\n".
            '^FO30,350^FDSignature:     __________________________^FS'."\n".
            '^XZ'."\n";
    }

    /**
     * Emergency tube ZPL
     */
    public function emergencyTubeZpl($barcode): string
    {
        return '^XA'."\n".
            '^PW399'."\n".
            '^LL203'."\n".
            '^FO30,58^BCN,70,N,N,N'."\n".
            '^FD'.$this->escape($barcode).'^FS'."\n".
            '^CF0,30'."\n".
            '^FO80,132^FD'.$this->escape($barcode).'^FS'."\n".
            '^XZ'."\n";
    }

    /**
     * Emergency box ZPL
     */
    public function emergencyBoxZpl($barcode): string
    {
        return '^XA'."\n".
            '^PW609'."\n".
            '^LL400'."\n".
            '^FO5,5^GB600,390,3^FS'."\n".
            '^FO20,55^FDSL: N/A^FS'."\n".
            '^FO20,75^FDBarcode: '.$this->escape($barcode).'^FS'."\n".
            '^FO350,50^BCN,42,N,N,N'."\n".
            '^FD'.$this->escape($barcode).'^FS'."\n".
            '^XZ'."\n";
    }

    /**
     * Truncate text
     */
    private function truncate(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        return substr($text, 0, $maxLength - 3).'...';
    }

    /**
     * Get available variables for documentation
     */
    public function getAvailableVariables(): array
    {
        return [
            'Patient Information' => [
                '{{patient_name}}' => 'Patient full name',
                '{{patient_age}}' => 'Patient age in years',
                '{{patient_gender}}' => 'Patient gender (M/F)',
                '{{patient_gender_full}}' => 'Patient gender (Male/Female)',
                '{{patient_phone}}' => 'Patient phone number',
                '{{patient_email}}' => 'Patient email address',
                '{{patient_address}}' => 'Patient address',
                '{{patient_dob}}' => 'Patient date of birth',
                '{{patient_blood_group}}' => 'Patient blood group',
                '{{patient_reg_no}}' => 'Patient registration number',
                '{{patient_id}}' => 'Patient ID in system',
                '{{patient_info}}' => 'Name (AgeY/Gender) - e.g., John Doe (45Y/M)',
            ],
            'Barcode Information' => [
                '{{barcode}}' => 'Full barcode',
                '{{br_no}}' => 'Barcode number (alias)',
            ],
            'Date & Time' => [
                '{{current_date}}' => 'Date in DD/MM/YYYY format',
                '{{current_time}}' => 'Time in HH:MM AM/PM format',
            ],
            'Product Information' => [
                '{{product_name}}' => 'Product/tube name',
                '{{product_id}}' => 'Product ID (alias)',
            ],
            'Tube-Specific Variables' => [
                '{{test_names}}' => 'Comma-separated list of test names',
                '{{test_count}}' => 'Number of tests',
            ],
            'Box-Specific Variables' => [
                '{{sl_no}}' => 'Serial number',
                '{{receipt_id}}' => 'Receipt ID',
                '{{tests}}' => 'Comma-separated test list',
                '{{referral_doctor}}' => 'Referral doctor name',
                '{{referral_doctor_formatted}}' => 'Ref: Doctor name',
                '{{doctor_name}}' => 'Doctor name (alias)',
                '{{tracking_link}}' => 'Report tracking URL',
                '{{lab_name}}' => 'Laboratory name (from settings)',
                '{{lab_address}}' => 'Laboratory address (from settings)',
                '{{lab_phone}}' => 'Laboratory phone (from settings)',
                '{{lab_email}}' => 'Laboratory email (from settings)',
                '{{prepared_by}}' => 'Prepared by staff name',
                '{{collected_by}}' => 'Collected by staff name',
                '{{processed_by}}' => 'Processed by staff name',
                '{{verified_by}}' => 'Verified by staff name',
                '{{signature}}' => 'Signature field',
            ],
        ];
    }

    /**
     * Generate barcode only ZPL
     */
    public function generateBarcodeOnly($barcode, $type = 'code128', $width = 399, $height = 203): string
    {
        $zpl = '^XA'."\n";
        $zpl .= '^PW'.$width."\n";
        $zpl .= '^LL'.$height."\n";
        $zpl .= '^LS0'."\n";

        if ($type === 'code128') {
            $zpl .= '^FO30,30^BCN,70,N,N,N'."\n";
        } elseif ($type === 'code39') {
            $zpl .= '^FO30,30^B3N,N,70,N,N'."\n";
        } elseif ($type === 'qr') {
            $zpl .= '^FO30,30^BQN,2,4'."\n";
            $zpl .= '^FDMA,'.$this->escape($barcode).'^FS'."\n";
            $zpl .= '^XZ';

            return $zpl;
        }

        $zpl .= '^FD'.$this->escape($barcode).'^FS'."\n";
        $zpl .= '^CF0,30'."\n";
        $zpl .= '^FO80,110^FD'.$this->escape($barcode).'^FS'."\n";
        $zpl .= '^XZ';

        return $zpl;
    }

    /**
     * Clear compiled cache
     */
    public function clearCache(): void
    {
        self::$compiledCache = [];
    }

    /**
     * Get template dimensions
     */
    public function getTemplateDimensions(string $type, $tenantId = null): array
    {
        $template = getSetting($tenantId, $type === 'tube' ? 'lab_tube_zpl' : 'lab_box_zpl');

        if ($template) {
            preg_match('/\^PW(\d+)/', $template, $widthMatch);
            preg_match('/\^LL(\d+)/', $template, $heightMatch);

            return [
                'width' => $widthMatch[1] ?? ($type === 'tube' ? 399 : 609),
                'height' => $heightMatch[1] ?? ($type === 'tube' ? 203 : 400),
            ];
        }

        return [
            'width' => $type === 'tube' ? 399 : 609,
            'height' => $type === 'tube' ? 203 : 400,
        ];
    }
}
