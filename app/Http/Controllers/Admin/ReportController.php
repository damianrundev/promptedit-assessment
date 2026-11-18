<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminReport;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(Request $request, AdminReport $report, PixelforgeClient $client): Response
    {
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        $to = CarbonImmutable::parse($request->query('to', now('UTC')->toDateString()), 'UTC')->startOfDay();
        $from = CarbonImmutable::parse($request->query('from', $to->subDays(13)->toDateString()), 'UTC')->startOfDay();
        $from = $from->max($to->subDays(91)); // Pixelforge's usage endpoint allows at most 92 days
        if ($from > $to) {
            $from = $to;
        }

        $data = $report->build($from, $to);
        $usage = $client->usage($from->toDateString(), $to->toDateString());

        return Inertia::render('Admin/Report', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $data['days'],
            'totals' => $data['totals'],
            'reconciliation' => $usage->ok() ? $report->reconcile($data['daily'], $usage) : null,
            'reconciliationError' => $usage->ok() ? null : ($usage->message() ?? 'Could not reach Pixelforge /v1/usage.'),
        ]);
    }
}
