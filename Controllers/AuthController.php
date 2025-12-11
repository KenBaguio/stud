<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    // REGISTER
    public function register(Request $request)
    {
        $data = $request->validate([
            'is_organization'   => 'required|boolean',
            'first_name'        => 'required_if:is_organization,false',
            'last_name'         => 'required_if:is_organization,false',
            'organization_name' => 'required_if:is_organization,true',
            'email'             => 'required|email|unique:users,email',
            'phone'             => 'required|digits:11|unique:users,phone',
            'password'          => 'required|confirmed|min:6',
        ]);

        $user = User::create([
            'first_name'        => $data['first_name'] ?? null,
            'last_name'         => $data['last_name'] ?? null,
            'organization_name' => $data['organization_name'] ?? null,
            'email'             => $data['email'],
            'phone'             => $data['phone'],
            'password'          => Hash::make($data['password']),
            'is_organization'   => $data['is_organization'],
            'role'              => 'customer',
        ]);

        [$token, $ttl, $neverExpires] = $this->issueTokenForUser($user, function () use ($user) {
            return JWTAuth::fromUser($user);
        });

        return response()->json([
            'message'     => 'Registered successfully',
            'user'        => $user,
            'token'       => $token,
            'token_type'  => 'bearer',
            'expires_in'  => $neverExpires ? null : $ttl * 60,
            'never_expires' => $neverExpires,
        ], 201);
    }

    // LOGIN
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'login'    => 'required',
            'password' => 'required',
        ]);

        $user = User::where('email', $credentials['login'])
                    ->orWhere('phone', $credentials['login'])
                    ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['Invalid credentials.'],
            ]);
        }

        [$token, $ttl, $neverExpires] = $this->issueTokenForUser($user, function () use ($user) {
            return JWTAuth::fromUser($user);
        });

        return response()->json([
            'message'     => 'Login successful',
            'user'        => $user,
            'token'       => $token,
            'token_type'  => 'bearer',
            'expires_in'  => $neverExpires ? null : $ttl * 60,
            'never_expires' => $neverExpires,
        ]);
    }

    // GOOGLE OAUTH
    public function redirectToGoogle()
    {
        // Stateless because we're using API + JWT
        // Get redirect URI from config (which reads from .env)
        $redirectUri = config('services.google.redirect');
        
        // If not set in config, construct from APP_URL (use actual APP_URL from env, not localhost)
        if (!$redirectUri) {
            $appUrl = config('app.url');
            // If APP_URL is still localhost, log a warning
            if (strpos($appUrl, 'localhost') !== false && config('app.env') === 'production') {
                \Log::warning('Google OAuth: APP_URL is set to localhost in production! Please set GOOGLE_CALLBACK_REDIRECTS or GOOGLE_REDIRECT in .env');
            }
            $redirectUri = rtrim($appUrl, '/') . '/api/auth/google/callback';
        }
        
        // Always log the redirect URI for debugging
        \Log::info('Google OAuth Configuration', [
            'app_url' => config('app.url'),
            'app_env' => config('app.env'),
            'google_client_id' => config('services.google.client_id'),
            'google_callback_redirects_from_env' => env('GOOGLE_CALLBACK_REDIRECTS'),
            'google_redirect_from_env' => env('GOOGLE_REDIRECT'),
            'redirect_uri_used' => $redirectUri,
            'note' => 'Make sure this redirect_uri_used matches EXACTLY what is in Google Cloud Console'
        ]);
        
        return Socialite::driver('google')
            ->stateless()
            ->redirectUrl($redirectUri)
            ->redirect();
    }

   public function handleGoogleCallback()
{
    try {
        \Log::info('Google OAuth callback received', [
            'request_params' => request()->all(),
            'redirect_uri_expected' => config('services.google.redirect')
        ]);
        
        // Check if there's an error from Google
        if (request()->has('error')) {
            $error = request()->get('error');
            $errorDescription = request()->get('error_description', 'No description provided');
            \Log::error('Google OAuth error from callback', [
                'error' => $error,
                'error_description' => $errorDescription
            ]);
            throw new \Exception("Google OAuth error: {$error} - {$errorDescription}");
        }
        
        $googleUser = Socialite::driver('google')->stateless()->user();
        
        if (!$googleUser || !$googleUser->getEmail()) {
            \Log::error('Google OAuth: No user data received');
            throw new \Exception('Failed to retrieve user data from Google');
        }
        
        \Log::info('Google OAuth: User data retrieved', ['email' => $googleUser->getEmail()]);

        // Kuhaa ang image URL gikan sa Google
        $googleImageUrl = $googleUser->getAvatar();

        // Download ug save locally
        $imageContents = file_get_contents($googleImageUrl);
        $imageName = 'profile_' . \Illuminate\Support\Str::random(10) . '.jpg';

        // Save profile image - use R2 if configured, otherwise use public storage
        $imagePath = 'profile_images/' . $imageName;
        $disk = \App\Helpers\R2Helper::getStorageDisk();
        try {
            \Illuminate\Support\Facades\Storage::disk($disk)->put($imagePath, $imageContents);
        } catch (\Exception $e) {
            \Log::error('Failed to save Google profile image: ' . $e->getMessage());
            // Continue without profile image if R2 fails
            $imagePath = null;
        }

        // Create or update user
        $user = \App\Models\User::firstOrCreate(
            ['email' => $googleUser->getEmail()],
            [
                'first_name'      => $googleUser->user['given_name'] ?? null,
                'last_name'       => $googleUser->user['family_name'] ?? null,
                'organization_name'=> null,
                'phone'           => null,
                'password'        => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(16)),
                'is_organization' => false,
                'role'            => 'customer',
                'profile_image'   => $imagePath, // R2 path: 'profile_images/filename.jpg'
            ]
        );
        
        // Update profile image if user already exists
        if ($user->wasRecentlyCreated === false && $imagePath && $user->profile_image !== $imagePath) {
            // Delete old profile image if it exists
            if ($user->profile_image) {
                try {
                    $oldDisk = \App\Helpers\R2Helper::isConfigured() && strpos($user->profile_image, 'profile_images/') === 0 ? 'r2' : 'public';
                    if (\Illuminate\Support\Facades\Storage::disk($oldDisk)->exists($user->profile_image)) {
                        \Illuminate\Support\Facades\Storage::disk($oldDisk)->delete($user->profile_image);
                    }
                } catch (\Exception $e) {
                    \Log::warning('Failed to delete old Google profile image: ' . $e->getMessage());
                }
            }
            $user->profile_image = $imagePath;
            $user->save();
        }

        // Generate JWT token
        [$token] = $this->issueTokenForUser($user, function () use ($user) {
            return \Tymon\JWTAuth\Facades\JWTAuth::fromUser($user);
        });

        // Get frontend URL from environment
        // Use FRONTEND_URL from env, or construct from APP_URL if not set
        $frontendUrl = env('FRONTEND_URL');
        $appUrl = config('app.url');
        
        // If FRONTEND_URL is not set, try to construct from APP_URL
        if (!$frontendUrl) {
            // For production, remove /api if present, or use the main domain
            if (strpos($appUrl, 'api.') !== false || strpos($appUrl, '-api.') !== false) {
                // If API is on subdomain, use main domain for frontend
                $frontendUrl = str_replace(['api.', '-api.'], '', $appUrl);
            } else {
                // For local development, if APP_URL is localhost:8000, use localhost:8080 for frontend
                if (strpos($appUrl, 'localhost:8000') !== false && config('app.env') !== 'production') {
                    $frontendUrl = 'http://localhost:8080';
                } else {
                    // Use same domain as backend for production
                    $frontendUrl = $appUrl;
                }
            }
        }
        
        // Final fallback - ensure we're not using backend URL for frontend redirect
        if (!$frontendUrl || (strpos($frontendUrl, 'localhost:8000') !== false && config('app.env') !== 'production')) {
            \Log::warning('Google OAuth: FRONTEND_URL not set or is backend URL! Using default frontend URL.');
            $frontendUrl = config('app.env') === 'local' ? 'http://localhost:8080' : $appUrl;
        }
        
        // Ensure we're not redirecting to backend URL
        if (strpos($frontendUrl, ':8000') !== false && config('app.env') !== 'production') {
            \Log::warning('Google OAuth: Frontend URL contains port 8000 (backend port)! Changing to port 8080.');
            $frontendUrl = str_replace(':8000', ':8080', $frontendUrl);
        }
        
        $frontendUrl = rtrim($frontendUrl, '/');
        
        // Log for debugging with account information
        \Log::info('Google OAuth success', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'user_name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
            'user_role' => $user->role,
            'profile_image' => $user->profile_image,
            'is_organization' => $user->is_organization,
            'was_recently_created' => $user->wasRecentlyCreated,
            'app_url' => config('app.url'),
            'frontend_url_from_env' => env('FRONTEND_URL'),
            'frontend_url_used' => $frontendUrl,
            'redirect_to' => "{$frontendUrl}/auth/google/callback?token={$token}",
            'account_info' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'role' => $user->role
            ]
        ]);
        
        // Redirect to frontend callback page with token and account info
        $callbackUrl = "{$frontendUrl}/auth/google/callback?token={$token}";
        $callbackUrl .= "&account_id={$user->id}";
        $callbackUrl .= "&account_email=" . urlencode($user->email);
        $callbackUrl .= "&account_name=" . urlencode(trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')));
        
        return redirect($callbackUrl);
    } catch (\Exception $e) {
        \Log::error('Google login error: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'exception_class' => get_class($e)
        ]);
        
        // Get frontend URL from environment (same logic as success handler)
        $frontendUrl = env('FRONTEND_URL');
        $appUrl = config('app.url');
        
        // If FRONTEND_URL is not set, try to construct from APP_URL
        if (!$frontendUrl) {
            if (strpos($appUrl, 'api.') !== false || strpos($appUrl, '-api.') !== false) {
                $frontendUrl = str_replace(['api.', '-api.'], '', $appUrl);
            } else {
                // For local development, if APP_URL is localhost:8000, use localhost:8080 for frontend
                if (strpos($appUrl, 'localhost:8000') !== false && config('app.env') !== 'production') {
                    $frontendUrl = 'http://localhost:8080';
                } else {
                    $frontendUrl = $appUrl;
                }
            }
        }
        
        // Final fallback - ensure we're not using backend URL for frontend redirect
        if (!$frontendUrl || (strpos($frontendUrl, 'localhost:8000') !== false && config('app.env') !== 'production')) {
            $frontendUrl = config('app.env') === 'local' ? 'http://localhost:8080' : $appUrl;
        }
        
        // Ensure we're not redirecting to backend URL
        if (strpos($frontendUrl, ':8000') !== false && config('app.env') !== 'production') {
            $frontendUrl = str_replace(':8000', ':8080', $frontendUrl);
        }
        
        $frontendUrl = rtrim($frontendUrl, '/');
        
        \Log::error('Google OAuth callback error - redirecting to frontend', [
            'error' => $e->getMessage(),
            'frontend_url_used' => $frontendUrl,
            'redirect_to' => "{$frontendUrl}/login?error=google_login_failed"
        ]);
        
        return redirect("{$frontendUrl}/login?error=google_login_failed");
    }
    }

    // ME
    public function me()
    {
        try {
            $user = auth()->user();
            if (!$user) return response()->json(['message' => 'Unauthorized'], 401);
            return response()->json($user);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Token invalid or expired'], 401);
        }
    }

    // REFRESH
    public function refresh()
    {
        try {
            $tokenInstance = JWTAuth::parseToken();
            $user = $tokenInstance->authenticate();
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            [$newToken, $ttl, $neverExpires] = $this->issueTokenForUser($user, function () use ($tokenInstance) {
                return $tokenInstance->refresh();
            });

            return response()->json([
                'access_token' => $newToken,
                'token_type'   => 'bearer',
                'expires_in'   => $neverExpires ? null : $ttl * 60,
                'never_expires' => $neverExpires,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Token refresh failed'], 401);
        }
    }

    // LOGOUT
    public function logout()
    {
        try {
            auth()->logout();
            return response()->json(['message' => 'Successfully logged out']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Logout failed'], 500);
        }
    }

    /**
     * Issue a token for the given user while applying any role-specific TTLs.
     *
     * @param  \App\Models\User  $user
     * @param  callable  $issuer
     * @return array{0:string,1:int,2:bool}
     */
    protected function issueTokenForUser(User $user, callable $issuer): array
    {
        [$resolvedTtl, $neverExpires] = $this->resolveRoleTtl($user);

        $factory = JWTAuth::factory();
        $originalTtl = $factory->getTTL();

        if ($resolvedTtl !== $originalTtl) {
            $factory->setTTL($resolvedTtl);
        }

        try {
            $token = $issuer();
        } finally {
            $factory->setTTL($originalTtl);
        }

        return [$token, $resolvedTtl, $neverExpires];
    }

    /**
     * Determine the TTL (in minutes) for the given user's role.
     *
     * @return array{0:int,1:bool}
     */
    protected function resolveRoleTtl(User $user): array
    {
        $defaultTtl = (int) config('jwt.ttl', 60);
        $roleTtls = config('jwt.role_ttls', []);
        $roleSpecific = $roleTtls[$user->role] ?? null;

        if (is_string($roleSpecific) && strtolower($roleSpecific) === 'never') {
            $neverTtl = (int) config('jwt.never_expire_ttl', $defaultTtl);
            return [$neverTtl, true];
        }

        if (is_numeric($roleSpecific) && (int) $roleSpecific > 0) {
            return [(int) $roleSpecific, false];
        }

        return [$defaultTtl, false];
    }
}
