<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class PayMongoController extends Controller
{
    private string $secretKey;
    private string $baseUrl;
    private string $returnUrl;

    public function __construct()
    {
        $this->secretKey = (string) config('services.paymongo.secret_key');
        $this->baseUrl = rtrim(config('services.paymongo.base_url', 'https://api.paymongo.com/v1'), '/');
        $this->returnUrl = (string) config('services.paymongo.return_url');

        if (blank($this->secretKey)) {
            throw new \RuntimeException('PAYMONGO_SECRET_KEY is not configured.');
        }

        if (blank($this->returnUrl)) {
            throw new \RuntimeException('PAYMONGO_RETURN_URL is not configured.');
        }
    }

    private function paymongoRequest(string $method, string $endpoint, array $payload = []): array
    {
        $response = Http::withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->{$method}("{$this->baseUrl}/{$endpoint}", $payload);

        if ($response->failed()) {
            $detail = $response->json('errors.0.detail') ?? $response->body();
            throw new \RuntimeException('PayMongo API error: ' . $detail);
        }

        return $response->json();
    }

    /**
     * Create a Payment Intent
     */
    public function createPaymentIntent(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1|max:999999.99',
        ]);

        $amountInPesos = floatval($request->amount);
        $amount = intval(round($amountInPesos * 100)); // peso to centavos (rounded)

        if ($amount < 100) { // Minimum 1 peso (100 centavos)
            return response()->json([
                'error' => 'Invalid amount',
                'message' => 'Minimum payment amount is ₱1.00',
            ], 422);
        }

        Log::info("Creating PayMongo PaymentIntent", [
            'amount_pesos' => $amountInPesos,
            'amount_centavos' => $amount
        ]);

        try {
            $statementDescriptor = substr(config('app.name', 'Checkout'), 0, 22); // Max 22 chars for PayMongo
            
            $response = $this->paymongoRequest('post', 'payment_intents', [
                'data' => [
                    'attributes' => [
                        'amount' => $amount,
                        'currency' => 'PHP',
                        'description' => 'Order Payment',
                        'payment_method_allowed' => ['gcash'],
                        'statement_descriptor' => $statementDescriptor,
                    ],
                ],
            ]);

            $intent = $response['data'] ?? [];

            if (empty($intent) || !isset($intent['id'])) {
                Log::error('PayMongo: Invalid response structure', ['response' => $response]);
                throw new \RuntimeException('Invalid response from payment gateway.');
            }

            Log::info('PayMongo: PaymentIntent created successfully', [
                'intent_id' => $intent['id'],
                'status' => $intent['attributes']['status'] ?? 'unknown'
            ]);

            return response()->json([
                'id' => $intent['id'],
                'client_key' => data_get($intent, 'attributes.client_key'),
            ]);
        } catch (\Exception $e) {
            Log::error('PayMongo PaymentIntent creation failed', [
                'amount_pesos' => $amountInPesos,
                'amount_centavos' => $amount,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $errorMessage = $e->getMessage();
            
            // Provide user-friendly error messages
            if (str_contains($errorMessage, 'PayMongo API error')) {
                $errorMessage = 'Payment gateway error. Please try again or contact support.';
            }

            return response()->json([
                'error' => 'PaymentIntent creation failed',
                'message' => $errorMessage,
            ], 500);
        }
    }

    /**
     * Attach a GCash Payment Method
     */
    public function attachPaymentMethod(Request $request)
    {
        $request->validate([
            'payment_intent_id' => 'required|string',
            'billing' => 'required|array',
            'billing.name' => 'required|string|max:255',
            'billing.email' => 'required|email|max:255',
            'billing.phone' => 'required|string|min:10|max:15',
        ]);

        $paymentIntentId = $request->payment_intent_id;
        $billing = $request->billing;

        Log::info('Attaching PayMongo Payment Method', [
            'payment_intent_id' => $paymentIntentId,
            'billing' => [
                'name' => $billing['name'] ?? 'N/A',
                'email' => $billing['email'] ?? 'N/A',
                'phone' => isset($billing['phone']) ? substr($billing['phone'], 0, 4) . '****' : 'N/A',
            ]
        ]);

        try {
            // Clean phone number (remove non-digits)
            $cleanPhone = preg_replace('/\D/', '', $billing['phone'] ?? '');
            
            if (strlen($cleanPhone) < 10) {
                throw new \RuntimeException('Invalid phone number format. Please provide a valid phone number.');
            }

            // Create payment method
            $createMethodResponse = $this->paymongoRequest('post', 'payment_methods', [
                'data' => [
                    'attributes' => [
                        'type' => 'gcash',
                        'billing' => [
                            'name' => trim($billing['name']),
                            'email' => trim($billing['email']),
                            'phone' => $cleanPhone,
                        ],
                    ],
                ],
            ]);

            $paymentMethodId = data_get($createMethodResponse, 'data.id');

            if (blank($paymentMethodId)) {
                Log::error('PayMongo: Payment method ID not found in response', [
                    'response' => $createMethodResponse
                ]);
                throw new \RuntimeException('Unable to create payment method. Please try again.');
            }

            Log::info('PayMongo: Payment method created', ['method_id' => $paymentMethodId]);

            // Attach payment method to intent
            $attachResponse = $this->paymongoRequest(
                'post',
                "payment_intents/{$paymentIntentId}/attach",
                [
                    'data' => [
                        'attributes' => [
                            'payment_method' => $paymentMethodId,
                            'return_url' => $this->returnUrl,
                        ],
                    ],
                ]
            );

            $attributes = data_get($attachResponse, 'data.attributes', []);
            $redirectUrl = data_get($attributes, 'next_action.redirect.url');

            if (blank($redirectUrl)) {
                Log::error('PayMongo: No redirect URL in response', [
                    'response' => $attachResponse,
                    'attributes' => $attributes
                ]);
                throw new \RuntimeException('Payment gateway did not provide a redirect URL. Please try again.');
            }

            Log::info('PayMongo: Payment method attached successfully', [
                'payment_intent_id' => data_get($attachResponse, 'data.id'),
                'status' => $attributes['status'] ?? 'unknown',
                'has_redirect_url' => !blank($redirectUrl)
            ]);

            return response()->json([
                'payment_intent_id' => data_get($attachResponse, 'data.id'),
                'status' => $attributes['status'] ?? null,
                'redirect_url' => $redirectUrl,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('PayMongo: Validation error', [
                'errors' => $e->errors(),
            ]);
            return response()->json([
                'error' => 'Validation failed',
                'message' => 'Invalid billing information provided.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('PayMongo Payment attachment failed', [
                'payment_intent_id' => $paymentIntentId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $errorMessage = $e->getMessage();
            
            // Provide user-friendly error messages
            if (str_contains($errorMessage, 'PayMongo API error')) {
                $errorMessage = 'Payment gateway error. Please try again or contact support.';
            }

            return response()->json([
                'error' => 'Payment attachment failed',
                'message' => $errorMessage,
            ], 500);
        }
    }
}
