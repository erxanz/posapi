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

    /**
     * Decrement used_count pada discount yang terpasang di order ini.
     * Dipanggil saat order dibatalkan (cancel/expire) agar kuota pemakaian
     * dikembalikan.
     */
    public function decrementDiscountUsage(): void
    {
        // Single discount path
        if ($this->discount_id) {
            $discount = Discount::find($this->discount_id);
            if ($discount && $discount->used_count > 0) {
                $discount->decrement('used_count');
            }
            return;
        }

        // Stacked discount path: read IDs from logs
        $logs = $this->logs ?? [];
        foreach ($logs as $log) {
            if (isset($log['type']) && $log['type'] === 'stacked_discounts' && !empty($log['discount_ids'])) {
                foreach ($log['discount_ids'] as $id) {
                    $discount = Discount::find((int) $id);
                    if ($discount && $discount->used_count > 0) {
                        $discount->decrement('used_count');
                    }
                }
                break;
            }
        }
    }
    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function payableTotal(): int
    {
        return max(
            0,
            (int) $this->subtotal_price
            - (int) $this->discount_amount
            + (int) $this->tax_amount
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Recalculate Total
    |--------------------------------------------------------------------------
    | FIX MASTER: Mengamankan Diskon Bersyarat & Perhitungan Pajak Berjenjang
    |--------------------------------------------------------------------------
    */
    public function recalculateTotals(array $overrides = [])
    {
        $this->loadMissing('outlet');
        $outletOwnerId = $this->outlet?->owner_id;

        $items = clone $this->items;

        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += max(0, (int) $item->total_price);
        }

        $discountId = array_key_exists('discount_id', $overrides) ? $overrides['discount_id'] : $this->discount_id;
        $manualDiscountType = array_key_exists('manual_discount_type', $overrides) ? $overrides['manual_discount_type'] : $this->manual_discount_type;
        $manualDiscountValue = array_key_exists('manual_discount_value', $overrides) ? $overrides['manual_discount_value'] : $this->manual_discount_value;
        $taxId = array_key_exists('tax_id', $overrides) ? $overrides['tax_id'] : $this->tax_id;

        /*
        |--------------------------------------------------------------------------
        | 4. Logika Kalkulasi Diskon Berdasarkan Syarat Minimum Pembelian
        |--------------------------------------------------------------------------
        */
        $discountAmount = 0;
        $discount = null;

        if ($discountId) {
            $discount = \App\Models\Discount::find($discountId);

            if ($discount) {
                if (!$outletOwnerId || (int) $discount->owner_id !== (int) $outletOwnerId) {
                    $discount = null;
                }

                // Validasi periode aktif diskon (start_date / end_date)
                if ($discount) {
                    $today = now()->format('Y-m-d');
                    if ($discount->start_date > $today || $discount->end_date < $today) {
                        $discount = null;
                    }
                }

                // Validasi batas pemakaian (max_usage)
                if ($discount) {
                    if ($discount->max_usage > 0 && $discount->used_count >= $discount->max_usage) {
                        $discount = null;
                    }
                }

            }
        }

        if ($discount) {
            $eligibleTotal = 0;
            $eligibleQty = 0;

            $scope = strtolower(trim($discount->scope ?? 'global'));
            $type = strtolower(trim($discount->type ?? 'nominal'));

            if ($scope === 'products' && !empty($discount->product_ids)) {
                $itemsInScope = $items->whereIn('product_id', $discount->product_ids);
                $eligibleTotal = $itemsInScope->sum('total_price');
                $eligibleQty = $itemsInScope->sum('qty');
            } elseif ($scope === 'categories' && !empty($discount->category_ids)) {
                $itemsInScope = $items->filter(function ($item) use ($discount) {
                    return in_array($item->product->category_id ?? null, $discount->category_ids);
                });
                $eligibleTotal = $itemsInScope->sum('total_price');
                $eligibleQty = $itemsInScope->sum('qty');
            } else {
                $eligibleTotal = $subtotal;
                $eligibleQty = $items->sum('qty');
            }

            // FILTER SYARAT UTAMA: Jika subtotal keranjang belum lolos min_purchase, diskon batal (0)
            if ($discount->min_purchase > 0 && $eligibletotal < $discount->min_purchase) {
                $discountAmount = 0;
            } else {
                // Lolos syarat, eksekusi pemotongan harga coret
                if ($eligibleTotal > 0) {
                       if ($type === 'percentage') {
                        // PERBAIKAN BUG: Gunakan (float)
                        $calc = $eligibleTotal * ((float) $discount->value / 100);
                        if ($discount->max_discount > 0 && $calc > $discount->max_discount) {
                            $calc = $discount->max_discount;
                        }
                        $discountAmount = (int) $calc;
                    } else {
                        // PERBAIKAN BUG: Validasi scope global & transaction agar nominal tidak dikalikan qty
                        if (in_array($scope, ['global', 'transaction', 'all', ''])) {
                            $discountAmount = (int) min($discount->value, $eligibleTotal);
                        } else {
                            $calc = $discount->value * $eligibleQty;
                            $discountAmount = (int) min($calc, $eligibleTotal);
                        }
                    }
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
        } elseif ($this->discount_id && !$discount) {
            // Diskon ada di order tapi di-nullify oleh validasi (expired/max_usage habis) -
            // jangan fallback ke old discount_amount dari DB.
            $discountAmount = 0;
        } else {
            $discountAmount = (int) $this->discount_amount;
        }

        $discountAmount = min($subtotal, $discountAmount);
        $afterDiscount = max(0, $subtotal - $discountAmount);

        /*
        |--------------------------------------------------------------------------
        | 5 & 6. Logika Perhitungan Pajak Berjenjang Dinamis Database
        |--------------------------------------------------------------------------
        */
        $tax = null;
        if ($taxId) {
            $tax = \App\Models\Tax::find($taxId);

            // Pastikan pajak ini memang milik outlet order ini - lihat
            // catatan yang sama untuk validasi diskon di atas.
            if ($tax && (int) $tax->outlet_id !== (int) $this->outlet_id) {
                $tax = null;
            }

            // Jangan terapkan pajak yang sudah dinonaktifkan (active=false)
            if ($tax && !$tax->active) {
                $tax = null;
            }
        }

        $taxAmount = 0;
        $newTaxBreakdown = null;

        if ($tax) {
            $dbRate = (float) $tax->rate;

            // Memisahkan paket pajak gabungan restoran (Service Charge + PPN) secara dinamis
            if ($dbRate == 16.0) {
                $serviceRate = 5.0;
                $ppnRate     = 11.0;
            } else if ($dbRate == 15.0) {
                $serviceRate = 5.0;
                $ppnRate     = 10.0;
            } else {
                $serviceRate = 0.0;
                $ppnRate     = $dbRate;
            }

            // EKSEKUSI BERJENJANG:
            // A. Service Charge dihitung dari nilai setelah diskon (DPP 1)
            $serviceAmount = (int) round($afterDiscount * ($serviceRate / 100));

            // B. Dasar Pengenaan PPN (DPP 2) = Nilai setelah diskon + Service Charge
            $dppPPN = $afterDiscount + $serviceAmount;

            // C. PPN dihitung dari DPP 2
            $ppnAmount = (int) round($dppPPN * ($ppnRate / 100));

            $taxAmount = $serviceAmount + $ppnAmount;

            // Bentuk breakdown JSON rapi untuk dikonsumsi Vue / Flutter
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
        } elseif ($this->tax_id && !$tax) {
            // Pajak ada di order tapi di-nullify oleh validasi (active=false / cross-tenant) -
            // jangan fallback ke old tax_amount dari DB, konsisten dengan pola diskon.
            $taxAmount = 0;
            $newTaxBreakdown = null;
        } elseif (array_key_exists('tax_amount', $overrides)) {
            $taxAmount = max(0, (int) $overrides['tax_amount']);
            if (array_key_exists('tax_breakdown', $overrides)) {
                $newTaxBreakdown = $overrides['tax_breakdown'];
            }
        } elseif (array_key_exists('tax_breakdown', $overrides) && is_array($overrides['tax_breakdown'])) {
            $newTaxBreakdown = $overrides['tax_breakdown'];
            $taxAmount = max(0, (int) collect($newTaxBreakdown)->sum(fn($item) => (int) data_get($item, 'amount', 0)));
        } else {
            // Fallback aman mengawal kestabilan data saat edit/void item via Kasir
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
                    $taxBase += $amt;
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
        | 8. Hasil Akhir (Grand Total)
        |--------------------------------------------------------------------------
        */
        $grandTotal = max(0, $afterDiscount + $taxAmount);

        /*
        |--------------------------------------------------------------------------
        | 9. Eksekusi Update Tunggal Database
        |--------------------------------------------------------------------------
        */
        $updates = [
            'subtotal_price'        => $subtotal,

            // Simpan ID yang sudah tervalidasi kepemilikannya ($discount/$tax
            // di-null-kan di langkah 4 & 5&6 kalau bukan milik outlet ini),
            // bukan $discountId/$taxId mentah - supaya data yang tersimpan
            // konsisten dengan apa yang benar-benar diterapkan ke perhitungan.
            'discount_id'          => $discount?->id,
            'manual_discount_type' => $manualDiscountType,
            'manual_discount_value'=> $manualDiscountType ? $manualDiscountValue : null,
            'discount_amount'      => $discountAmount,

            'tax_id'               => $tax?->id,
            'tax_amount'           => $taxAmount,

            'total_price'          => $grandTotal,
        ];

        if ($newTaxBreakdown !== null) {
            $updates['tax_breakdown'] = $newTaxBreakdown;
        }

        $this->update($updates);
    }

}
