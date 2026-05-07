<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Table;
use App\Models\User;
use App\Models\Discount;
use App\Models\Tax;
use App\Models\Outlet;
use App\Models\HistoryTransaction;

class Order extends Model
{
    use HasFactory;

    /*
    |--------------------------------------------------------------------------
    | Constants
    |--------------------------------------------------------------------------
    */
    public const STATUS_PENDING   = 'pending';
    public const STATUS_PAID      = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    public const DISCOUNT_TYPE_PERCENTAGE = 'percentage';
    public const DISCOUNT_TYPE_NOMINAL    = 'nominal';

    /*
    |--------------------------------------------------------------------------
    | Fillable
    |--------------------------------------------------------------------------
    */
    protected $fillable = [
        'outlet_id',
        'user_id',
        'table_id',
        'customer_name',
        'invoice_number',

        // subtotal
        'subtotal_price',

        // discount
        'discount_id',
        'discount_amount',
        'manual_discount_type',
        'manual_discount_value',

        // tax
        'tax_id',
        'tax_amount',
        'tax_breakdown',

        // total
        'total_price',

        'status',
        'logs',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    */
    protected $casts = [
        'subtotal_price'       => 'integer',
        'discount_amount'      => 'integer',
        'manual_discount_value'=> 'integer',
        'tax_amount'           => 'integer',
        'tax_breakdown'        => 'array',
        'total_price'          => 'integer',
        'logs'                 => 'array',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function table()
    {
        return $this->belongsTo(Table::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function historyTransaction()
    {
        return $this->hasOne(HistoryTransaction::class);
    }

    public function discount()
    {
        return $this->belongsTo(Discount::class);
    }

    public function tax()
    {
        return $this->belongsTo(Tax::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopePaid($query)
    {
        return $query->where('status', self::STATUS_PAID);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */
    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /*
    |--------------------------------------------------------------------------
    | Recalculate Total
    |--------------------------------------------------------------------------
    | FIX:
    | Pajak sekarang dihitung dari subtotal setelah diskon
    |--------------------------------------------------------------------------
    */
    public function recalculateTotals(array $overrides = []): void
    {
        $this->loadMissing('items');

        /*
        |--------------------------------------------------------------------------
        | 1. Subtotal
        |--------------------------------------------------------------------------
        */
        $subtotal = (int) $this->items->sum('total_price');

        /*
        |--------------------------------------------------------------------------
        | 2. Ambil Diskon
        |--------------------------------------------------------------------------
        */
        $manualDiscountType = $overrides['manual_discount_type']
            ?? $this->manual_discount_type;

        $manualDiscountValue = (int) (
            $overrides['manual_discount_value']
            ?? $this->manual_discount_value
            ?? 0
        );

        $discountId = $overrides['discount_id']
            ?? $this->discount_id;

        /*
        |--------------------------------------------------------------------------
        | Jika pakai discount master
        |--------------------------------------------------------------------------
        */
        if (!$manualDiscountType && $discountId) {
            $discount = Discount::query()
                ->whereKey($discountId)
                ->where('is_active', true)
                ->first();

            if ($discount && $subtotal >= (int) $discount->min_purchase) {
                $manualDiscountType  = $discount->type;
                $manualDiscountValue = (int) $discount->value;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Hitung Diskon
        |--------------------------------------------------------------------------
        */
        $discountAmount = $this->computeAdjustmentAmount(
            $manualDiscountType,
            $manualDiscountValue,
            $subtotal
        );

        /*
        |--------------------------------------------------------------------------
        | 4. Sisa setelah diskon
        |--------------------------------------------------------------------------
        */
        $afterDiscount = max(0, $subtotal - $discountAmount);

        /*
        |--------------------------------------------------------------------------
        | 5. Ambil Pajak
        |--------------------------------------------------------------------------
        */
        $taxId = $overrides['tax_id'] ?? $this->tax_id;

        $tax = $taxId
            ? Tax::query()
                ->where('id', $taxId)
                ->where('active', true)
                ->first()
            : null;

        /*
        |--------------------------------------------------------------------------
        | 6. Hitung Pajak
        |--------------------------------------------------------------------------
        */
        $taxAmount = 0;
        $newTaxBreakdown = null;

        if ($tax) {

            if ($tax->type === 'percentage') {
                $taxAmount = (int) round($afterDiscount * ((float) $tax->rate / 100));
            } else {
                $taxAmount = (int) $tax->rate;
            }

        } elseif (array_key_exists('tax_amount', $overrides)) {

            $taxAmount = max(0, (int) $overrides['tax_amount']);
            if (array_key_exists('tax_breakdown', $overrides)) {
                $newTaxBreakdown = $overrides['tax_breakdown'];
            }

        } elseif (
            array_key_exists('tax_breakdown', $overrides)
            && is_array($overrides['tax_breakdown'])
        ) {
            $newTaxBreakdown = $overrides['tax_breakdown'];
            $taxAmount = max(
                0,
                (int) collect($newTaxBreakdown)->sum(
                    fn($item) => (int) data_get($item, 'amount', 0)
                )
            );
        } else {
            // ==============================================================
            // FIX: KALAU KASIR EDIT (TANPA OVERRIDES), HITUNG ULANG DARI BREAKDOWN LAMA
            // ==============================================================
            $existingBreakdown = $this->tax_breakdown;

            if (!empty($existingBreakdown) && is_array($existingBreakdown)) {
                $newTaxBreakdown = [];
                foreach ($existingBreakdown as $tb) {
                    $rate = (float) ($tb['rate'] ?? 0);
                    $type = $tb['type'] ?? 'percentage';
                    $amt = 0;

                    // Hitung nominal pajak baru berdasarkan subtotal terbaru
                    if ($type === 'percentage') {
                        $amt = (int) round($afterDiscount * ($rate / 100));
                    } else {
                        $amt = (int) $rate;
                    }

                    $tb['amount'] = $amt;
                    $newTaxBreakdown[] = $tb;
                    $taxAmount += $amt; // Totalkan ke tax_amount
                }
            } elseif ($this->tax_amount > 0 && $this->subtotal_price > 0) {
                // Logic proporsi kalau cuma ada nominal tax_amount tanpa breakdown
                $oldAmountAfterDiscount = max(0, $this->subtotal_price - $this->discount_amount);
                if ($oldAmountAfterDiscount > 0) {
                    $rate = $this->tax_amount / $oldAmountAfterDiscount;
                    $taxAmount = (int) round($afterDiscount * $rate);
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 7. Grand Total
        |--------------------------------------------------------------------------
        */
        $grandTotal = max(0, $afterDiscount + $taxAmount);

        /*
        |--------------------------------------------------------------------------
        | 8. Save
        |--------------------------------------------------------------------------
        */
        $updates = [
            'subtotal_price'        => $subtotal,

            'discount_id'          => $discountId,
            'manual_discount_type' => $manualDiscountType,
            'manual_discount_value'=> $manualDiscountType
                ? $manualDiscountValue
                : null,
            'discount_amount'      => $discountAmount,

            'tax_id'               => $taxId,
            'tax_amount'           => $taxAmount,

            'total_price'          => $grandTotal,
        ];

        // Simpan JSON breakdown pajak yang baru jika ada perhitungan ulang
        if ($newTaxBreakdown !== null) {
            $updates['tax_breakdown'] = $newTaxBreakdown;
        }

        $this->update($updates);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Hitung Discount / Nominal
    |--------------------------------------------------------------------------
    */
    private function computeAdjustmentAmount(
        ?string $type,
        float $value,
        int $baseAmount
    ): int {
        if (!$type || $baseAmount <= 0 || $value <= 0) {
            return 0;
        }

        if ($type === self::DISCOUNT_TYPE_PERCENTAGE) {
            $percent = min(100, max(0, $value));

            return (int) round(
                ($baseAmount * $percent) / 100
            );
        }

        return min(
            $baseAmount,
            max(0, (int) $value)
        );
    }
}
