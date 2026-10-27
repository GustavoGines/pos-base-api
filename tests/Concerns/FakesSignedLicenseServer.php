<?php

namespace Tests\Concerns;

use App\Services\LicenseSyncService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * Simula un servidor de licencias legítimo (FINDING-LIC-01): devuelve el nonce
 * recibido y firma cada respuesta 200 con un par Ed25519 de prueba cuya clave
 * pública se inyecta en config('app.license_ed25519_public_key').
 *
 * Http::fake() acumula stubs y gana el primero que coincide, por lo que se registra
 * un único stub para '*\/api/validate' que delega en un handler intercambiable.
 */
trait FakesSignedLicenseServer
{
    private ?string $testLicenseSecretKey = null;

    /** @var (callable(Request): PromiseInterface)|null */
    private $licenseServerHandler = null;

    private bool $licenseServerFakeRegistered = false;

    protected function useTestLicenseKeyPair(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $this->testLicenseSecretKey = sodium_crypto_sign_secretkey($keyPair);
        Config::set('app.license_ed25519_public_key', bin2hex(sodium_crypto_sign_publickey($keyPair)));
    }

    /**
     * Reemplaza el comportamiento del servidor de licencias falso para las próximas llamadas.
     *
     * @param  callable(Request): PromiseInterface  $handler
     */
    protected function setLicenseServerHandler(callable $handler): void
    {
        if ($this->testLicenseSecretKey === null) {
            $this->useTestLicenseKeyPair();
        }

        $this->licenseServerHandler = $handler;

        if (! $this->licenseServerFakeRegistered) {
            Http::fake([
                '*/api/validate' => fn (Request $request) => ($this->licenseServerHandler)($request),
            ]);
            $this->licenseServerFakeRegistered = true;
        }
    }

    /**
     * Cada elemento es un body (array) que se responde con HTTP 200 firmado, o una
     * respuesta ya construida con Http::response(...) que se devuelve tal cual (sin firmar).
     * Las respuestas se consumen en orden; la última se repite si hay más llamadas.
     */
    protected function fakeSignedLicenseServer(array|PromiseInterface ...$responses): void
    {
        $queue = array_values($responses);

        $this->setLicenseServerHandler(function (Request $request) use (&$queue) {
            $next = count($queue) > 1 ? array_shift($queue) : $queue[0];

            if ($next instanceof PromiseInterface) {
                return $next;
            }

            return Http::response(
                $this->signLicensePayload($next, (string) $request['installation_id'], (string) $request['nonce']),
                200
            );
        });
    }

    protected function signLicensePayload(array $body, string $installationId, string $nonce): array
    {
        if ($this->testLicenseSecretKey === null) {
            $this->useTestLicenseKeyPair();
        }

        $body['nonce'] = $nonce;
        $body['signature_ed25519'] = bin2hex(sodium_crypto_sign_detached(
            LicenseSyncService::buildCanonicalPayload($body, $installationId),
            $this->testLicenseSecretKey
        ));

        return $body;
    }
}
