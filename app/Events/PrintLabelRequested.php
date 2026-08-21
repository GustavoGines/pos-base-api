<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PrintLabelRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $productId;
    public string $targetPcId;

    public function __construct(int $productId, string $targetPcId = 'caja-1')
    {
        $this->productId = $productId;
        $this->targetPcId = $targetPcId;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('pos.printers.' . $this->targetPcId),
        ];
    }
}
