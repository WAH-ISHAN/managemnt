<?php

namespace App\Models;

use CodeIgniter\Model;

class ProcurementModel extends Model
{
    protected $table         = 'procurements';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    // TH-SEC-002: explicit writable columns instead of protectFields=false.
    protected $allowedFields = [
        'org_id', 'notice_id', 'stage_idx', 'created_by', 'submitted_by', 'approved_by',
        'approved_at', 'published_by', 'published_at', 'opened_by_a', 'opened_by_b',
        'opening_started_at', 'opened_at', 'created_at', 'updated_at',
    ];
}
