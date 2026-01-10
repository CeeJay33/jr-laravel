<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSecretRequest;
use App\Http\Resources\SecretResource;
use App\Services\SecretService;
use Illuminate\Http\JsonResponse;

/**
 * SECRET CONTROLLER
 * 
 * PURPOSE: Handle HTTP requests for secrets API
 * 
 * RESPONSIBILITIES:
 * 1. Receive HTTP requests
 * 2. Validate input (via FormRequest)
 * 3. Call service layer (business logic)
 * 4. Format responses (via Resources)
 * 5. Return HTTP responses
 * 
 * WHAT CONTROLLER SHOULD NOT DO:
 * ❌ Business logic (that's in Service)
 * ❌ Database queries (that's in Repository)
 * ❌ Encryption/decryption (that's in Service)
 * ❌ Complex calculations (that's in Service)
 * 
 * WHAT CONTROLLER SHOULD DO:
 * ✅ HTTP-specific tasks (status codes, headers)
 * ✅ Delegate to services
 * ✅ Format responses
 * ✅ Handle HTTP errors
 * 
 * PRINCIPLE: Thin Controllers, Fat Services
 * - Controllers are just HTTP adapters
 * - All logic lives in Service layer
 * 
 * SCRIBE ANNOTATIONS:
 * The @group, @bodyParam, @response comments are for API documentation
 * Scribe reads these and generates interactive docs
 */

/**
 * @group Secret Management
 *
 * APIs for managing self-destructing secure notes
 */
class SecretController extends Controller
{
    /**
     * DEPENDENCY INJECTION via Constructor
     * 
     * WHY:
     * - Service is required for all controller methods
     * - Laravel automatically provides instance
     * - Easy to test (can mock service)
     * 
     * TYPE HINT: SecretService (concrete class)
     * - Controller works with concrete implementation
     * - Service handles abstraction (uses interface for repository)
     */
    public function __construct(
        private SecretService $secretService
    ) {}

    /**
     * CREATE A NEW SECRET
     * 
     * Endpoint: POST /api/v1/secrets
     * 
     * ========================================
     * SCRIBE DOCUMENTATION ANNOTATIONS
     * ========================================
     * 
     * These comments generate API documentation:
     * 
     * @bodyParam content string required 
     *   - Shows in docs: "content" field is required, type string
     *   - Example appears in "Try it out"
     * 
     * @bodyParam ttl integer 
     *   - Shows in docs: "ttl" field is optional, type integer
     *   - Example: 60 (minutes)
     * 
     * @response 201 {...}
     *   - Shows example successful response
     *   - Status code 201 Created
     * 
     * @response 422 {...}
     *   - Shows example validation error response
     *   - Status code 422 Unprocessable Entity
     */

