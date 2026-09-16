<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class FlashSale extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    private const FLASH_SALE_TIMEZONE = 'Asia/Ho_Chi_Minh';

    protected $table = 'flash_sales';

    protected $fillable = [
        'store_id',
        'name',
        'start_time',
        'end_time',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected function startTime(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->castFlashSaleDateTime($value),
            set: fn ($value) => $this->serializeFlashSaleDateTime($value),
        );
    }

    protected function endTime(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->castFlashSaleDateTime($value),
            set: fn ($value) => $this->serializeFlashSaleDateTime($value),
        );
    }

    private function castFlashSaleDateTime($value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->setTimezone(self::FLASH_SALE_TIMEZONE);
        }

        return Carbon::parse((string) $value, self::FLASH_SALE_TIMEZONE);
    }

    private function serializeFlashSaleDateTime($value): ?string
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)
                ->setTimezone(self::FLASH_SALE_TIMEZONE)
                ->toDateTimeString();
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        $hasExplicitTimezone = preg_match('/(?:Z|[+-]\d{2}:\d{2})$/i', $normalized) === 1;
        $date = $hasExplicitTimezone
            ? Carbon::parse($normalized)->setTimezone(self::FLASH_SALE_TIMEZONE)
            : Carbon::parse($normalized, self::FLASH_SALE_TIMEZONE);

        return $date->toDateTimeString();
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function items()
    {
        return $this->hasMany(FlashSaleItem::class, 'flash_sale_id');
    }
}
