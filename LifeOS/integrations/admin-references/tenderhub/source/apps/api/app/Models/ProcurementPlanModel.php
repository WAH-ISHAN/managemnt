<?php

namespace App\Models;

use CodeIgniter\Model;

class ProcurementPlanModel extends Model
{
    protected $table         = 'procurement_plans';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'org_id', 'year', 'title', 'department', 'project', 'category_id', 'estimated_value',
        'funding_source', 'procurement_method', 'planned_tender_date', 'planned_award_date',
        'budget_allocation', 'officer_id', 'officer_name', 'status', 'linked_procurement_id',
        'revision_of', 'created_by', 'approved_by', 'approved_at',
    ];
}
