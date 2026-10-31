<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Notifications\AlertTraceService;
use App\Libraries\Notifications\EmailOutbox;
use App\Libraries\SystemSettings;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Staff console email endpoints. Every number comes from email_dispatch_audit
 * (the outbox), email_provider_events or the job tables; nothing is estimated.
 */
class EmailController extends BaseApiController
{
    public function retryFailed(): ResponseInterface
    {
        $count = \App\Libraries\Notifications\RegistrationEmailQueue::retryFailedOnboarding();
        service('eventLedger')->record('email_queue', 0, 'email.retry_requested', 'Failed onboarding emails requeued by staff.', ['count' => $count, 'actor_id' => (int) ($this->request->userId ?? 0)]);
        return $this->ok(['requeued' => $count]);
    }

    public function analytics(): ResponseInterface
    {
        $db = db_connect();
        $status = (string) $this->request->getGet('status');
        $kind = (string) $this->request->getGet('kind');
        $query = trim((string) $this->request->getGet('q'));
        $page = max(1, (int) $this->request->getGet('page'));
        $builder = $db->table('email_dispatch_audit e')
            ->select('e.id, e.user_id, e.recipient_email, e.subject, e.kind, e.status, e.provider, e.detail, e.last_error, e.attempt_count, e.max_attempts, e.next_attempt_at, e.attempted_at, e.accepted_at, e.delivered_at, e.bounced_at, e.bounce_type, e.complained_at, e.completed_at, e.open_count, e.opened_at, e.last_opened_at, e.click_count, e.first_clicked_at, u.name AS user_name')
            ->join('users u', 'u.id = e.user_id', 'left');
        if (in_array($status, EmailOutbox::STATUSES, true)) {
            $builder->where('e.status', $status);
        }
        if (preg_match('/^[a-z_]{1,40}$/', $kind)) {
            $builder->where('e.kind', $kind);
        }
        if ($query !== '') {
            $builder->groupStart()->like('e.recipient_email', $query)
                ->orLike('u.name', $query)->groupEnd();
        }
        $total = $builder->countAllResults(false);
        $rows = $builder->orderBy('e.id', 'DESC')->limit(25, ($page - 1) * 25)->get()->getResultArray();
        return $this->ok($rows, ['total' => $total, 'page' => $page]);
    }

    public function dispatch(int $id): ResponseInterface
    {
        $db = db_connect();
        $row = $db->table('email_dispatch_audit e')
            ->select('e.*, u.name AS user_name, u.user_group')
            ->join('users u', 'u.id = e.user_id', 'left')
            ->where('e.id', $id)->get()->getFirstRow('array');
        if (! $row) {
            return problem(404, 'not_found', 'No recorded email attempt has that ID.');
        }
        unset($row['open_token'], $row['last_open_ip_hash']);
        $row['tenders'] = $db->table('email_dispatch_audit_items')
            ->where('dispatch_id', $id)->orderBy('id', 'ASC')->get()->getResultArray();
        $row['provider_events'] = $db->table('email_provider_events')
            ->select('event_type, recipient, detail, occurred_at, received_at')->where('dispatch_id', $id)->orderBy('id', 'ASC')->get()->getResultArray();
        return $this->ok($row);
    }

