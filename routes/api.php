<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\SecretController;

/**
 * API ROUTES
 * 
 * PURPOSE: Define all API endpoints
 * 
 * FILE LOCATION: routes/api.php
 * 
 * AUTO PREFIX: All routes here automatically get /api prefix
 * Example: Route::get('/secrets') becomes /api/secrets
 * 
 * WHY ORGANIZE BY VERSION:
 * - API versioning (v1, v2, etc.)
 * - Can maintain old versions while developing new ones
 * - Breaking changes don't affect existing clients
 * 
 * EXAMPLE VERSION STRATEGY:
 * - v1: Original API
 * - v2: New API with breaking changes
 * - Both run simultaneously
 * - Clients migrate at their own pace
 * 
 * ROUTE STRUCTURE:
 * /api/v1/secrets        → POST (create secret)
 * /api/v1/secrets/{id}   → GET (retrieve and burn secret)
 */

/**
 * VERSION 1 API GROUP
 * 
 * Route::prefix('v1')
 * - Adds /v1 to all routes in this group
 * - Example: /api/v1/secrets
 * 
 * WHY GROUP:
 * - Organize related routes
 * - Apply middleware to multiple routes at once
 * - Easy to add v2, v3 later
 * 
 * FUTURE VERSION:
 * Route::prefix('v2')->group(function () {
 *     // New API with different behavior
 *     Route::post('/secrets', [V2SecretController::class, 'store']);
 * });
 */
Route::prefix('v1')->group(function () {
    /**
     * RATE LIMITED GROUP
     * 
     * Route::middleware(['throttle:secrets'])
     * - Applies rate limiting to all routes in this group
     * - Uses 'secrets' rate limiter from AppServiceProvider
     * - 60 requests per minute per IP
     * 
     * WHY MIDDLEWARE ARRAY:
     * - Can add multiple middlewares
     * - Example: ['throttle:secrets', 'auth', 'verified']
     * 
     * WHAT HAPPENS:
     * Request → Middleware (rate limit check) → Controller
     * If rate limit exceeded → 429 response, controller never reached
     * 
     * MIDDLEWARE ORDER MATTERS:
     * ['auth', 'throttle:secrets'] → Check auth first, then rate limit
     * ['throttle:secrets', 'auth'] → Rate limit first, then auth
     * 
     * Usually rate limit should be FIRST:
     * - Cheaper check (just counter)
     * - Prevents wasting resources on auth checks
     * - Protects against brute force on auth endpoint
     */
    Route::middleware(['throttle:secrets'])->group(function () {
        /**
         * CREATE SECRET ENDPOINT
         * 
         * METHOD: POST
         * PATH: /api/v1/secrets
         * CONTROLLER: SecretController@store
         * NAME: secrets.store
         * 
         * REQUEST:
         * POST /api/v1/secrets
         * {
         *   "content": "my-secret-password",
         *   "ttl": 60
         * }
         * 
         * FLOW:
         * 1. Request arrives
         * 2. Rate limiter checks (middleware)
         * 3. If OK, continue to controller
         * 4. StoreSecretRequest validates data (automatic)
         * 5. If valid, SecretController@store runs
         * 6. Service encrypts and stores secret
         * 7. Response returned with UUID
         * 
         * RESPONSE:
         * HTTP/1.1 201 Created
         * {
         *   "data": {
         *     "id": "uuid",
         *     "url": "http://.../api/v1/secrets/uuid",
         *     "expires_at": "2025-01-09T15:00:00+00:00",
         *     "created_at": "2025-01-09T14:00:00+00:00"
         *   },
         *   "message": "Secret created successfully"
         * }
         * 
         * NAMED ROUTE:
         * ->name('secrets.store')
         * - Can generate URL: route('secrets.store')
         * - Used in tests: $this->postJson(route('secrets.store'), [...])
         * - Cleaner than hardcoding URLs
         * 
         * WHY NAMED ROUTES:
         * - If URL changes, name stays same
         * - Update route definition, all usages work
         * - Self-documenting code
         */
        Route::post('/secrets', [SecretController::class, 'store'])
            ->name('secrets.store');
        
        /**
         * RETRIEVE SECRET ENDPOINT (BURN ON READ)
         * 
         * METHOD: GET
         * PATH: /api/v1/secrets/{id}
         * CONTROLLER: SecretController@show
         * NAME: secrets.show
         * 
         * PATH PARAMETER:
         * {id} - The UUID of the secret
         * Laravel extracts this and passes to controller
         * 
         * REQUEST:
         * GET /api/v1/secrets/550e8400-e29b-41d4-a716-446655440000
         * 
         * FLOW:
         * 1. Request arrives
         * 2. Rate limiter checks (middleware)
         * 3. Laravel extracts UUID from URL
         * 4. Passes to SecretController@show($id)
         * 5. Service finds and decrypts secret
         * 6. Service DELETES secret (burn on read)
         * 7. Response returned with content
         * 
         * RESPONSE (SUCCESS):
         * HTTP/1.1 200 OK
         * {
         *   "data": {
         *     "content": "my-secret-password",
         *     "created_at": "2025-01-09T14:00:00+00:00",
         *     "expires_at": "2025-01-09T15:00:00+00:00"
         *   },
         *   "message": "Secret retrieved successfully. This secret has been permanently deleted."
         * }
         * 
         * RESPONSE (NOT FOUND):
         * HTTP/1.1 404 Not Found
         * {
         *   "message": "Secret not found or has expired"
         * }
         * 
         * NAMED ROUTE USAGE:
         * route('secrets.show', ['id' => $uuid])
         * → Generates: /api/v1/secrets/{uuid}
         * 
         * Used in SecretResource:
         * 'url' => route('secrets.show', ['id' => $this->uuid])
         * 
         * CRITICAL FEATURE:
         * After this endpoint is accessed ONCE, the secret is GONE.
         * Second request to same URL returns 404.
         */
        Route::get('/secrets/{id}', [SecretController::class, 'show'])
            ->name('secrets.show');
    });
});

