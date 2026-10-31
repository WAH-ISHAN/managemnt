<?php
namespace App\Controllers\Api\V1\Admin;
use App\Controllers\Api\V1\BaseApiController;
class ApiKeyController extends BaseApiController
{
    public function documentation()
    {
        return $this->response->setHeader('Cache-Control', 'private, no-store')
            ->setContentType('text/plain')->setBody(file_get_contents(APPPATH . 'Docs/tender-feed.md'));
    }

    public function create()
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        $in = $this->body();
        $in['label'] = trim((string) ($in['label'] ?? ''));
        if (!$this->validateData($in, [
            'label' => 'required|max_length[80]',
            'daily_quota' => 'required|integer|greater_than_equal_to[1]|less_than_equal_to[100000]',
        ])) return problem(422, 'validation_failed', 'Enter a name and a daily quota between 1 and 100000.');
        $owner = model('App\Models\UserModel')->find((int) $this->request->userId);
        if (!$owner || $owner['status'] !== 'active' || $owner['user_group'] !== 'staff') {
            return problem(403, 'forbidden', 'An active staff account is required.');
        }
        $secret = 'th_' . bin2hex(random_bytes(32));
        $id = model('App\Models\ApiKeyModel')->insert([
            'org_id' => (int) $owner['org_id'], 'label' => $in['label'],
            'prefix' => substr($secret, 0, 12), 'key_hash' => hash('sha256', $secret),
            'daily_quota' => (int) $in['daily_quota'], 'approval_status' => 'approved',
            'admin_issued' => true, 'requested_by' => (int) $owner['id'],
            'reviewed_by' => (int) $owner['id'], 'reviewed_at' => date('Y-m-d H:i:s'),
        ], true);
        if (!$id) return problem(500, 'key_creation_failed', 'Unable to generate the key.');
        service('eventLedger')->record('api_key', $id, 'api_key.created', 'Read-only integration key created', ['actor' => (int) $owner['id']]);
        return $this->ok(['id' => $id, 'key' => $secret, 'approval_status' => 'approved'], [], 201);
    }

    public function index()
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        return $this->ok(db_connect('default')->table('api_keys k')->select('k.id,k.label,k.prefix,k.approval_status,k.daily_quota,k.used_today,k.quota_date,k.admin_issued,k.last_used_at,k.revoked_at,k.created_at,k.reviewed_at,o.name as organisation,u.email as requested_email')
            ->join('organisations o','o.id=k.org_id')->join('users u','u.id=k.requested_by','left')->orderBy('k.id','DESC')->get()->getResultArray());
    }
    public function review(int $id)
    {
        $in = $this->body();
        if (!$this->validateData($in,['decision'=>'required|in_list[approved,rejected,revoked]'])) return problem(422,'validation_failed','Choose approved, rejected or revoked.');
        $db = db_connect('default');
        $row = $db->table('api_keys')->where('id',$id)->get()->getRowArray();
        if (!$row) return problem(404,'not_found','Key not found.');
        if ($row['revoked_at'] !== null) return problem(409,'revoked','Revoked keys cannot be reactivated.');
        $changes = ['approval_status'=>$in['decision'],'reviewed_by'=>(int)$this->request->userId,'reviewed_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')];
        if ($in['decision']==='revoked') $changes['revoked_at']=date('Y-m-d H:i:s');
        $db->table('api_keys')->where('id',$id)->where('revoked_at',null)->update($changes);
        service('eventLedger')->record('api_key',$id,'api_key.'.$in['decision'],'Integration key reviewed',['actor'=>(int)$this->request->userId]);
        return $this->ok(['id'=>$id,'approval_status'=>$in['decision']]);
    }
}
