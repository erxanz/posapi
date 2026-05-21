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
        $discount = Discount::where('is_active', true)
            ->where('scope', 'products')
            ->first();

        if (!$discount || empty($discount->product_ids)) {
            return false;
        }

        $productIds = is_string($discount->product_ids) ? json_decode($discount->product_ids, true) : $discount->product_ids;
        
        return in_array($this->id, $productIds);
    }

    public function getDiscountAmountPerItemAttribute(): int
    {
        $discount = Discount::where('is_active', true)
            ->where('scope', 'products')
            ->first();

        if (!$discount || !$this->getIsPromoAttribute()) {
            return 0;
        }

        $originalPrice = $this->pivot ? (int) $this->pivot->price : (int) $this->cost_price;

        if ($discount->type === 'percentage') {
            $calc = $originalPrice * ((int) $discount->value / 100);
            if ($discount->max_discount && $calc > $discount->max_discount) {
                return (int) $discount->max_discount;
            }
            return (int) $calc;
        }

        return (int) $discount->value;
    }

    public function getPromoPriceAttribute(): int
    {
        $originalPrice = $this->pivot ? (int) $this->pivot->price : (int) $this->cost_price;
        return max(0, $originalPrice - $this->getDiscountAmountPerItemAttribute());
    }
}