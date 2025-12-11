<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;

// -----------------------------
// Controllers
// -----------------------------
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileDashboard\{
    ProfileController,
    ManagePasswordController,
    AddressController,
    CartController,
    VoucherController,
    OrderController
};
use App\Http\Controllers\Api\{
    CategoryController,
    ProductController,
    ReviewController,
    WishlistController,
    MessageController,
    NotificationController,
    CustomProposalController,
    ImageController,
    AnalyticsController,
    ConversationController,
    PayMongoController
};
use App\Http\Controllers\AdminDashboard\{
    CustomerController,
    VipCustomerController,
    VoucherController as AdminVoucherController,
    ReviewController as AdminReviewController,
    MaterialController,
    ExpenseController,
    ExpenseCategoryController,
    ClerkController as AdminClerkController,
    AdminOrderController,
    AdminSalesController,
    WalkinPurchaseController
};
use App\Http\Controllers\ClerkDashboard\{
    ClerkAuthController,
    ClerkCustomerController,
    ClerkMessageController,
    WalkinPurchaseController as ClerkWalkinPurchaseController
};

// -----------------------------
// Broadcasting Auth
// -----------------------------
Route::post('/broadcasting/auth', function () {
    return Broadcast::auth(request());
})->middleware('auth:api');

// -----------------------------
// Image Handling Routes
// -----------------------------
Route::get('/placeholder/{width}/{height}', [ImageController::class, 'placeholder']);
Route::get('/storage/{path}', [ImageController::class, 'serveStorageImage'])->where('path', '.*');
Route::get('/chat-images/{filename}', [ImageController::class, 'serveChatImage']);
Route::post('/upload/chat-images', [ImageController::class, 'uploadChatImages']);

// -----------------------------
// Public API Routes
// -----------------------------
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);

// -----------------------------
// PUBLIC REVIEWS ROUTE - MOVED OUTSIDE AUTH MIDDLEWARE
// -----------------------------
Route::get('/reviews', [ReviewController::class, 'index']);

// -----------------------------
// Analytics Routes
// -----------------------------
Route::get('/analytics/customers-count', [AnalyticsController::class, 'customersCount']);
Route::get('/analytics/popular-searches', [AnalyticsController::class, 'popularSearches']);
Route::get('/analytics/customer-avatars', [AnalyticsController::class, 'customerAvatars']);

// -----------------------------
// Public Voucher Routes
// -----------------------------
Route::get('/vouchers/enabled', [AdminVoucherController::class, 'enabledVouchers']);

// -----------------------------
// Authentication Routes
// -----------------------------
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me'])->middleware('jwt.auth');
    Route::post('/change-password', [ManagePasswordController::class, 'changePassword'])->middleware('jwt.auth');

    // Google OAuth
    Route::get('/google/redirect', [AuthController::class, 'redirectToGoogle']);
    Route::get('/google/callback', [AuthController::class, 'handleGoogleCallback']);
});

