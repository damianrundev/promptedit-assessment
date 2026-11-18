<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Credits ledger. Every entry is a signed delta to the user's available balance:
 *   purchase +X | reserve -max | (success) release +max, charge -real | (failure) release +max
 * Balance = SUM(amount_nano). The unique (generation_id, type) index makes a double
 * reserve / release / charge impossible even if every other safeguard fails.
 */
class Ledger
{
    public function purchase(User $user, int $nano, string $description = 'Credit purchase'): void
    {
        LedgerEntry::create(['user_id' => $user->id, 'type' => LedgerEntry::PURCHASE, 'amount_nano' => $nano, 'description' => $description]);
    }

    public function reserve(Generation $g): void
    {
        $this->insert($g, LedgerEntry::RESERVE, -$g->reserved_nano);
    }

    /** Failure path: give back the whole reservation. Returns false if it had already been released. */
    public function release(Generation $g): bool
    {
        return $this->insert($g, LedgerEntry::RELEASE, $g->reserved_nano);
    }

    /** Success path: return the reservation and take the real price. Returns false if already charged. */
    public function settle(Generation $g, int $chargeNano): bool
    {
        return DB::transaction(function () use ($g, $chargeNano) {
            if (! $this->insert($g, LedgerEntry::CHARGE, -$chargeNano)) {
                return false;
            }
            $this->insert($g, LedgerEntry::RELEASE, $g->reserved_nano);

            return true;
        });
    }

    private function insert(Generation $g, string $type, int $amount): bool
    {
        return LedgerEntry::insertOrIgnore([
            'user_id' => $g->user_id,
            'generation_id' => $g->id,
            'type' => $type,
            'amount_nano' => $amount,
            'created_at' => now(),
        ]) === 1;
    }
}
