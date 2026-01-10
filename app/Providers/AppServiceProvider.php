<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use App\Repositories\Contracts\SecretRepositoryInterface;
use App\Repositories\SecretRepository;

/**
 * APP SERVICE PROVIDER
 * 
 * PURPOSE: Application-wide service registration and bootstrapping
 * 
 * DEFAULT PROVIDER:
 * - Created automatically with every Laravel app
 * - Central place for app-level configurations
 * - Runs on every request
 * 
 * WHAT WE ADD:
 * - Rate limiting for API endpoints
 * - Prevents abuse of secret creation/retrieval
 * 
 * WHY RATE LIMITING:
 * Without limits, malicious users could:
 * - Create millions of secrets (database bloat)
 * - Try to brute-force UUIDs
 * - Overwhelm server with requests
 * - Perform DDoS attacks
 * 
 * WITH RATE LIMITING:
 * - 60 requests per minute per IP
 * - Reasonable for legitimate use
 * - Prevents abuse
 * - Returns 429 Too Many Requests when exceeded
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * REGISTER APPLICATION SERVICES
     * 
     * PURPOSE: Register bindings in IoC container
     * 
     * WHEN TO USE:
     * - Service bindings
     * - Singleton registrations
     * - Interface implementations
     * 
     * NOTE: We don't register repository here
     * That's in RepositoryServiceProvider for better organization
     * 
     * EXAMPLE IF NEEDED:
     * public function register(): void
     * {
     *     $this->app->singleton(PaymentGateway::class, function () {
     *         return new StripeGateway(config('services.stripe.key'));
     *     });
     * }
     */
    public function register(): void
    {
         $this->app->bind(
        SecretRepositoryInterface::class,
        SecretRepository::class
    );
    }

    /**
     * BOOTSTRAP APPLICATION SERVICES
     * 
     * PURPOSE: Configure services after all providers registered
     * 
     * WHEN TO USE:
     * - Event listeners
     * - Rate limiting configuration
     * - View composers
     * - Model observers
     * - Route configurations
     * 
     * RATE LIMITING SETUP:
     * We configure a named rate limiter called 'secrets'
     * Routes can then use: Route::middleware('throttle:secrets')
     */
    public function boot(): void
    {
        /**
         * RATE LIMITER CONFIGURATION
         * 
         * RateLimiter::for('name', callback)
         * 
         * PARAMETERS:
         * - 'secrets': Name of this rate limiter
         * - callback: Function that returns Limit configuration
         * 
         * CALLBACK RECEIVES:
         * - $request: Current HTTP request
         * 
         * CALLBACK RETURNS:
         * - Limit object defining the rate limit
         */
        RateLimiter::for('secrets', function (Request $request) {
            /**
             * LIMIT CONFIGURATION
             * 
             * Limit::perMinute(60)
             * - Allows 60 requests per minute
             * - After 60 requests, returns 429 error
             * - Counter resets every minute
             * 
             * WHY 60 REQUESTS/MINUTE:
             * - Legitimate user: 1 request per second is generous
             * - Prevents abuse: Can't hammer the API
             * - Not too strict: Won't annoy real users
             * 
             * OTHER OPTIONS:
             * - Limit::perMinute(100): More permissive
             * - Limit::perHour(1000): Hourly limit
             * - Limit::perDay(5000): Daily limit
             * - Limit::none(): No limit (not recommended)
             * 
             * ->by($request->ip())
             * - Tracks limits by IP address
             * - Each IP has independent counter
             * - User at IP 1.2.3.4 has 60 requests
             * - User at IP 5.6.7.8 also has 60 requests
             * 
             * OTHER TRACKING OPTIONS:
             * - ->by($request->user()->id): Track by user ID (if authenticated)
             * - ->by($request->header('X-API-Key')): Track by API key
             * - ->by($request->fingerprint()): Track by request fingerprint
             * 
             * EXAMPLE SCENARIOS:
             * 
             * Request 1-60 from IP 1.2.3.4:
             * → Success (200 OK)
             * 
             * Request 61 from IP 1.2.3.4:
             * → Error (429 Too Many Requests)
             * → Response: {
             *     "message": "Too many requests"
             *   }
             * → Headers include: Retry-After: 60 (seconds)
             * 
             * Request 1 from IP 5.6.7.8 (different IP):
             * → Success (200 OK)
             * → Independent counter
             * 
             * After 1 minute:
             * → Counters reset
             * → IP 1.2.3.4 can make 60 more requests
             */
            return Limit::perMinute(60)->by($request->ip());
        });

        /**
         * ADVANCED RATE LIMITING EXAMPLES
         * 
         * EXAMPLE 1: Different limits for authenticated vs guest users
         * 
         * RateLimiter::for('secrets', function (Request $request) {
         *     return $request->user()
         *         ? Limit::perMinute(100)->by($request->user()->id)
         *         : Limit::perMinute(10)->by($request->ip());
         * });
         * 
         * EXAMPLE 2: Stricter limits for creation, looser for retrieval
         * 
         * RateLimiter::for('create-secrets', function (Request $request) {
         *     return Limit::perMinute(10)->by($request->ip());
         * });
         * 
         * RateLimiter::for('read-secrets', function (Request $request) {
         *     return Limit::perMinute(100)->by($request->ip());
         * });
         * 
         * Then in routes:
         * Route::post('/secrets', ...)->middleware('throttle:create-secrets');
         * Route::get('/secrets/{id}', ...)->middleware('throttle:read-secrets');
         * 
         * EXAMPLE 3: Multiple limits (per minute AND per day)
         * 
         * RateLimiter::for('secrets', function (Request $request) {
         *     return [
         *         Limit::perMinute(60)->by($request->ip()),
         *         Limit::perDay(1000)->by($request->ip()),
         *     ];
         * });
         * 
         * EXAMPLE 4: Custom response when rate limit exceeded
         * 
         * RateLimiter::for('secrets', function (Request $request) {
         *     return Limit::perMinute(60)
         *         ->by($request->ip())
         *         ->response(function (Request $request, array $headers) {
         *             return response()->json([
         *                 'error' => 'Rate limit exceeded',
         *                 'message' => 'Please wait before making more requests',
         *                 'retry_after' => $headers['Retry-After'] ?? 60
         *             ], 429, $headers);
         *         });
         * });
         */
    }
}

