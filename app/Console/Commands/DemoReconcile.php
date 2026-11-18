<?php

namespace App\Console\Commands;

use App\Services\AdminReport;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** CLI twin of the admin page's reconciliation section. Exit code 1 when any day does not match. */
class DemoReconcile extends Command
{
    protected $signature = 'demo:reconcile {--days=7}';

    protected $description = 'Compare recorded provider costs with GET /v1/usage per UTC day';

    public function handle(AdminReport $report, PixelforgeClient $client): int
    {
        $to = CarbonImmutable::now('UTC')->startOfDay();
        $from = $to->subDays((int) $this->option('days') - 1);
        $data = $report->build($from, $to);
        $rows = $report->reconcile($data['daily'], $client->usage($from->toDateString(), $to->toDateString()));

        $this->table(['day', 'ours', 'pixelforge', 'gens ours/theirs', 'ok'], array_map(fn ($r) => [
            $r['date'], $r['ours'], $r['theirs'], "{$r['our_generations']}/{$r['their_generations']}", $r['ok'] ? 'OK' : 'MISMATCH',
        ], $rows));

        return collect($rows)->every(fn ($r) => $r['ok']) ? self::SUCCESS : self::FAILURE;
    }
}
