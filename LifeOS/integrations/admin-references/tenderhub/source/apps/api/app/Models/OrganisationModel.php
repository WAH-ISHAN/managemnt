<?php

namespace App\Models;

use CodeIgniter\Model;

class OrganisationModel extends Model
{
    protected $DBGroup       = 'default';
    protected $table         = 'organisations';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    // Mass-assignment protection ON (TH-SEC-002). The profile endpoint already
    // intersects the request body against a field whitelist before it reaches
    // the model; this is the defence-in-depth layer. `id` is excluded.
    protected $allowedFields = [
        'name', 'slug', 'type', 'entity_type', 'reg_no', 'district_id', 'cida_grade', 'plan',
        'sub_status', 'renews_at', 'trial_started_at', 'seats', 'verified_at', 'verify_state',
        'approval_threshold', 'standstill_days', 'contact_email', 'contact_phone',
        'created_at', 'updated_at',
    ];
}