    /**
     * GET /api/v1/admin/email/status
     */
    public function status(): ResponseInterface
    {
        /** @var \Config\Email $config */
        $config = config('Email');

        $db = db_connect();
        $since = date('Y-m-d H:i:s', time() - 86400);
        $count = static fn (callable $scope): int => $scope($db->table('email_dispatch_audit'))->countAllResults();

        $lifecycle = [];
        foreach (EmailOutbox::STATUSES as $state) {
            $lifecycle[$state] = $count(static fn ($q) => $q->where('status', $state));
        }
        $last24h = [
            'created'           => $count(static fn ($q) => $q->where('attempted_at >=', $since)),
            'provider_accepted' => $count(static fn ($q) => $q->where('accepted_at >=', $since)),
            'delivered'         => $count(static fn ($q) => $q->where('delivered_at >=', $since)),
            'bounced'           => $count(static fn ($q) => $q->where('bounced_at >=', $since)),
            'complained'        => $count(static fn ($q) => $q->where('complained_at >=', $since)),
            'failed'            => $count(static fn ($q) => $q->where('status', 'failed')->where('completed_at >=', $since)),
            'opened'            => $count(static fn ($q) => $q->where('opened_at >=', $since)),
            'clicked'           => $count(static fn ($q) => $q->where('first_clicked_at >=', $since)),
        ];
        $oldestQueued = $db->table('email_dispatch_audit')->selectMin('attempted_at')->where('status', 'queued')
            ->groupStart()->where('next_attempt_at IS NULL', null, false)->orWhere('next_attempt_at <=', date('Y-m-d H:i:s'))->groupEnd()
            ->get()->getFirstRow('array')['attempted_at'] ?? null;

        $digest = [
            'pending' => $db->table('login_tender_email_jobs')->where('status', 'pending')->countAllResults(),
            'registration_pending' => $db->table('registration_email_jobs')->where('status', 'pending')->countAllResults(),
            'registration_failed' => $db->table('registration_email_jobs')->where('status', 'failed')->countAllResults(),
            'registration_processing' => $db->table('registration_email_jobs')->where('status', 'processing')->countAllResults(),
            'active_profiles' => $db->table('alert_profiles ap')->join('users u', 'u.id = ap.user_id')
                ->where('ap.active', 1)->like('ap.channels', 'email')
                ->where('u.status', 'active')->whereIn('u.user_group', ['bidder', 'company'])->countAllResults(),
            'alert_recipients_entitled' => count((new \App\Libraries\Notifications\TenderEmailService())->alertEligibleUsersQuery()->get()->getResultArray()),
            'notification_pending' => $db->table('notification_deliveries')->where('channel', 'email')->whereIn('status', ['queued', 'processing'])->countAllResults(),
            'tender_alert_items_24h' => $db->table('category_alert_events')->where('sent_at >=', $since)->countAllResults(),
        ];
        $lastEvent = $db->table('email_provider_events')->selectMax('received_at')->get()->getFirstRow('array')['received_at'] ?? null;
        $authJobs = $db->table('registration_email_jobs j')
            ->select('j.id, j.kind, j.status, j.attempts, j.requested_at, j.next_attempt_at, j.completed_at, j.last_error, u.email AS recipient_email')
            ->join('users u', 'u.id = j.user_id', 'left')
            ->orderBy('j.id', 'DESC')->limit(25)->get()->getResultArray();

        return $this->response->setJSON([
            'status' => 200,
            'data'   => $config->getStatus() + [
                'digest'    => $digest,
                'recent_auth_email_jobs' => $authJobs,
                'lifecycle' => $lifecycle,
                'last_24h'  => $last24h,
                'queue'     => ['oldest_due_at' => $oldestQueued, 'last_worker_run' => cache()->get('email_outbox_last_run')],
                'provider_events' => [
                    'configured'        => trim((string) env('SES_SNS_TOPIC_ARN')) !== '',
                    'configuration_set' => (string) env('SES_CONFIGURATION_SET') ?: null,
                    'last_event_at'     => $lastEvent,
                    'total'             => $db->table('email_provider_events')->countAllResults(),
                ],
                'suppressions' => [
                    'hard_bounce' => $db->table('email_suppressions')->where('reason', 'hard_bounce')->countAllResults(),
                    'complaint'   => $db->table('email_suppressions')->where('reason', 'complaint')->countAllResults(),
                ],
                'finops' => EmailOutbox::finOpsStatus($db),
                'settings' => [SystemSettings::ADMIN_NEW_TENDER_ALERTS => SystemSettings::describe(SystemSettings::ADMIN_NEW_TENDER_ALERTS)],
                'incidents' => $db->table('automation_incidents')->orderBy('id', 'DESC')->limit(10)->get()->getResultArray(),
            ],
            'meta'   => ['now' => date('c')],
        ]);
    }

