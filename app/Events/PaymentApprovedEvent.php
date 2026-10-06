<?php

namespace App\Events;

use App\Models\MpTransaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentApprovedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $externalReference,
        public ?string $mpPaymentId,
        public float $amount,
        public ?string $posId = null,
        public ?MpTransaction $transaction = null
    ) {
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new Channel("pos.payments.{$this->externalReference}"),
        ];

        if (! empty($this->posId)) {
            $channels[] = new Channel("pos.terminal.{$this->posId}");
        }

        return $channels;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'PaymentApproved';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'external_reference' => $this->externalReference,
            'mp_payment_id' => $this->mpPaymentId,
            'amount' => $this->amount,
            'status' => 'approved',
            'pos_id' => $this->posId,
            'transaction_id' => $this->transaction?->id,
        ];
    }
}
