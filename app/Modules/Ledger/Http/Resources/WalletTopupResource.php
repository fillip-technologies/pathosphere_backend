<?php

namespace App\Modules\Ledger\Http\Resources;

use App\Modules\Booking\Contracts\PaymentLink;
use App\Modules\Ledger\Models\WalletTopup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A wallet top-up. The payment URL is returned only when the link is created;
 * the gateway sends it nowhere else.
 *
 * @mixin WalletTopup
 */
final class WalletTopupResource extends JsonResource
{
    private ?PaymentLink $link = null;

    public function withLink(PaymentLink $link): self
    {
        $this->link = $link;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'franchise_id' => $this->franchise_id,
            'amount' => $this->amount,
            'status' => $this->status,
            'payment_link_id' => $this->payment_link_id,
            'payment_url' => $this->when($this->link !== null, fn () => $this->link?->url),
            'link_expires_at' => $this->link_expires_at,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}
