<?php

use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\GoogleAuthController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\BarterController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\CouponController;
use App\Http\Controllers\Api\V1\CrmController;
use App\Http\Controllers\Api\V1\CustomPageController;
use App\Http\Controllers\Api\V1\CustomizerController;
use App\Http\Controllers\Api\V1\AdGeneratorController;
use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\LoyaltyController;
use App\Http\Controllers\Api\V1\MarketplaceController;
use App\Http\Controllers\Api\V1\QrController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\DeliveryController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PosController;
use App\Http\Controllers\Api\V1\ProductAddonController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\Store\StoreController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Ruta de la Seda v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // Autenticación pública
    Route::post('auth/login', LoginController::class);
    Route::post('auth/register', RegisterController::class);
    Route::post('auth/google', GoogleAuthController::class);
    Route::post('auth/forgot-password', ForgotPasswordController::class);
    Route::post('auth/reset-password', ResetPasswordController::class);

    // Public
    Route::post('catalog/{store}/verify-password', [StoreController::class, 'verifyCatalogPassword']);
    Route::post('payments/webhook', [PaymentController::class, 'webhook']);
    Route::get('public/stores/{serial}', [StoreController::class, 'publicShow']);
    Route::get('public/stores/{serial}/theme', [StoreController::class, 'publicTheme']);
    Route::get('public/stores/{serial}/availability', [StoreController::class, 'publicAvailability']);
    Route::get('public/stores/{serial}/products', [\App\Http\Controllers\Api\V1\ProductController::class, 'publicIndex']);
    Route::get('public/products/{id}', [\App\Http\Controllers\Api\V1\ProductController::class, 'publicShow']);
    Route::get('public/products/{product}/addons', [ProductAddonController::class, 'publicIndex']);
    Route::get('qr/{id}/track', [QrController::class, 'track']);
    Route::post('public/bookings', [AppointmentController::class, 'publicStore']);
    Route::get('public/site-settings', [AdminController::class, 'publicSiteSettings']);

    // Customer delivery tracking (public, session-based)
    Route::get('delivery/track', [DeliveryController::class, 'customerTrack']);

    // Marketplace (public)
    Route::get('marketplace/products', [MarketplaceController::class, 'products']);
    Route::get('marketplace/categories', [MarketplaceController::class, 'categories']);
    Route::get('marketplace/product/{id}', [MarketplaceController::class, 'show']);
    Route::get('marketplace/stores', [MarketplaceController::class, 'stores']);
    Route::get('marketplace/store/{id}', [MarketplaceController::class, 'storeProfile']);
    Route::get('marketplace/cart', [MarketplaceController::class, 'aggregatedCart']);

    // Ratings (auth required)
    Route::middleware('auth:sanctum')->post('marketplace/store/{storeId}/rate', [MarketplaceController::class, 'rateStore']);

    // Rutas protegidas
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', LogoutController::class);

        Route::get('user', [UserController::class, 'show']);
        Route::delete('user', [UserController::class, 'destroy']);

        // Store
        Route::get('store', [StoreController::class, 'show']);
        Route::put('store', [StoreController::class, 'update']);
        Route::get('store/extra', [StoreController::class, 'extraInfo']);
        Route::put('store/extra', [StoreController::class, 'extraInfo']);
        Route::put('store/banner', [StoreController::class, 'banner']);
        Route::get('store/colors', [StoreController::class, 'colors']);
        Route::put('store/colors', [StoreController::class, 'colors']);
        Route::get('store/theme', [StoreController::class, 'theme']);
        Route::put('store/theme', [StoreController::class, 'theme']);
        Route::get('store/catalog-password', [StoreController::class, 'catalogPassword']);
        Route::put('store/catalog-password', [StoreController::class, 'catalogPassword']);
        Route::delete('store/catalog-password', [StoreController::class, 'removeCatalogPassword']);
        Route::get('store/features', [StoreController::class, 'getFeatures']);
        Route::put('store/features/toggle', [StoreController::class, 'featureToggle']);
        Route::get('store/shipping-costs', [StoreController::class, 'shippingCosts']);
        Route::put('store/shipping-costs', [StoreController::class, 'shippingCosts']);
        Route::get('store/ratings', [StoreController::class, 'ratings']);

        // Products
        Route::get('products/search-barcode', [ProductController::class, 'searchByBarcode']);
        Route::apiResource('products', ProductController::class);
        Route::get('products/{product}/addons', [ProductAddonController::class, 'index']);
        Route::post('products/{product}/addons', [ProductAddonController::class, 'store']);
        Route::put('products/{product}/addons/{addon}', [ProductAddonController::class, 'update']);
        Route::delete('products/{product}/addons/{addon}', [ProductAddonController::class, 'destroy']);

        // POS
        Route::get('pos/orders', [PosController::class, 'activeOrders']);
        Route::post('pos/orders', [PosController::class, 'createOrder']);
        Route::post('pos/orders/{order}/products', [PosController::class, 'addProduct']);
        Route::put('pos/orders/{order}/products/{detail}', [PosController::class, 'updateProduct']);
        Route::delete('pos/orders/{order}/products/{detail}', [PosController::class, 'removeProduct']);
        Route::post('pos/orders/{order}/save', [PosController::class, 'saveOrder']);
        Route::post('pos/orders/{order}/pay', [PosController::class, 'payOrder']);
        Route::delete('pos/orders/{order}', [PosController::class, 'deleteOrder']);
        Route::get('pos/history', [PosController::class, 'history']);
        Route::get('pos/ticket/{noOrder}', [PosController::class, 'ticket']);

        // Delivery - Store owner side
        Route::post('orders/{order}/emit-shipping', [DeliveryController::class, 'emitOrder']);
        Route::get('delivery/linked', [DeliveryController::class, 'linkedDeliverers']);
        Route::put('delivery/linked/{link}/toggle-block', [DeliveryController::class, 'toggleBlock']);
        Route::put('delivery/linked/{link}/verify', [DeliveryController::class, 'verifyDeliver']);

        // Delivery - Deliver side
        Route::post('delivery/attach-store', [DeliveryController::class, 'attachStore']);
        Route::delete('delivery/detach-store/{link}', [DeliveryController::class, 'detachStore']);
        Route::get('delivery/my-stores', [DeliveryController::class, 'myStores']);
        Route::get('delivery/available-orders', [DeliveryController::class, 'availableOrders']);
        Route::post('delivery/orders/{shipping}/accept', [DeliveryController::class, 'acceptOrder']);
        Route::post('delivery/orders/{shipping}/complete', [DeliveryController::class, 'completeOrder']);
        Route::post('delivery/orders/{shipping}/cancel', [DeliveryController::class, 'cancelOrder']);
        Route::get('delivery/active-order', [DeliveryController::class, 'activeOrder']);
        Route::post('delivery/location', [DeliveryController::class, 'updateLocation']);
        Route::get('delivery/location/{deliver}', [DeliveryController::class, 'getLocation']);
        Route::get('delivery/profile', [DeliveryController::class, 'profile']);
        Route::put('delivery/profile', [DeliveryController::class, 'updateProfile']);
        Route::get('delivery/wallet', [DeliveryController::class, 'wallet']);
        Route::get('delivery/history', [DeliveryController::class, 'deliveryHistory']);
        Route::post('delivery/evidence', [DeliveryController::class, 'uploadEvidence']);

        // Payments
        Route::get('payments/account', [PaymentController::class, 'getAccount']);
        Route::post('payments/account', [PaymentController::class, 'saveAccount']);
        Route::post('payments/orders/{order}/preference', [PaymentController::class, 'createPreference']);

        // Appointments
        Route::get('appointments/availability', [AppointmentController::class, 'availability']);
        Route::apiResource('appointments', AppointmentController::class)->only(['index', 'store', 'destroy']);

        // Barters
        Route::post('barters', [BarterController::class, 'store']);
        Route::get('barters/{id}', [BarterController::class, 'show']);

        // Uploads
        Route::post('upload/image', [UploadController::class, 'image']);
        Route::post('upload/video', [UploadController::class, 'video']);
        Route::post('upload/images', [UploadController::class, 'multiple']);

        // QR
        Route::get('qr', [QrController::class, 'list']);
        Route::post('qr/generate', [QrController::class, 'generate']);

        // CRM
        Route::get('crm/tags', [CrmController::class, 'tags']);
        Route::post('crm/sync', [CrmController::class, 'syncFromOrders']);
        Route::apiResource('crm/clients', CrmController::class)->except(['edit']);

        // Media
        Route::get('media/videos', [MediaController::class, 'videos']);
        Route::post('media/videos', [MediaController::class, 'videos']);
        Route::delete('media/videos/{id}', [MediaController::class, 'deleteVideo']);
        Route::get('media/photos', [MediaController::class, 'photos']);
        Route::post('media/photos', [MediaController::class, 'photos']);
        Route::delete('media/photos/{id}', [MediaController::class, 'deletePhoto']);

        // AI Customizer
        Route::post('customizer/ai-suggest', [CustomizerController::class, 'aiSuggest']);
        Route::post('customizer/ai-template', [CustomizerController::class, 'aiTemplate']);
        Route::post('customizer/ai-css', [CustomizerController::class, 'aiCss']);

        // Ad Generator (AI)
        Route::post('ad-generator/generate', [AdGeneratorController::class, 'generateAd']);
        Route::post('ad-generator/description', [AdGeneratorController::class, 'generateDescription']);

        // Loyalty Program
        Route::get('loyalty/config', [LoyaltyController::class, 'config']);
        Route::put('loyalty/config', [LoyaltyController::class, 'updateConfig']);
        Route::get('loyalty/clients', [LoyaltyController::class, 'clients']);
        Route::post('loyalty/adjust', [LoyaltyController::class, 'adjustPoints']);
        Route::get('loyalty/transactions', [LoyaltyController::class, 'transactions']);

        // Chat (store owner)
        Route::get('chat/conversations', [ChatController::class, 'storeConversations']);
        Route::get('chat/conversations/{id}/messages', [ChatController::class, 'storeMessages']);
        Route::post('chat/conversations/{id}/send', [ChatController::class, 'storeSend']);
        Route::post('chat/conversations/{id}/close', [ChatController::class, 'close']);

        // Super Admin
        Route::get('admin/stats', [AdminController::class, 'stats']);
        Route::post('admin/clean-data', [AdminController::class, 'cleanData']);

        // Subscription Plans (super-admin)
        Route::get('admin/subscription-plans', [SubscriptionController::class, 'indexPlans']);
        Route::post('admin/subscription-plans', [SubscriptionController::class, 'storePlan']);
        Route::put('admin/subscription-plans/{id}', [SubscriptionController::class, 'updatePlan']);
        Route::delete('admin/subscription-plans/{id}', [SubscriptionController::class, 'deletePlan']);
        Route::get('admin/store-subscriptions', [SubscriptionController::class, 'indexStoreSubscriptions']);
        Route::post('admin/assign-subscription', [SubscriptionController::class, 'assignSubscription']);
        Route::post('admin/create-all-subscriptions', [SubscriptionController::class, 'createAllSubscriptions']);

        // Subscription (store owner)
        Route::get('my-subscription', [SubscriptionController::class, 'mySubscription']);
        Route::get('admin/users', [AdminController::class, 'users']);
        Route::put('admin/users/{id}/toggle', [AdminController::class, 'toggleActive']);
        Route::put('admin/users/{id}/password', [AdminController::class, 'changePassword']);
        Route::delete('admin/users/{id}', [AdminController::class, 'destroy']);
        Route::get('admin/site-settings', [AdminController::class, 'siteSettings']);
        Route::put('admin/site-settings', [AdminController::class, 'updateSiteSettings']);
        Route::get('admin/deliverers/{user}', [DeliveryController::class, 'adminGetDeliverer']);
        Route::put('admin/deliverers/{user}/verify', [DeliveryController::class, 'adminToggleVerify']);

        // Custom Pages (super admin)
        Route::apiResource('admin/custom-pages', CustomPageController::class)->except(['show']);

        // Dashboard & Analytics
        Route::get('dashboard/stats', [DashboardController::class, 'stats']);
        Route::get('analytics/overview', [AnalyticsController::class, 'overview']);
        Route::get('analytics/sales-by-day', [AnalyticsController::class, 'salesByDay']);
        Route::get('analytics/top-products', [AnalyticsController::class, 'topProducts']);
        Route::get('analytics/peak-hours', [AnalyticsController::class, 'peakHours']);
        Route::get('analytics/monthly', [AnalyticsController::class, 'monthlyComparison']);

        // Categories
        Route::get('categories', [CategoryController::class, 'index']);

        // Coupons (store owner)
        Route::get('coupons', [CouponController::class, 'index']);
        Route::post('coupons', [CouponController::class, 'store']);
        Route::delete('coupons/{id}', [CouponController::class, 'destroy']);

        // Orders (store owner)
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{id}', [OrderController::class, 'show']);
        Route::put('orders/{id}/confirm-payment', [OrderController::class, 'confirmPayment']);

        // Orders (customer)
        Route::get('my-orders', [OrderController::class, 'myOrders']);
});

    // Public cart (session-based, no auth required)
    Route::get('stores/{storeSerial}/cart', [CartController::class, 'index']);
    Route::post('stores/{storeSerial}/cart', [CartController::class, 'store']);
    Route::put('stores/{storeSerial}/cart/{cartId}', [CartController::class, 'update']);
    Route::delete('stores/{storeSerial}/cart/{cartId}', [CartController::class, 'destroy']);
    Route::delete('stores/{storeSerial}/cart', [CartController::class, 'clear']);
    Route::post('stores/{storeSerial}/cart/coupon', [CartController::class, 'applyCoupon']);

    // Public checkout (creates order)
    Route::post('stores/{storeSerial}/checkout', [OrderController::class, 'checkout']);

    // Public custom page
    Route::get('pages/{slug}', [CustomPageController::class, 'showBySlug']);

    // Public order detail (session-based, for thank-you page)
    Route::get('public/orders/{id}', [OrderController::class, 'publicOrderDetail']);

    // Public loyalty (check points + redeem)
    Route::post('loyalty/check', [LoyaltyController::class, 'clientPoints']);
    Route::post('loyalty/redeem', [LoyaltyController::class, 'redeem']);

    // Public chat (customer)
    Route::post('chat/start', [ChatController::class, 'customerConversation']);
    Route::get('chat/{id}/messages', [ChatController::class, 'customerMessages']);
    Route::post('chat/{id}/send', [ChatController::class, 'customerSend']);

    // SPA catch-all: serve Vue index.html for any non-API request (must be LAST)
    Route::get('/{any}', function () {
        return file_get_contents(public_path('index.html'));
    })->where('any', '^(?!api).*$');
});

// SPA: serve Vue index.html for all non-API requests
Route::get('/{any?}', function ($any = null) {
    return file_get_contents(public_path('index.html'));
})->where('any', '^(?!api).*$');