// -----------------------------
// Authenticated User Routes
// -----------------------------
Route::middleware('jwt.auth')->group(function () {

    // Profile
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/profile/show', [ProfileController::class, 'show']);
    Route::put('/profile/update', [ProfileController::class, 'update']);
    Route::post('/profile/updateProfileImage', [ProfileController::class, 'updateProfileImage']);

    // Addresses
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::put('/addresses/{id}', [AddressController::class, 'update']);
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy']);

    // Reviews (Customer)
    Route::post('/reviews', [ReviewController::class, 'store']);
    Route::put('/reviews/{id}', [ReviewController::class, 'update']);
    Route::delete('/reviews/{id}', [ReviewController::class, 'destroy']);

    // Customer Vouchers
    Route::get('/my-vouchers', [VoucherController::class, 'myVouchers']);
    Route::get('/vouchers/unread', [VoucherController::class, 'unreadVouchers']);
    Route::get('/vouchers/available-reminder', [VoucherController::class, 'availableVouchersForReminder']);
    Route::post('/vouchers/{id}/mark-viewed', [VoucherController::class, 'markAsViewed']);
    Route::post('/vouchers/mark-all-viewed', [VoucherController::class, 'markAllAsViewed']);
    Route::post('/checkout/validate-voucher', [VoucherController::class, 'validateVoucher']);

    // Cart Routes
    Route::get('/cart', [CartController::class, 'index']);
    Route::post('/cart', [CartController::class, 'store']);
    Route::post('/cart/custom-proposal', [CartController::class, 'storeCustomProposal']);
    Route::put('/cart/{id}', [CartController::class, 'update']);
    Route::delete('/cart/{id}', [CartController::class, 'destroy']);
    Route::delete('/cart/clear', [CartController::class, 'clear']);
    Route::get('/cart/count', [CartController::class, 'count']);
    Route::get('/cart/total', [CartController::class, 'total']);
    Route::post('/cart/validate', [CartController::class, 'validateCart']);
    Route::post('/cart/bulk-update', [CartController::class, 'bulkUpdate']);
    Route::post('/cart/sync', [CartController::class, 'sync']);

    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::put('/orders/{id}/status', [OrderController::class, 'updateStatus']);
    Route::post('/orders/clear-cart', [OrderController::class, 'clearCart']);
    Route::get('/orders/cart-count', [OrderController::class, 'getCartCount']);

    // Wishlist
    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist/toggle', [WishlistController::class, 'toggle']);
    Route::delete('/wishlist/{productId}', [WishlistController::class, 'remove']);

    // Messages
    Route::get('/messages', [MessageController::class, 'index']);
    Route::post('/messages', [MessageController::class, 'store']);
    Route::post('/messages/typing/start', [MessageController::class, 'startTyping']);
    Route::post('/messages/typing/stop', [MessageController::class, 'stopTyping']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications', [NotificationController::class, 'store']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::patch('/notifications/mark-read-sender/{senderId}', [NotificationController::class, 'markAllFromSender']);
    Route::patch('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);

    // Custom Proposals Routes
    Route::prefix('custom-proposals')->group(function () {
        Route::get('/', [CustomProposalController::class, 'index']);
        Route::post('/', [CustomProposalController::class, 'store']);
        Route::get('/{id}', [CustomProposalController::class, 'show']);
        Route::put('/{id}', [CustomProposalController::class, 'update']);
        Route::delete('/{id}', [CustomProposalController::class, 'destroy']);
        Route::get('/customer/{customerId}', [CustomProposalController::class, 'getCustomerProposals']);
        Route::get('/my/proposals', [CustomProposalController::class, 'getMyProposals']);
    });

    // Clerk Routes
    Route::prefix('clerk')->group(function () {
        Route::get('/me', [ClerkCustomerController::class, 'me']);
        Route::get('/customers', [ClerkCustomerController::class, 'index']);
        Route::get('/conversations', [ConversationController::class, 'clerkConversations']);
        Route::get('/messages', [ClerkMessageController::class, 'index']);
        Route::post('/messages/send', [ClerkMessageController::class, 'sendMessage']);
        Route::post('/messages/send-product', [ClerkMessageController::class, 'sendProduct']);
        Route::post('/messages/typing/start', [MessageController::class, 'startTyping']);
        Route::post('/messages/typing/stop', [MessageController::class, 'stopTyping']);
        Route::post('/walkin-purchases', [ClerkWalkinPurchaseController::class, 'store']);
        Route::get('/walkin-purchases', [ClerkWalkinPurchaseController::class, 'index']);
        Route::get('/walkin-purchases/stats', [ClerkWalkinPurchaseController::class, 'getStats']);
        Route::post('/custom-proposals', [CustomProposalController::class, 'store']);
    });

    // Upload proposal images
    Route::post('/upload/proposal-images', [ImageController::class, 'uploadProposalImages']);

    // -----------------------------
    // PayMongo Payment Routes
    // -----------------------------
    Route::prefix('paymongo')->group(function () {
        Route::post('/create-intent', [PayMongoController::class, 'createPaymentIntent']);
        Route::post('/attach-method', [PayMongoController::class, 'attachPaymentMethod']);
    });
});

// -----------------------------
// Admin Routes
// -----------------------------
Route::prefix('admin')->middleware('jwt.auth')->group(function () {

    // Categories
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

    // Products
    Route::get('/products', [ProductController::class, 'adminIndex']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    Route::post('/products/{product}/deactivate', [ProductController::class, 'deactivateProduct']);
    Route::post('/products/bulk-status-update', [ProductController::class, 'bulkUpdateStatus']);
    Route::get('/products/status/{status}', [ProductController::class, 'getProductsByStatus']);

    // Materials
    Route::get('/materials', [MaterialController::class, 'index']);
    Route::post('/materials', [MaterialController::class, 'store']);
    Route::put('/materials/{material}', [MaterialController::class, 'update']);
    Route::delete('/materials/{material}', [MaterialController::class, 'destroy']);
    Route::post('/materials/{material}/restock', [MaterialController::class, 'restock']);
    Route::get('/materials/cost-used', [MaterialController::class, 'getMaterialCostUsed']);

    // Customers
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::put('/customers/{id}/promote', [CustomerController::class, 'promote']);
    Route::get('/customers/{id}/purchase-stats', [CustomerController::class, 'getPurchaseStats']); 

    // VIP Customers
    Route::get('/vips', [VipCustomerController::class, 'index']);
    Route::put('/vips/{id}/remove', [VipCustomerController::class, 'remove']);

    // Vouchers
    Route::get('/vouchers', [AdminVoucherController::class, 'index']);
    Route::post('/vouchers', [AdminVoucherController::class, 'store']);
    Route::put('/vouchers/{id}', [AdminVoucherController::class, 'update']);
    Route::post('/vouchers/{id}/send', [AdminVoucherController::class, 'send']);
    Route::patch('/vouchers/{id}/toggle-status', [AdminVoucherController::class, 'toggleStatus']);

    // Reviews
    Route::get('/reviews', [AdminReviewController::class, 'index']);
    Route::delete('/reviews/{id}', [AdminReviewController::class, 'destroy']);
    Route::put('/reviews/{id}/status', [AdminReviewController::class, 'updateStatus']);
    Route::post('/reviews/{id}/reply', [AdminReviewController::class, 'reply']);

    // Clerks
    Route::get('/clerks', [AdminClerkController::class, 'index']);
    Route::post('/clerks', [AdminClerkController::class, 'store']);
    Route::put('/clerks/{id}', [AdminClerkController::class, 'update']);
    Route::delete('/clerks/{id}', [AdminClerkController::class, 'destroy']);

    // Orders
    Route::get('/orders', [AdminOrderController::class, 'index']);
    Route::get('/orders/{id}', [AdminOrderController::class, 'show']);
    Route::put('/orders/{id}/status', [AdminOrderController::class, 'updateStatus']);

    // Walk-in Purchase
    Route::get('/walkin-purchases', [WalkinPurchaseController::class, 'index']);
    Route::get('/walkin-purchases/stats', [WalkinPurchaseController::class, 'getStats']);

    // Expenses
    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::post('/expenses', [ExpenseController::class, 'store']);
    Route::put('/expenses/{expense}', [ExpenseController::class, 'update']);
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);

    // Expense Categories
    Route::get('/expense-categories', [ExpenseCategoryController::class, 'index']);
    Route::post('/expense-categories', [ExpenseCategoryController::class, 'store']);
    Route::delete('/expense-categories/{category}', [ExpenseCategoryController::class, 'destroy']);

    // Custom Proposals
    Route::get('/custom-proposals', [CustomProposalController::class, 'adminIndex']);

    // Sales Dashboard Routes 
    Route::prefix('sales')->group(function () {
        Route::get('/dashboard', [AdminSalesController::class, 'dashboard']);
        Route::get('/top-products', [AdminSalesController::class, 'topProducts']);
        Route::get('/inventory-alerts', [AdminSalesController::class, 'inventoryAlerts']);
        Route::get('/recent-orders', [AdminSalesController::class, 'recentOrders']);
        Route::get('/charts', [AdminSalesController::class, 'charts']);
        Route::get('/total-discounts', [AdminSalesController::class, 'totalDiscounts']);
    });
});