/**
 * ========================================
 * ALTERNATIVE ROUTE DEFINITIONS
 * ========================================
 * 
 * OPTION 1: Resource Routes (if you had full CRUD)
 * 
 * Route::prefix('v1')->group(function () {
 *     Route::apiResource('secrets', SecretController::class)
 *         ->only(['store', 'show'])
 *         ->middleware('throttle:secrets');
 * });
 * 
 * Generates:
 * POST   /api/v1/secrets         → store
 * GET    /api/v1/secrets/{id}    → show
 * 
 * ========================================
 * 
 * OPTION 2: Different Rate Limits per Endpoint
 * 
 * Route::prefix('v1')->group(function () {
 *     // Stricter limit for creation (10/min)
 *     Route::post('/secrets', [SecretController::class, 'store'])
 *         ->middleware('throttle:10,1')
 *         ->name('secrets.store');
 *     
 *     // Looser limit for retrieval (100/min)
 *     Route::get('/secrets/{id}', [SecretController::class, 'show'])
 *         ->middleware('throttle:100,1')
 *         ->name('secrets.show');
 * });
 * 
 * ========================================
 * 
 * OPTION 3: With Authentication (if required)
 * 
 * Route::prefix('v1')->group(function () {
 *     Route::middleware(['auth:sanctum', 'throttle:secrets'])->group(function () {
 *         Route::post('/secrets', [SecretController::class, 'store']);
 *         Route::get('/secrets/{id}', [SecretController::class, 'show']);
 *     });
 * });
 * 
 * Now requires valid API token to access
 * 
 * ========================================
 * 
 * OPTION 4: Route Model Binding
 * 
 * In AppServiceProvider boot():
 * Route::bind('secret', function ($uuid) {
 *     return app(SecretRepositoryInterface::class)
 *         ->findByUuid($uuid) ?? abort(404);
 * });
 * 
 * Then in routes:
 * Route::get('/secrets/{secret}', function (Secret $secret) {
 *     // $secret is already loaded, or 404 thrown
 * });
 * 
 * ========================================
 * TESTING ROUTES
 * ========================================
 * 
 * List all routes:
 * php artisan route:list
 * 
 * Filter for API routes:
 * php artisan route:list --path=api
 * 
 * Test with curl:
 * 
 * # Create secret
 * curl -X POST http://localhost:8000/api/v1/secrets \
 *   -H "Content-Type: application/json" \
 *   -d '{"content":"test","ttl":60}'
 * 
 * # Retrieve secret
 * curl http://localhost:8000/api/v1/secrets/{uuid}
 * 
 * ========================================
 * ROUTE CACHING
 * ========================================
 * 
 * For production performance:
 * php artisan route:cache
 * 
 * Clear route cache:
 * php artisan route:clear
 * 
 * NOTE: Route caching doesn't work with closures
 * Always use controller methods for cacheable routes
 * 
 * ========================================
 * SECURITY CONSIDERATIONS
 * ========================================
 * 
 * 1. RATE LIMITING:
 *    ✅ Implemented
 *    Prevents brute force and DoS
 * 
 * 2. HTTPS:
 *    Configure in production
 *    Force HTTPS in middleware
 * 
 * 3. CORS:
 *    Configure in config/cors.php if needed
 *    Restrict allowed origins
 * 
 * 4. VALIDATION:
 *    ✅ Implemented via FormRequest
 *    Never trust client input
 * 
 * 5. UUID EXPOSURE:
 *    ✅ Safe - UUIDs are non-sequential
 *    No information leakage
 * 
 * ========================================
 * MONITORING
 * ========================================
 * 
 * Track endpoint usage:
 * - Log successful creates
 * - Log successful retrievals
 * - Alert on high 429 rates
 * - Monitor average TTL usage
 * 
 * Example logging in controller:
 * Log::info('Secret created', ['uuid' => $secret->uuid]);
 * Log::info('Secret retrieved', ['uuid' => $uuid]);
 */