    /**
     * Create a new secret
     *
     * Creates a new self-destructing secret note. The note will be encrypted and stored
     * with an optional expiration time. Once retrieved, the note is permanently deleted.
     *
     * @bodyParam content string required The secret content to store. Max 10,000 characters. Example: my-secret-api-key-12345
     * @bodyParam ttl integer Optional expiration time in minutes (1-43200). Example: 60
     *
     * @response 201 {
     *   "data": {
     *     "id": "9d45e8c7-1234-4567-89ab-cdef01234567",
     *     "url": "https://api.example.com/api/v1/secrets/9d45e8400-...",
     *     "expires_at": "2025-01-10T14:30:00+00:00",
     *     "created_at": "2025-01-09T14:30:00+00:00"
     *   },
     *   "message": "Secret created successfully"
     * }
     *
     * @response 422 {
     *   "message": "The content field is required.",
     *   "errors": {
     *     "content": ["The content field is required."]
     *   }
     * }
     */
    public function store(StoreSecretRequest $request): JsonResponse
    {
        /**
         * FLOW EXPLANATION:
         * 
         * 1. REQUEST ARRIVES
         *    POST /api/v1/secrets
         *    Body: {"content": "my-password", "ttl": 60}
         * 
         * 2. LARAVEL VALIDATES (AUTOMATIC)
         *    - Sees StoreSecretRequest type hint
         *    - Runs validation rules
         *    - If fails: returns 422 automatically
         *    - If passes: continues to this method
         * 
         * 3. EXTRACT INPUT
         *    - Data is guaranteed valid (validation passed)
         *    - Safe to use without additional checks
         */
        
        // Get validated input
        // content: always exists (validation requires it)
        // ttl: might be null (validation allows null)
        $content = $request->input('content');
        $ttl = $request->input('ttl');
        
        /**
         * 4. CALL SERVICE LAYER
         *    - Delegate business logic to service
         *    - Service handles:
         *      • Encryption
         *      • TTL calculation
         *      • Database storage
         *    - Controller just orchestrates
         */
        $secret = $this->secretService->createSecret(
            content: $content,  // Named arguments (PHP 8+)
            ttl: $ttl
        );

        /**
         * 5. FORMAT RESPONSE
         *    - Wrap model in Resource for consistent formatting
         *    - Add success message
         *    - Set HTTP status code 201 (Created)
         * 
         * BREAKDOWN:
         * new SecretResource($secret)
         *   → Creates resource instance
         * 
         * ->additional(['message' => '...'])
         *   → Adds extra fields to response (outside 'data')
         * 
         * ->response()
         *   → Converts to HTTP response
         * 
         * ->setStatusCode(201)
         *   → Sets HTTP status (201 = Created)
         * 
         * RESULT:
         * HTTP/1.1 201 Created
         * {
         *   "data": {...},      // From SecretResource
         *   "message": "..."    // From additional()
         * }
         */
        return (new SecretResource($secret))
            ->additional([
                'message' => 'Secret created successfully'
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * RETRIEVE AND BURN A SECRET
     * 
     * Endpoint: GET /api/v1/secrets/{id}
     * 
     * SCRIBE DOCUMENTATION ANNOTATIONS:
     * 
     * @urlParam id string required
     *   - Shows in docs: URL requires {id} parameter
     *   - Type: string (UUID)
     * 
     * @response 200 {...}
     *   - Example successful response
     *   - Shows decrypted content
     * 
     * @response 404 {...}
     *   - Example not found response
     *   - When secret doesn't exist or expired
     */

    /**
     * Retrieve and burn a secret
     *
     * Retrieves the decrypted content of a secret and permanently deletes it from the database.
     * This action can only be performed once per secret (burn on read).
     *
     * @urlParam id string required The UUID of the secret. Example: 9d45e8c7-1234-4567-89ab-cdef01234567
     *
     * @response 200 {
     *   "data": {
     *     "content": "my-secret-api-key-12345",
     *     "created_at": "2025-01-09T14:30:00+00:00",
     *     "expires_at": "2025-01-10T14:30:00+00:00"
     *   },
     *   "message": "Secret retrieved successfully. This secret has been permanently deleted."
     * }
     *
     * @response 404 {
     *   "message": "Secret not found or has expired"
     * }
     */
    public function show(string $id): JsonResponse
    {
        /**
         * FLOW EXPLANATION:
         * 
         * 1. REQUEST ARRIVES
         *    GET /api/v1/secrets/550e8400-e29b-41d4-a716-446655440000
         * 
         * 2. LARAVEL ROUTING
         *    - Extracts UUID from URL
         *    - Passes as $id parameter
         *    - No model binding (we handle lookup in service)
         * 
         * 3. CONTROLLER RECEIVES
         *    - $id contains the UUID string
         *    - Might be valid UUID, might be garbage
         *    - Service/Repository handle validation
         */
        
        /**
         * 4. CALL SERVICE LAYER
         *    - Service handles:
         *      • Finding secret by UUID
         *      • Checking expiration
         *      • Decrypting content
         *      • Deleting secret (burn on read)
         *    - Returns array with decrypted content or null
         * 
         * WHY NULL RETURN:
         * - Secret doesn't exist
         * - Secret expired
         * - Secret already burned (deleted)
         */
        $result = $this->secretService->retrieveAndBurnSecret($id);

        /**
         * 5. CHECK RESULT
         * 
         * If null → Secret not found or expired
         */
        if (!$result) {
            /**
             * RETURN 404 NOT FOUND
             * 
             * response()->json(...)
             *   → Create JSON response manually
             * 
             * ['message' => '...']
             *   → Simple error message
             * 
             * 404
             *   → HTTP status code (Not Found)
             * 
             * WHY NOT USE RESOURCE:
             * - No model to transform
             * - Just a simple error message
             * - Manual JSON is simpler
             * 
             * RESULT:
             * HTTP/1.1 404 Not Found
             * {
             *   "message": "Secret not found or has expired"
             * }
             */
            return response()->json([
                'message' => 'Secret not found or has expired'
            ], 404);
        }

        /**
         * 6. FORMAT SUCCESS RESPONSE
         * 
         * $result contains:
         * - content: "decrypted-secret-text"
         * - created_at: Carbon instance
         * - expires_at: Carbon instance or null
         * 
         * RESPONSE STRUCTURE:
         * {
         *   "data": {
         *     "content": "...",
         *     "created_at": "ISO-8601 format",
         *     "expires_at": "ISO-8601 format or null"
         *   },
         *   "message": "Success message with warning"
         * }
         * 
         * WHY INCLUDE WARNING:
         * - Remind user secret is permanently deleted
         * - Can't retrieve again
         * - Important security feature
         */
        return response()->json([
            'data' => [
                // The decrypted secret (plain text)
                'content' => $result['content'],
                
                // When secret was created (ISO-8601 format)
                'created_at' => $result['created_at']->toIso8601String(),
                
                // When secret would expire (null if no TTL)
                'expires_at' => $result['expires_at']?->toIso8601String(),
            ],
            // Success message with important warning
            'message' => 'Secret retrieved successfully. This secret has been permanently deleted.'
        ], 200); // HTTP 200 OK
    }
}

/**
 * ========================================
 * COMPLETE REQUEST/RESPONSE EXAMPLES
 * ========================================
 * 
 * SCENARIO 1: CREATE SECRET WITH TTL
 * 
 * Request:
 * POST /api/v1/secrets
 * {
 *   "content": "my-database-password",
 *   "ttl": 60
 * }
 * 
 * Response (201):
 * {
 *   "data": {
 *     "id": "550e8400-e29b-41d4-a716-446655440000",
 *     "url": "http://localhost:8000/api/v1/secrets/550e8400-...",
 *     "expires_at": "2025-01-09T15:00:00+00:00",
 *     "created_at": "2025-01-09T14:00:00+00:00"
 *   },
 *   "message": "Secret created successfully"
 * }
 * 
 * ========================================
 * 
 * SCENARIO 2: CREATE SECRET WITHOUT TTL
 * 
 * Request:
 * POST /api/v1/secrets
 * {
 *   "content": "api-key-that-never-expires"
 * }
 * 
 * Response (201):
 * {
 *   "data": {
 *     "id": "7c9e6679-7425-40de-944b-e07fc1f90ae7",
 *     "url": "http://localhost:8000/api/v1/secrets/7c9e6679-...",
 *     "expires_at": null,
 *     "created_at": "2025-01-09T14:00:00+00:00"
 *   },
 *   "message": "Secret created successfully"
 * }
 * 
 * ========================================
 * 
 * SCENARIO 3: RETRIEVE SECRET (FIRST TIME)
 * 
 * Request:
 * GET /api/v1/secrets/550e8400-e29b-41d4-a716-446655440000
 * 
 * Response (200):
 * {
 *   "data": {
 *     "content": "my-database-password",
 *     "created_at": "2025-01-09T14:00:00+00:00",
 *     "expires_at": "2025-01-09T15:00:00+00:00"
 *   },
 *   "message": "Secret retrieved successfully. This secret has been permanently deleted."
 * }
 * 
 * ========================================
 * 
 * SCENARIO 4: RETRIEVE SAME SECRET (SECOND TIME)
 * 
 * Request:
 * GET /api/v1/secrets/550e8400-e29b-41d4-a716-446655440000
 * 
 * Response (404):
 * {
 *   "message": "Secret not found or has expired"
 * }
 * 
 * ========================================
 * 
 * SCENARIO 5: VALIDATION ERROR
 * 
 * Request:
 * POST /api/v1/secrets
 * {
 *   "content": ""
 * }
 * 
 * Response (422):
 * {
 *   "message": "The secret content is required.",
 *   "errors": {
 *     "content": ["The secret content is required."]
 *   }
 * }
 */