/**
 * ========================================
 * USING RATE LIMITER IN ROUTES
 * ========================================
 * 
 * In routes/api.php:
 * 
 * Route::middleware(['throttle:secrets'])->group(function () {
 *     Route::post('/secrets', [SecretController::class, 'store']);
 *     Route::get('/secrets/{id}', [SecretController::class, 'show']);
 * });
 * 
 * OR individual routes:
 * 
 * Route::post('/secrets', [SecretController::class, 'store'])
 *     ->middleware('throttle:secrets');
 * 
 * ========================================
 * TESTING RATE LIMITING
 * ========================================
 * 
 * Test with curl:
 * 
 * # Make 61 requests quickly
 * for i in {1..61}; do
 *     curl -X POST http://localhost:8000/api/v1/secrets \
 *         -H "Content-Type: application/json" \
 *         -d '{"content":"test"}'
 *     echo "Request $i"
 * done
 * 
 * Expected output:
 * - Requests 1-60: Success (201)
 * - Request 61: Error (429 Too Many Requests)
 * 
 * ========================================
 * RATE LIMIT RESPONSE
 * ========================================
 * 
 * When limit exceeded:
 * 
 * HTTP/1.1 429 Too Many Requests
 * X-RateLimit-Limit: 60
 * X-RateLimit-Remaining: 0
 * Retry-After: 60
 * 
 * {
 *   "message": "Too Many Requests"
 * }
 * 
 * HEADERS EXPLAINED:
 * - X-RateLimit-Limit: Total allowed per window
 * - X-RateLimit-Remaining: Requests left in current window
 * - Retry-After: Seconds until can retry
 * 
 * ========================================
 * DISABLING RATE LIMITING (FOR TESTING)
 * ========================================
 * 
 * In tests, you might want to disable:
 * 
 * // In test file
 * public function testSomething()
 * {
 *     $this->withoutMiddleware(ThrottleRequests::class);
 *     
 *     // Now can make unlimited requests in test
 * }
 * 
 * ========================================
 * MONITORING RATE LIMITS
 * ========================================
 * 
 * Check Redis (if using Redis cache):
 * redis-cli
 * > KEYS *rate_limit*
 * > TTL rate_limit:secrets:1.2.3.4
 * 
 * Check logs:
 * tail -f storage/logs/laravel.log | grep "429"
 * 
 * ========================================
 * PRODUCTION CONSIDERATIONS
 * ========================================
 * 
 * 1. CACHE DRIVER:
 *    - Use Redis/Memcached in production
 *    - File cache doesn't scale well
 *    - Database cache is slow
 * 
 * 2. LOAD BALANCER:
 *    - Rate limit might not work correctly
 *    - Use X-Forwarded-For header
 *    - Configure trusted proxies
 * 
 * 3. CLOUDFLARE/CDN:
 *    - Implement at edge for better performance
 *    - Laravel rate limit as backup
 * 
 * 4. MONITORING:
 *    - Log 429 responses
 *    - Alert if too many rate limit hits
 *    - Might indicate attack or need to adjust limits
 */