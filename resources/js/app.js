import "./bootstrap";
import { initRealtimeListeners, disconnectRealtimeListeners, onRealtimeEvent } from "./realtime.js";

/**
 * Expose realtime functions to window for use in components/controllers
 *
 * Usage:
 *   - window.initRealtimeListeners(outletId) -> initialize listeners
 *   - window.disconnectRealtimeListeners() -> cleanup
 *   - window.onRealtimeEvent('order-created', (data) => { console.log(data) })
 */
window.initRealtimeListeners = initRealtimeListeners;
window.disconnectRealtimeListeners = disconnectRealtimeListeners;
window.onRealtimeEvent = onRealtimeEvent;

/**
 * Auto-init realtime setelah user authenticated
 * Jika currentOutletId belum tersedia, panggil initRealtimeListeners(outletId) manual dari component
 */
if (window.Echo && window.currentOutletId) {
    initRealtimeListeners(window.currentOutletId);
}
