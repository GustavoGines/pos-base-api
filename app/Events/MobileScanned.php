<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MobileScanned implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $barcode;
    public string $targetPcId;

    public function __construct(string $barcode, string $targetPcId = "caja-1")
    {
        $this->barcode = $barcode;
        $this->targetPcId = $targetPcId;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel("pos.scans." . $this->targetPcId),
        ];
    }
}

