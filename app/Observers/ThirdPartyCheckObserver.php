<?php

namespace App\Observers;

use App\Models\ThirdPartyCheck;
use Illuminate\Support\Facades\Log;

class ThirdPartyCheckObserver
{
    public function updated(ThirdPartyCheck $thirdPartyCheck): void
    {
        if ($thirdPartyCheck->isDirty('status')) {
            Log::info('Auditoría de Cheque', [
                'check_id' => $thirdPartyCheck->id,
                'old_status' => $thirdPartyCheck->getOriginal('status'),
                'new_status' => $thirdPartyCheck->status,
                'user_id' => auth()->id(),
                'endorsement_note' => $thirdPartyCheck->status === 'endorsed' ? $thirdPartyCheck->endorsement_note : null,
            ]);
        }
    }

}
