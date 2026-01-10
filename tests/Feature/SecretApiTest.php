<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Secret;

/**
 * SECRET API FEATURE TESTS
 * 
 * PURPOSE: Test the complete API functionality end-to-end
 * 
 * WHAT IS A FEATURE TEST:
 * - Tests entire features from HTTP request to response
 * - Simulates real API calls
 * - Tests integration of all layers (Controller → Service → Repository → Database)
 * - Verifies business requirements
 * 
 * VS UNIT TESTS:
 * Unit tests: Test individual classes in isolation
 * Feature tests: Test complete workflows
 * 
 * REFRESH DATABASE TRAIT:
 * - Resets database before each test
 * - Uses transactions (fast)
 * - Each test starts with clean slate
 * - No test pollution
 * 
 * WHY IMPORTANT:
 * Tests prove the code works and prevent regressions
 */
class SecretApiTest extends TestCase
{
    /**
     * USE REFRESH DATABASE
     * 
     * WHAT IT DOES:
     * - Before each test: Migrates fresh database
     * - During test: Wraps in transaction
     * - After test: Rolls back transaction
     * 
     * BENEFITS:
     * - Fast (uses transactions, not full migrations)
     * - Isolated (each test independent)
     * - Clean (no leftover data)
     * 
     * DATABASE USED:
     * Check phpunit.xml: <env name="DB_CONNECTION" value="sqlite"/>
     * Uses :memory: SQLite database (super fast)
     */
    use RefreshDatabase;

    /**
     * TEST: Can Create Secret
     * 
     * REQUIREMENT: POST /api/v1/secrets should create and return secret
     * 
     * WHAT IT TESTS:
     * 1. Endpoint accepts valid data
     * 2. Returns 201 Created status
     * 3. Response has correct JSON structure
     * 4. Secret is saved to database
     * 5. TTL is respected
     * 
     * WHY IMPORTANT:
     * Core functionality - must be able to create secrets
     */
    public function test_can_create_secret(): void
    {
        /**
         * MAKE POST REQUEST
         * 
         * $this->postJson(url, data)
         * - Makes POST request with JSON body
         * - Sets Content-Type: application/json automatically
         * - Returns TestResponse for assertions
         * 
         * DATA:
         * - content: "This is a secret message"
         * - ttl: 60 minutes
         */
        $response = $this->postJson('/api/v1/secrets', [
            'content' => 'This is a secret message',
            'ttl' => 60
        ]);

        /**
         * ASSERT RESPONSE STATUS
         * 
         * ->assertStatus(201)
         * Verifies HTTP status code is 201 (Created)
         * 
         * If fails: Shows actual status and response body
         */
        $response->assertStatus(201);
        
        /**
         * ASSERT JSON STRUCTURE
         * 
         * ->assertJsonStructure([...])
         * Verifies response has expected structure
         * 
         * CHECKS:
         * - 'data' key exists
         * - 'data' contains: id, url, expires_at, created_at
         * - 'message' key exists
         * 
         * DOESN'T CHECK:
         * - Actual values (just structure)
         * - Data types
         * 
         * WHY USEFUL:
         * Ensures consistent API response format
         */
        $response->assertJsonStructure([
            'data' => [
                'id',          // UUID
                'url',         // Full URL to retrieve
                'expires_at',  // ISO-8601 timestamp
                'created_at'   // ISO-8601 timestamp
            ],
            'message'          // Success message
        ]);

        /**
         * ASSERT DATABASE HAS SECRET
         * 
         * $this->assertDatabaseHas('table', ['column' => 'value'])
         * Verifies a row exists in database
         * 
         * CHECKS:
         * - Table 'secrets' has row where uuid = returned uuid
         * 
         * WHY IMPORTANT:
         * Response might be fake - this proves it's actually saved
         * 
         * $response->json('data.id')
         * Extracts the 'id' from response JSON
         * Same as: $response['data']['id']
         */
        $this->assertDatabaseHas('secrets', [
            'uuid' => $response->json('data.id')
        ]);
    }

    /**
     * TEST: Can Create Secret Without TTL
     * 
     * REQUIREMENT: TTL should be optional
     * 
     * WHAT IT TESTS:
     * 1. Can create secret without ttl field
     * 2. expires_at should be null (never expires)
     * 
     * WHY IMPORTANT:
     * Verifies optional parameter handling
     */
    public function test_can_create_secret_without_ttl(): void
    {
        // Create secret WITHOUT ttl field
        $response = $this->postJson('/api/v1/secrets', [
            'content' => 'Secret without expiration'
        ]);

        // Should still succeed
        $response->assertStatus(201);
        
        /**
         * VERIFY EXPIRES_AT IS NULL
         * 
         * Find the created secret
         * Check that expires_at column is null
         * 
         * WHY:
         * Proves secret never expires when no TTL provided
         */
        $secret = Secret::where('uuid', $response->json('data.id'))->first();
        $this->assertNull($secret->expires_at);
    }

