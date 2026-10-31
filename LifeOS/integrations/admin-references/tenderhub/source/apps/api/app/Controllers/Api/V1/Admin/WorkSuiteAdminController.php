<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\SystemSettings;
use App\Libraries\WorkSuite\WorkSuiteClient;
use App\Libraries\WorkSuite\WorkSuiteEligibility;
use App\Libraries\WorkSuite\WorkSuiteService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Integration health for staff. Counts and error classes only: no API keys,
 * no WorkSuite payloads. Per organisation: the TenderHub-side access decision
 * (KYC, subscription, plan, eligibility) and link counts.
 */
class WorkSuiteAdminController extends BaseApiController
{
    /** GET admin/integrations/worksuite */
    public function health(): ResponseInterface
    {
        $db = db_connect();
        $since = date('Y-m-d H:i:s', time() - 86400);
        $count = static fn ($b) => $b->countAllResults();
        $connections = [];
        foreach (['connected', 'expired', 'error', 'revoked'] as $s) {
            $connections[$s] = $count($db->table('worksuite_connections')->where('status', $s));
        }
        $links = [];
        foreach (['synced', 'syncing', 'failed', 'unknown'] as $s) {
            $links[$s] = $count($db->table('worksuite_tender_links')->where('status', $s));
        }
        $failures = $db->table('worksuite_api_events')->select('outcome, COUNT(*) AS n', false)
            ->where('created_at >=', $since)->where('outcome !=', 'ok')->groupBy('outcome')->get()->getResultArray();
        $lastOk = $db->table('worksuite_api_events')->selectMax('created_at')->where('outcome', 'ok')->get()->getFirstRow('array')['created_at'] ?? null;
        $calls24h = $count($db->table('worksuite_api_events')->where('created_at >=', $since));

        return $this->ok([
            'enabled' => WorkSuiteService::enabled(),
            'setting' => SystemSettings::describe(SystemSettings::WORKSUITE_INTEGRATION),
            'allowed_hosts' => WorkSuiteClient::allowedHosts(),
            'connections' => $connections,
            'links' => $links,
            'calls_24h' => $calls24h,
            'failures_24h' => array_column($failures, 'n', 'outcome'),
            'last_successful_call_at' => $lastOk,
            'recent_failures' => $db->table('worksuite_api_events')->select('operation, http_status, outcome, detail, created_at')
                ->where('outcome !=', 'ok')->orderBy('id', 'DESC')->limit(20)->get()->getResultArray(),
            'organisations' => $this->organisations($db),
        ]);
    }

    /** Organisations holding a WorkSuite connection, with their live access decision. */
    private function organisations($db): array
    {
        $rows = $db->table('worksuite_connections c')
            ->select("o.id, o.name, o.type, o.plan, o.sub_status, o.renews_at, o.verify_state,
                SUM(CASE WHEN c.status != 'revoked' THEN 1 ELSE 0 END) AS connections,
                MAX(c.company_name) AS worksuite_company", false)
            ->join('organisations o', 'o.id = c.org_id')
            ->groupBy('o.id, o.name, o.type, o.plan, o.sub_status, o.renews_at, o.verify_state')
            ->orderBy('o.name', 'ASC')->limit(200)->get()->getResultArray();
        $eligibility = new WorkSuiteEligibility($db);

        return array_map(static function (array $o) use ($db, $eligibility): array {
            $links = $db->table('worksuite_tender_links l')->select("COUNT(*) AS n, MAX(l.synced_at) AS last_synced_at", false)
                ->join('worksuite_connections c', 'c.id = l.connection_id')
                ->where('c.org_id', (int) $o['id'])->where('l.status', 'synced')->get()->getFirstRow('array');
            $decision = $eligibility->checkOrganisation((int) $o['id']);

            return [
                'id' => (int) $o['id'], 'name' => $o['name'], 'type' => $o['type'], 'plan' => $o['plan'],
                'subscription' => $o['sub_status'], 'renews_at' => $o['renews_at'], 'kyc' => $o['verify_state'],
                'worksuite_company' => $o['worksuite_company'], 'connections' => (int) $o['connections'],
                'tenders_synced' => (int) ($links['n'] ?? 0), 'last_synced_at' => $links['last_synced_at'] ?? null,
                'eligible' => $decision['eligible'], 'eligibility' => $decision['reason'],
            ];
        }, $rows);
    }

    /** POST admin/integrations/worksuite  { enabled: bool } */
    public function update(): ResponseInterface
    {
        $actor = db_connect()->table('users')->where('id', (int) $this->request->userId)->get()->getFirstRow('array');
        if (! $actor || $actor['user_group'] !== 'staff' || ! in_array($actor['role'], \App\Filters\StaffRole::ADMIN_ROLES, true) || $actor['status'] !== 'active') {
            return problem(403, 'forbidden', 'Only active administrators can change integrations.');
        }
        $body = $this->request->getJSON(true) ?? [];
        if (! is_bool($body['enabled'] ?? null)) {
            return problem(422, 'validation_failed', 'enabled must be true or false.');
        }
        $before = WorkSuiteService::enabled();
        SystemSettings::set(SystemSettings::WORKSUITE_INTEGRATION, $body['enabled'] ? '1' : '0', (int) $this->request->userId);
        service('eventLedger')->record('settings', 0, 'settings.worksuite_integration', 'WorkSuite integration switched ' . ($body['enabled'] ? 'on' : 'off') . '.', [
            'actor_id' => (int) $this->request->userId, 'before' => $before ? 'on' : 'off', 'after' => $body['enabled'] ? 'on' : 'off',
        ]);

        return $this->health();
    }
}
