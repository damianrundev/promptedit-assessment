<?php

namespace App\Http\Controllers;

use App\Services\Ledger;
use App\Support\Money;
use Illuminate\Http\Request;

class CreditController extends Controller
{
    /** Fake purchase: no payment provider, just a ledger entry. */
    public function store(Request $request, Ledger $ledger)
    {
        $amount = $request->validate(['amount' => ['required', 'integer', 'in:5,10,25,50']])['amount'];
        $nano = $amount * Money::NANO;

        $ledger->purchase($request->user(), $nano, 'Simulated credit purchase');

        return back()->with('status', 'Added '.Money::format($nano).' in credits (simulated purchase).');
    }
}
