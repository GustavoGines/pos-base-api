<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class BusinessSetting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value'];

    public const SENSITIVE_KEYS = [
        'mp_access_token',
        'mp_webhook_secret',
        'afip_key_path',
        'afip_cert_path',
    ];

    public const PUBLIC_KEYS = [
        'company_name',
        'business_name',
        'address',
        'phone',
        'cuit',
        'tax_id',
        'receipt_footer_message',
        'ticket_footer',
        'currency_symbol',
        'timezone',
        'logo_path',
        'printer_type',
        'printer_paper_width',
        'printer_com_port',
        'printer_ip_address',
        'printer_ip_port',
        'com_port_scale',
        'card_percentage',
        'wholesale_percentage',
        'custom_price_tiers',
        'enable_advanced_price_tiers',
        'license_features_dict',
        'app_plan',
        'license_plan_mode',
        'license_key',
        'installation_id',
        'license_addons',
        'license_allowed_addons',
        'last_license_check',
        'license_expires_at',
        'license_next_payment_at',
        'license_manage_url',
        'license_is_lifetime',
        'license_business_type',
        'afip_enabled',
        'mp_qr_enabled',
        'mp_point_device_id',
        'theme',
    ];

    public static function getSecret(string $key, ?string $default = null): ?string
    {
        $value = static::where('key', $key)->value('value');
        if (empty($value)) {
            return $default;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            return $value;
        }
    }

    public static function setSecret(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            static::where('key', $key)->delete();
            return;
        }

        static::updateOrCreate(
            ['key' => $key],
            ['value' => Crypt::encryptString($value)]
        );
    }
}
