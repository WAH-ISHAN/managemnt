<?php

namespace App\Models;

use CodeIgniter\Model;
use RuntimeException;

/**
 * Complaints are immutable evidence. They advance through a state machine but
 * are NEVER deleted — so delete() is disabled here rather than merely left
 * uncalled. A future controller that tries to delete a complaint fails loudly
 * instead of quietly destroying a challenge record.
 */
class ComplaintModel extends Model
{
    protected $table         = 'complaints';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'reference', 'procurement_id', 'notice_ref', 'complainant_org_id', 'complainant_user_id',
        'complainant_name', 'grounds', 'status', 'assigned_reviewer_id', 'assigned_reviewer_name',
        'response_deadline', 'decision', 'decision_reason',
    ];

    public function delete($id = null, bool $purge = false)
    {
        throw new RuntimeException('Complaints are immutable and cannot be deleted.');
    }

    public function purgeDeleted(): bool
    {
        throw new RuntimeException('Complaints are immutable and cannot be deleted.');
    }
}
