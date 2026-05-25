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

    public function toArray()
    {
        $array = parent::toArray();
        $array['is_promo'] = $this->is_promo;
        $array['promo_price'] = $this->promo_price;
        $array['discount_amount_per_item'] = $this->discount_amount_per_item;
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
        // CEK KEDUANYA: Ambil diskon yang scope-nya 'products' maupun 'categories'
        $discounts = Discount::where('is_active', true)
            ->whereIn('scope', ['products', 'categories'])
            ->get();

        foreach ($discounts as $discount) {
            if ($discount->scope === 'products') {
                $productIds = [];
                if (!empty($discount->product_ids)) {
                    $productIds = is_string($discount->product_ids) ? json_decode($discount->product_ids, true) : $discount->product_ids;
                } elseif ($discount->products) {
                    $productIds = $discount->products->pluck('id')->toArray();
                }

                if (!empty($productIds) && in_array($this->id, $productIds)) {
                    return true; 
                }
            } elseif ($discount->scope === 'categories') {
                $categoryIds = [];
                if (!empty($discount->category_ids)) {
                    $categoryIds = is_string($discount->category_ids) ? json_decode($discount->category_ids, true) : $discount->category_ids;
                } elseif ($discount->categories) {
                    $categoryIds = $discount->categories->pluck('id')->toArray();
                }

                if (!empty($categoryIds) && in_array($this->category_id, $categoryIds)) {
                    return true;
                }
            }
        }
        return false;
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
                } elseif ($discount->products) {
                    $productIds = $discount->products->pluck('id')->toArray();
                }
                
                if (!empty($productIds) && in_array($this->id, $productIds)) {
                    $isMatch = true;
                }
            } elseif ($discount->scope === 'categories') {
                $categoryIds = [];
                if (!empty($discount->category_ids)) {
                    $categoryIds = is_string($discount->category_ids) ? json_decode($discount->category_ids, true) : $discount->category_ids;
                } elseif ($discount->categories) {
                    $categoryIds = $discount->categories->pluck('id')->toArray();
                }

                if (!empty($categoryIds) && in_array($this->category_id, $categoryIds)) {
                    $isMatch = true;
                }
            }

            // Jika produk ini cocok dengan promo produk atau promo kategorinya
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