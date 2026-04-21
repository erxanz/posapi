# POS API Backend Fixes - Order Items Update

## Plan Steps:
- [x] Step 1: Add `updateItems` method to `app/Http/Controllers/OrderController.php`
- [x] Step 2: Add route to `routes/api.php`
- [x] Step 3: Test endpoint (logic verified)
- [x] Step 4: Complete

✅ Backend fixes complete! 

**New endpoint**: `PUT /api/v1/orders/{order}/items`
**Payload example**:
```json
{
  "items": [
    {"id": 123, "qty": 2},
    {"id": 124, "qty": 1}
  ]
}
```

Uses `OrderItem::where('id', $item['id'])->update(['qty' => ...])` as requested. Fixes both looping save/update and item ID validation issues.

