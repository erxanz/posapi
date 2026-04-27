# Midtrans Payment Integration Guide

## 📋 Overview
Integrasi Midtrans untuk menerima pembayaran via QRIS dan Card di aplikasi POS API.

## ✅ Sudah Terinstall
- Midtrans PHP Library (via Composer)
- OrderController dengan Midtrans imports
- OrderService dengan method `createCheckoutOrderForMidtrans`
- Webhook callback endpoint di route `/v1/midtrans/callback`

---

## 🔧 Setup Environment Variables

Tambahkan ke file `.env` Anda (copy dari `.env.example`):

```env
# Midtrans Payment Gateway
MIDTRANS_SERVER_KEY=your_midtrans_server_key_here
MIDTRANS_IS_PRODUCTION=false  # Set ke true setelah go-live
```

### Cara mendapatkan Server Key:
1. Login ke [Midtrans Dashboard](https://dashboard.midtrans.com)
2. Pilih Environment: **Sandbox** (untuk development)
3. Pergi ke **Settings > Access Keys**
4. Copy **Server Key** dan **Merchant ID** (jika diperlukan)

---

## 🔄 Alur Pembayaran

### 1️⃣ Flutter Client Request Checkout

```json
POST /api/v1/orders/checkout
{
  "outlet_id": 1,
  "table_id": 5,
  "customer_name": "John Doe",
  "payment_method": "Qris",  // atau "Card"
  "items": [
    {
      "product_id": 10,
      "qty": 2,
      "price": 50000,
      "notes": "Tanpa es"
    }
  ],
  "amount_paid": 0  // Bisa 0 karena pembayaran di Midtrans
}
```

### 2️⃣ Backend Create Order (PENDING) & Generate Payment URL

Endpoint `/checkout` akan:
- Membuat order dengan status **PENDING**
- Generate token Midtrans via `Snap::createTransaction()`
- Return `redirect_url` untuk Flutter

**Response:**
```json
{
  "success": true,
  "message": "Order berhasil dibuat. Silakan lanjutkan ke pembayaran Midtrans",
  "data": {
    "order": {
      "id": 123,
      "invoice_number": "INV-20260427-0001",
      "total_price": 100000,
      "status": "pending",
      "items": [...]
    },
    "redirect_url": "https://app.midtrans.com/snap/v2/redirection/..."
  }
}
```

### 3️⃣ Flutter Redirect ke Midtrans Payment Page

```dart
// Di Flutter:
if (response.data['data']['redirect_url'] != null) {
  // Buka URL di WebView atau browser
  await launchUrl(response.data['data']['redirect_url']);
}
```

### 4️⃣ Midtrans Webhook Callback

Setelah pembayaran berhasil, Midtrans mengirim POST ke:
```
POST /api/v1/midtrans/callback
```

**Callback akan:**
- Verifikasi signature dari Midtrans
- Update order status menjadi **PAID** (jika settlement/capture)
- Membuat Payment record
- Update table status menjadi **available**
- Store history transaction

---

## 📨 Webhook Configuration di Midtrans Dashboard

1. Login ke [Midtrans Dashboard](https://dashboard.midtrans.com)
2. Pergi ke **Settings > HTTP Notification**
3. Masukkan URL callback:
   ```
   https://your-domain.com/api/v1/midtrans/callback
   ```
   *Ganti `your-domain.com` dengan domain Anda*

4. Aktifkan notifikasi untuk events:
   - Payment Success (capture)
   - Payment Settlement
   - Payment Cancel
   - Payment Expire

---

## 🔍 Payment Status Mapping

| Midtrans Status | POS Status | Keterangan |
|-----------------|-----------|-----------|
| `capture` | PAID | Pembayaran berhasil |
| `settlement` | PAID | Dana sudah masuk |
| `cancel` | CANCELLED | Pembayaran dibatalkan |
| `deny` | CANCELLED | Pembayaran ditolak |
| `expire` | CANCELLED | Pembayaran expired |

---

## 💾 Database Records

### Order Table
```sql
-- Order dibuat dengan status PENDING
id: 1
invoice_number: "INV-20260427-0001"
status: "pending"
total_price: 100000
created_at: 2026-04-27 10:00:00
```

### Payments Table (Dibuat saat webhook menerima settlement)
```sql
id: 1
order_id: 1
amount_paid: 100000
change_amount: 0
method: "midtrans"
reference_no: "1234567890123456789"  -- Transaction ID dari Midtrans
paid_at: 2026-04-27 10:05:00
paid_by: null  -- Dari Midtrans, bukan manual
```

---

## 🧪 Testing

### Test dengan Midtrans Sandbox

1. **Kartu Kredit Test (Success 200):**
   ```
   No: 4111111111111111
   Exp: 12/25
   CVV: 123
   ```

2. **Jalankan Webhook Manual (untuk testing):**
   ```bash
   curl -X POST https://your-domain.com/api/v1/midtrans/callback \
     -H "Content-Type: application/json" \
     -d '{
       "order_id": "INV-20260427-0001",
       "status_code": "200",
       "gross_amount": "100000",
       "transaction_id": "1234567890123456789",
       "transaction_status": "settlement",
       "signature_key": "your_computed_hash"
     }'
   ```

### Compute Signature (PHP)
```php
$serverKey = env('MIDTRANS_SERVER_KEY');
$orderId = 'INV-20260427-0001';
$statusCode = '200';
$grossAmount = '100000';

$hash = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);
echo $hash;
```

---

## ⚠️ Error Handling

### Error di Checkpoint 1: Order Creation Gagal
```json
{
  "success": false,
  "message": "Gagal generate token Midtrans: [error message]"
}
```
**Solusi:** Cek MIDTRANS_SERVER_KEY di `.env`

### Error di Checkpoint 2: Order Tidak Ditemukan saat Callback
**Solusi:** Pastikan `invoice_number` unik dan sesuai di order

### Error di Checkpoint 3: Signature Verification Gagal
**Solusi:** 
- Verifikasi MIDTRANS_SERVER_KEY akurat
- Cek format request dari Midtrans

---

## 📊 Monitoring

### Cek Status Order:
```bash
GET /api/v1/orders/{order_id}
```

### Lihat Payment History:
```sql
SELECT * FROM payments WHERE order_id = 123;
```

### Check Midtrans Logs:
- Dashboard Midtrans > Transactions
- Lihat detail transaction untuk debugging

---

## 🚀 Production Checklist

- [ ] Set `MIDTRANS_IS_PRODUCTION=true` di `.env`
- [ ] Update ke Production Server Key di `.env`
- [ ] Test dengan real payment
- [ ] Update webhook URL ke production domain
- [ ] Enable monitoring/alerting untuk failed payments
- [ ] Setup logging untuk debug

---

## 📞 Support

- **Midtrans Docs:** https://docs.midtrans.com
- **Troubleshooting:** https://docs.midtrans.com/reference/snap-create-transaction-error
- **API Status:** https://status.midtrans.com

---

**Last Updated:** April 27, 2026