    /**
     * POST /api/v1/admin/email/toggle
     * Body: { "driver": "smtp" }
     */
    public function toggle(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? $this->request->getPost();
        $target = strtolower(trim((string) ($body['driver'] ?? '')));

        if ($target !== 'smtp') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 400,
                'reason' => 'invalid_driver',
                'detail' => 'TenderHub email is SMTP-only. Driver must be "smtp".',
            ]);
        }

        /** @var \Config\Email $config */
        $config  = config('Email');
        $current = $config->driver;

        return $this->response->setJSON([
            'status'  => 200,
            'message' => "The active email transport is '{$current}'.",
            'data'    => ['active_driver' => $current] + $config->getStatus(),
        ]);
    }

    /**
     * POST /api/v1/admin/email/test  Body: { "to": "..." }
     *
     * Only to the acting administrator's own address or a configured staff
     * alert address (no open relay), at most 5 per administrator per hour.
     */
    public function test(): ResponseInterface
    {
        if ($denied = $this->requireAdministrator()) {
            return $denied;
        }
        $body = $this->request->getJSON(true) ?? $this->request->getPost();
        $to = strtolower(trim((string) ($body['to'] ?? '')));
        $actorId = (int) $this->request->userId;
        $actor = db_connect()->table('users')->select('email')->where('id', $actorId)->get()->getFirstRow('array');
        $allowed = array_unique(array_filter(array_merge(
            [strtolower((string) ($actor['email'] ?? ''))],
            array_map('strtolower', array_map('trim', preg_split('/[,;\s]+/', (string) (env('AUTOMATION_ADMIN_EMAILS') ?: env('ADMIN_EMAIL') ?: '')) ?: []))
        )));
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL) || ! in_array($to, $allowed, true)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 400,
                'reason' => 'invalid_recipient',
                'detail' => 'Test emails can only go to your own address or a configured staff alert address.',
                'allowed' => array_values($allowed),
            ]);
        }
        $rateKey = 'email_test_' . $actorId;
        $sent = (int) (cache()->get($rateKey) ?? 0);
        if ($sent >= 5) {
            return problem(429, 'rate_limited', 'At most 5 test emails per hour.');
        }
        cache()->save($rateKey, $sent + 1, 3600);

        /** @var \Config\Email $config */
        $config = config('Email');
        $start = microtime(true);
        $result = EmailOutbox::sendNow([
            'to' => $to, 'subject' => 'TenderHub email diagnostic', 'kind' => 'test', 'user_id' => $actorId,
            'html' => '<h1>TenderHub email diagnostic</h1><p>This message was sent from the TenderHub admin console to check the email transport. '
                . 'If you can read it, it reached your mailbox.</p>',
        ]);
        $latency = round((microtime(true) - $start) * 1000);
        service('eventLedger')->record('email_queue', $result['dispatch_id'], 'email.test_sent', 'Diagnostic email requested by staff.', ['actor_id' => $actorId, 'status' => $result['status']]);

        return $this->response->setStatusCode($result['success'] ? 200 : 502)->setJSON([
            'status' => $result['success'] ? 200 : 502,
            'data'   => [
                'success'     => $result['success'],
                'status'      => $result['status'],
                'dispatch_id' => $result['dispatch_id'],
                'provider'    => $result['provider'],
                'latency_ms'  => $latency,
                'detail'      => $result['detail'],
                'config'      => $config->getStatus()['active_profile'],
            ],
        ]);
    }

    /** POST /api/v1/admin/email/settings  Body: { "admin_new_tender_alerts": true|false } */
    public function updateSettings(): ResponseInterface
    {
        if ($denied = $this->requireAdministrator()) {
            return $denied;
        }
        $body = $this->request->getJSON(true) ?? [];
        if (! array_key_exists(SystemSettings::ADMIN_NEW_TENDER_ALERTS, $body) || ! is_bool($body[SystemSettings::ADMIN_NEW_TENDER_ALERTS])) {
            return problem(422, 'validation_failed', 'admin_new_tender_alerts must be true or false.');
        }
        $on = $body[SystemSettings::ADMIN_NEW_TENDER_ALERTS];
        $before = SystemSettings::get(SystemSettings::ADMIN_NEW_TENDER_ALERTS);
        SystemSettings::set(SystemSettings::ADMIN_NEW_TENDER_ALERTS, $on ? '1' : '0', (int) $this->request->userId);
        service('eventLedger')->record('settings', 0, 'settings.admin_new_tender_alerts', 'Admin new-tender alert emails switched ' . ($on ? 'on' : 'off') . '.', [
            'actor_id' => (int) $this->request->userId, 'before' => $before === '1' ? 'on' : 'off', 'after' => $on ? 'on' : 'off',
        ]);

        return $this->ok([SystemSettings::ADMIN_NEW_TENDER_ALERTS => SystemSettings::describe(SystemSettings::ADMIN_NEW_TENDER_ALERTS)]);
    }

    /** GET /api/v1/admin/email/trace?notice_id=&user_id= */
    public function trace(): ResponseInterface
    {
        if ($denied = $this->requireAdministrator()) {
            return $denied;
        }
        $noticeId = (int) $this->request->getGet('notice_id');
        $userId = (int) $this->request->getGet('user_id');
        $email = trim((string) $this->request->getGet('email'));
        if ($userId <= 0 && $email !== '') {
            $userId = (int) (db_connect()->table('users')->select('id')->where('email', $email)->get()->getFirstRow('array')['id'] ?? 0);
        }
        if ($noticeId <= 0 || $userId <= 0) {
            return problem(422, 'validation_failed', 'notice_id and user_id (or email) are required.');
        }

        return $this->ok(AlertTraceService::explain($noticeId, $userId));
    }

    /** GET /api/v1/admin/email/suppressions */
    public function suppressions(): ResponseInterface
    {
        return $this->ok(db_connect()->table('email_suppressions')->orderBy('id', 'DESC')->limit(100)->get()->getResultArray());
    }

    private function requireAdministrator(): ?ResponseInterface
    {
        $actor = db_connect()->table('users')->where('id', (int) $this->request->userId)->get()->getFirstRow('array');
        if (! $actor || $actor['user_group'] !== 'staff'
            || ! in_array($actor['role'], \App\Filters\StaffRole::ADMIN_ROLES, true) || $actor['status'] !== 'active') {
            return problem(403, 'forbidden', 'Only active administrators can do this.');
        }

        return null;
    }
}
