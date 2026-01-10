<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up(): void
    {
        Schema::create('secrets', function (Blueprint $table) {
            // Primary key (internal use only, never exposed in API)
            $table->id();
            
            // Public identifier - UUID ensures security
            // unique() prevents duplicate UUIDs
            $table->uuid('uuid')->unique();
            
            // Store encrypted content as TEXT (allows large secrets)
            // TEXT type can store up to 65,535 characters
            $table->text('encrypted_content');
            
            // Optional expiration time (nullable = can be null/not set)
            // When set, secret expires after this timestamp
            $table->timestamp('expires_at')->nullable();
            
            // Laravel's automatic timestamps (created_at, updated_at)
            $table->timestamps();

            // Add indexes for faster queries
            // Index on uuid: speeds up SELECT WHERE uuid = ?
            $table->index('uuid');
            
            // Index on expires_at: speeds up cleanup queries
            // SELECT WHERE expires_at < NOW()
            $table->index('expires_at');
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('secrets');
    }
};
