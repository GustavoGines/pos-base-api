<?php

namespace App\Http\Controllers\Api;

use App\Events\MobileScanned;
use App\Events\PrintLabelRequested;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MobileScannerController extends Controller
{
    public function scan(Request $request)
    {
        $request->validate(['barcode' => 'required|string']);
        try {
            broadcast(new MobileScanned($request->barcode, $request->target_pc ?? 'caja-1'));

            return response()->json(['status' => 'Scanned event sent']);
        } catch (\Throwable $e) {
            Log::error('Broadcast error in /mobile/scan: '.$e->getMessage());

            return response()->json(['status' => 'Event queued, but Reverb might be down', 'error' => $e->getMessage()]);
        }
    }

    public function printLabel(Request $request)
    {
        $request->validate(['product_id' => 'required|integer']);
        broadcast(new PrintLabelRequested($request->product_id, $request->target_pc ?? 'caja-1'));

        return response()->json(['status' => 'Print label event sent']);
    }
}
