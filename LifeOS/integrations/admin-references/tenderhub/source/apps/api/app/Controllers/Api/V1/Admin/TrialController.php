<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * GET /api/v1/admin/trials — users whose free trial is running or ended.
 *
 * "On trial" uses exactly the rule of subscriptions:maintain-trials: the
 * organisation has trial_started_at and renews_at, and no confirmed paid
 * (non-trial) payment. The trial ends at renews_at (60 days from signup).
 * Buckets follow the existing reminder schedule (7, 3 and 1 day emails,
 * expiry): expired, today (ends on today's Asia/Colombo date), 1d, 3d
 * (2-3 days), 7d (4-7 days), active (more than 7). Days left are counted
 * exactly like the reminder emails: remaining time in whole days, rounded up.
 */
class TrialController extends BaseApiController
{
    public const BUCKETS = ['expired', 'today', '1d', '3d', '7d', 'active'];

    public function index(): ResponseInterface
    {
        $db = db_connect();
        $bucket   = (string) $this->request->getGet('bucket');
        $q        = trim((string) $this->request->getGet('q'));
        $verified = (string) $this->request->getGet('verified');   // verified|unverified
        $group    = (string) $this->request->getGet('group');      // bidder|company
        $category = trim((string) $this->request->getGet('category'));

        $paidOrgIds = array_map('intval', array_column($db->table('payments')->select('org_id')->distinct()
            ->where('state', 'confirmed')->where('term !=', 'trial')->get()->getResultArray(), 'org_id'));

        $b = $db->table('users u')
            ->select('u.id, u.name, u.email, u.phone, u.role, u.user_group, u.status, u.last_login_at, u.email_verified_at,
                      o.id AS org_id, o.name AS org_name, o.type AS org_type, o.plan, o.sub_status, o.verify_state,
                      o.trial_started_at, o.renews_at')
            ->join('organisations o', 'o.id = u.org_id')
            ->where('o.trial_started_at IS NOT NULL', null, false)
            ->where('o.renews_at IS NOT NULL', null, false)
            ->whereIn('u.user_group', ['bidder', 'company'])
            ->where('o.plan !=', 'staff');
        if ($paidOrgIds !== []) {
            $b->whereNotIn('o.id', $paidOrgIds);
        }
        if ($q !== '') {
            $b->groupStart()->like('u.name', $q)->orLike('u.email', $q)->orLike('o.name', $q)->orLike('u.phone', $q)->groupEnd();
        }
        if (in_array($group, ['bidder', 'company'], true)) {
            $b->where('u.user_group', $group);
        }
        if ($verified === 'verified') {
            $b->where('o.verify_state', 'verified');
        } elseif ($verified === 'unverified') {
            $b->where('o.verify_state !=', 'verified');
        }
        $rows = $b->orderBy('o.renews_at', 'ASC')->limit(2000)->get()->getResultArray();

        $userIds = array_map('intval', array_column($rows, 'id'));
        $profiles = [];
        if ($userIds) {
            foreach ($db->table('alert_profiles')->select('user_id, category_slugs, channels, active')->whereIn('user_id', $userIds)->get()->getResultArray() as $p) {
                $profiles[(int) $p['user_id']][] = $p;
            }
        }
        $names = array_column($db->table('categories')->select('slug, name')->get()->getResultArray(), 'name', 'slug');
        $reminders = [];
        if ($userIds) {
            foreach ($db->table('notifications')->select('user_id, COUNT(*) AS n', false)->whereIn('user_id', $userIds)
                ->groupStart()->like('type', 'trial_reminder_', 'after')->orLike('type', 'trial_expired_', 'after')->groupEnd()
                ->groupBy('user_id')->get()->getResultArray() as $n) {
                $reminders[(int) $n['user_id']] = (int) $n['n'];
            }
        }

        $tz = new \DateTimeZone('Asia/Colombo');
        $today = new \DateTimeImmutable('today', $tz);
        $counts = array_fill_keys(self::BUCKETS, 0);
        $out = [];
        foreach ($rows as $r) {
            $ends = (new \DateTimeImmutable($r['renews_at'], new \DateTimeZone('UTC')))->setTimezone($tz);
            $secondsLeft = $ends->getTimestamp() - time();
            // Same count as the reminder emails (subscriptions:maintain-trials):
            // whole days rounded up, so the console and the user agree.
            $daysLeft = (int) ceil($secondsLeft / 86400);
            $endsToday = $ends->format('Y-m-d') === $today->format('Y-m-d');
            $rowBucket = match (true) {
                $secondsLeft <= 0 || $r['sub_status'] === 'expired' => 'expired',
                $endsToday => 'today',
                $daysLeft <= 1 => '1d',
                $daysLeft <= 3 => '3d',
                $daysLeft <= 7 => '7d',
                default => 'active',
            };
            $slugs = [];
            $emailAlerts = false;
            foreach ($profiles[(int) $r['id']] ?? [] as $p) {
                if ((int) $p['active'] === 1) {
                    $slugs = array_merge($slugs, array_filter(array_map('trim', explode(',', (string) $p['category_slugs']))));
                    $emailAlerts = $emailAlerts || str_contains(strtolower((string) $p['channels']), 'email');
                }
            }
            $slugs = array_values(array_unique($slugs));
            if ($category !== '' && ! in_array($category, $slugs, true)) {
                continue;
            }
            $counts[$rowBucket]++;
            if ($bucket !== '' && $bucket !== $rowBucket && ! ($bucket === 'expiring' && in_array($rowBucket, ['today', '1d', '3d', '7d'], true))) {
                continue;
            }
            $out[] = [
                'user_id' => (int) $r['id'], 'name' => $r['name'], 'email' => $r['email'], 'phone' => $r['phone'],
                'account_type' => $r['user_group'], 'role' => $r['role'], 'status' => $r['status'],
                'email_verified' => $r['email_verified_at'] !== null,
                'org_id' => (int) $r['org_id'], 'organisation' => $r['org_name'], 'plan' => $r['plan'],
                'kyc' => $r['verify_state'], 'subscription_status' => $r['sub_status'],
                'trial_started_at' => $r['trial_started_at'], 'trial_ends_at' => $r['renews_at'],
                'seconds_left' => max(0, $secondsLeft), 'days_left' => $secondsLeft > 0 ? $daysLeft : 0, 'bucket' => $rowBucket,
                'last_login_at' => $r['last_login_at'],
                'categories' => array_map(static fn ($s) => $names[$s] ?? $s, $slugs),
                'email_alerts' => $emailAlerts,
                'reminders_sent' => $reminders[(int) $r['id']] ?? 0,
            ];
        }

        return $this->ok($out, ['counts' => $counts, 'total' => count($out), 'timezone' => 'Asia/Colombo']);
    }
}
