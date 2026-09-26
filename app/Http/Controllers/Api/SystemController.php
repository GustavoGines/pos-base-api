<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class SystemController extends Controller
{
    public function installPath()
    {
        return response()->json([
            'backend_path' => base_path(),
            'base_path' => dirname(base_path())
        ]);
    }

    public function rescueMigrate(Request $request)
    {
        $secret = config('app.rescue_migrate_secret');

        if (!empty($secret)) {
            $token = $request->header('X-Rescue-Token');
            if ($token !== $secret) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        }

        Artisan::call('migrate', ['--force' => true]);
        return response()->json(['success' => true, 'output' => Artisan::output()]);
    }

    public function versionCheck()
    {
        $path = public_path('version.txt');
        clearstatcache(true, $path);
        $version = '0.0.0';
        if (file_exists($path)) {
            $version = trim(file_get_contents($path));
        }
        return response()->json(['version' => $version])
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
