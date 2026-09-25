<?php

namespace App\Modules\CashRegister\Http\Resources;

use App\Modules\CashRegister\Models\CashRegisterSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashRegisterSession
 */
class CashRegisterSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'opening_amount' => $this->opening_amount,
            // Only meaningful while open — once closed, actual/expected/
            // difference already fully describe the final state.
            'current_balance' => $this->isOpen() ? $this->movements()->latest('id')->value('balance_after') : null,
            'expected_closing_amount' => $this->expected_closing_amount,
            'actual_closing_amount' => $this->actual_closing_amount,
            'difference' => $this->difference,
            'closing_note' => $this->closing_note,
            'opened_by' => $this->whenLoaded('openedBy', fn () => $this->openedBy ? ['id' => $this->openedBy->id, 'name' => $this->openedBy->name] : null),
            'closed_by' => $this->whenLoaded('closedBy', fn () => $this->closedBy ? ['id' => $this->closedBy->id, 'name' => $this->closedBy->name] : null),
            'opened_at' => $this->opened_at,
            'closed_at' => $this->closed_at,
        ];
    }
}
