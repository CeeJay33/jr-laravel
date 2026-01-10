<?php

namespace App\Repositories\Contracts;

use App\Models\Secret;

/**
 * SECRET REPOSITORY INTERFACE
 * 
 * PURPOSE: Defines the contract (methods) that any Secret repository must implement
 * 
 * WHY USE INTERFACES?
 * 1. DEPENDENCY INVERSION: High-level code depends on abstraction, not concrete implementation
 * 2. TESTABILITY: Easy to mock in tests
 * 3. FLEXIBILITY: Can swap implementations without changing service code
 * 4. DOCUMENTATION: Clearly shows what operations are available
 * 
 * EXAMPLE BENEFIT:
 * - Today: MySQL implementation
 * - Tomorrow: Can create RedisSecretRepository implementing same interface
 * - Service code doesn't change at all!
 * 
 * SOLID PRINCIPLE: Interface Segregation + Dependency Inversion
 */
interface SecretRepositoryInterface
{
    /**
     * CREATE A NEW SECRET
     * 
     * @param array $data - Array containing secret data
     *                      Example: ['encrypted_content' => '...', 'expires_at' => '...']
     * @return Secret     - Returns the created Secret model instance
     * 
     * PURPOSE: Insert a new secret into the database
     * 
     * WHY RETURN SECRET: So the calling code can access the generated UUID
     * 
     * EXAMPLE USAGE:
     * $secret = $repository->create([
     *     'encrypted_content' => 'abc123',
     *     'expires_at' => now()->addHour()
     * ]);
     * echo $secret->uuid; // Outputs the generated UUID
     */
    public function create(array $data): Secret;

    /**
     * FIND A SECRET BY UUID (NOT EXPIRED)
     * 
     * @param string $uuid - The UUID to search for
     * @return Secret|null - Returns Secret if found and not expired, null otherwise
     * 
     * PURPOSE: Retrieve a secret by its public UUID, but only if it hasn't expired
     * 
     * WHY NULLABLE: Secret might not exist, or might have expired
     * 
     * LOGIC: Find secret WHERE uuid = ? AND (expires_at IS NULL OR expires_at > NOW())
     * 
     * EXAMPLE USAGE:
     * $secret = $repository->findByUuid('550e8400-e29b-41d4-a716-446655440000');
     * if ($secret) {
     *     // Secret exists and hasn't expired
     * } else {
     *     // Secret not found or expired
     * }
     */
    public function findByUuid(string $uuid): ?Secret;

    /**
     * DELETE A SECRET (BURN ON READ)
     * 
     * @param Secret $secret - The Secret model instance to delete
     * @return bool          - Returns true if deleted successfully, false otherwise
     * 
     * PURPOSE: Permanently remove a secret from the database
     * 
     * WHY: This implements the "burn on read" feature
     *      Once you read a secret, it's gone forever
     * 
     * SQL: DELETE FROM secrets WHERE id = ?
     * 
     * EXAMPLE USAGE:
     * $secret = $repository->findByUuid($uuid);
     * $repository->delete($secret); // Secret is now gone from database
     */
    public function delete(Secret $secret): bool;

    /**
     * DELETE ALL EXPIRED SECRETS (CLEANUP)
     * 
     * @return int - Number of deleted records
     * 
     * PURPOSE: Cleanup job to remove secrets that have passed their expiration
     * 
     * WHY: Secrets with TTL should be auto-deleted when they expire
     *      This prevents database bloat
     * 
     * WHEN TO RUN: Via scheduled task (cron job), typically hourly
     * 
     * SQL: DELETE FROM secrets WHERE expires_at <= NOW()
     * 
     * EXAMPLE USAGE:
     * $deleted = $repository->deleteExpired();
     * Log::info("Cleaned up {$deleted} expired secrets");
     */
    public function deleteExpired(): int;
}