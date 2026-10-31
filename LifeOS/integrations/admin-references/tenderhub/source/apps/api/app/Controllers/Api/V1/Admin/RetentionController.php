<?php
namespace App\Controllers\Api\V1\Admin;
use App\Controllers\Api\V1\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/** Legal holds — while a hold is active, the held record cannot be deleted. */
class RetentionController extends BaseApiController
{
    public function holds(): ResponseInterface
    {
        return $this->ok(db_connect()->table('legal_holds')->where('released_at', null)->orderBy('id', 'DESC')->get()->getResultArray());
    }

    public function place(): ResponseInterface
    {
        $in = $this->body();
        $type = (string) ($in['entity_type'] ?? '');
        $eid  = (int) ($in['entity_id'] ?? 0);
        $reason = trim((string) ($in['reason'] ?? ''));
        if ($type === '' || $eid <= 0 || $reason === '') {
            return problem(422, 'validation_failed', 'entity_type, entity_id and reason are required.');
        }
        $claims = (array) $this->request->claims;
        $db     = db_connect();
        $tables = ['procurement' => 'procurements', 'complaint' => 'complaints', 'contract' => 'contracts', 'notice' => 'notices', 'document' => 'notice_documents'];
        if (! isset($tables[$type])) {
            return problem(422, 'invalid_entity_type', 'Choose procurement, complaint, contract, notice or document.');
        }
        if (! $db->table($tables[$type])->where('id', $eid)->countAllResults()) {
            return problem(404, 'entity_not_found', 'The selected record does not exist. No hold was placed.');
        }
        if ($db->table('legal_holds')->where(['entity_type' => $type, 'entity_id' => $eid])->where('released_at', null)->countAllResults()) {
            return problem(409, 'already_held', 'This record already has an active legal hold.');
        }
        $db->table('legal_holds')->insert([
            'entity_type' => $type, 'entity_id' => $eid, 'reason' => $reason,
            'created_by' => (int) $this->request->userId, 'created_name' => $claims['nm'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        // Capture the hold's id BEFORE any further insert (the ledger write below
        // would otherwise make insertID() return the ledger row's id).
        $holdId = (int) $db->insertID();
        service('eventLedger')->record($type, $eid, 'legal_hold.placed', 'Legal hold placed: ' . $reason);
        return $this->ok(['held' => true, 'id' => $holdId], [], 201);
    }

    public function release(int $id): ResponseInterface
    {
        $db = db_connect();
        $h = $db->table('legal_holds')->where('id', $id)->get()->getFirstRow('array');
        if (! $h || $h['released_at']) { return problem(404, 'not_found', 'No active hold.'); }
        $db->table('legal_holds')->where('id', $id)->update(['released_at' => date('Y-m-d H:i:s')]);
        service('eventLedger')->record($h['entity_type'], (int) $h['entity_id'], 'legal_hold.released', 'Legal hold released');
        return $this->ok(['released' => true]);
    }
}
