<?php

namespace App\Models;

use CodeIgniter\Model;

class ApiKeyModel extends Model
{
    protected $table         = 'api_keys';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    // TH-SEC-002: explicit writable columns instead of protectFields=false.
    protected $allowedFields = [
        'org_id', 'label', 'prefix', 'key_hash', 'daily_quota', 'used_today',
        'quota_date', 'revoked_at', 'created_at', 'updated_at',
        'approval_status', 'requested_by', 'reviewed_by', 'reviewed_at',
        'admin_issued', 'last_used_at',
    ];
}
