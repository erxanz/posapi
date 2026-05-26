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
        'payment_method',

        // midtrans
        'midtrans_server_key_used',

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

    public function payment()
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function latestPayment()
    {
        return $this->payment();
    }

    public function historyTransaction()
    {
        return $this->hasOne(HistoryTransaction::class);
    }

    public function acceptances()
    {
        return $this->hasMany(OrderAcceptance::class);
    }

    public function latestAcceptance()
    {
        return $this->hasOne(OrderAcceptance::class)->latestOfMany();
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
    | FIX FULL DINAMIS: Pajak & Service dibaca langsung dari database tax_id
    |--------------------------------------------------------------------------
    */
    public function recalculateTotals(array $overrides = []): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Dapatkan semua item terkait
        |--------------------------------------------------------------------------
        */
        $items = clone collect($this->items);

        /*
        |--------------------------------------------------------------------------
        | 2. Hitung Subtotal (Murni dari total_price item aktif)
        |--------------------------------------------------------------------------
        */
        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += max(0, (int) $item->total_price);
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Set up Override Values
        |--------------------------------------------------------------------------
        */
        $discountId = array_key_exists('discount_id', $overrides) ? $overrides['discount_id'] : $this->discount_id;
        $manualDiscountType = array_key_exists('manual_discount_type', $overrides) ? $overrides['manual_discount_type'] : $this->manual_discount_type;
        $manualDiscountValue = array_key_exists('manual_discount_value', $overrides) ? $overrides['manual_discount_value'] : $this->manual_discount_value;
        $taxId = array_key_exists('tax_id', $overrides) ? $overrides['tax_id'] : $this->tax_id;

        /*
        |--------------------------------------------------------------------------
        | 4. Hitung Diskon (PRODUK SCOPE & STACKING - AMAN TIDAK DISENTUH)
        |--------------------------------------------------------------------------
        */
        $discountAmount = 0;
        $discount = null;

        if ($discountId) {
            $discount = \App\Models\Discount::find($discountId);
        }

        if ($discount) {
            $eligibleTotal = 0;
            $eligibleQty = 0;

            if ($discount->scope === 'products' && !empty($discount->product_ids)) {
                $itemsInScope = $items->whereIn('product_id', $discount->product_ids);
                $eligibleTotal = $itemsInScope->sum('total_price');
                $eligibleQty = $itemsInScope->sum('qty');
            } elseif ($discount->scope === 'categories' && !empty($discount->category_ids)) {
                $itemsInScope = $items->filter(function ($item) use ($discount) {
                    return in_array($item->product->category_id ?? null, $discount->category_ids);
                });
                $eligibleTotal = $itemsInScope->sum('total_price');
                $eligibleQty = $itemsInScope->sum('qty');
            } else {
                $eligibleTotal = $subtotal;
                $eligibleQty = $items->sum('qty');
            }

            if ($eligibleTotal > 0) {
                if ($discount->type === 'percentage') {
                    $calc = $eligibleTotal * ((float) $discount->value / 100);
                    if ($discount->max_discount > 0 && $calc > $discount->max_discount) {
                        $calc = $discount->max_discount;
                    }
                    $discountAmount = (int) $calc;
                } else {
                    $calc = $discount->value * $eligibleQty;
                    $discountAmount = (int) min($calc, $eligibleTotal);
                }
            }
        } elseif ($manualDiscountType && $manualDiscountValue > 0) {
            if ($manualDiscountType === 'percentage') {
                $discountAmount = (int) round($subtotal * ((float) $manualDiscountValue / 100));
            } else {
                $discountAmount = (int) $manualDiscountValue;
            }
        } elseif (array_key_exists('discount_amount', $overrides)) {
            $discountAmount = max(0, (int) $overrides['discount_amount']);
        } else {
            $discountAmount = (int) $this->discount_amount;
        }

        $discountAmount = min($subtotal, $discountAmount);
        $afterDiscount = max(0, $subtotal - $discountAmount);

        /*
        |--------------------------------------------------------------------------
        | 5 & 6. Hitung Pajak Berjenjang Secara DINAMIS (Data Pajak Sendiri)
        |--------------------------------------------------------------------------
        */
        $tax = null;
        if ($taxId) {
            $tax = \App\Models\Tax::find($taxId);
        }

        $taxAmount = 0;
        $newTaxBreakdown = null;

        if ($tax) {
            // Ambil rate asli dari database Anda (Misal: 10, 11, atau 16)
            $dbRate = (float) $tax->rate;

            // Logika Pemisahan Otomatis:
            // Jika total rate adalah 16% (Artinya gabungan Service 5% + PPN 11%)
            if ($dbRate == 16.0) {
                $serviceRate = 5.0;
                $ppnRate     = 11.0;
            } else if ($dbRate == 15.0) { // Jika suatu saat outlet pakai Service 5% + PPN 10%
                $serviceRate = 5.0;
                $ppnRate     = 10.0;
            } else {
                // Jika data pajak di db hanya PPN murni (misal 10% atau 11% tanpa service charge)
                $serviceRate = 0.0;
                $ppnRate     = $dbRate;
            }

            // REGULASI URUTAN BERJENJANG:
            // 1. Hitung nominal Service Charge dari nilai setelah diskon
            $serviceAmount = (int) round($afterDiscount * ($serviceRate / 100));

            // 2. Dasar Pengenaan Pajak PPN (DPP 2) = Nilai setelah diskon + Service Charge
            $dppPPN = $afterDiscount + $serviceAmount;

            // 3. Hitung nominal PPN dari DPP 2
            $ppnAmount = (int) round($dppPPN * ($ppnRate / 100));

            // Total Pajak Gabungan yang disimpan ke database
            $taxAmount = $serviceAmount + $ppnAmount;

            // Buat breakdown dinamis untuk dilempar ke Flutter / Vue
            $newTaxBreakdown = [
                [
                    'name'   => 'Service Charge',
                    'rate'   => $serviceRate,
                    'type'   => 'percentage',
                    'amount' => $serviceAmount
                ],
                [
                    'name'   => 'PPN',
                    'rate'   => $ppnRate,
                    'type'   => 'percentage',
                    'amount' => $ppnAmount
                ]
            ];
        } elseif (array_key_exists('tax_amount', $overrides)) {
            $taxAmount = max(0, (int) $overrides['tax_amount']);
            if (array_key_exists('tax_breakdown', $overrides)) {
                $newTaxBreakdown = $overrides['tax_breakdown'];
            }
        } elseif (array_key_exists('tax_breakdown', $overrides) && is_array($overrides['tax_breakdown'])) {
            $newTaxBreakdown = $overrides['tax_breakdown'];
            $taxAmount = max(0, (int) collect($newTaxBreakdown)->sum(fn($item) => (int) data_get($item, 'amount', 0)));
        } else {
            // Fallback Amankan hitungan jika kasir hanya edit item biasa
            $existingBreakdown = $this->tax_breakdown;

            if (!empty($existingBreakdown) && is_array($existingBreakdown)) {
                $newTaxBreakdown = [];
                $taxBase = $afterDiscount;

                foreach ($existingBreakdown as $tb) {
                    $rate = (float) ($tb['rate'] ?? 0);
                    $type = $tb['type'] ?? 'percentage';
                    $amt = 0;

                    if ($type === 'percentage') {
                        $amt = (int) round($taxBase * ($rate / 100));
                    } else {
                        $amt = (int) $rate;
                    }

                    $tb['amount'] = $amt;
                    $newTaxBreakdown[] = $tb;

                    $taxAmount += $amt;
                    $taxBase += $amt; // Berjenjang otomatis
                }
            } elseif ($this->tax_amount > 0 && $this->subtotal_price > 0) {
                $oldAmountAfterDiscount = max(0, $this->subtotal_price - $this->discount_amount);
                if ($oldAmountAfterDiscount > 0) {
                    $rate = $this->tax_amount / $oldAmountAfterDiscount;
                    $taxAmount = (int) round($afterDiscount * $rate);
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 7. Grand Total (Hasil Akhir)
        |--------------------------------------------------------------------------
        */
        $grandTotal = max(0, $afterDiscount + $taxAmount);

        /*
        |--------------------------------------------------------------------------
        | 8. Simpan ke Database
        |--------------------------------------------------------------------------
        */
        $updates = [
            'subtotal_price'        => $subtotal,

            'discount_id'          => $discountId,
            'manual_discount_type' => $manualDiscountType,
            'manual_discount_value'=> $manualDiscountType ? $manualDiscountValue : null,
            'discount_amount'      => $discountAmount,

            'tax_id'               => $taxId,
            'tax_amount'           => $taxAmount,

            'total_price'          => $grandTotal,
        ];

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