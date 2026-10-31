<?php
namespace App\Controllers\Api\V1\Admin;
use App\Controllers\Api\V1\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/** Security Center — recent security events + a summary, staff only. */
class SecurityController extends BaseApiController
{
    public function events(): ResponseInterface
    {
        $db = db_connect('default');
        $rows = $db->table('security_events')->orderBy('id', 'DESC')->limit(100)->get()->getResultArray();
        $summary = [];
        $yesterday = date('Y-m-d H:i:s', strtotime('-1 day'));
        foreach ($db->query("SELECT kind, COUNT(*) AS c FROM security_events WHERE created_at > ? GROUP BY kind", [$yesterday])->getResultArray() as $r) {
            $summary[$r['kind']] = (int) $r['c'];
        }
        return $this->ok($rows, ['summary_24h' => $summary]);
    }
}
