<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * STORE SECRET REQUEST - FORM VALIDATION
 * 
 * PURPOSE: Validates incoming data when creating a secret
 * 
 * WHY FORM REQUEST CLASS:
 * 1. SEPARATION OF CONCERNS: Validation logic separate from controller
 * 2. REUSABILITY: Can use same validation in multiple places
 * 3. AUTOMATIC: Laravel automatically validates before reaching controller
 * 4. CLEAN CONTROLLERS: Controller assumes data is already valid
 * 
 * HOW IT WORKS:
 * 1. Request comes to controller
 * 2. Laravel sees StoreSecretRequest type hint
 * 3. Automatically runs validation BEFORE controller method
 * 4. If validation fails → automatic 422 response with errors
 * 5. If validation passes → controller receives validated data
 * 
 * EXAMPLE FLOW:
 * POST /api/v1/secrets
 * Body: {"content": "test"}
 * 
 * → Laravel sees StoreSecretRequest
 * → Runs rules()
 * → Validation passes
 * → Controller's store() method runs
 * 
 * VS FAILURE:
 * POST /api/v1/secrets
 * Body: {}  // Missing "content"
 * 
 * → Laravel sees StoreSecretRequest
 * → Runs rules()
 * → Validation FAILS
 * → Returns 422 with error: {"content": ["The content field is required"]}
 * → Controller method NEVER runs
 */
class StoreSecretRequest extends FormRequest
{
    /**
     * AUTHORIZATION CHECK
     * 
     * PURPOSE: Determine if user can make this request
     * 
     * WHY ALWAYS TRUE HERE:
     * - This API has no authentication
     * - Anyone can create secrets
     * - If you add auth later, check permissions here
     * 
     * EXAMPLE WITH AUTH:
     * public function authorize(): bool
     * {
     *     return $this->user()->can('create-secrets');
     * }
     * 
     * WHAT HAPPENS:
     * - If returns false → 403 Forbidden response
     * - If returns true → Continue to validation
     */
    public function authorize(): bool
    {
        return true; // Allow all requests (no authentication required)
    }

    /**
     * VALIDATION RULES
     * 
     * PURPOSE: Define what data is valid
     * 
     * ARRAY FORMAT:
     * 'field_name' => 'rule1|rule2|rule3'
     * OR
     * 'field_name' => ['rule1', 'rule2', 'rule3']
     * 
     * RULES EXPLAINED:
     */
    public function rules(): array
    {
        return [
            /**
             * CONTENT FIELD
             * 
             * 'required' - Must be present in request
             *              POST {} → Fails
             *              POST {"content": ""} → Fails (empty string)
             *              POST {"content": "x"} → Passes
             * 
             * 'string' - Must be a string (not array, not object)
             *            POST {"content": 123} → Fails
             *            POST {"content": {"key": "val"}} → Fails
             *            POST {"content": "123"} → Passes (string)
             * 
             * 'max:10000' - Maximum 10,000 characters
             *               Why 10,000? Reasonable limit for secrets
             *               Prevents abuse (sending huge payloads)
             *               TEXT column can handle it
             *               POST {"content": "x" * 10001} → Fails
             *               POST {"content": "x" * 10000} → Passes
             * 
             * WHY THESE RULES:
             * - required: Can't create empty secret
             * - string: Ensures data type consistency
             * - max: Prevents database issues and abuse
             */
            'content' => 'required|string|max:10000',
            
            /**
             * TTL FIELD (Time To Live)
             * 
             * 'nullable' - Field is OPTIONAL
             *              POST {"content": "x"} → Passes (ttl not required)
             *              POST {"content": "x", "ttl": null} → Passes
             *              POST {"content": "x", "ttl": 60} → Passes
             * 
             * 'integer' - Must be a whole number (no decimals)
             *             POST {"ttl": 60} → Passes
             *             POST {"ttl": 60.5} → Fails
             *             POST {"ttl": "60"} → Passes (Laravel auto-casts)
             * 
             * 'min:1' - Minimum value is 1 minute
             *           Why? 0 or negative TTL doesn't make sense
             *           POST {"ttl": 0} → Fails
             *           POST {"ttl": -5} → Fails
             *           POST {"ttl": 1} → Passes
             * 
             * 'max:43200' - Maximum 43,200 minutes (30 days)
             *               Why 30 days? Reasonable maximum for TTL
             *               Longer = more database storage
             *               43,200 minutes = 30 days * 24 hours * 60 minutes
             *               POST {"ttl": 50000} → Fails
             *               POST {"ttl": 43200} → Passes
             * 
             * WHY THESE RULES:
             * - nullable: TTL is optional feature
             * - integer: Fractional minutes don't make sense
             * - min:1: Prevents invalid TTL values
             * - max:43200: Prevents abuse and excessive storage
             */
            'ttl' => 'nullable|integer|min:1|max:43200',
        ];
    }

    /**
     * CUSTOM ERROR MESSAGES
     * 
     * PURPOSE: Provide user-friendly error messages
     * 
     * DEFAULT MESSAGES:
     * Without this method, Laravel uses defaults:
     * "The content field is required."
     * 
     * CUSTOM MESSAGES:
     * Override defaults for better UX
     * Format: 'field.rule' => 'Custom message'
     * 
     * WHY CUSTOMIZE:
     * - More helpful to API consumers
     * - Can add context (why 43,200 = 30 days)
     * - Professional API design
     * 
     * EXAMPLE ERROR RESPONSE:
     * POST {"content": "", "ttl": 50000}
     * 
     * Response (422):
     * {
     *   "message": "The secret content is required.",
     *   "errors": {
     *     "content": ["The secret content is required."],
     *     "ttl": ["The TTL cannot exceed 43,200 minutes (30 days)."]
     *   }
     * }
     */
    public function messages(): array
    {
        return [
            // Content field messages
            'content.required' => 'The secret content is required.',
            'content.string' => 'The secret content must be a string.',
            'content.max' => 'The secret content cannot exceed 10,000 characters.',
            
            // TTL field messages
            'ttl.integer' => 'The TTL must be an integer.',
            'ttl.min' => 'The TTL must be at least 1 minute.',
            'ttl.max' => 'The TTL cannot exceed 43,200 minutes (30 days).',
        ];
    }
}

/**
 * USAGE IN CONTROLLER:
 * 
 * public function store(StoreSecretRequest $request)
 * {
 *     // By the time we reach here, data is GUARANTEED valid
 *     // No need to check if 'content' exists
 *     // No need to check if 'ttl' is an integer
 *     // Laravel already validated everything
 *     
 *     $content = $request->input('content'); // Always exists, always string
 *     $ttl = $request->input('ttl');         // Always integer or null
 *     
 *     // Safe to use directly
 *     $secret = $this->secretService->createSecret($content, $ttl);
 * }
 * 
 * VALIDATION HAPPENS AUTOMATICALLY:
 * - No manual validation code needed
 * - Consistent error responses
 * - Easy to test
 * - Clean controller code
 */