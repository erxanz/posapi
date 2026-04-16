<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>POS MINI</title>

    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />

    <!-- Styles / Scripts -->
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    {{-- import dari public css --}}
    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>

<body>
    <nav class="navbar">
        <div class="nav-container">
            <div class="nav-brand">
                <div class="nav-icon">
                    <i class="fa-solid fa-server fa-lg"></i>
                </div>
                <div>
                    <h1 class="nav-title">POS API</h1>
                    <p class="nav-subtitle">Documentation Reference</p>
                </div>
            </div>
            <span class="version-badge">v1.0</span>
        </div>
    </nav>

    <main class="main-container">
        <!-- Auth Section - OPEN by default -->
        <div class="section-card">
            {{-- <details open> --}}
            <details>
                <summary
                    style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 1rem; list-style: none;">
                    <h2 class="section-title" style="margin: 0;">Authentication</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-lock" style="color: #6366f1; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #6366f1; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/register</code>
                        <span class="note">Public</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/login</code>
                        <span class="note">Public</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/login-pin</code>
                        <span class="note">Public</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/forgot-password</code>
                        <span class="note">Public</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/reset-password</code>
                        <span class="note">Public</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/logout</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-get">GET</span>
                        <code class="api-path">/api/v1/me</code>
                        <span class="note">Auth Required</span>
                    </div>
                </div>
            </details>
        </div>

        <!-- Public API (QR) Section - CLOSED by default -->
        <div class="section-card">
            <details>
                <summary
                    style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 1rem; list-style: none;">
                    <h2 class="section-title" style="margin: 0;">Public API <span
                            style="font-weight: 400; color: #6b7280; font-size: 0.875rem;">(QR Menu)</span></h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-qrcode" style="color: #f43f5e; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #f43f5e; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-get">GET</span>
                        <code class="api-path">/api/v1/public/menu/{outletId}/{tableId}</code>
                        <span class="note">Public</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/public/order</code>
                        <span class="note">Public</span>
                    </div>
                </div>
            </details>
        </div>

        <!-- Users Section - CLOSED by default -->
        <div class="section-card">
            <details>
                <summary
                    style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 1rem; list-style: none;">
                    <h2 class="section-title" style="margin: 0;">Users</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-users" style="color: #8b5cf6; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #8b5cf6; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-resource">API RES</span>
                        <code class="api-path">/api/v1/users</code>
                        <span class="api-method-list">POST, GET, GET /{id}, PUT /{id}, DELETE /{id}</span>
                        <span class="note">Auth Required (Developer)</span>
                    </div>
                </div>
            </details>
        </div>

        <!-- Outlets Section - CLOSED by default -->
        <div class="section-card">
            <details>
                <summary
                    style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 1rem; list-style: none;">
                    <h2 class="section-title" style="margin: 0;">Outlets</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-store" style="color: #10b981; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #10b981; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/outlets</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-get">GET</span>
                        <code class="api-path">/api/v1/outlets</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-get">GET</span>
                        <code class="api-path">/api/v1/outlets/{outlet}</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-put">PUT</span>
                        <code class="api-path">/api/v1/outlets/{outlet}</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-delete">DELETE</span>
                        <code class="api-path">/api/v1/outlets/{outlet}</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-get">GET</span>
                        <code class="api-path">/api/v1/outlets/{outlet}/products</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/outlets/{outlet}/sync-products</code>
                        <span class="note">Auth Required</span>
                    </div>
                </div>
            </details>
        </div>

        <!-- Tables Section - CLOSED by default -->
        <div class="section-card">
            <details>
                <summary
                    style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 1rem; list-style: none;">
                    <h2 class="section-title" style="margin: 0;">Tables</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-chair" style="color: #64748b; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #64748b; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-resource">API RES</span>
                        <code class="api-path">/api/v1/tables</code>
                        <span class="api-method-list">GET, POST, GET {id}, PUT /{id}, DELETE /{id}</span>
                        <span class="note">Auth Required</span>
                    </div>
                </div>
            </details>
        </div>

        <!-- Stations Section - CLOSED by default -->
        <div class="section-card">
            <details>
                <summary
                    style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 1rem; list-style: none;">
                    <h2 class="section-title" style="margin: 0;">Stations</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-layer-group" style="color: #8b5cf6; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #8b5cf6; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-resource">API RES</span>
                        <code class="api-path">/api/v1/stations</code>
                        <span class="api-method-list">GET, POST, GET /{id}, PUT /{id}, DELETE /{id}</span>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-get">GET</span>
                        <code class="api-path">/api/v1/stations/{id}/products</code>
                        <span class="note">Auth Required</span>
                    </div>
                </div>
            </details>
        </div>

        <!-- Products & Categories Section - CLOSED by default -->
        <div class="section-card">
            <details>
                <summary
                    style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 1rem; list-style: none;">
                    <h2 class="section-title" style="margin: 0;">Categories</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-tags" style="color: #f59e0b; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #f59e0b; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-resource">API RES</span>
                        <code class="api-path">/api/v1/categories</code>
                        <span class="api-method-list">GET, POST, GET /{id}, PUT /{id}, DELETE /{id}</span>
                        <span class="note">Auth Required</span>
                    </div>
                </div>
            </details>
        </div>

        <div class="section-card">
            <details>
                <summary
                    style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 1rem; list-style: none;">
                    <h2 class="section-title" style="margin: 0;">Products</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-box-open" style="color: #f59e0b; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #f59e0b; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-resource">API RES</span>
                        <code class="api-path">/api/v1/products</code>
                        <span class="api-method-list">GET, POST, GET /{id}, PUT /{id}, DELETE /{id}</span>
                        <span class="note">Auth Required</span>
                    </div>
                </div>
            </details>
        </div>

        <div class="section-card">
            <details>
                <summary
                    style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 1rem; list-style: none;">
                    <h2 class="section-title" style="margin: 0;">Discounts</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-tag" style="color: #10b981; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #10b981; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-resource">API RES</span>
                        <code class="api-path">/api/v1/discounts</code>
                        <span class="api-method-list">GET, POST, GET /{id}, PUT /{id}, DELETE /{id}</span>
                        <span class="note">Auth Required</span>
                    </div>
                </div>
            </details>
        </div>

        <div class="section-card">
            <details>
                <summary
                    style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 1rem; list-style: none;">
                    <h2 class="section-title" style="margin: 0;">Taxes</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-percent" style="color: #3b82f6; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #3b82f6; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-resource">API RES</span>
                        <code class="api-path">/api/v1/taxes</code>
                        <span class="api-method-list">GET, POST, GET /{id}, PUT /{id}, DELETE /{id}</span>
                        <span class="note">Auth Required</span>
                    </div>
                </div>
            </details>
        </div>

        <!-- Shift Karyawans Section - CLOSED by default -->
        <div class="section-card">
            <details>
                <summary
                    style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 1rem; list-style: none;">
                    <h2 class="section-title" style="margin: 0;">Shift Karyawans</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-user-clock" style="color: #0ea5e9; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #0ea5e9; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/shift-karyawans/start</code>
                        <span class="note">Auth Required (Flutter)</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/shift-karyawans/end</code>
                        <span class="note">Auth Required (Flutter)</span>
                    </div>
                    <hr style="margin: 1rem 0; border: none; border-top: 1px solid #e5e7eb;">
                    <div class="endpoint-row">
                        <span class="badge badge-resource">API RES (limited)</span>
                        <code class="api-path">/api/v1/shift-karyawans</code>
                        <span class="api-method-list">GET, GET /{id}, DELETE /{id}</span>
                        <span class="note">Auth Required (Manager)</span>
                    </div>
                </div>
            </details>
        </div>

        <div class="section-card">
            <details>
                <summary
                    style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 1rem; list-style: none;">
                    <h2 class="section-title" style="margin: 0;">Shifts</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-clock-rotate-left" style="color: #f97316; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #f97316; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-get">GET</span>
                        <code class="api-path">/api/v1/shifts</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/shifts</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-put">PUT</span>
                        <code class="api-path">/api/v1/shifts/{id}</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-delete">DELETE</span>
                        <code class="api-path">/api/v1/shifts/{id}</code>
                        <span class="note">Auth Required</span>
                    </div>
                </div>
            </details>
        </div>

        <!-- History Transactions Section - CLOSED by default -->
        <div class="section-card">
            <details>
                <summary
                    style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 1rem; list-style: none;">
                    <h2 class="section-title" style="margin: 0;">History Transactions</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-receipt" style="color: #14b8a6; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #14b8a6; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-resource">API RES (limited)</span>
                        <code class="api-path">/api/v1/history-transactions</code>
                        <span class="api-method-list">GET, GET /{id}</span>
                        <span class="note">Auth Required</span>
                    </div>
                </div>
            </details>
        </div>

        <!-- Orders Section - CLOSED by default -->
        <div class="section-card">
            <details>
                <summary
                    style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 1rem; list-style: none;">
                    <h2 class="section-title" style="margin: 0;">Orders</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-cart-shopping" style="color: #ec4899; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #ec4899; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-get">GET</span>
                        <code class="api-path">/api/v1/orders</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/orders</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-get">GET</span>
                        <code class="api-path">/api/v1/orders/{id}</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/orders/checkout</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/orders/{id}/items</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-delete">DELETE</span>
                        <code class="api-path">/api/v1/orders/{id}/items/{itemId}</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/orders/{id}/checkout</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/orders/{id}/payments</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-patch">PATCH</span>
                        <code class="api-path">/api/v1/orders/{id}/adjustments</code>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/orders/{id}/void-items</code>
                        <span class="note">Auth Required</span>
                    </div>
                </div>
            </details>
        </div>

        <div class="section-card">
            <details>
                <summary
                    style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 1rem; list-style: none;">
                    <h2 class="section-title" style="margin: 0;">Stocks</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-warehouse" style="color: #8b5cf6; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #8b5cf6; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/stocks/adjust</code>
                        <span class="note">Auth Required</span>
                    </div>
                </div>
            </details>
        </div>

        <div class="section-card">
            <details>
                <summary
                    style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 1rem; list-style: none;">
                    <h2 class="section-title" style="margin: 0;">Order Items (Kitchen)</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-list" style="color: #f59e0b; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #f59e0b; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-patch">PATCH</span>
                        <code class="api-path">/api/v1/order-items/{id}/status</code>
                        <span class="note">Auth Required</span>
                    </div>
                </div>
            </details>
        </div>
    </main>

    <script>
        // Handle chevron rotation animation on details toggle
        const detailsElements = document.querySelectorAll('details');

        detailsElements.forEach(details => {
            const summary = details.querySelector('summary');
            const chevron = summary.querySelector('.fa-chevron-down');

            // Initial state
            if (!details.hasAttribute('open')) {
                chevron.style.transform = 'rotate(0deg)';
            } else {
                chevron.style.transform = 'rotate(180deg)';
            }

            // Toggle event
            details.addEventListener('toggle', () => {
                if (details.open) {
                    chevron.style.transform = 'rotate(180deg)';
                } else {
                    chevron.style.transform = 'rotate(0deg)';
                }
            });
        });
    </script>
</body>

</html>

</html>
