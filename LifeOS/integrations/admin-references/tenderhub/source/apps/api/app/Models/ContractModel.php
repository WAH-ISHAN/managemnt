<?php

namespace App\Models;

use CodeIgniter\Model;

class ContractModel extends Model
{
    protected $table         = 'contracts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'contract_no', 'procurement_id', 'award_id', 'org_id', 'supplier_org_id', 'supplier_name',
        'title', 'value', 'start_date', 'end_date', 'performance_security', 'retention_pct',
        'status', 'created_by',
    ];
}
