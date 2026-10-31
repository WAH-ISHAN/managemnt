<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * This filter used to accept ANY non-empty X-Api-Key header — it returned
 * "API key required" and then let anything through. An open feed with a padlock
 * drawn on it. Keys are now rows: SHA-256 hashed, quota-counted here so a
 * partner can never reach a controller it has already exhausted.
 */
class ApiKey implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $given = trim($request->getHeaderLine('X-Api-Key'));

        // One message for "no such key" and "revoked key". Distinguishing them
        // tells an attacker which guesses were once real.
        $deny = static fn () => problem(401, 'invalid_key', 'API key missing, unknown or revoked.');

        if ($given === '') {
            return $deny();
        }

        $keys = model('App\Models\ApiKeyModel');
        $row  = $keys->where('key_hash', hash('sha256', $given))->first();

        if (! $row || $row['revoked_at'] !== null || ($row['approval_status'] ?? 'pending') !== 'approved') {
            return $deny();
        }

        $org = model('App\Models\OrganisationModel')->find((int) $row['org_id']);
        if (!empty($row['requested_by'])) {
            $owner=model('App\Models\UserModel')->find((int)$row['requested_by']);
            if (!$owner || $owner['status'] !== 'active' || (int)$owner['org_id'] !== (int)$row['org_id']) return $deny();
        }
        $adminIssued = !empty($row['admin_issued']);
        if ($adminIssued && (empty($owner) || $owner['user_group'] !== 'staff' || !$org)) return $deny();
        $uri = $request->getUri();
        $routePath = $uri instanceof \CodeIgniter\HTTP\SiteURI ? $uri->getRoutePath() : $uri->getPath();
        if ($adminIssued && (strtoupper($request->getMethod()) !== 'GET'
            || !in_array(trim($routePath, '/'), ['api/v1/partner/notices', 'api/v1/tenders/feed'], true))) {
            return problem(403, 'read_only_key', 'This key can only read the TenderHub tender feed.');
        }
        if (!$adminIssued && (! $org || ($org['sub_status'] ?? '') !== 'active'
            || !in_array($org['plan'] ?? '', ['business','enterprise'],true)
            || empty($org['renews_at']) || strtotime($org['renews_at']) <= time())) {
            return problem(403, 'subscription_required', 'An active TenderHub membership is required.');
        }

        $today = date('Y-m-d');
        $used  = $row['quota_date'] === $today ? (int) $row['used_today'] : 0;

        if ($used >= (int) $row['daily_quota']) {
            return problem(429, 'quota_exhausted', 'Daily quota exhausted.', [
                'quota'    => (int) $row['daily_quota'],
                'resets_at'=> date('c', strtotime('tomorrow midnight')),
            ]);
        }

        $db=db_connect('default');
        $db->query('UPDATE api_keys SET used_today = CASE WHEN quota_date = ? THEN used_today + 1 ELSE 1 END, quota_date = ? WHERE id = ? AND revoked_at IS NULL AND approval_status = ? AND (quota_date IS NULL OR quota_date <> ? OR used_today < daily_quota)', [$today,$today,$row['id'],'approved',$today]);
        if ($db->affectedRows() !== 1) return problem(429,'quota_exhausted','Daily quota exhausted.');
        $current=$keys->find($row['id']);
        $keys->update($row['id'], ['last_used_at' => date('Y-m-d H:i:s')]);

        $request->apiKey    = $row;
        $request->orgId     = (int) $row['org_id'];
        $request->remaining = max(0,(int)$current['daily_quota']-(int)$current['used_today']);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        if (isset($request->remaining)) {
            $response->setHeader('X-RateLimit-Remaining', (string) $request->remaining);
        }
    }
}
