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

    public function getIsPromoAttribute(): bool
    {
        $discounts = Discount::where('is_active', true)
            ->whereIn('scope', ['products', 'categories'])
            ->get();

        foreach ($discounts as $discount) {
            $isMatch = false;

            if ($discount->scope === 'products') {
                $productIds = [];
                if (!empty($discount->product_ids)) {
                    $productIds = is_string($discount->product_ids) ? json_decode($discount->product_ids, true) : $discount->product_ids;
                }

                if (!empty($productIds) && in_array($this->id, $productIds)) {
                    $isMatch = true;
                }
            } elseif ($discount->scope === 'categories') {
                $categoryIds = [];
                if (!empty($discount->category_ids)) {
                    $categoryIds = is_string($discount->category_ids) ? json_decode($discount->category_ids, true) : $discount->category_ids;
                }

                if (!empty($categoryIds) && in_array($this->category_id, $categoryIds)) {
                    $isMatch = true;
                }
            }

            if ($isMatch) {
                return true;
            }
        }
        return false;
    }

    public function getMinPurchaseAttribute(): int
    {
        $discounts = Discount::where('is_active', true)
            ->whereIn('scope', ['products', 'categories'])
            ->get();

        foreach ($discounts as $discount) {
            $isMatch = false;

            if ($discount->scope === 'products') {
                $productIds = [];
                if (!empty($discount->product_ids)) {
                    $productIds = is_string($discount->product_ids) ? json_decode($discount->product_ids, true) : $discount->product_ids;
                }
                if (!empty($productIds) && in_array($this->id, $productIds)) {
                    $isMatch = true;
                }
            } elseif ($discount->scope === 'categories') {
                $categoryIds = [];
                if (!empty($discount->category_ids)) {
                    $categoryIds = is_string($discount->category_ids) ? json_decode($discount->category_ids, true) : $discount->category_ids;
                }
                if (!empty($categoryIds) && in_array($this->category_id, $categoryIds)) {
                    $isMatch = true;
                }
            }

            if ($isMatch) {
                return (int) ($discount->min_purchase ?? 0);
            }
        }
        return 0;
    }

    public function getDiscountAmountPerItemAttribute(): int
    {
        $discounts = Discount::where('is_active', true)
            ->whereIn('scope', ['products', 'categories'])
            ->get();

        foreach ($discounts as $discount) {
            $isMatch = false;

            if ($discount->scope === 'products') {
                $productIds = [];
                if (!empty($discount->product_ids)) {
                    $productIds = is_string($discount->product_ids) ? json_decode($discount->product_ids, true) : $discount->product_ids;
                }

                if (!empty($productIds) && in_array($this->id, $productIds)) {
                    $isMatch = true;
                }
            } elseif ($discount->scope === 'categories') {
                $categoryIds = [];
                if (!empty($discount->category_ids)) {
                    $categoryIds = is_string($discount->category_ids) ? json_decode($discount->category_ids, true) : $discount->category_ids;
                }

                if (!empty($categoryIds) && in_array($this->category_id, $categoryIds)) {
                    $isMatch = true;
                }
            }

            if ($isMatch) {
                $originalPrice = $this->price ?? ($this->pivot ? (int) $this->pivot->price : (int) $this->cost_price);

                if ($discount->type === 'percentage') {
                    $calc = $originalPrice * ((int) $discount->value / 100);
                    return $discount->max_discount && $calc > $discount->max_discount ? (int) $discount->max_discount : (int) $calc;
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