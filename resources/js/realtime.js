// resources/js/realtime.js
import "./echo";

/**
 * Initialize realtime listeners untuk order events
 * Panggil initRealtimeListeners(outletId) setelah authenticated
 *
 * @param {number} outletId - ID outlet yang ingin di-monitor (optional, fallback ke window.currentOutletId)
 */
export function initRealtimeListeners(outletId) {
    const channelOutletId = outletId || window.currentOutletId;

    if (!window.Echo) {
        console.error("[Realtime] Echo tidak tersedia");
        return;
    }

    if (!channelOutletId) {
        console.error("[Realtime] outletId diperlukan untuk subscribe");
        return;
    }

    console.log(`[Realtime] Subscribing to orders.outlet.${channelOutletId}`);

    // Listen order creation
    window.Echo.private("orders.outlet." + channelOutletId)
        .listen("order.created", (data) => {
            console.log("[Realtime] Order Created:", data);
            window.dispatchEvent(
                new CustomEvent("realtime:order-created", { detail: data }),
            );
        })
        .listen("order.updated", (data) => {
            console.log("[Realtime] Order Updated:", data);
            window.dispatchEvent(
                new CustomEvent("realtime:order-updated", { detail: data }),
            );
        })
        .listen("OrderAccepted", (data) => {
            console.log("[Realtime] Order Accepted:", data);
            window.dispatchEvent(
                new CustomEvent("realtime:order-accepted", { detail: data }),
            );
        })
        .listen("PaymentPaid", (data) => {
            console.log("[Realtime] Payment Paid:", data);
            window.dispatchEvent(
                new CustomEvent("realtime:payment-paid", { detail: data }),
            );
        });
}

/**
 * Listen to user-specific channel
 *
 * @param {number} userId - ID user
 */
export function listenToUserChannel(userId) {
    if (!window.Echo || !userId) {
        return;
    }

    window.Echo.private("App.Models.User." + userId).listen(".", (data) => {
        console.log("[Realtime] User Event:", data);
    });
}

/**
 * Disconnect semua realtime listeners
 */
export function disconnectRealtimeListeners() {
    if (window.Echo) {
        window.Echo.disconnect();
        console.log("[Realtime] Disconnected");
    }
}

/**
 * Helper untuk listen ke custom event dari realtime
 * Contoh: onRealtimeEvent('order-created', (data) => { console.log('Order:', data) })
 */
export function onRealtimeEvent(eventName, callback) {
    const customEventName = `realtime:${eventName}`;
    window.addEventListener(customEventName, (e) => callback(e.detail));
}
