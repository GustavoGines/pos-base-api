<?php

declare(strict_types=1);

namespace App\Services\Ecommerce;

use App\Contracts\EcommercePlatformInterface;
use App\Models\EcommerceIntegration;
use App\Services\Ecommerce\Adapters\InternalEcommercePlatformAdapter;
use App\Services\Ecommerce\Adapters\MercadoLibreAdapter;
use App\Services\Ecommerce\Adapters\TiendaNubeAdapter;
use App\Services\Ecommerce\Adapters\WooCommerceAdapter;
use Closure;
use InvalidArgumentException;

class EcommerceManager
{
    /**
     * Canonical platform drivers map.
     *
     * @var array<string, class-string<EcommercePlatformInterface>>
     */
    protected const DRIVER_MAP = [
        'mercadolibre' => MercadoLibreAdapter::class,
        'tiendanube' => TiendaNubeAdapter::class,
        'woocommerce' => WooCommerceAdapter::class,
        'ecommerce_platform' => InternalEcommercePlatformAdapter::class,
        'internal' => InternalEcommercePlatformAdapter::class, // Alias
    ];

    /**
     * In-memory cache of resolved adapter instances.
     *
     * @var array<string, EcommercePlatformInterface>
     */
    protected array $adapters = [];

    /**
     * Custom driver resolvers registered at runtime.
     *
     * @var array<string, Closure>
     */
    protected array $customCreators = [];

    /**
     * Resolve an adapter for a persisted EcommerceIntegration model.
     */
    public function forIntegration(EcommerceIntegration $integration): EcommercePlatformInterface
    {
        $id = $integration->id;
        $cacheKey = $id ? 'integration_'.$id : null;

        if ($cacheKey !== null && isset($this->adapters[$cacheKey])) {
            return $this->adapters[$cacheKey];
        }

        $adapter = $this->createAdapter($integration->platform, $integration, []);

        if ($cacheKey !== null) {
            $this->adapters[$cacheKey] = $adapter;
        }

        return $adapter;
    }

    /**
     * Resolve an adapter by platform identifier with optional integration or raw configuration.
     *
     * @param  array<string, mixed>  $config
     */
    public function driver(
        string $platform,
        ?EcommerceIntegration $integration = null,
        array $config = []
    ): EcommercePlatformInterface {
        $platform = strtolower(trim($platform));

        if ($integration !== null && $integration->exists) {
            return $this->forIntegration($integration);
        }

        $cacheKey = 'platform_'.$platform;

        if (empty($config) && $integration === null && isset($this->adapters[$cacheKey])) {
            return $this->adapters[$cacheKey];
        }

        $adapter = $this->createAdapter($platform, $integration, $config);

        if (empty($config) && $integration === null) {
            $this->adapters[$cacheKey] = $adapter;
        }

        return $adapter;
    }

    /**
     * Factory method to instantiate a fresh un-cached adapter (ideal for testing ad-hoc credentials).
     *
     * @param  array<string, mixed>  $config
     */
    public function make(string $platform, array $config = []): EcommercePlatformInterface
    {
        return $this->createAdapter(strtolower(trim($platform)), null, $config);
    }

    /**
     * Check if a platform identifier is supported.
     */
    public function isSupported(string $platform): bool
    {
        $platform = strtolower(trim($platform));

        return isset(self::DRIVER_MAP[$platform]) || isset($this->customCreators[$platform]);
    }

    /**
     * List all supported platform identifiers.
     *
     * @return array<int, string>
     */
    public function supportedPlatforms(): array
    {
        $builtIn = array_values(array_unique(array_keys(self::DRIVER_MAP)));
        $custom = array_keys($this->customCreators);

        return array_values(array_unique(array_merge($builtIn, $custom)));
    }

    /**
     * Register a custom driver creator closure.
     *
     * @return $this
     */
    public function extend(string $platform, Closure $callback): static
    {
        $this->customCreators[strtolower(trim($platform))] = $callback;

        return $this;
    }

    /**
     * Flush resolved adapter instances from in-memory cache.
     */
    public function purge(?int $integrationId = null): void
    {
        if ($integrationId !== null) {
            unset($this->adapters['integration_'.$integrationId]);
        } else {
            $this->adapters = [];
        }
    }

    /**
     * Instantiate the concrete adapter instance.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidArgumentException
     */
    protected function createAdapter(
        string $platform,
        ?EcommerceIntegration $integration,
        array $config
    ): EcommercePlatformInterface {
        $platform = strtolower(trim($platform));

        if (isset($this->customCreators[$platform])) {
            $adapter = ($this->customCreators[$platform])($integration, $config);
            if (! ($adapter instanceof EcommercePlatformInterface)) {
                throw new InvalidArgumentException(
                    "El driver personalizado para [{$platform}] debe implementar EcommercePlatformInterface."
                );
            }

            return $adapter;
        }

        if (! isset(self::DRIVER_MAP[$platform])) {
            $supported = implode(', ', $this->supportedPlatforms());
            throw new InvalidArgumentException(
                "Plataforma de e-commerce no soportada [{$platform}]. Plataformas admitidas: [{$supported}]."
            );
        }

        $adapterClass = self::DRIVER_MAP[$platform];

        return new $adapterClass($integration, $config);
    }
}
