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
        'card_surcharge_on_offers_mode',
        'discount_tier_on_offers_mode',
        'ignore_product_fixed_prices',
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
        'license_secondary_business_types',
        'afip_enabled',
        'mp_qr_enabled',
        'mp_point_device_id',
        'theme',
        'is_iibb_perception_agent',
        'default_iibb_perception_rate',
        'iibb_calculation_base',
        'iibb_subject_tax_conditions',
        'tax_condition',
        'iibb',
        'activity_start_date',
        'pos_popularity_days',
        'pos_popularity_metric',
        'pos_demote_out_of_stock',
        'pos_popularity_reset_at',
        'bank_transfer_accounts',
    ];

    public const IIBB_BASE_NET_WITHOUT_VAT = 'net_without_vat';

    public const IIBB_BASE_GROSS_WITH_VAT = 'gross_with_vat';

    public const ALLOWED_IIBB_SUBJECT_CONDITIONS = [
        'responsable_inscripto',
        'monotributo',
        'exento',
    ];

    public const ISSUER_TAX_RESPONSABLE_INSCRIPTO = 'IVA Responsable Inscripto';

    public const ISSUER_TAX_MONOTRIBUTO = 'Responsable Monotributo';

    public const ISSUER_TAX_EXENTO = 'IVA Exento';

    public static function isLegacyInvalidIssuerTaxCondition(mixed $raw): bool
    {
        if ($raw === null) {
            return false;
        }
        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return false;
        }

        $norm = strtolower(str_replace(['_', '-'], ' ', $trimmed));
        if (str_contains($norm, 'consumidor')) {
            return true;
        }

        return ! str_contains($norm, 'inscripto')
            && ! str_contains($norm, 'monotributo')
            && ! str_contains($norm, 'exento');
    }

    public static function normalizeIssuerTaxCondition(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return null;
        }

        $norm = strtolower(str_replace(['_', '-'], ' ', $trimmed));
        if (str_contains($norm, 'monotributo')) {
            return self::ISSUER_TAX_MONOTRIBUTO;
        }
        if (str_contains($norm, 'exento')) {
            return self::ISSUER_TAX_EXENTO;
        }
        if (str_contains($norm, 'inscripto')) {
            return self::ISSUER_TAX_RESPONSABLE_INSCRIPTO;
        }

        return null;
    }

    public static function isIssuerEligibleForIibb(mixed $rawTaxCondition = null): bool
    {
        if (func_num_args() === 0) {
            $rawTaxCondition = static::where('key', 'tax_condition')->value('value');
        }

        if ($rawTaxCondition === null || trim((string) $rawTaxCondition) === '') {
            return true;
        }

        return static::normalizeIssuerTaxCondition($rawTaxCondition) === self::ISSUER_TAX_RESPONSABLE_INSCRIPTO;
    }

    public static function isEffectiveIibbPerceptionAgent(?string $rawTaxCondition = null): bool
    {
        $isAgentRaw = static::where('key', 'is_iibb_perception_agent')->value('value');
        $isAgent = $isAgentRaw === '1' || $isAgentRaw === 'true' || $isAgentRaw === true || $isAgentRaw === 1;
        if (! $isAgent) {
            return false;
        }

        return func_num_args() > 0
            ? static::isIssuerEligibleForIibb($rawTaxCondition)
            : static::isIssuerEligibleForIibb();
    }

    public static function sanitizeIibbCalculationBase(mixed $raw): string
    {
        $normalized = strtolower(trim((string) ($raw ?? '')));

        return $normalized === self::IIBB_BASE_GROSS_WITH_VAT
            ? self::IIBB_BASE_GROSS_WITH_VAT
            : self::IIBB_BASE_NET_WITHOUT_VAT;
    }

    /**
     * Normaliza las condiciones frente al IVA sujetas por defecto a Percepción IIBB.
     * Consumidor Final queda siempre excluido por normativa fiscal.
     *
     * @return array<int, string>
     */
    public static function sanitizeIibbSubjectTaxConditions(mixed $raw): array
    {
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return [];
            }
            $decoded = json_decode($trimmed, true);
            $items = is_array($decoded) ? $decoded : explode(',', $trimmed);
        } elseif (is_array($raw)) {
            $items = $raw;
        } else {
            return ['responsable_inscripto'];
        }

        $sanitized = [];
        foreach ($items as $item) {
            $norm = strtolower(trim((string) $item));
            $norm = str_replace(['-', ' '], '_', $norm);
            if (in_array($norm, self::ALLOWED_IIBB_SUBJECT_CONDITIONS, true) && ! in_array($norm, $sanitized, true)) {
                $sanitized[] = $norm;
            }
        }

        return $sanitized;
    }

    public static function getIibbCalculationBase(): string
    {
        $raw = static::where('key', 'iibb_calculation_base')->value('value');

        return self::sanitizeIibbCalculationBase($raw);
    }

    /**
     * @return array<int, string>
     */
    public static function getIibbSubjectTaxConditions(): array
    {
        $record = static::where('key', 'iibb_subject_tax_conditions')->first();
        if ($record === null || $record->value === null) {
            return ['responsable_inscripto'];
        }

        return self::sanitizeIibbSubjectTaxConditions($record->value);
    }

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

    /**
     * Indica si el rubro principal o alguno de los secundarios tiene control nativo
     * de vencimientos/lotes (Minimercado 'retail' y Carnicería/Frescos 'butcher').
     *
     * @param  array<int, string>  $secondaryTypes
     */
    public static function hasNativeBatchExpiration(?string $businessType, array $secondaryTypes = []): bool
    {
        $primary = strtolower(trim((string) ($businessType ?? 'retail')));
        $effective = array_merge([$primary], array_map(
            fn ($t) => strtolower(trim((string) $t)),
            $secondaryTypes
        ));

        return in_array('retail', $effective, true) || in_array('butcher', $effective, true);
    }

    /**
     * Lee los rubros secundarios almacenados localmente como array de strings normalizados.
     *
     * @return array<int, string>
     */
    public static function getSecondaryBusinessTypes(): array
    {
        $raw = static::where('key', 'license_secondary_business_types')->value('value');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($v) => strtolower(trim((string) $v)), $decoded),
            fn ($v) => $v !== ''
        )));
    }

    /**
     * Verifica si el comercio posee la capacidad de un rubro dado (como principal o secundario).
     */
    public static function hasVerticalCapability(string $targetVertical): bool
    {
        $target = strtolower(trim($targetVertical));
        $primary = strtolower(trim((string) (
            static::where('key', 'license_business_type')->value('value')
            ?? static::where('key', 'business_type')->value('value')
            ?? 'retail'
        )));

        if ($primary === $target) {
            return true;
        }

        return in_array($target, static::getSecondaryBusinessTypes(), true);
    }

    /**
     * Indica si el control por lotes/vencimientos está habilitado globalmente
     * por licencia activa (o por perfil nativo si aún no hay diccionario sembrado).
     */
    public static function isBatchExpirationEnabledGlobally(): bool
    {
        $primary = strtolower(trim((string) (
            static::where('key', 'license_business_type')->value('value')
            ?? static::where('key', 'business_type')->value('value')
            ?? 'retail'
        )));
        $secondary = static::getSecondaryBusinessTypes();

        $rawDict = static::where('key', 'license_features_dict')->value('value');
        if (is_string($rawDict) && trim($rawDict) !== '') {
            $features = json_decode($rawDict, true);
            if (is_array($features)) {
                $hasProd = ($features['production'] ?? false) === true || ($features['production'] ?? false) === 1;
                if (array_key_exists('batch_expiration', $features)) {
                    return ($features['batch_expiration'] === true || $features['batch_expiration'] === 1) || $hasProd;
                }

                return static::hasNativeBatchExpiration($primary, $secondary) || $hasProd;
            }
        }

        return static::hasNativeBatchExpiration($primary, $secondary);
    }

    /**
     * Determina si un producto específico debe operar con control de lotes/vencimientos,
     * aislando los rubros no perecederos en comercios Polirrubro mixtos y desactivando
     * bloqueos legados cuando la licencia (ej. Ferretería pura) no incluye lotes.
     */
    public static function canUseBatchExpirationForProduct(\App\Models\Product $product): bool
    {
        if (! static::isBatchExpirationEnabledGlobally()) {
            return false;
        }

        $primary = strtolower(trim((string) (
            static::where('key', 'license_business_type')->value('value')
            ?? static::where('key', 'business_type')->value('value')
            ?? 'retail'
        )));
        $secondary = static::getSecondaryBusinessTypes();
        $storeHasNativeBatches = static::hasNativeBatchExpiration($primary, $secondary);

        if (! $storeHasNativeBatches) {
            return true;
        }

        $rubro = $product->relationLoaded('category')
            ? $product->category?->rubro
            : $product->category()->with('rubro')->first()?->rubro;

        if ($rubro !== null) {
            $rubroVertical = Rubro::hasExtendedRubroColumns()
                ? strtolower(trim((string) ($rubro->vertical_type ?? '')))
                : '';
            if ($rubroVertical === '') {
                $rubroVertical = (string) (Rubro::inferVerticalTypeFromName($rubro->name) ?? '');
            }
            if ($rubroVertical !== '' && ! in_array($rubroVertical, ['retail', 'butcher'], true)) {
                return false;
            }
        }

        return true;
    }
}
