<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    public const PURCHASE = 'purchase';
    public const RESERVE = 'reserve';
    public const RELEASE = 'release';
    public const CHARGE = 'charge';

    public $timestamps = false;

    protected $guarded = [];
}
