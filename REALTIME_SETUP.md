# Real-time Setup - Local & Cloudflare Tunnel

## ✅ Perbaikan yang Sudah Dilakukan

### 1. **Environment Files**
- **`.env`** ✅
  - Fixed: `REVERB_HOST=0.0.0.0` → `REVERB_HOST=localhost` (untuk client WebSocket connection)
  - Removed: Duplicate `BROADCAST_DRIVER` dan `QUEUE_CONNECTION`
  
- **`.env.example`** ✅
  - Removed: Duplikat `BROADCAST_CONNECTION` (ada 2 nilai berbeda)
  - Removed: Duplikat `FILESYSTEM_DISK` dan `QUEUE_CONNECTION`
  - Cleaned: Hanya 1 set `BROADCAST_CONNECTION=reverb` di akhir

- **`.env.cloudflare.example`** ✅ (baru)
  - Template untuk deploy dengan Cloudflare Tunnel
  - Set `REVERB_HOST` ke domain tunnel + HTTPS

### 2. **Frontend Realtime**
- **`resources/js/realtime.js`** ✅ (ditingkatkan)
  - Added: Parameter `outletId` (previously hardcoded)
  - Added: Error handling & validation
  - Added: `disconnectRealtimeListeners()` function
  - Added: `onRealtimeEvent()` helper untuk listen custom events

- **`resources/js/app.js`** ✅ (ditingkatkan)
  - Exposed: `window.initRealtimeListeners(outletId)`
  - Exposed: `window.disconnectRealtimeListeners()`
  - Exposed: `window.onRealtimeEvent(eventName, callback)`
  - Improved: Auto-init logic

---

## 🚀 Cara Testing

### **1. Local Setup**

Terminal 1 - Start Reverb server:
```bash
php artisan reverb:start
```

Terminal 2 - Start Laravel + Vite:
```bash
npm run dev
```

### **2. Browser Test**

1. Buka: `http://localhost:8000`
2. Buka console (F12)
3. Set outlet ID:
   ```javascript
   window.currentOutletId = 1
   ```
4. Initialize realtime:
   ```javascript
   window.initRealtimeListeners(1)
   ```
5. Listen ke events:
   ```javascript
    window.onRealtimeEvent('order.created', (data) => {
      console.log('🎉 Order Created:', data)
    })
    window.onRealtimeEvent('order.updated', (data) => {
      console.log('📝 Order Updated:', data)
    })
    window.onRealtimeEvent('order.paid', (data) => {
      console.log('💰 Order Paid:', data)
    })
    window.onRealtimeEvent('order.accepted', (data) => {
      console.log('✅ Order Accepted:', data)
    })
    ```
6. Trigger event (dari tinker / endpoint):
   ```bash
   curl http://localhost/test-realtime
   ```

**Output di console:**
```
[Realtime] Subscribing to orders.outlet.1
[Realtime] Order Created: {order: {...}}
🎉 Order Created: {order: {...}}
```

---

## 🌐 Cloudflare Tunnel Setup

### **1. Update Environment**

Copy `.env.cloudflare.example` ke `.env`:
```bash
cp .env.cloudflare.example .env
```

Edit `.env`:
```env
APP_URL=https://posapi.tunnel.example.com
REVERB_HOST=posapi.tunnel.example.com
REVERB_PORT=443
REVERB_SCHEME=https
VITE_REVERB_SCHEME=https
SANCTUM_STATEFUL_DOMAINS=localhost,posapi.tunnel.example.com
```

### **2. Start Reverb dengan HTTPS**

Reverb harus listen di HTTPS untuk Cloudflare Tunnel. Setup:

```bash
# Option A: Gunakan ngrok / localhost tunnel tool
ngrok http 8080

# Option B: Gunakan Cloudflare Tunnel langsung untuk Reverb
cloudflared tunnel run <tunnel-name>

# Kemudian di .env:
REVERB_HOST=<tunnel-domain>
```

### **3. Test dari External**

```bash
# Di browser eksternal
curl -i https://posapi.tunnel.example.com
```

---

## 🔍 Troubleshooting

| Masalah | Penyebab | Fix |
|---------|---------|-----|
| "Echo tidak tersedia" | `window.Echo` undefined | Pastikan `npm run dev` jalan & import bootstrap.js |
| WebSocket connection refused | `REVERB_HOST=0.0.0.0` | Ganti ke `localhost` (local) atau domain tunnel (tunnel) |
| "Private channel denied" | Auth tidak lolos channel auth | Pastikan Sanctum stateful domains benar |
| HTTPS cert error di Tunnel | Cloudflare cert expire | Renew cert atau disable cert verification di dev |
| Channel subscription failed | `outletId` null | Set `window.currentOutletId` atau pass ke `initRealtimeListeners(1)` |

---

## 📝 Backend Event Broadcasting

Events sudah tersedia di:
- `app/Events/OrderCreated.php` → broadcasts ke `orders.outlet.{outletId}` + `customer-order.{orderId}`
- `app/Events/OrderUpdated.php` → broadcasts ke `orders.outlet.{outletId}` + `customer-order.{orderId}`
- `app/Events/OrderAccepted.php` → broadcasts ke `orders.outlet.{outletId}` + `customer-order.{orderId}`
- `app/Events/PaymentPaid.php` → broadcasts ke `orders.outlet.{outletId}` + `customer-order.{orderId}`

Trigger manual:
```php
// routes/web.php atau controller
event(new OrderCreated($order));
event(new OrderUpdated($order));
event(new PaymentPaid($order));
event(new OrderAccepted($order));
```

---

## 📌 Environment Checklist

- ✅ `BROADCAST_CONNECTION=reverb`
- ✅ `REVERB_HOST=localhost` (local) / `REVERB_HOST=domain.tunnel` (tunnel)
- ✅ `REVERB_PORT=8080` (local) / `443` (tunnel)
- ✅ `REVERB_SCHEME=http` (local) / `https` (tunnel)
- ✅ `VITE_REVERB_*` matches server config
- ✅ `SANCTUM_STATEFUL_DOMAINS` includes current domain
- ✅ Queue connection is set (bisa `sync` atau `database`)

