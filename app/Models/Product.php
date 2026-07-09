<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'name',
        'category_id',
        'description',
        'station_id',
        'cost_price',
        'image',
        'sku',
    ];

    public function toArray()
    {
        $array = parent::toArray();
        $array['is_promo'] = $this->is_promo;
        $array['promo_price'] = $this->promo_price;
        $array['discount_amount_per_item'] = $this->discount_amount_per_item;
        $array['min_purchase'] = $this->min_purchase; // Melempar data ke JSON frontend
        return $array;
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function outlets()
    {
        return $this->belongsToMany(Outlet::class, 'outlet_product')
            ->withPivot('price', 'stock', 'is_active', 'station_id')
            ->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | Discount Accessor Cache
    |--------------------------------------------------------------------------
    | Ketiga accessor di bawah (is_promo, discount_amount_per_item, min_purchase)
    | butuh query Discount yang persis sama. Dulu masing-masing tembak DB
    | sendiri → 3 query per produk → 150 query untuk 50 produk di publicMenu.
    |
    | Sekarang query dijalankan sekali dan hasilnya di-cache di $_discountCache
    | selama lifetime request. Cache di-reset otomatis tiap kali model di-fresh
    | dari DB (karena instance baru = property baru).
    |--------------------------------------------------------------------------
    */

    /** @var \Illuminate\Support\Collection|null */
    private ?object $_discountCache = null;

    /**
     * Ambil discount yang relevan untuk produk ini (cached per-instance).
     * Return discount pertama yang cocok, atau null kalau tidak ada.
     */
    private function _getMatchingDiscount(): ?Discount
    {
        if ($this->_discountCache === null) {
            $today = now()->format('Y-m-d');

            $this->_discountCache = Discount::where('is_active', true)
                ->where('owner_id', $this->owner_id)
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
                ->whereIn('scope', ['global', 'products', 'categories'])
                ->get();
        }

        foreach ($this->_discountCache as $discount) {
            if ($discount->scope === 'global') {
                return $discount;
            }

            if ($discount->scope === 'products') {
                $productIds = is_string($discount->product_ids)
                    ? json_decode($discount->product_ids, true)
                    : ($discount->product_ids ?? []);

                if (!empty($productIds) && in_array($this->id, $productIds)) {
                    return $discount;
                }
            } elseif ($discount->scope === 'categories') {
                $categoryIds = is_string($discount->category_ids)
                    ? json_decode($discount->category_ids, true)
                    : ($discount->category_ids ?? []);

                if (!empty($categoryIds) && in_array($this->category_id, $categoryIds)) {
                    return $discount;
                }
            }
        }

        return null;
    }

    /**
     * Harga dasar produk ini: pivot.price (per-outlet) → cost_price (fallback).
     *
     * $this->price bukan kolom di tabel products — harga ada di pivot
     * outlet_product. Dulu ada `$this->price ??` di sini yang selalu null,
     * lalu fallback ke pivot, lalu ke cost_price. Sekarang langsung ke pivot.
     */
    private function _basePrice(): int
    {
        return $this->pivot ? (int) $this->pivot->price : (int) $this->cost_price;
    }

    public function getIsPromoAttribute(): bool
    {
        return $this->_getMatchingDiscount() !== null;
    }

    public function getMinPurchaseAttribute(): int
    {
        $discount = $this->_getMatchingDiscount();
        return $discount ? (int) ($discount->min_purchase ?? 0) : 0;
    }

    public function getDiscountAmountPerItemAttribute(): int
    {
        $discount = $this->_getMatchingDiscount();
        if (!$discount) return 0;

        $originalPrice = $this->_basePrice();

        if ($discount->type === 'percentage') {
            $calc = $originalPrice * ((float) $discount->value / 100);
            return ($discount->max_discount && $calc > $discount->max_discount)
                ? (int) $discount->max_discount
                : (int) $calc;
        }

        return (int) $discount->value;
    }

    public function getPromoPriceAttribute(): int
    {
        return max(0, $this->_basePrice() - $this->getDiscountAmountPerItemAttribute());
    }
}