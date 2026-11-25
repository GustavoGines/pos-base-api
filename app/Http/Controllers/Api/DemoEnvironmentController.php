<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Demo\DemoEnvironmentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DemoEnvironmentController extends Controller
{
    public function __construct(
        private readonly DemoEnvironmentService $demoService
    ) {}

    public function status(): JsonResponse
    {
        return response()->json($this->demoService->getStatus());
    }

    public function seed(Request $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if (! $actor) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        try {
            $summary = $this->demoService->seed($actor);

            return response()->json([
                'message' => 'Entorno de exploración cargado con datos de ejemplo.',
                'summary' => $summary,
                'status' => $this->demoService->getStatus(),
            ]);
        } catch (DomainException $e) {
            return $this->domainErrorResponse($e);
        }
    }

    public function dismissOnboarding(Request $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if (! $actor || ! $actor->isAdmin()) {
            return response()->json(['message' => 'Solo un administrador puede omitir la invitación inicial.'], 403);
        }

        try {
            $this->demoService->dismissOnboarding();

            return response()->json([
                'message' => 'Asistente inicial omitido.',
                'status' => $this->demoService->getStatus(),
            ]);
        } catch (DomainException $e) {
            return $this->domainErrorResponse($e);
        }
    }

    public function reset(Request $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if (! $actor) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $validated = $request->validate([
            'admin_pin' => 'required|string|min:4|max:12',
            'confirmation_text' => 'required|string|max:20',
        ]);

        try {
            $result = $this->demoService->purgeDemoData(
                $actor,
                (string) $validated['admin_pin'],
                (string) $validated['confirmation_text'],
                (string) $request->ip()
            );

            return response()->json([
                'message' => 'Datos de ejemplo eliminados. Tu negocio quedó limpio y listo para operar.',
                'result' => $result,
                'status' => $this->demoService->getStatus(),
            ]);
        } catch (DomainException $e) {
            return $this->domainErrorResponse($e);
        }
    }

    private function resolveUser(Request $request): ?User
    {
        return $request->user() ?? $request->attributes->get('authenticated_user');
    }

    private function domainErrorResponse(DomainException $e): JsonResponse
    {
        $raw = $e->getMessage();
        $code = 'DEMO_ERROR';
        $message = $raw;
        if (str_contains($raw, ':')) {
            [$code, $message] = explode(':', $raw, 2);
        }
        $httpStatus = $e->getCode();
        if ($httpStatus < 400 || $httpStatus > 599) {
            $httpStatus = 422;
        }

        return response()->json([
            'message' => $message,
            'error_code' => $code,
        ], $httpStatus);
    }
}
