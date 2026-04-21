# TODO: Add Missing API Routes

## Steps:
1. [ ] Update `routes/api.php`:
   - Expand `history-transactions` apiResource to `only(['index', 'show', 'destroy'])`
   - Add `Route::apiResource('stocks', StockController::class);` after stocks adjust or stations section.

2. [ ] Update `resources/views/welcome.blade.php`:
   - Expand **History Transactions** section to document destroy.
   - Add new **Stocks** section after Stocks/Order Items with full apiResource endpoints + adjust POST.
   - Ensure **Reports** section exists (add if missing: GET /reports, GET /reports/export).

3. [ ] Verify routes: Run `php artisan route:list --path=v1 | grep -E '(stocks|history-transactions|reports)'`

4. [ ] Test docs: Visit http://posapi.test in browser.

5. [ ] Mark complete and attempt_completion.

