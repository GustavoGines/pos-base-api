<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEcommerceIntegrationRequest;
use App\Http\Requests\UpdateEcommerceIntegrationRequest;
use App\Models\EcommerceIntegration;
use App\Models\EcommerceSyncLog;
use App\Services\Ecommerce\CatalogSyncService;
use App\Services\Ecommerce\EcommerceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class EcommerceIntegrationController extends Controller
{
    public function __construct(
        protected EcommerceManager $ecommerceManager
    ) {}

    /**
     * Display a listing of configured e-commerce integrations.
     */
    public function index(): JsonResponse
    {
        $integrations = EcommerceIntegration::orderBy('id', 'asc')
            ->get()
            ->map(fn (EcommerceIntegration $integration) => $this->transformIntegration($integration));

        return response()->json($integrations);
    }

    /**
     * Store a newly created e-commerce integration.
     * Sensitive credentials are encrypted at rest with Crypt::encryptString via model mutators.
     */
    public function store(StoreEcommerceIntegrationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $sensitiveFields = ['client_secret', 'access_token', 'refresh_token', 'webhook_secret'];
        foreach ($sensitiveFields as $field) {
            if (isset($validated[$field]) && is_string($validated[$field])) {
                $validated[$field] = trim($validated[$field]);
            }
        }

        $integration = EcommerceIntegration::create($validated);

        return response()->json([
            'message' => 'Integración creada con éxito.',
            'integration' => $this->transformIntegration($integration),
        ], 201);
    }

    /**
     * Display the specified e-commerce integration.
     */
    public function show(EcommerceIntegration $integration): JsonResponse
    {
        return response()->json($this->transformIntegration($integration));
    }

    /**
     * Update the specified e-commerce integration.
     * Preserves existing secrets if incoming values are null or masked with '****'.
     */
    public function update(UpdateEcommerceIntegrationRequest $request, EcommerceIntegration $integration): JsonResponse
    {
        $validated = $request->validated();

        $sensitiveFields = ['client_secret', 'access_token', 'refresh_token', 'webhook_secret'];
        foreach ($sensitiveFields as $field) {
            if (array_key_exists($field, $validated)) {
                $value = $validated[$field];
                if ($value === null || (is_string($value) && str_contains($value, '****'))) {
                    unset($validated[$field]);
                } else {
                    $validated[$field] = trim((string) $value);
                }
            }
        }

        $integration->update($validated);
        $this->ecommerceManager->purge($integration->id);

        return response()->json([
            'message' => 'Integración actualizada correctamente.',
            'integration' => $this->transformIntegration($integration->fresh()),
        ]);
    }

    /**
     * Remove the specified e-commerce integration.
     */
    public function destroy(EcommerceIntegration $integration): JsonResponse
    {
        $this->ecommerceManager->purge($integration->id);
        $integration->delete();

        return response()->json([
            'message' => 'Integración eliminada correctamente.',
        ]);
    }

    /**
     * Test live API connectivity and credentials validity for the given integration.
     */
    public function testConnection(EcommerceIntegration $integration): JsonResponse
    {
        try {
            $diagnostic = $this->ecommerceManager->forIntegration($integration)->testConnection();
        } catch (Throwable $e) {
            $diagnostic = [
                'success' => false,
                'message' => 'Error al probar conexión: '.$e->getMessage(),
                'error' => get_class($e),
            ];
        }

        $isSuccess = (bool) ($diagnostic['success'] ?? false);
        $status = $isSuccess ? 'connected' : 'error';

        $integration->update([
            'last_health_check_at' => now(),
            'last_health_status' => $status,
        ]);

        return response()->json($diagnostic, $isSuccess ? 200 : 400);
    }

    /**
     * Toggle the active status of the e-commerce integration.
     */
    public function toggleActive(EcommerceIntegration $integration): JsonResponse
    {
        $integration->update([
            'is_active' => ! $integration->is_active,
        ]);

        return response()->json([
            'message' => 'Estado de la integración actualizado correctamente.',
            'integration' => $this->transformIntegration($integration->fresh()),
        ]);
    }

    /**
     * Import entire remote catalog from ecommerce platform into local POS.
     */
    public function importCatalog(EcommerceIntegration $integration, CatalogSyncService $catalogSyncService): JsonResponse
    {
        $result = $catalogSyncService->importFromRemote($integration);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Export active local POS catalog to remote ecommerce platform.
     */
    public function exportCatalog(EcommerceIntegration $integration, CatalogSyncService $catalogSyncService): JsonResponse
    {
        $result = $catalogSyncService->exportToRemote($integration);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Retrieve paginated synchronization audit logs.
     */
    public function logs(Request $request): JsonResponse
    {
        $query = EcommerceSyncLog::with(['integration', 'mapping.product'])
            ->latest('id');

        if ($request->filled('integration_id')) {
            $query->where('integration_id', $request->query('integration_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('direction')) {
            $query->where('direction', $request->query('direction'));
        }

        $logs = $query->paginate($request->integer('per_page', 30));

        return response()->json($logs);
    }

    /**
     * Mask sensitive credentials for presentation in responses without leaking plaintext.
     */
    protected function maskToken(?string $token): ?string
    {
        if (empty($token) || trim($token) === '') {
            return null;
        }

        $token = trim($token);
        $length = strlen($token);
        if ($length <= 8) {
            return '****';
        }

        if (str_starts_with($token, 'APP_USR-')) {
            $prefix = 'APP_USR-';
            $suffix = substr($token, -4);

            return $prefix.'****'.$suffix;
        }

        $prefix = substr($token, 0, 4);
        $suffix = substr($token, -4);

        return $prefix.'****'.$suffix;
    }

    /**
     * Transform an EcommerceIntegration instance into a safe representation.
     *
     * @return array<string, mixed>
     */
    protected function transformIntegration(EcommerceIntegration $integration): array
    {
        $decryptedClientSecret = $integration->decrypted_client_secret;
        $decryptedAccessToken = $integration->decrypted_access_token;
        $decryptedRefreshToken = $integration->decrypted_refresh_token;
        $decryptedWebhookSecret = $integration->decrypted_webhook_secret;

        $hasClientSecret = ! empty($decryptedClientSecret) && trim($decryptedClientSecret) !== '';
        $hasAccessToken = ! empty($decryptedAccessToken) && trim($decryptedAccessToken) !== '';
        $hasRefreshToken = ! empty($decryptedRefreshToken) && trim($decryptedRefreshToken) !== '';
        $hasWebhookSecret = ! empty($decryptedWebhookSecret) && trim($decryptedWebhookSecret) !== '';

        $data = $integration->toArray();

        $data['client_secret'] = $hasClientSecret ? $this->maskToken($decryptedClientSecret) : null;
        $data['access_token'] = $hasAccessToken ? $this->maskToken($decryptedAccessToken) : null;
        $data['refresh_token'] = $hasRefreshToken ? $this->maskToken($decryptedRefreshToken) : null;
        $data['webhook_secret'] = $hasWebhookSecret ? $this->maskToken($decryptedWebhookSecret) : null;

        $data['has_client_secret'] = $hasClientSecret;
        $data['has_access_token'] = $hasAccessToken;
        $data['has_refresh_token'] = $hasRefreshToken;
        $data['has_webhook_secret'] = $hasWebhookSecret;

        return $data;
    }
}
