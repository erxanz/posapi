<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'station_id',
        'name',
        'cost_price',
        'image',
        'sku',
    ];

    protected $appends = ['is_promo', 'promo_price', 'discount_amount_per_item'];

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

    public function getIsPromoAttribute(): bool
    {
        // Pengecekan membaca semua diskon aktif
        $discounts = Discount::where('is_active', true)
            ->where('scope', 'products')
            ->get();

        foreach ($discounts as $discount) {
            $productIds = is_string($discount->product_ids) ? json_decode($discount->product_ids, true) : $discount->product_ids;
            if (!empty($productIds) && in_array($this->id, $productIds)) {
                return true; 
            }
        }

        return false;
    }

    public function getDiscountAmountPerItemAttribute(): int
    {
        $discounts = Discount::where('is_active', true)
            ->where('scope', 'products')
            ->get();

        foreach ($discounts as $discount) {
            $productIds = is_string($discount->product_ids) ? json_decode($discount->product_ids, true) : $discount->product_ids;
            
            if (!empty($productIds) && in_array($this->id, $productIds)) {
                $originalPrice = $this->price ?? ($this->pivot ? (int) $this->pivot->price : (int) $this->cost_price);

                if ($discount->type === 'percentage') {
                    $calc = $originalPrice * ((int) $discount->value / 100);
                    if ($discount->max_discount && $calc > $discount->max_discount) {
                        return (int) $discount->max_discount;
                    }
                    return (int) $calc;
                }

                return (int) $discount->value; 
            }
        }

        return 0;
    }

    public function getPromoPriceAttribute(): int
    {
        $originalPrice = $this->price ?? ($this->pivot ? (int) $this->pivot->price : (int) $this->cost_price);
        return max(0, $originalPrice - $this->getDiscountAmountPerItemAttribute());
    }
}