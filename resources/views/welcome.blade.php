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
            <details open>
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
                    <h2 class="section-title" style="margin: 0;">Users <span
                            style="font-weight: 400; color: #6b7280; font-size: 0.875rem;">(Role Based)</span></h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-users" style="color: #8b5cf6; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #8b5cf6; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/users/karyawan</code>
                        <span class="note">Manager</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-get">GET</span>
                        <code class="api-path">/api/v1/users/karyawan</code>
                        <span class="note">Manager</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-get">GET</span>
                        <code class="api-path">/api/v1/users/karyawan/{id}</code>
                        <span class="note">Manager</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-delete">DELETE</span>
                        <code class="api-path">/api/v1/users/karyawan/{id}</code>
                        <span class="note">Manager</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-put">PUT</span>
                        <code class="api-path">/api/v1/users/karyawan/{id}</code>
                        <span class="note">Manager</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-post">POST</span>
                        <code class="api-path">/api/v1/users</code>
                        <span class="note">Developer</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-get">GET</span>
                        <code class="api-path">/api/v1/users</code>
                        <span class="note">Developer</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-get">GET</span>
                        <code class="api-path">/api/v1/users/{id}</code>
                        <span class="note">Developer</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-delete">DELETE</span>
                        <code class="api-path">/api/v1/users/{id}</code>
                        <span class="note">Developer</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-put">PUT</span>
                        <code class="api-path">/api/v1/users/{id}</code>
                        <span class="note">Developer</span>
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
                        <span class="badge badge-resource">API RES</span>
                        <code class="api-path">/api/v1/outlets</code>
                        <span class="api-method-list">GET, POST, GET {id}, PUT, DELETE</span>
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
                    <h2 class="section-title" style="margin: 0;">Tables (Meja)</h2>
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
                        <span class="api-method-list">GET, POST, GET {id}, PUT, DELETE</span>
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
                    <h2 class="section-title" style="margin: 0;">Stations (Stasiun Kerja)</h2>
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
                        <span class="api-method-list">GET, POST, GET {id}, PUT, DELETE</span>
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
                    <h2 class="section-title" style="margin: 0;">Products & Categories</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-box-open" style="color: #f59e0b; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #f59e0b; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-resource">API RES</span>
                        <code class="api-path">/api/v1/categories</code>
                        <span class="api-method-list">GET, POST, GET {id}, PUT, DELETE</span>
                        <span class="note">Auth Required</span>
                    </div>
                    <div class="endpoint-row">
                        <span class="badge badge-resource">API RES</span>
                        <code class="api-path">/api/v1/products</code>
                        <span class="api-method-list">GET, POST, GET {id}, PUT, DELETE</span>
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
                        <span class="badge badge-resource">API RES</span>
                        <code class="api-path">/api/v1/shift-karyawans</code>
                        <span class="api-method-list">GET, POST, GET {id}, PUT, DELETE</span>
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
                        <span class="badge badge-resource">API RES</span>
                        <code class="api-path">/api/v1/history-transactions</code>
                        <span class="api-method-list">GET, GET {id}</span>
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
                    <h2 class="section-title" style="margin: 0;">Orders (POS)</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-cart-shopping" style="color: #ec4899; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #ec4899; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-resource">API RES</span>
                        <code class="api-path">/api/v1/orders</code>
                        <span class="api-method-list">GET, POST, GET {id}</span>
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

        <!-- Order Items Section - CLOSED by default -->
        <div class="section-card">
            <details>
                <summary
                    style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 1rem; list-style: none;">
                    <h2 class="section-title" style="margin: 0;">Order Items (Kitchen Action)</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-list" style="color: #f59e0b; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #f59e0b; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 0 1rem 1rem 1rem;">
                    <div class="endpoint-row">
                        <span class="badge badge-put">PATCH</span>
                        <code class="api-path">/api/v1/order-items/{id}/status</code>
                        <span class="note">Auth Required</span>
                    </div>
                </div>
            </details>
        </div>

        <!-- Database Schema Section - CLOSED by default -->
        <div class="section-card">
            <details>
                <summary
                    style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 1rem; list-style: none;">
                    <h2 class="section-title" style="margin: 0;">Database Schema</h2>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <i class="fa-solid fa-database" style="color: #3b82f6; font-size: 1.125rem;"></i>
                        <i class="fa-solid fa-chevron-down"
                            style="color: #3b82f6; font-size: 0.875rem; transition: transform 0.2s;"></i>
                    </div>
                </summary>
                <div style="padding: 1.5rem;">
                    <div style="margin-bottom: 1.5rem;">
                        <details>
                            <summary
                                style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.5rem 1rem; font-size: 0.9rem; font-weight: 600; color: #111827; background: #f3f4f6; border-radius: 0.375rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <h3 style="font-size: 0.95rem; font-weight: 600; color: #111827; margin: 0;">
                                        users
                                    </h3>
                                </div>
                                <i class="fa-solid fa-chevron-down"
                                    style="color: #8b5cf6; font-size: 0.875rem; transition: transform 0.2s;"></i>
                            </summary>
                            <div style="padding: 1rem;">
                                <table
                                    style="width: 100%; border-collapse: collapse; font-size: 0.85rem; color: #4b5563; background: #f9fafb; border-radius: 0.375rem; overflow: hidden;">
                                    <thead>
                                        <tr style="background: #e5e7eb;">
                                            <th
                                                style="padding: 0.75rem; text-align: left; font-weight: 600; border: 1px solid #d1d5db;">
                                                Column</th>
                                            <th
                                                style="padding: 0.75rem; text-align: left; font-weight: 600; border: 1px solid #d1d5db;">
                                                Type/Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Primary Key</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">name</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">String</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">email</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">String (Unique)
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">email_verified_at
                                            </td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Timestamp
                                                (Nullable)</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">password</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">String (Hashed)
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">remember_token</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">String (Nullable)
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">pin</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">String</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">outlet_id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">FK: outlets</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">role</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Enum
                                                (developer|manager|karyawan)</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">created_at</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Timestamp</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">updated_at</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Timestamp</td>
                                        </tr>
                                    </tbody>
                                </table>
                        </details>
                    </div>

                    <div style="margin-bottom: 1.5rem;">
                        <details>
                            <summary
                                style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.5rem 1rem; font-size: 0.9rem; font-weight: 600; color: #111827; background: #f3f4f6; border-radius: 0.375rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <h3 style="font-size: 0.95rem; font-weight: 600; color: #111827; margin: 0;">
                                        outlets
                                    </h3>
                                </div>
                                <i class="fa-solid fa-chevron-down"
                                    style="color: #8b5cf6; font-size: 0.875rem; transition: transform 0.2s;"></i>
                            </summary>
                            <div style="padding: 1rem;">
                                <table
                                    style="width: 100%; border-collapse: collapse; font-size: 0.85rem; color: #4b5563; background: #f9fafb; border-radius: 0.375rem; overflow: hidden;">
                                    <thead>
                                        <tr style="background: #e5e7eb;">
                                            <th
                                                style="padding: 0.75rem; text-align: left; font-weight: 600; border: 1px solid #d1d5db;">
                                                Column</th>
                                            <th
                                                style="padding: 0.75rem; text-align: left; font-weight: 600; border: 1px solid #d1d5db;">
                                                Type/Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Primary Key</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">name</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">String</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">owner_id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">FK: users</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">created_at</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Timestamp</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">updated_at</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Timestamp</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                        </details>
                    </div>



                    <div style="margin-bottom: 1.5rem;">
                        <details>
                            <summary
                                style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.5rem 1rem; font-size: 0.9rem; font-weight: 600; color: #111827; background: #f3f4f6; border-radius: 0.375rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <h3 style="font-size: 0.95rem; font-weight: 600; color: #111827; margin: 0;">
                                        tables
                                    </h3>
                                </div>
                                <i class="fa-solid fa-chevron-down"
                                    style="color: #8b5cf6; font-size: 0.875rem; transition: transform 0.2s;"></i>
                            </summary>
                            <div style="padding: 1rem;">
                                <table
                                    style="width: 100%; border-collapse: collapse; font-size: 0.85rem; color: #4b5563; background: #f9fafb; border-radius: 0.375rem; overflow: hidden;">
                                    <thead>
                                        <tr style="background: #e5e7eb;">
                                            <th
                                                style="padding: 0.75rem; text-align: left; font-weight: 600; border: 1px solid #d1d5db;">
                                                Column</th>
                                            <th
                                                style="padding: 0.75rem; text-align: left; font-weight: 600; border: 1px solid #d1d5db;">
                                                Type/Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Primary Key</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">outlet_id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">FK: outlets</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">name</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">String</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">code</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">String</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">capacity</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Integer</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">qr_code</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Text</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">qr_token</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">UUID</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">status</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Enum
                                                (available|occupied|reserved|maintenance)</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">is_active</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Boolean</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">created_at</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Timestamp</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">updated_at</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Timestamp</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">deleted_at</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Timestamp
                                                (Nullable)
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </div>

                    <div style="margin-bottom: 1.5rem;">
                        <details>
                            <summary
                                style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.5rem 1rem; font-size: 0.9rem; font-weight: 600; color: #111827; background: #f3f4f6; border-radius: 0.375rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <h3 style="font-size: 0.95rem; font-weight: 600; color: #111827; margin: 0;">
                                        stations
                                    </h3>
                                </div>
                                <i class="fa-solid fa-chevron-down"
                                    style="color: #8b5cf6; font-size: 0.875rem; transition: transform 0.2s;"></i>
                            </summary>
                            <div style="padding: 1rem;">
                                <table
                                    style="width: 100%; border-collapse: collapse; font-size: 0.85rem; color: #4b5563; background: #f9fafb; border-radius: 0.375rem; overflow: hidden;">
                                    <thead>
                                        <tr style="background: #e5e7eb;">
                                            <th
                                                style="padding: 0.75rem; text-align: left; font-weight: 600; border: 1px solid #d1d5db;">
                                                Column</th>
                                            <th
                                                style="padding: 0.75rem; text-align: left; font-weight: 600; border: 1px solid #d1d5db;">
                                                Type/Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Primary Key</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">outlet_id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">FK: outlets</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">name</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Enum
                                                (Kitchen|Bar|Kasir|Dessert)</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">created_at</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Timestamp</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">updated_at</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Timestamp</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </details>

                    </div>

                    <div style="margin-bottom: 1.5rem;">
                        <details>
                            <summary
                                style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.5rem 1rem; font-size: 0.9rem; font-weight: 600; color: #111827; background: #f3f4f6; border-radius: 0.375rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <h3 style="font-size: 0.95rem; font-weight: 600; color: #111827; margin: 0;">
                                        categories
                                    </h3>
                                </div>
                                <i class="fa-solid fa-chevron-down"
                                    style="color: #8b5cf6; font-size: 0.875rem; transition: transform 0.2s;"></i>
                            </summary>
                            <div style="padding: 1rem;">
                                <table
                                    style="width: 100%; border-collapse: collapse; font-size: 0.85rem; color: #4b5563; background: #f9fafb; border-radius: 0.375rem; overflow: hidden;">
                                    <thead>
                                        <tr style="background: #e5e7eb;">
                                            <th
                                                style="padding: 0.75rem; text-align: left; font-weight: 600; border: 1px solid #d1d5db;">
                                                Column</th>
                                            <th
                                                style="padding: 0.75rem; text-align: left; font-weight: 600; border: 1px solid #d1d5db;">
                                                Type/Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Primary Key</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">name</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">String</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">outlet_id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">FK: outlets</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">created_at</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Timestamp</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">updated_at</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Timestamp</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </details>

                    </div>

                    <div style="margin-bottom: 1.5rem;">
                        <details>
                            <summary
                                style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.5rem 1rem; font-size: 0.9rem; font-weight: 600; color: #111827; background: #f3f4f6; border-radius: 0.375rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <h3 style="font-size: 0.95rem; font-weight: 600; color: #111827; margin: 0;">
                                        products
                                    </h3>
                                </div>
                                <i class="fa-solid fa-chevron-down"
                                    style="color: #8b5cf6; font-size: 0.875rem; transition: transform 0.2s;"></i>
                            </summary>
                            <div style="padding: 1rem;">
                                <table
                                    style="width: 100%; border-collapse: collapse; font-size: 0.85rem; color: #4b5563; background: #f9fafb; border-radius: 0.375rem; overflow: hidden;">
                                    <thead>
                                        <tr style="background: #e5e7eb;">
                                            <th
                                                style="padding: 0.75rem; text-align: left; font-weight: 600; border: 1px solid #d1d5db;">
                                                Column</th>
                                            <th
                                                style="padding: 0.75rem; text-align: left; font-weight: 600; border: 1px solid #d1d5db;">
                                                Type/Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Primary Key</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">category_id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">FK: categories</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">outlet_id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">FK: outlets</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">station_id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">FK: stations</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">name</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">String</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">description</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Text</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">price</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Decimal</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">cost_price</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Decimal</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">stock</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Integer</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">image</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">String (Nullable)
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">is_active</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Boolean</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">created_at</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Timestamp</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">updated_at</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Timestamp</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </div>

                    <div style="margin-bottom: 1.5rem;">
                        <details>
                            <summary
                                style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.5rem 1rem; font-size: 0.9rem; font-weight: 600; color: #111827; background: #f3f4f6; border-radius: 0.375rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <h3 style="font-size: 0.95rem; font-weight: 600; color: #111827; margin: 0;">
                                        orders
                                    </h3>
                                </div>
                                <i class="fa-solid fa-chevron-down"
                                    style="color: #8b5cf6; font-size: 0.875rem; transition: transform 0.2s;"></i>
                            </summary>
                            <div style="padding: 1rem;">
                                <table
                                    style="width: 100%; border-collapse: collapse; font-size: 0.85rem; color: #4b5563; background: #f9fafb; border-radius: 0.375rem; overflow: hidden;">
                                    <thead>
                                        <tr style="background: #e5e7eb;">
                                            <th
                                                style="padding: 0.75rem; text-align: left; font-weight: 600; border: 1px solid #d1d5db;">
                                                Column</th>
                                            <th
                                                style="padding: 0.75rem; text-align: left; font-weight: 600; border: 1px solid #d1d5db;">
                                                Type/Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Primary Key
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">outlet_id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">FK: outlets
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">user_id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">FK: users</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">customer_name
                                            </td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">String</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">notes</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Text (Nullable)
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">table_id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">FK: tables</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">invoice_number
                                            </td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">String (Unique)
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">total_price
                                            </td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Decimal</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">status</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Enum
                                                (pending|paid|cancelled)
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">created_at</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Timestamp</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">updated_at</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Timestamp</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </div>

                    <div>
                        <details>
                            <summary
                                style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.5rem 1rem; font-size: 0.9rem; font-weight: 600; color: #111827; background: #f3f4f6; border-radius: 0.375rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <h3 style="font-size: 0.95rem; font-weight: 600; color: #111827; margin: 0;">
                                        order_items
                                    </h3>
                                </div>
                                <i class="fa-solid fa-chevron-down"
                                    style="color: #8b5cf6; font-size: 0.875rem; transition: transform 0.2s;"></i>
                            </summary>
                            <div style="padding: 1rem;">
                                <table
                                    style="width: 100%; border-collapse: collapse; font-size: 0.85rem; color: #4b5563; background: #f9fafb; border-radius: 0.375rem; overflow: hidden;">
                                    <thead>
                                        <tr style="background: #e5e7eb;">
                                            <th
                                                style="padding: 0.75rem; text-align: left; font-weight: 600; border: 1px solid #d1d5db;">
                                                Column</th>
                                            <th
                                                style="padding: 0.75rem; text-align: left; font-weight: 600; border: 1px solid #d1d5db;">
                                                Type/Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Primary Key</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">order_id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">FK: orders</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">product_id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">FK: products</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">station_id</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">FK: stations</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">qty</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Integer</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">price</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Decimal</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">total_price</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Decimal</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">created_at</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Timestamp</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">updated_at</td>
                                            <td style="padding: 0.75rem; border: 1px solid #d1d5db;">Timestamp</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </details>
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
