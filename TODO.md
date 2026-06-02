# TODO

- [x] Perbaiki diskon hilang saat pembayaran Midtrans dengan menambah `Order::recalculateTotals()` ulang di `OrderController::midtransCallback()` pada saat `settlement/capture` sebelum update status `paid` dan sebelum `syncHistoryTransaction()`.
- [ ] Jalankan test manual: buat order pakai diskon -> checkout midtrans -> selesaikan pembayaran -> verifikasi `discount_amount` dan tampilan history/invoice tetap ada.


