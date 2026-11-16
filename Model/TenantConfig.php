<?php

namespace App\Models;

use App\Traits\BelongsToSelfTenant;

class TenantConfig extends BaseModel
{
    use BelongsToSelfTenant;

    protected $table = 'tenant_configs';

    protected $fillable = [
        'id', 'uuid', 'tenant_id', 'lis_enabled', 'barcode_enabled', 'barcode_required',
        'auto_print_labels', 'machine_integration_enabled', 'enabled_modules',
        'disabled_modules', 'hl7_enabled', 'hl7_versions', 'astm_enabled',
        'astm_versions', 'kermit_enabled', 'poct1a_enabled', 'mirth_enabled',
        'mirth_config', 'python_bridge_enabled', 'python_config', 'java_bridge_enabled',
        'java_config', 'workflow_mode', 'auto_sample_creation', 'auto_machine_assignment',
        'auto_result_verification', 'requires_supervisor_approval', 'printing_enabled',
        'default_printer_id', 'default_label_template_id', 'inventory_tracking_enabled',
        'auto_consume_products', 'low_stock_alerts', 'low_stock_threshold',
        'audit_logging_enabled', 'audit_log_retention_days', 'audit_log_levels',
        'notification_channels', 'notification_events', 'require_2fa_for_verification',
        'require_electronic_signature', 'ui_settings', 'report_header', 'report_footer',
        'max_daily_tests', 'max_concurrent_machines', 'max_users', 'metadata',
    ];

    protected $casts = [
        'lis_enabled' => 'boolean',
        'barcode_enabled' => 'boolean',
        'barcode_required' => 'boolean',
        'auto_print_labels' => 'boolean',
        'machine_integration_enabled' => 'boolean',
        'hl7_enabled' => 'boolean',
        'astm_enabled' => 'boolean',
        'kermit_enabled' => 'boolean',
        'poct1a_enabled' => 'boolean',
        'mirth_enabled' => 'boolean',
        'python_bridge_enabled' => 'boolean',
        'java_bridge_enabled' => 'boolean',
        'auto_sample_creation' => 'boolean',
        'auto_machine_assignment' => 'boolean',
        'auto_result_verification' => 'boolean',
        'requires_supervisor_approval' => 'boolean',
        'printing_enabled' => 'boolean',
        'inventory_tracking_enabled' => 'boolean',
        'auto_consume_products' => 'boolean',
        'low_stock_alerts' => 'boolean',
        'audit_logging_enabled' => 'boolean',
        'require_2fa_for_verification' => 'boolean',
        'require_electronic_signature' => 'boolean',
        'low_stock_threshold' => 'integer',
        'audit_log_retention_days' => 'integer',
        'max_daily_tests' => 'integer',
        'max_concurrent_machines' => 'integer',
        'max_users' => 'integer',
        'hl7_versions' => 'array',
        'astm_versions' => 'array',
        'enabled_modules' => 'array',
        'disabled_modules' => 'array',
        'mirth_config' => 'array',
        'python_config' => 'array',
        'java_config' => 'array',
        'audit_log_levels' => 'array',
        'notification_channels' => 'array',
        'notification_events' => 'array',
        'ui_settings' => 'array',
        'report_header' => 'array',
        'report_footer' => 'array',
        'metadata' => 'array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function defaultPrinter()
    {
        return $this->belongsTo(Printer::class, 'default_printer_id');
    }

    public function defaultLabelTemplate()
    {
        return $this->belongsTo(LabelTemplate::class, 'default_label_template_id');
    }

    public function isFeatureEnabled($feature)
    {
        return $this->{$feature} ?? false;
    }

    public function enableFeature($feature)
    {
        $this->update([$feature => true]);
    }

    public function disableFeature($feature)
    {
        $this->update([$feature => false]);
    }
}
