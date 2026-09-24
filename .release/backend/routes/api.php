<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerPortalController;
use App\Http\Controllers\Api\OrderRequestController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\WithdrawalController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product:slug}', [ProductController::class, 'show']);

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::get('/order-requests', [OrderRequestController::class, 'index']);
        Route::post('/order-requests', [OrderRequestController::class, 'store']);
        Route::get('/order-requests/{orderRequest}', [OrderRequestController::class, 'show']);
        Route::get('/portal/summary', [CustomerPortalController::class, 'summary']);
        Route::patch('/portal/profile', [CustomerPortalController::class, 'updateProfile']);
        Route::get('/portal/orders', [CustomerPortalController::class, 'orders']);
        Route::get('/portal/orders/{order}', [CustomerPortalController::class, 'showOrder']);
        Route::get('/withdrawals', [WithdrawalController::class, 'index']);
        Route::post('/withdrawals', [WithdrawalController::class, 'store']);
        Route::get('/withdrawals/{withdrawalRequest}', [WithdrawalController::class, 'show']);

        Route::prefix('staff')->group(function () {
            Route::get('/dashboard', [StaffController::class, 'dashboard']);
            Route::get('/customers', [StaffController::class, 'customers']);
            Route::get('/customers/{customer}', [StaffController::class, 'showCustomer']);
            Route::get('/order-requests', [StaffController::class, 'requests']);
            Route::get('/order-requests/{orderRequest}', [StaffController::class, 'showRequest']);
            Route::post('/order-requests/{orderRequest}/finalize', [StaffController::class, 'finalize']);
            Route::get('/orders', [StaffController::class, 'orders']);
            Route::get('/orders/{order}', [StaffController::class, 'showOrder']);
            Route::patch('/orders/{order}/status', [StaffController::class, 'updateStatus']);
            Route::post('/orders/{order}/cashback', [StaffController::class, 'addCashback']);
            Route::post('/orders/{order}/wallet-redemption', [StaffController::class, 'redeemWallet']);
            Route::get('/products', [ProductController::class, 'staffIndex']);
            Route::post('/products', [ProductController::class, 'store']);
            Route::patch('/products/{product}', [ProductController::class, 'update']);
            Route::delete('/products/{product}', [ProductController::class, 'destroy']);
            Route::get('/categories', [CategoryController::class, 'index']);
            Route::post('/categories', [CategoryController::class, 'store']);
            Route::patch('/categories/{category}', [CategoryController::class, 'update']);
            Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
            Route::get('/withdrawals', [WithdrawalController::class, 'staffIndex']);
            Route::post('/withdrawals/{withdrawalRequest}/approve', [WithdrawalController::class, 'approve']);
            Route::post('/withdrawals/{withdrawalRequest}/reject', [WithdrawalController::class, 'reject']);
            Route::post('/withdrawals/{withdrawalRequest}/process', [WithdrawalController::class, 'process']);
        });
    });
});