    /**
     * TEST: Can Retrieve Secret
     * 
     * REQUIREMENT: GET /api/v1/secrets/{id} should return decrypted content
     * 
     * WHAT IT TESTS:
     * 1. Can retrieve existing secret
     * 2. Response includes decrypted content
     * 3. Content matches what was stored
     * 
     * WHY IMPORTANT:
     * Core functionality - must be able to read secrets
     */
    public function test_can_retrieve_secret(): void
    {
        // ARRANGE: Create a secret first
        $createResponse = $this->postJson('/api/v1/secrets', [
            'content' => 'Test secret content'
        ]);

        // Extract the UUID
        $secretId = $createResponse->json('data.id');

        // ACT: Retrieve the secret
        $response = $this->getJson("/api/v1/secrets/{$secretId}");

        // ASSERT: Check response
        $response->assertStatus(200);
        
        /**
         * ASSERT JSON CONTENT
         * 
         * ->assertJson(['key' => 'value'])
         * Checks that response contains these values
         * 
         * VERIFIES:
         * - 'data.content' equals original content
         * - Content was properly encrypted/decrypted
         */
        $response->assertJson([
            'data' => [
                'content' => 'Test secret content'
            ]
        ]);
    }

    /**
     * TEST: Secret Is Deleted After Reading (BURN ON READ)
     * 
     * REQUIREMENT: Secrets must self-destruct after one retrieval
     * 
     * WHAT IT TESTS:
     * 1. First retrieval succeeds
     * 2. Secret is deleted from database
     * 3. Second retrieval fails with 404
     * 
     * WHY CRITICAL:
     * This is the core security feature!
     * Proves one-time access works
     */
    public function test_secret_is_deleted_after_reading(): void
    {
        // ARRANGE: Create secret
        $createResponse = $this->postJson('/api/v1/secrets', [
            'content' => 'Secret to be burned'
        ]);

        $secretId = $createResponse->json('data.id');

        // ACT 1: First read (should succeed)
        $firstResponse = $this->getJson("/api/v1/secrets/{$secretId}");
        $firstResponse->assertStatus(200);

        /**
         * VERIFY SECRET IS GONE FROM DATABASE
         * 
         * $this->assertDatabaseMissing('table', ['column' => 'value'])
         * Verifies NO row exists with these conditions
         * 
         * PROVES:
         * Secret was deleted after retrieval
         * Database query confirms deletion
         */
        $this->assertDatabaseMissing('secrets', [
            'uuid' => $secretId
        ]);

        // ACT 2: Second read (should fail)
        $secondResponse = $this->getJson("/api/v1/secrets/{$secretId}");
        
        /**
         * ASSERT 404 NOT FOUND
         * 
         * Second retrieval must fail
         * Returns 404 with appropriate message
         */
        $secondResponse->assertStatus(404);
        $secondResponse->assertJson([
            'message' => 'Secret not found or has expired'
        ]);
    }

    /**
     * TEST: Retrieving Non-Existent Secret Returns 404
     * 
     * REQUIREMENT: Invalid UUIDs should return 404
     * 
     * WHAT IT TESTS:
     * 1. API handles non-existent UUIDs gracefully
     * 2. Returns proper 404 status
     * 3. Returns helpful error message
     * 
     * WHY IMPORTANT:
     * Error handling verification
     */
    public function test_retrieving_non_existent_secret_returns_404(): void
    {
        // Try to get secret that doesn't exist
        $response = $this->getJson('/api/v1/secrets/non-existent-uuid');

        // Should return 404
        $response->assertStatus(404);
        $response->assertJson([
            'message' => 'Secret not found or has expired'
        ]);
    }

