<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SECRET RESOURCE - API RESPONSE FORMATTER
 * 
 * PURPOSE: Transform Secret model into consistent JSON response
 * 
 * WHY USE RESOURCES:
 * 1. CONSISTENCY: All API responses have same format
 * 2. TRANSFORMATION: Control exactly what data is exposed
 * 3. FLEXIBILITY: Easy to change response format without touching controller
 * 4. SECURITY: Hide sensitive fields (id, encrypted_content already hidden in model)
 * 5. DOCUMENTATION: Clear contract of what API returns
 * 
 * HOW IT WORKS:
 * Controller: return new SecretResource($secret);
 * Laravel: Calls toArray(), converts to JSON, sends response
 * 
 * EXAMPLE FLOW:
 * $secret = Secret (model instance with data)
 * return new SecretResource($secret);
 * → toArray() is called automatically
 * → Returns array
 * → Laravel converts array to JSON
 * → Sends HTTP response
 * 
 * VS RAW RETURN:
 * return $secret; 
 * → Returns ALL model fields (including hidden ones get removed)
 * → No control over format
 * → No custom fields (like 'url')
 * 
 * WITH RESOURCE:
 * return new SecretResource($secret);
 * → Returns ONLY fields we specify
 * → Custom formatting
 * → Can add computed fields
 */
class SecretResource extends JsonResource
{
    /**
     * TRANSFORM MODEL TO ARRAY
     * 
     * PURPOSE: Define exactly what JSON structure to return
     * 
     * @param Request $request - The HTTP request (rarely used, but available)
     * @return array - Array that Laravel converts to JSON
     * 
     * THIS ARRAY BECOMES THE JSON RESPONSE
     * 
     * EXAMPLE:
     * Input: Secret model with:
     * - uuid: "550e8400-e29b-41d4-a716-446655440000"
     * - encrypted_content: "eyJpdiI6..." (hidden from JSON)
     * - expires_at: Carbon("2025-01-09 15:00:00")
     * - created_at: Carbon("2025-01-09 14:00:00")
     * 
     * Output JSON:
     * {
     *   "id": "550e8400-e29b-41d4-a716-446655440000",
     *   "url": "http://localhost:8000/api/v1/secrets/550e8400-...",
     *   "expires_at": "2025-01-09T15:00:00+00:00",
     *   "created_at": "2025-01-09T14:00:00+00:00"
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * ID FIELD
             * 
             * $this->uuid - Accesses the Secret model's uuid field
             * 
             * WHY "id" not "uuid" in response:
             * - Cleaner API design
             * - Consumer doesn't need to know internal field name
             * - "id" is standard REST convention
             * 
             * SECURITY NOTE:
             * - We expose UUID (public identifier)
             * - We NEVER expose database id (auto-increment)
             * - UUID is safe to expose, sequential ID is not
             */
            'id' => $this->uuid,
            
            /**
             * URL FIELD - COMPUTED
             * 
             * route('secrets.show', ['id' => $this->uuid])
             * 
             * WHAT THIS DOES:
             * - Generates full URL to retrieve this secret
             * - Uses named route 'secrets.show'
             * - Passes uuid as 'id' parameter
             * 
             * WHY INCLUDE URL:
             * - HATEOAS principle (Hypermedia as the Engine of Application State)
             * - Client doesn't need to construct URLs
             * - If URL structure changes, client code doesn't break
             * - Convenient for API consumers
             * 
             * EXAMPLE:
             * route('secrets.show', ['id' => '550e8400-...'])
             * → "http://localhost:8000/api/v1/secrets/550e8400-..."
             * 
             * USAGE BY CLIENT:
             * Client receives: {"url": "http://..."}
             * Client can directly GET that URL
             * No need to manually build URL string
             */
            'url' => route('secrets.show', ['id' => $this->uuid]),
            
            /**
             * EXPIRES_AT FIELD - FORMATTED
             * 
             * $this->expires_at?->toIso8601String()
             * 
             * BREAKDOWN:
             * - $this->expires_at: Carbon datetime object or null
             * - ?-> : Null-safe operator (PHP 8.0+)
             *        If null, entire expression returns null
             *        If not null, calls toIso8601String()
             * - toIso8601String(): Formats as ISO 8601 standard
             * 
             * WHY ISO 8601:
             * - International standard for dates
             * - Includes timezone info
             * - Parseable by all modern languages
             * - Example: "2025-01-09T15:00:00+00:00"
             * 
             * EXAMPLES:
             * If expires_at = null:
             *   → "expires_at": null (never expires)
             * 
             * If expires_at = Carbon("2025-01-09 15:00:00"):
             *   → "expires_at": "2025-01-09T15:00:00+00:00"
             * 
             * CLIENT USAGE:
             * JavaScript: new Date(response.expires_at)
             * Python: datetime.fromisoformat(response['expires_at'])
             * PHP: Carbon::parse($response['expires_at'])
             */
            'expires_at' => $this->expires_at?->toIso8601String(),
            
            /**
             * CREATED_AT FIELD - FORMATTED
             * 
             * $this->created_at->toIso8601String()
             * 
             * WHY NO NULL CHECK:
             * - created_at is ALWAYS set (Laravel timestamps)
             * - Never null
             * - So we can safely call ->toIso8601String()
             * 
             * SAME FORMATTING AS expires_at for consistency
             * 
             * EXAMPLE:
             * "created_at": "2025-01-09T14:00:00+00:00"
             */
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}

/**
 * USAGE IN CONTROLLER:
 * 
 * POST /api/v1/secrets
 * 
 * public function store(StoreSecretRequest $request)
 * {
 *     $secret = $this->secretService->createSecret(...);
 *     
 *     return (new SecretResource($secret))
 *         ->additional(['message' => 'Secret created successfully'])
 *         ->response()
 *         ->setStatusCode(201);
 * }
 * 
 * RESPONSE:
 * HTTP/1.1 201 Created
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
 * BENEFITS:
 * 1. Client knows exact format to expect
 * 2. Dates in standard format
 * 3. URL provided for convenience
 * 4. Sensitive data excluded
 * 5. Easy to add fields later
 * 
 * WHAT'S NOT INCLUDED:
 * - Database id (security)
 * - encrypted_content (security)
 * - updated_at (not relevant)
 */