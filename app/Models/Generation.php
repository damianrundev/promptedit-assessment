<?php

namespace App\Models;

use App\Support\ErrorMessages;
use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    use HasUuids;

    public const PENDING = 'pending';
    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const REJECTED = 'rejected';

    public const ACTIVE = [self::PENDING, self::QUEUED, self::PROCESSING];
    public const TERMINAL = [self::SUCCEEDED, self::FAILED, self::REJECTED];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'usage' => 'array',
            'rates' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'image_url_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /** Shape sent to the Vue pages. Money is pre-formatted here; the frontend never does money math. */
    public function present(): array
    {
        $failed = in_array($this->status, [self::FAILED, self::REJECTED], true);

        return [
            'id' => $this->id,
            'status' => match ($this->status) {
                self::PENDING => self::QUEUED,      // users only see queued/processing/succeeded/failed
                self::REJECTED => self::FAILED,
                default => $this->status,
            },
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimate' => Money::format($this->est_min_nano).' – '.Money::format($this->est_max_nano),
            'cost' => match (true) {
                $this->status === self::SUCCEEDED => Money::format($this->charged_nano),
                $failed => Money::format(0),
                default => null,
            },
            'error' => $failed ? ErrorMessages::for($this->error_code, $this->error_message) : null,
            'note' => $this->isActive() ? $this->note : null,
            'has_image' => $this->image_path !== null,
            'active' => $this->isActive(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
