<?php

namespace App\Models;

use CodeIgniter\Model;

class AuthIdentityModel extends Model
{
    protected $DBGroup       = 'default';
    protected $table         = 'auth_identities';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    protected $allowedFields = [
        'user_id',
        'provider',
        'provider_user_id',
        'provider_email',
        'avatar_url',
        'created_at',
        'updated_at',
    ];

    /**
     * Find identity by provider and provider's unique user ID (e.g. Google sub)
     */
    public function findIdentity(string $provider, string $providerUserId): ?array
    {
        return $this->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();
    }

    /**
     * Link a user with a third-party identity
     */
    public function linkIdentity(
        int $userId,
        string $provider,
        string $providerUserId,
        ?string $providerEmail = null,
        ?string $avatarUrl = null
    ): int {
        $existing = $this->findIdentity($provider, $providerUserId);
        if ($existing) {
            $this->update($existing['id'], [
                'user_id'        => $userId,
                'provider_email' => $providerEmail,
                'avatar_url'     => $avatarUrl ?: ($existing['avatar_url'] ?? null),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
            return (int) $existing['id'];
        }

        return (int) $this->insert([
            'user_id'          => $userId,
            'provider'         => $provider,
            'provider_user_id' => $providerUserId,
            'provider_email'   => $providerEmail,
            'avatar_url'       => $avatarUrl,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ], true);
    }

    /**
     * Get all linked identities for a given user
     */
    public function getUserIdentities(int $userId): array
    {
        return $this->where('user_id', $userId)->findAll();
    }

    /**
     * Unlink a provider from a user account
     */
    public function unlinkIdentity(int $userId, string $provider): bool
    {
        return (bool) $this->where('user_id', $userId)->where('provider', $provider)->delete();
    }
}
