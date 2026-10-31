<?php

namespace App\Models;

use CodeIgniter\Model;

class SubmissionModel extends Model
{
    protected $table         = 'submissions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'procurement_id','bidder_org_id','bidder_name','reference','total_price','has_security',
        'size_bytes','content_hash','cipher_path','status','disqualified','received_at',
    ];

    /** Fields withheld before the opening, named so the interface can say why. */
    public const WITHHELD = ['bidder', 'total_price', 'has_security'];

    public const WITHHELD_REASON =
        'Bids are sealed until two authorised officers countersign the opening. '
        . 'The identifying columns are not read out of the database before that point.';

    /**
     * THE CONFIDENTIALITY BOUNDARY. It is a query, not a stylesheet.
     *
     * Before the opening this selects five columns — id, reference, size,
     * status, timestamp. The bidder and the price are not merely hidden from
     * the response: they are never read out of the database into it. The caller
     * cannot forget to mask them because they were never there.
     */
    public function forProcurement(int $procurementId, bool $opened): array
    {
        $b = $this->db->table('submissions')->where('procurement_id', $procurementId);

        if (! $opened) {
            return $b->select('id, reference, size_bytes, status, received_at')
                ->orderBy('received_at', 'ASC')->get()->getResultArray();
        }

        return $b->select('id, reference, bidder_org_id, bidder_name, total_price, has_security,
                           size_bytes, content_hash, status, disqualified, received_at')
            ->orderBy('total_price', 'ASC')->get()->getResultArray();
    }
}
