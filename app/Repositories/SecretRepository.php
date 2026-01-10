<?php

namespace App\Repositories;

use App\Models\Secret;
use App\Repositories\Contracts\SecretRepositoryInterface;

class SecretRepository implements SecretRepositoryInterface
{
    public function create(array $data): Secret
    {
        return Secret::create($data);
    }

    public function findByUuid(string $uuid): ?Secret
    {
        return Secret::where('uuid', $uuid)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    public function delete(Secret $secret): bool
    {
        return $secret->delete();
    }

    public function deleteExpired(): int
    {
        return Secret::whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();
    }
}
