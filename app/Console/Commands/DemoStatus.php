<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Support\Money;
use Illuminate\Console\Command;

class DemoStatus extends Command
{
    protected $signature = 'demo:status';

    protected $description = 'Print every generation with status, cost and ledger entries';

    public function handle(): int
    {
        $rows = Generation::orderBy('created_at')->get()->map(fn (Generation $g) => [
            substr($g->id, 0, 8), $g->status, $g->pixelforge_id, Money::toDecimal((int) $g->provider_cost_nano), Money::toDecimal((int) $g->charged_nano),
            \App\Models\LedgerEntry::where('generation_id', $g->id)->pluck('type')->implode(','), $g->error_code, mb_substr($g->prompt, 0, 40),
        ]);
        $this->table(['id', 'status', 'pf id', 'provider $', 'charged $', 'ledger', 'error', 'prompt'], $rows);

        return self::SUCCESS;
    }
}
