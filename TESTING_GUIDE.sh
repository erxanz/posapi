#!/usr/bin/env bash

# ==========================================
# POS Multi-Outlet Backend - Testing Guide
# ==========================================
# Copy & paste commands di bawah untuk testing
# Ganti BASE_URL dan TOKEN sesuai environment

BASE_URL="http://localhost:8000/api/v1"
MANAGER_TOKEN=""
KARYAWAN_TOKEN=""
DEVELOPER_TOKEN=""

# ==========================================
# 1. AUTHENTICATION
# ==========================================

# Register Manager
curl -X POST "$BASE_URL/register" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Manager Jakarta",
    "email": "manager@jakarta.com",
    "password": "password123",
    "password_confirmation": "password123",
    "role": "manager"
  }'

# Login Manager
curl -X POST "$BASE_URL/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "manager@jakarta.com",
    "password": "password123"
  }'

# Login with PIN (Karyawan)
curl -X POST "$BASE_URL/login-pin" \
  -H "Content-Type: application/json" \
  -d '{
    "pin": "123456",
    "outlet_id": 1
  }'

# ==========================================
# 2. PUBLIC API (No Auth)
# ==========================================

# Get Public Menu
curl -X GET "$BASE_URL/public/menu/1/1"

# Create Public Order
curl -X POST "$BASE_URL/public/order" \
  -H "Content-Type: application/json" \
  -d '{
    "outlet_id": 1,
    "table_id": 1,
    "customer_name": "Bro Ahmad",
    "items": [
      { "product_id": 1, "qty": 2 },
      { "product_id": 2, "qty": 1 }
    ]
  }'

# ==========================================
# 3. OUTLET MANAGEMENT
# ==========================================

# Create Outlet
curl -X POST "$BASE_URL/outlets" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d '{
    "name": "Outlet Jakarta Timur"
  }'

# List My Outlets
curl -X GET "$BASE_URL/outlets" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# Get Outlet Detail
curl -X GET "$BASE_URL/outlets/1" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# Update Outlet
curl -X PUT "$BASE_URL/outlets/1" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d '{
    "name": "Outlet Jakarta Timur (Updated)"
  }'

# Delete Outlet (Safety: must have no karyawan/orders)
curl -X DELETE "$BASE_URL/outlets/1" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# ==========================================
# 4. USER MANAGEMENT
# ==========================================

# Create Karyawan (as Manager)
curl -X POST "$BASE_URL/users/karyawan" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d '{
    "name": "Kasir 1",
    "email": "kasir1@outlet.com",
    "password": "password123",
    "pin": "123456"
  }'

# List Karyawan
curl -X GET "$BASE_URL/users/karyawan" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# Get Karyawan Detail
curl -X GET "$BASE_URL/users/karyawan/5" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# Update Karyawan
curl -X PUT "$BASE_URL/users/karyawan/5" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d '{
    "name": "Kasir Updated",
    "is_active": true,
    "password": "newpassword123"
  }'

# Delete Karyawan
curl -X DELETE "$BASE_URL/users/karyawan/5" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# Create User (as Developer)
curl -X POST "$BASE_URL/users" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $DEVELOPER_TOKEN" \
  -d '{
    "name": "Manager Surabaya",
    "email": "manager.surabaya@pos.com",
    "password": "password123",
    "role": "manager"
  }'

# List All Users (Developer)
curl -X GET "$BASE_URL/users" \
  -H "Authorization: Bearer $DEVELOPER_TOKEN"

# ==========================================
# 5. CATEGORY MANAGEMENT
# ==========================================

# Create Category
curl -X POST "$BASE_URL/categories" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d '{
    "outlet_id": 1,
    "name": "Makanan"
  }'

# List Categories
curl -X GET "$BASE_URL/categories?outlet_id=1" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# Get Category Detail
curl -X GET "$BASE_URL/categories/1" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# Update Category
curl -X PUT "$BASE_URL/categories/1" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d '{
    "name": "Makanan Berat"
  }'

