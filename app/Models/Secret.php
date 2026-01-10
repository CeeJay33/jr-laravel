<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * SECRET MODEL
 * 
 * PURPOSE: Represents a secret in the database
 * 
 * RESPONSIBILITIES:
 * 1. Define which fields can be mass-assigned (security)
 * 2. Auto-generate UUID when creating new secrets
 * 3. Cast dates to Carbon objects for easy manipulation
 * 4. Hide sensitive fields from JSON responses
 * 5. Provide reusable query scopes
 * 
 * WHY THIS APPROACH:
 * - UUID generation in model ensures it's automatic and consistent
 * - Hidden fields prevent accidental exposure of sensitive data
 * - Scopes make queries reusable and readable
 */
class Secret extends Model
{
    use HasFactory; // Enables factory for testing

    /**
     * MASS ASSIGNABLE FIELDS
     * 
     * Only these fields can be filled using Secret::create([...])
     * This is a security feature (prevents mass assignment vulnerabilities)
     * 
     * WHY: If someone sends extra fields in request, they're ignored
     * EXAMPLE: User sends {"uuid": "hack", "content": "x"} 
     *          The "uuid" would be ignored, auto-generated instead
     */
    protected $fillable = [
        'uuid',               // Public identifier
        'encrypted_content',  // The encrypted secret
        'expires_at',        // Expiration timestamp
    ];

    /**
     * ATTRIBUTE CASTING
     * 
     * Automatically converts database values to PHP types
     * 
     * WHY: expires_at is stored as string in DB, but we want 
     *      a Carbon object so we can do: $secret->expires_at->addHours(1)
     */
    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * HIDDEN FIELDS
     * 
     * These fields are automatically removed when converting to JSON
     * 
     * WHY: 
     * - 'id' should never be exposed (we use UUID instead)
     * - 'encrypted_content' should only be decrypted in service layer
     * 
     * RESULT: When you do Secret::all()->toJson(), these fields disappear
     */
    protected $hidden = [
        'id',
        'encrypted_content',
    ];

    /**
     * MODEL BOOT METHOD
     * 
     * Runs when model is first loaded
     * Sets up event listeners for model lifecycle
     * 
     * WHY HERE: Auto-generate UUID before saving to database
     * WHEN: Fires before INSERT INTO secrets...
     */
    protected static function boot()
    {
        parent::boot();

        // Listen to 'creating' event (before INSERT)
        static::creating(function ($model) {
            // Only generate UUID if not already set
            if (empty($model->uuid)) {
                // Str::uuid() generates RFC 4122 compliant UUID
                // Format: 550e8400-e29b-41d4-a716-446655440000
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * ROUTE KEY NAME
     * 
     * Tells Laravel to use 'uuid' instead of 'id' for route binding
     * 
     * WHY: When you have Route::get('/secrets/{secret}'), Laravel 
     *      should find by UUID, not ID
     * 
     * EXAMPLE: 
     * Route: /api/v1/secrets/550e8400-e29b-41d4-a716-446655440000
     * Laravel automatically does: Secret::where('uuid', '550e...')->first()
     */
    public function getRouteKeyName()
    {
        return 'uuid';
    }

    /**
     * QUERY SCOPE: Not Expired
     * 
     * PURPOSE: Reusable query to get only non-expired secrets
     * 
     * USAGE: Secret::notExpired()->get()
     * 
     * LOGIC: A secret is NOT expired if:
     * 1. expires_at is NULL (never expires), OR
     * 2. expires_at is in the future (hasn't expired yet)
     * 
     * WHY SCOPE: Instead of repeating this logic everywhere,
     *            we write it once and reuse it
     * 
     * SQL GENERATED:
     * SELECT * FROM secrets 
     * WHERE (expires_at IS NULL OR expires_at > '2025-01-09 10:00:00')
     */
    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')              // Never expires
              ->orWhere('expires_at', '>', now());   // Or hasn't expired yet
        });
    }
}