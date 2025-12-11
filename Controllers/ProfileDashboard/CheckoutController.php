<?php

namespace App\Http\Controllers\ProfileDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Voucher;
use App\Models\Cart;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CheckoutController extends Controller
{
    /**
     * Place an order with cart items, optional voucher, shipping fee, and customer info
     */
    public function placeOrder(Request $request)
    {
        $user = auth()->user();

        $request->validate([
            'cartItems' => 'required|array|min:1',
            'voucher' => 'nullable|array',
            'shippingFee' => 'nullable|numeric',
            'customer' => 'required|array',
            'customer.first_name' => 'required|string',
            'customer.last_name' => 'required|string',
            'customer.email' => 'required|email',
            'customer.phone' => 'required|digits:11',
            'customer.main_address' => 'required|string',
            'customer.specific_address' => 'nullable|string',
        ]);

        $cartItems = $request->cartItems;
        $voucherData = $request->voucher;
        $shippingFee = $request->shippingFee ?? 0;
        $customer = $request->customer;

        DB::beginTransaction();

        try {
            $subtotal = 0;
            $voucherDiscount = 0;
            $voucherId = null;

            // Validate payment method
            $paymentMethod = $request->payment_method ?? 'cod';
            if (!in_array($paymentMethod, ['cod','gcash','credit-card'])) {
                $paymentMethod = 'cod';
            }

            // Calculate subtotal considering size prices
            foreach ($cartItems as $item) {
                $priceToUse = $item['product']['price'] ?? $item['price'] ?? 0;

                // Handle size-based pricing if available
                if (!empty($item['size']) && isset($item['product']['available_sizes']) && isset($item['product']['prices'])) {
                    $sizes = json_decode($item['product']['available_sizes'], true);
                    $prices = json_decode($item['product']['prices'], true);

                    $index = array_search($item['size'], $sizes);
                    if ($index !== false && isset($prices[$index])) {
                        $priceToUse = (float) $prices[$index];
                    }
                }

                $subtotal += $priceToUse * $item['quantity'];
            }

            // Apply voucher if provided
            if ($voucherData && isset($voucherData['id'])) {
                $voucher = Voucher::where('id', $voucherData['id'])
                    ->where('status', 'enabled')
                    ->whereHas('users', function ($query) use ($user) {
                        $query->where('user_id', $user->id)->whereNull('used_at');
                    })
                    ->first();

                if (!$voucher) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Voucher is invalid, expired, or already used.'
                    ], 400);
                }

                $pivotCreated = $voucher->users()->where('user_id', $user->id)->first()->pivot->created_at;
                $expiresAt = $voucher->expiration_type === 'hours'
                    ? Carbon::parse($pivotCreated)->addHours($voucher->expiration_duration)
                    : Carbon::parse($pivotCreated)->addDays($voucher->expiration_duration);

                if (now()->greaterThan($expiresAt)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Voucher has expired.'
                    ], 400);
                }

                $voucherDiscount = round(($subtotal * $voucher->percent) / 100, 2);
                $voucherId = $voucher->id;
            }

            $totalAmount = max(0, $subtotal + $shippingFee - $voucherDiscount);

            // Create order
            $order = Order::create([
                'user_id' => $user->id,
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'total_amount' => $totalAmount,
                'voucher_id' => $voucherId,
                'payment_method' => $paymentMethod,
                'customer' => json_encode($customer),
                'status' => 'pending',
                'payment_status' => 'pending'
            ]);

            // Create order items
            foreach ($cartItems as $item) {
                $priceToUse = $item['product']['price'] ?? $item['price'] ?? 0;

                // Handle size-based pricing
                if (!empty($item['size']) && isset($item['product']['available_sizes']) && isset($item['product']['prices'])) {
                    $sizes = json_decode($item['product']['available_sizes'], true);
                    $prices = json_decode($item['product']['prices'], true);

                    $index = array_search($item['size'], $sizes);
                    if ($index !== false && isset($prices[$index])) {
                        $priceToUse = (float) $prices[$index];
                    }
                }

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product']['id'] ?? null,
                    'name' => $item['product']['name'] ?? $item['name'] ?? 'Unknown',
                    'price' => $priceToUse,
                    'quantity' => $item['quantity'],
                    'size' => $item['size'] ?? null,
                    'color' => $item['product']['color'] ?? $item['color'] ?? null,
                    'image' => $item['product']['image'] ?? null,
                    'size_price' => (!empty($item['size']) ? $priceToUse : null),
                ]);
            }

            // Mark voucher as used
            if ($voucherId) {
                DB::table('user_voucher')
                    ->where('user_id', $user->id)
                    ->where('voucher_id', $voucherId)
                    ->update(['used_at' => now()]);
            }

            // Remove cart items from DB if they have an id
            $cartIds = collect($cartItems)->pluck('id')->filter()->toArray();
            if (!empty($cartIds)) {
                Cart::where('user_id', $user->id)->whereIn('id', $cartIds)->delete();
            }

            DB::commit();

            // Load order with items limited to 5 and voucher
            $order->load([
                'items' => function($query) {
                    $query->limit(5);
                },
                'voucher'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order placed successfully!',
                'order' => $order,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to place order: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validate a voucher for the logged-in user
     */
    public function validateVoucher(Request $request)
    {
        $user = auth()->user();

        $request->validate([
            'voucher_id' => 'required|exists:vouchers,id',
        ]);

        $voucher = Voucher::where('id', $request->voucher_id)
            ->where('status', 'enabled')
            ->whereHas('users', function ($query) use ($user) {
                $query->where('user_id', $user->id)->whereNull('used_at');
            })
            ->first();

        if (!$voucher) {
            return response()->json([
                'success' => false,
                'voucher' => null,
                'message' => 'Voucher is invalid, expired, or already used.'
            ], 400);
        }

        $pivotCreated = $voucher->users()->where('user_id', $user->id)->first()->pivot->created_at;
        $expiresAt = $voucher->expiration_type === 'hours'
            ? Carbon::parse($pivotCreated)->addHours($voucher->expiration_duration)
            : Carbon::parse($pivotCreated)->addDays($voucher->expiration_duration);

        if (now()->greaterThan($expiresAt)) {
            return response()->json([
                'success' => false,
                'voucher' => null,
                'message' => 'Voucher has expired.'
            ], 400);
        }

        return response()->json([
            'success' => true,
            'voucher' => $voucher,
            'expires_at' => $expiresAt,
        ]);
    }
}
