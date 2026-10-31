<?php

namespace App\Models;

use CodeIgniter\Model;

class PaymentModel extends Model
{
    protected $table         = 'payments';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    // TH-SEC-002: explicit writable columns instead of protectFields=false.
    protected $allowedFields = [
        'order_id', 'order_number', 'org_id', 'user_id', 'plan', 'term', 'amount',
        'currency', 'method', 'bank', 'slip_ref', 'paid_on', 'channel', 'receipt_path',
        'receipt_name', 'receipt_mime', 'receipt_size', 'submitted_at', 'state',
        'reviewed_by', 'reviewed_at', 'reject_reason', 'created_at', 'updated_at',
    ];
}
