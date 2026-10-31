<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $DBGroup       = 'default';
    protected $table         = 'users';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    // Mass-assignment protection ON (TH-SEC-002): only these columns can be
    // written through the model, so a stray raw request body can never introduce
    // an unexpected column. `id` is intentionally excluded.
    protected $allowedFields = [
        'org_id', 'name', 'email', 'phone', 'nic', 'avatar_url', 'password_hash', 'role', 'user_group',
        'status', 'otp_hash', 'otp_expires_at', 'free_views', 'last_login_at', 'discovery_source',
        'email_verified_at', 'created_at', 'updated_at',
    ];
}