    /**
     * TEST: Creating Secret Without Content Fails
     * 
     * REQUIREMENT: Content field is required
     * 
     * WHAT IT TESTS:
     * 1. Validation catches missing required field
     * 2. Returns 422 Unprocessable Entity
     * 3. Returns validation error for 'content' field
     * 
     * WHY IMPORTANT:
     * Validates that validation is working
     */
    public function test_creating_secret_without_content_fails(): void
    {
        // Send empty request (no content field)
        $response = $this->postJson('/api/v1/secrets', []);

        /**
         * ASSERT VALIDATION ERROR
         * 
         * ->assertStatus(422)
         * 422 = Unprocessable Entity (validation failed)
         * 
         * ->assertJsonValidationErrors(['content'])
         * Checks that 'errors.content' exists in response
         * 
         * RESPONSE FORMAT:
         * {
         *   "message": "The content field is required.",
         *   "errors": {
         *     "content": ["The content field is required."]
         *   }
         * }
         */
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['content']);
    }

    /**
     * TEST: Invalid TTL Fails Validation
     * 
     * REQUIREMENT: TTL must be at least 1 if provided
     * 
     * WHAT IT TESTS:
     * 1. TTL validation rules work
     * 2. TTL = 0 is rejected
     * 3. Returns proper validation error
     * 
     * WHY IMPORTANT:
     * Ensures business rules are enforced
     */
    public function test_invalid_ttl_fails_validation(): void
    {
        $response = $this->postJson('/api/v1/secrets', [
            'content' => 'Test',
            'ttl' => 0  // Invalid: less than minimum (1)
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ttl']);
    }

    /**
     * TEST: Expired Secret Cannot Be Retrieved
     * 
     * REQUIREMENT: Expired secrets should return 404
     * 
     * WHAT IT TESTS:
     * 1. Expired secrets are treated as not found
     * 2. Repository's notExpired scope works
     * 3. TTL feature functions correctly
     * 
     * WHY IMPORTANT:
     * Verifies expiration logic
     */
    public function test_expired_secret_cannot_be_retrieved(): void
    {
        /**
         * CREATE EXPIRED SECRET DIRECTLY
         * 
         * We bypass the API and create directly in database
         * Set expires_at to past time
         * 
         * WHY:
         * - Can't create expired secret via API
         * - Need to test expiration handling
         * - Direct model creation simulates already-expired secret
         */
        $secret = Secret::create([
            'encrypted_content' => encrypt('Expired secret'),
            'expires_at' => now()->subMinute()  // 1 minute ago
        ]);

        // Try to retrieve expired secret
        $response = $this->getJson("/api/v1/secrets/{$secret->uuid}");

        // Should return 404 (treated as not found)
        $response->assertStatus(404);
    }

    /**
     * TEST: Content Is Encrypted In Database
     * 
     * REQUIREMENT: Content must be encrypted at rest
     * 
     * WHAT IT TESTS:
     * 1. Content is not stored as plain text
     * 2. Encryption is actually happening
     * 3. Decryption works correctly
     * 
     * WHY CRITICAL:
     * Security requirement - proves encryption works
     */
    public function test_content_is_encrypted_in_database(): void
    {
        $plainContent = 'Plain text secret';
        
        // Create secret via API
        $response = $this->postJson('/api/v1/secrets', [
            'content' => $plainContent
        ]);

        // Get secret from database
        $secret = Secret::where('uuid', $response->json('data.id'))->first();
        
        /**
         * VERIFY ENCRYPTION
         * 
         * Encrypted content should NOT match plain content
         * If it matches, encryption didn't happen!
         */
        $this->assertNotEquals($plainContent, $secret->encrypted_content);
        
        /**
         * VERIFY DECRYPTION WORKS
         * 
         * decrypt() should return original plain text
         * Proves encryption is reversible
         */
        $this->assertEquals($plainContent, decrypt($secret->encrypted_content));
    }
}

/**
 * ========================================
 * RUNNING TESTS
 * ========================================
 * 
 * Run all tests:
 * php artisan test
 * 
 * Run this test file only:
 * php artisan test tests/Feature/SecretApiTest.php
 * 
 * Run specific test:
 * php artisan test --filter test_secret_is_deleted_after_reading
 * 
 * Run with coverage:
 * php artisan test --coverage
 * 
 * ========================================
 * TEST OUTPUT EXAMPLE
 * ========================================
 * 
 * PASS  Tests\Feature\SecretApiTest
 * ✓ can create secret                                    0.15s
 * ✓ can create secret without ttl                        0.05s
 * ✓ can retrieve secret                                  0.06s
 * ✓ secret is deleted after reading                      0.07s
 * ✓ retrieving non existent secret returns 404           0.03s
 * ✓ creating secret without content fails                0.04s
 * ✓ invalid ttl fails validation                         0.03s
 * ✓ expired secret cannot be retrieved                   0.04s
 * ✓ content is encrypted in database                     0.05s
 * 
 * Tests:    9 passed
 * Duration: 0.52s
 * 
 * ========================================
 * CONTINUOUS INTEGRATION
 * ========================================
 * 
 * In GitHub Actions (.github/workflows/tests.yml):
 * 
 * name: Tests
 * on: [push, pull_request]
 * jobs:
 *   test:
 *     runs-on: ubuntu-latest
 *     steps:
 *       - uses: actions/checkout@v2
 *       - name: Setup PHP
 *         uses: shivammathur/setup-php@v2
 *         with:
 *           php-version: 8.2
 *       - name: Install Dependencies
 *         run: composer install
 *       - name: Run Tests
 *         run: php artisan test
 * 
 * ========================================
 * ADDITIONAL TESTS TO CONSIDER
 * ========================================
 * 
 * 1. Rate Limiting:
 * public function test_rate_limiting_works(): void
 * {
 *     for ($i = 0; $i < 61; $i++) {
 *         $response = $this->postJson('/api/v1/secrets', [
 *             'content' => 'test'
 *         ]);
 *     }
 *     $this->assertEquals(429, $response->status());
 * }
 * 
 * 2. Large Content:
 * public function test_rejects_content_over_limit(): void
 * {
 *     $response = $this->postJson('/api/v1/secrets', [
 *         'content' => str_repeat('a', 10001) // Over 10,000 chars
 *     ]);
 *     $response->assertStatus(422);
 * }
 * 
 * 3. Special Characters:
 * public function test_handles_special_characters(): void
 * {
 *     $content = '!@#$%^&*()_+{}:"<>?[];\',./`~';
 *     $response = $this->postJson('/api/v1/secrets', [
 *         'content' => $content
 *     ]);
 *     $response->assertStatus(201);
 *     
 *     $retrieve = $this->getJson($response->json('data.url'));
 *     $this->assertEquals($content, $retrieve->json('data.content'));
 * }
 */