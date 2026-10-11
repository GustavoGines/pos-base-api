<?php

namespace App\Jobs;

use App\Services\Catalog\CatalogImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ImportCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    public string $filePath;

    public ?int $userId;

    public string $jobKey;

    /**
     * Create a new job instance.
     */
    public function __construct(string $filePath, ?int $userId = null, ?string $jobKey = null)
    {
        $this->filePath = $filePath;
        $this->userId = $userId;
        $this->jobKey = $jobKey ?? 'catalog_import_'.($userId ?? 'system').'_'.time();
    }

    /**
     * Execute the job.
     */
    public function handle(CatalogImportService $importService): void
    {
        Cache::put("catalog_import_{$this->jobKey}", [
            'status' => 'processing',
            'progress' => 0,
            'message' => 'Procesando archivo de importación...',
        ], 3600);

        try {
            $result = $importService->import($this->filePath);

            Cache::put("catalog_import_{$this->jobKey}", [
                'status' => 'completed',
                'progress' => 100,
                'result' => $result,
                'message' => 'Importación completada exitosamente.',
            ], 3600);
        } catch (\Throwable $e) {
            Log::error('Error en ImportCatalogJob: '.$e->getMessage(), [
                'jobKey' => $this->jobKey,
                'filePath' => $this->filePath,
                'exception' => $e,
            ]);

            Cache::put("catalog_import_{$this->jobKey}", [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'message' => 'Error al procesar la importación del catálogo.',
            ], 3600);

            throw $e;
        } finally {
            if (file_exists($this->filePath) && str_contains($this->filePath, 'catalog_imports')) {
                @unlink($this->filePath);
            }
        }
    }
}