# Delete Category
curl -X DELETE "$BASE_URL/categories/1" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# ==========================================
# 6. PRODUCT MANAGEMENT
# ==========================================

# Create Product
curl -X POST "$BASE_URL/products" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -F "outlet_id=1" \
  -F "category_id=1" \
  -F "station_id=1" \
  -F "name=Nasi Goreng Spesial" \
  -F "description=Nasi goreng dengan telur dan daging sapi" \
  -F "price=35000" \
  -F "cost_price=15000" \
  -F "stock=100" \
  -F "is_active=true" \
  -F "image=@/path/to/image.jpg"

# List Products
curl -X GET "$BASE_URL/products?outlet_id=1&category_id=1&is_active=true&search=nasi" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# Get Product Detail
curl -X GET "$BASE_URL/products/1" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# Update Product
curl -X PUT "$BASE_URL/products/1" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d '{
    "name": "Nasi Goreng Premium",
    "price": 40000,
    "stock": 50
  }'

# Delete Product
curl -X DELETE "$BASE_URL/products/1" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# ==========================================
# 7. ORDER MANAGEMENT
# ==========================================

# Create Order
curl -X POST "$BASE_URL/orders" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d '{
    "outlet_id": 1,
    "table_id": 1,
    "customer_name": "Bro Ahmad",
    "notes": "Jangan pedes"
  }'

# Add Item to Order
curl -X POST "$BASE_URL/orders/1/items" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d '{
    "product_id": 1,
    "station_id": 1,
    "qty": 2
  }'

# List Orders
curl -X GET "$BASE_URL/orders?outlet_id=1&status=pending" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# Get Order Detail
curl -X GET "$BASE_URL/orders/1" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# Remove Item from Order
curl -X DELETE "$BASE_URL/orders/1/items/1" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# Checkout Order
curl -X POST "$BASE_URL/orders/1/checkout" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# Cancel Order
curl -X POST "$BASE_URL/orders/1/cancel" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# ==========================================
# 8. SECURITY TESTS
# ==========================================

# ❌ Manager tries to access other manager's outlet (should fail)
curl -X GET "$BASE_URL/outlets/2" \
  -H "Authorization: Bearer $MANAGER_TOKEN"
# Expected: 403 Forbidden

# ❌ Karyawan tries to create product (should fail)
curl -X POST "$BASE_URL/products" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $KARYAWAN_TOKEN" \
  -d '{"name": "test"}'
# Expected: 403 Forbidden

# ❌ Invalid outlet_id in request (should fail)
curl -X POST "$BASE_URL/orders" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d '{"outlet_id": 99, "table_id": 1}'
# Expected: 403 Forbidden (not owned)

# ==========================================
# 9. USEFUL QUERIES
# ==========================================

# Get all orders for outlet with items & products
curl -X GET "$BASE_URL/orders?outlet_id=1&page=1&limit=20" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# Search products by name
curl -X GET "$BASE_URL/products?search=goreng&outlet_id=1" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# Get all karyawan in outlet (with pagination)
curl -X GET "$BASE_URL/users/karyawan?page=1&per_page=10" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# Get pending orders only
curl -X GET "$BASE_URL/orders?status=pending&outlet_id=1" \
  -H "Authorization: Bearer $MANAGER_TOKEN"

# ==========================================
# NOTES
# ==========================================

# 1. Replace $MANAGER_TOKEN with actual token from login response
# 2. Replace $KARYAWAN_TOKEN with token dari karyawan login/login-pin
# 3. Replace $DEVELOPER_TOKEN with token dari developer
# 4. All times are in UTC (created_at, updated_at)
# 5. Prices are in cents/satuan terkecil (multiply by 100 if storing)
# 6. All POST/PUT endpoints return 201 on success (except updates which return 200)
# 7. Protected endpoints require Authorization header dengan Bearer token
