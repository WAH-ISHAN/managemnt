<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Auth\PrimaryOwner;
use App\Libraries\SafeCsv;
use App\Libraries\SimpleXlsx;

class UserController extends BaseApiController
{
    private function requireAdministrator()
    {
        $actor = db_connect()->table('users')->where('id', (int) $this->request->userId)->get()->getFirstRow('array');
        // 'owner' (the primary owner, AGENTS.md §3) outranks 'admin'. Checking
        // only for 'admin' locked the primary owner out of staff management.
        if (! $actor || $actor['user_group'] !== 'staff'
            || ! in_array($actor['role'], \App\Filters\StaffRole::ADMIN_ROLES, true) || $actor['status'] !== 'active') {
            return problem(403, 'forbidden', 'Only active administrators can manage accounts.');
        }
        return null;
    }

    public function index()
    {
        $db = db_connect();
        $allTotal = $db->table('users')->countAllResults();
        $accountCounts = $db->table('users')->select(
            "COUNT(*) AS all_users,
             SUM(CASE WHEN user_group IN ('bidder', 'company') THEN 1 ELSE 0 END) AS clients,
             SUM(CASE WHEN user_group = 'staff' THEN 1 ELSE 0 END) AS staff,
             SUM(CASE WHEN user_group = 'staff' AND role IN ('owner', 'admin') THEN 1 ELSE 0 END) AS administrators",
            false
        )->get()->getFirstRow('array') ?? [];
        $q  = $this->request->getGet('q');
        $status  = $this->request->getGet('status');
        $group   = $this->request->getGet('group');
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = 50;
        $hasDiscoverySource = $db->fieldExists('discovery_source', 'users');
        $discoverySelect = $hasDiscoverySource ? ', users.discovery_source' : '';

        $builder = $db->table('users')
            ->select('users.id, users.org_id, users.name, users.email, users.phone, users.nic, users.email_verified_at, users.role, users.user_group, users.status, users.created_at, users.last_login_at' . $discoverySelect . ', organisations.name AS org_name, organisations.type AS org_type, organisations.verify_state AS org_verify_state')
            ->join('organisations', 'organisations.id = users.org_id', 'left');

        if ($q) {
            $builder->groupStart()
                ->like('users.name', $q)
                ->orLike('users.email', $q)
                ->orLike('users.phone', $q)
                ->orLike('users.nic', $q)
                ->orLike('organisations.name', $q)
            ->groupEnd();
        }
        if ($status) {
            $builder->where('users.status', $status);
        }
        if ($group) {
            $builder->where('users.user_group', $group);
        }

        $total = (clone $builder)->countAllResults(false);
        $rows  = $builder->orderBy('users.created_at', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        // Registration persists the selected category in the initial Alerts profile.
        // Read it in one query, without guessing from account group or organisation.
        $registrationCategories = [];
        if ($rows !== []) {
            $profiles = $db->table('alert_profiles')->select('user_id, name')
                ->whereIn('user_id', array_column($rows, 'id'))->like('name', 'Alerts: ', 'after')
                ->orderBy('id', 'ASC')->get()->getResultArray();
            foreach ($profiles as $profile) {
                $uid = (int) $profile['user_id'];
                if (! isset($registrationCategories[$uid])) {
                    $registrationCategories[$uid] = trim(substr($profile['name'], strlen('Alerts: '))) ?: null;
                }
            }
        }

        // Organisation KYC for the KYC column: the live verify_state plus the
        // newest submission, so staff can review a pending one from this list.
        $latestKyc = [];
        $approvedOrgs = [];
        $orgIds = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'org_id')))));
        if ($orgIds !== []) {
            $subs = $db->table('kyc_submissions')->select('id, org_id, status, reason, submitted_at')
                ->whereIn('org_id', $orgIds)->orderBy('id', 'DESC')->get()->getResultArray();
            foreach ($subs as $sub) {
                $latestKyc[(int) $sub['org_id']] ??= $sub;
                if ($sub['status'] === 'approved') {
                    $approvedOrgs[(int) $sub['org_id']] = true;
                }
            }
        }

        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['is_primary_owner'] = PrimaryOwner::isPrimaryOwner($r['email'] ?? null);
            $r['registration_category'] = $registrationCategories[$r['id']] ?? null;
            $r['discovery_source'] = $r['discovery_source'] ?? null;
            $sub = $latestKyc[(int) $r['org_id']] ?? null;
            $r['kyc'] = [
                'state'                 => $r['org_verify_state'] ?? null,
                // A submission awaiting a decision (pending or under review).
                'pending_submission_id' => $sub && in_array($sub['status'], \App\Libraries\Kyc\KycWorkflow::OPEN, true) ? (int) $sub['id'] : null,
                'latest_submission_id'  => $sub ? (int) $sub['id'] : null,
                'last_submission'       => $sub ? ['status' => $sub['status'], 'reason' => $sub['reason'], 'submitted_at' => $sub['submitted_at']] : null,
                // "Verified" with no approved KYC on record (set before review was enforced).
                'verified_without_review' => ($r['org_verify_state'] ?? null) === 'verified' && ! isset($approvedOrgs[(int) $r['org_id']]),
            ];
            unset($r['org_id'], $r['org_verify_state']);
        }

        return $this->ok($rows, [
            'total'    => (int) $total,
            'all_total' => (int) $allTotal,
            'account_counts' => [
                'all_users'      => (int) ($accountCounts['all_users'] ?? 0),
                'clients'        => (int) ($accountCounts['clients'] ?? 0),
                'staff'          => (int) ($accountCounts['staff'] ?? 0),
                'administrators' => (int) ($accountCounts['administrators'] ?? 0),
            ],
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ]);
    }


    public function exportCsv()
    {
        if ($denied = $this->requireAdministrator()) { return $denied; }
        $rows = $this->exportRows((int) ($this->request->getGet('id') ?? 0));
        // Bulk export of personal data (NIC, BRN, phone) is an auditable act.
        service('eventLedger')->record('user', (int) ($this->request->getGet('id') ?? 0), 'users.exported', 'User/KYC data exported as CSV by administrator #' . $this->request->userId, [
            'format' => 'csv', 'rows' => count($rows), 'scope' => $this->request->getGet('id') ? 'single' : 'all', 'actor' => (int) $this->request->userId,
        ]);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $this->exportHeaders());
        foreach ($this->exportDataRows($rows) as $row) {
            fputcsv($out, SafeCsv::row($row));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $this->response->setContentType('text/csv')->setHeader('Content-Disposition', 'attachment; filename="tenderhub-users.csv"')->setBody($csv);
    }

    public function exportXlsx()
    {
        if ($denied = $this->requireAdministrator()) { return $denied; }
        $onlyUserId = (int) ($this->request->getGet('id') ?? 0);
        $rows = $this->exportRows($onlyUserId);
        service('eventLedger')->record('user', $onlyUserId, 'users.exported', 'User/KYC data exported as Excel by administrator #' . $this->request->userId, [
            'format' => 'xlsx', 'rows' => count($rows), 'scope' => $onlyUserId > 0 ? 'single' : 'all', 'actor' => (int) $this->request->userId,
        ]);

        $xlsx = SimpleXlsx::build($this->exportHeaders(), $this->exportDataRows($rows), 'TenderHub Users');
        $filename = $onlyUserId > 0 ? 'tenderhub-user-' . $onlyUserId . '.xlsx' : 'tenderhub-users.xlsx';

        return $this->response
            ->setContentType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($xlsx);
    }

    public function exportPdf()
    {
        if ($denied = $this->requireAdministrator()) { return $denied; }
        $rows = $this->exportRows((int) ($this->request->getGet('id') ?? 0));
        // Bulk export of personal data (NIC, BRN, phone) is an auditable act.
        service('eventLedger')->record('user', (int) ($this->request->getGet('id') ?? 0), 'users.exported', 'User/KYC data exported as PDF by administrator #' . $this->request->userId, [
            'format' => 'pdf', 'rows' => count($rows), 'scope' => $this->request->getGet('id') ? 'single' : 'all', 'actor' => (int) $this->request->userId,
        ]);
        $lines = ['TenderHub user/KYC export', 'Generated: ' . date('Y-m-d H:i:s'), ''];
        foreach ($rows as $r) {
            $lines[] = '#'.$r['id'].' '.$r['name'].' <'.$r['email'].'>';
            $lines[] = 'Business: '.($r['org_name'] ?: '-').' | BR No: '.($r['org_reg_no'] ?: ($r['kyc_reg_no'] ?: '-'));
            $lines[] = 'Phone: '.($r['phone'] ?: '-').' | NIC: '.($r['nic'] ?: ($r['kyc_nic'] ?: '-'));
            $lines[] = 'Category: '.($r['registration_category'] ?: '-').' | Discovery source: '.($r['discovery_source'] ?: '-');
            $lines[] = 'Group: '.$r['user_group'].' | Status: '.$r['status'];
            $lines[] = 'Email verified: '.($r['email_verified_at'] ?: 'No').' | Plan: '.($r['plan'] ?: '-').' | Verification: '.($r['verify_state'] ?: '-');
            $lines[] = 'Joined: '.($r['created_at'] ?: '-').' | Last seen: '.($r['last_login_at'] ?: '-');
            $lines[] = '';
        }
        $pdf = $this->simplePdf($lines);
        return $this->response->setContentType('application/pdf')->setHeader('Content-Disposition', 'attachment; filename="tenderhub-users.pdf"')->setBody($pdf);
    }

    private function exportRows(int $onlyUserId = 0): array
    {
        $db = db_connect();
        $hasDiscoverySource = $db->fieldExists('discovery_source', 'users');
        $discoverySelect = $hasDiscoverySource ? ', users.discovery_source' : '';
        $builder = $db->table('users')
            ->select('users.id, users.name, users.email, users.phone, users.nic, users.email_verified_at, users.role, users.user_group, users.status, users.created_at, users.last_login_at' . $discoverySelect . ', organisations.id AS org_id, organisations.name AS org_name, organisations.reg_no AS org_reg_no, organisations.type AS org_type, organisations.plan, organisations.verify_state')
            ->join('organisations', 'organisations.id = users.org_id', 'left')
            ->orderBy('users.created_at', 'DESC');
        if ($onlyUserId > 0) { $builder->where('users.id', $onlyUserId); }
        // Select only the earliest registration profile and latest KYC in the
        // directory query instead of issuing two more queries for every user.
        $builder->select('registration.name AS registration_profile, latest_kyc.notes AS kyc_notes')
            ->join('alert_profiles registration', "registration.id = (SELECT MIN(ap.id) FROM alert_profiles ap WHERE ap.user_id = users.id AND ap.name LIKE 'Alerts: %')", 'left', false)
            ->join('kyc_submissions latest_kyc', 'latest_kyc.id = (SELECT MAX(ks.id) FROM kyc_submissions ks WHERE ks.org_id = users.org_id)', 'left', false);
        $rows = $builder->get()->getResultArray();
        foreach ($rows as &$row) {
            $row['registration_category'] = $row['registration_profile']
                ? (trim(substr((string) $row['registration_profile'], strlen('Alerts: '))) ?: '')
                : '';
            $row['discovery_source'] = $row['discovery_source'] ?? '';
            $payload = json_decode((string) ($row['kyc_notes'] ?? ''), true);
            $row['kyc_company'] = is_array($payload) ? ($payload['company_name'] ?? '') : '';
            $row['kyc_reg_no'] = is_array($payload) ? ($payload['reg_no'] ?? '') : '';
            $row['kyc_nic'] = is_array($payload) ? ($payload['nic'] ?? '') : '';
            unset($row['registration_profile'], $row['kyc_notes']);
        }
        return $rows;
    }

    private function exportHeaders(): array
    {
        return ['User ID','Name','Email','Phone','NIC','Business / organisation','Business registration no.','Registered category','Discovery source','Group','Role','Status','Email verified','Org type','Plan','Verification state','Joined','Last seen','KYC company','KYC BRN','KYC NIC'];
    }

    private function exportDataRows(array $rows): array
    {
        return array_map(static fn(array $r): array => [
            $r['id'], $r['name'], $r['email'], $r['phone'], $r['nic'], $r['org_name'], $r['org_reg_no'],
            $r['registration_category'], $r['discovery_source'], $r['user_group'], $r['role'], $r['status'],
            $r['email_verified_at'], $r['org_type'], $r['plan'], $r['verify_state'], $r['created_at'],
            $r['last_login_at'], $r['kyc_company'], $r['kyc_reg_no'], $r['kyc_nic'],
        ], $rows);
    }

    private function simplePdf(array $lines): string
    {
        $pages = array_chunk($lines ?: [''], 52);
        $kids = [];
        foreach ($pages as $index => $_) $kids[] = (4 + $index * 2) . ' 0 R';
        $objects = [
            "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
            "2 0 obj << /Type /Pages /Kids [" . implode(' ', $kids) . '] /Count ' . count($pages) . " >> endobj\n",
            "3 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n",
        ];
        foreach ($pages as $index => $page) {
            $content = "BT /F1 10 Tf 36 806 Td\n";
            foreach ($page as $line) {
                $line = str_replace(["\r", "\n"], ' ', (string) $line);
                $safe = str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], mb_substr($line, 0, 120));
                $content .= '(' . $safe . ") Tj 0 -14 Td\n";
            }
            $content .= 'ET';
            $pageId = 4 + $index * 2;
            $contentId = $pageId + 1;
            $objects[] = "$pageId 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents $contentId 0 R >> endobj\n";
            $objects[] = "$contentId 0 obj << /Length " . strlen($content) . " >> stream\n" . $content . "\nendstream endobj\n";
        }
        $pdf = "%PDF-1.4\n"; $offsets = [0];
        foreach ($objects as $obj) { $offsets[] = strlen($pdf); $pdf .= $obj; }
        $xref = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= "xref\n0 $size\n0000000000 65535 f \n";
        for ($i=1;$i<$size;$i++) { $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]); }
        $pdf .= "trailer << /Size $size /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $pdf;
    }

    public function show(int $id)
    {
        $db   = db_connect();
        $user = $db->table('users')
            ->select('users.*, organisations.name AS org_name, organisations.type AS org_type, organisations.plan, organisations.verify_state')
            ->join('organisations', 'organisations.id = users.org_id', 'left')
            ->where('users.id', $id)
            ->get()->getFirstRow('array');

        if (! $user) {
            return problem(404, 'not_found', 'No such user.');
        }
        unset($user['password_hash'], $user['otp_hash'], $user['otp_expires_at']);
        $user['id'] = (int) $user['id'];
        $user['is_primary_owner'] = PrimaryOwner::isPrimaryOwner($user['email'] ?? null);

        return $this->ok($user);
    }

    /** Explicit administrator override of email verification, not organisation KYC. */
    public function verify(int $id)
    {
        if ($denied = $this->requireAdministrator()) { return $denied; }
        $db = db_connect();
        $user = $db->table('users')->where('id', $id)->get()->getFirstRow('array');
        if (! $user) { return problem(404, 'not_found', 'No such user.'); }
        if (! in_array($user['user_group'], ['bidder', 'company'], true)) {
            return problem(403, 'cannot_verify_staff', 'Manual verification is available only for bidder and company accounts.');
        }
        if ($user['status'] === 'suspended') {
            return problem(409, 'account_suspended', 'Reactivate this account before verifying it.');
        }
        if ($user['email_verified_at']) {
            return $this->ok(['id' => $id, 'email_verified_at' => $user['email_verified_at'], 'already_verified' => true]);
        }
        $now = date('Y-m-d H:i:s');
        $actor = $db->table('users')->where('id', (int) $this->request->userId)->get()->getFirstRow('array');
        $db->transBegin();
        try {
            $db->table('users')->where('id', $id)->where('email_verified_at', null)
                ->update(['email_verified_at' => $now, 'updated_at' => $now]);
            if ($db->affectedRows() > 0) {
                $db->table('email_verifications')->where('email', $user['email'])->delete();
                service('eventLedger')->record('user', $id, 'user.email_verified_manually',
                    'Account email manually verified by administrator', ['user_id' => $id, 'method' => 'administrator_override'],
                    ['id' => (int) $actor['id'], 'name' => $actor['name'], 'role' => $actor['role'], 'org' => (int) $actor['org_id']]);
            }
            if (! $db->transStatus()) { throw new \RuntimeException('Verification update failed'); }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Manual user verification failed for user {id}: {message}', ['id' => $id, 'message' => $e->getMessage()]);
            return problem(500, 'verification_failed', 'Verification could not be saved. Please try again.');
        }
        $updated = $db->table('users')->select('email_verified_at')->where('id', $id)->get()->getFirstRow('array');
        return $this->ok(['id' => $id, 'email_verified_at' => $updated['email_verified_at']]);
    }

    public function suspend(int $id)
    {
        if ($denied = $this->requireAdministrator()) { return $denied; }
        $db   = db_connect();
        $user = $db->table('users')->where('id', $id)->get()->getFirstRow('array');
        if (! $user) {
            return problem(404, 'not_found', 'No such user.');
        }
        if (PrimaryOwner::isPrimaryOwner($user['email'] ?? null)) {
            return problem(403, 'protected_owner', 'The primary owner account cannot be suspended.');
        }
        // Never suspend another staff member — that would lock admins out
        if ($user['user_group'] === 'staff') {
            return problem(403, 'cannot_suspend_staff', 'Staff accounts cannot be suspended via this endpoint.');
        }
        $db->table('users')->where('id', $id)->update(['status' => 'suspended', 'updated_at' => date('Y-m-d H:i:s')]);

        service('eventLedger')->record('user', $id, 'user.suspended', "User #{$id} suspended by staff #{$this->request->userId}", [
            'user_id' => $id,
            'actor'   => (int) $this->request->userId,
        ]);

        return $this->ok(['status' => 'suspended']);
    }

    /**
     * POST admin/users/{id}/convert-to-bidder
     *
     * A company (buyer) account that should be a bidder: the user moves to the
     * bidder portal and the organisation becomes a bidder organisation on the
     * bidder plan (business), exactly as bidder signup creates it. Trial
     * dates, subscription status, KYC, profile, alert preferences and
     * history are untouched. Refused when the organisation has other members
     * or its own tenders (converting would change them too), and never for
     * staff. Ledgered with before/after values.
     */
    public function convertToBidder(int $id)
    {
        if ($denied = $this->requireAdministrator()) { return $denied; }
        $db   = db_connect();
        $user = $db->table('users')->where('id', $id)->get()->getFirstRow('array');
        if (! $user) {
            return problem(404, 'not_found', 'No such user.');
        }
        if (PrimaryOwner::isPrimaryOwner($user['email'] ?? null)) {
            return problem(403, 'protected_owner', 'The primary owner account cannot be converted.');
        }
        if ($user['user_group'] === 'bidder') {
            return problem(409, 'already_bidder', 'This account is already a bidder account.');
        }
        if ($user['user_group'] !== 'company') {
            return problem(403, 'not_convertible', 'Only company accounts can be converted to bidder accounts.');
        }
        $org = $db->table('organisations')->where('id', (int) $user['org_id'])->get()->getFirstRow('array');
        if (! $org) {
            return problem(409, 'no_organisation', 'The account has no organisation.');
        }
        if ($db->table('users')->where('org_id', (int) $org['id'])->where('id !=', $id)->countAllResults() > 0) {
            return problem(409, 'org_has_other_members', 'The organisation has other members; converting it would change their accounts too.');
        }
        if ($db->table('procurements')->where('org_id', (int) $org['id'])->countAllResults() > 0) {
            return problem(409, 'org_has_tenders', 'The organisation has published or draft tenders as a buyer and cannot become a bidder organisation.');
        }

        $before = ['user_group' => $user['user_group'], 'org_type' => $org['type'], 'plan' => $org['plan']];
        $after  = ['user_group' => 'bidder', 'org_type' => 'bidder', 'plan' => $org['plan'] === 'publish' ? 'business' : $org['plan']];
        $now = date('Y-m-d H:i:s');
        $db->transStart();
        $db->table('users')->where('id', $id)->update(['user_group' => 'bidder', 'updated_at' => $now]);
        $db->table('organisations')->where('id', (int) $org['id'])->update(['type' => 'bidder', 'plan' => $after['plan'], 'updated_at' => $now]);
        $db->transComplete();

        service('eventLedger')->record('user', $id, 'user.converted_to_bidder', "User #{$id} converted from company to bidder by staff #{$this->request->userId}", [
            'user_id' => $id, 'org_id' => (int) $org['id'], 'actor' => (int) $this->request->userId,
            'before' => $before, 'after' => $after,
            'preserved' => ['trial_started_at' => $org['trial_started_at'], 'renews_at' => $org['renews_at'], 'sub_status' => $org['sub_status'], 'verify_state' => $org['verify_state'], 'role' => $user['role']],
        ]);

        return $this->ok(['user_id' => $id, 'before' => $before, 'after' => $after,
            'note' => 'API access changes immediately; the web portal switches at the next session refresh (within 15 minutes) or sign-in.']);
    }

    public function reactivate(int $id)
    {
        if ($denied = $this->requireAdministrator()) { return $denied; }
        $db   = db_connect();
        $user = $db->table('users')->where('id', $id)->get()->getFirstRow('array');
        if (! $user) {
            return problem(404, 'not_found', 'No such user.');
        }
        $db->table('users')->where('id', $id)->update(['status' => 'active', 'updated_at' => date('Y-m-d H:i:s')]);

        service('eventLedger')->record('user', $id, 'user.reactivated', "User #{$id} reactivated by staff #{$this->request->userId}", [
            'user_id' => $id,
            'actor'   => (int) $this->request->userId,
        ]);

        return $this->ok(['status' => 'active']);
    }

    public function create()
    {
        if ($denied = $this->requireAdministrator()) { return $denied; }
        $body = $this->body();
        $name = trim($body['name'] ?? '');
        $email = strtolower(trim($body['email'] ?? ''));
        $password = $body['password'] ?? '';
        $role = trim($body['role'] ?? 'admin');
        $userGroup = trim($body['user_group'] ?? 'staff');
        $phone = !empty($body['phone']) ? trim($body['phone']) : null;
        $nic   = !empty($body['nic']) ? trim($body['nic']) : null;

        if (empty($name)) {
            return problem(400, 'validation_failed', 'Full name is required.');
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return problem(400, 'validation_failed', 'A valid email address is required.');
        }

        if (empty($password) || strlen($password) < 8) {
            return problem(400, 'validation_failed', 'Password must be at least 8 characters long.');
        }

        if ($userGroup !== 'staff' || !in_array($role, ['admin', 'editor', 'viewer'], true)) {
            return problem(422, 'validation_failed', 'Select a valid staff role.');
        }

        $db = db_connect();

        // Check if user already exists
        $existing = $db->table('users')->where('email', $email)->get()->getFirstRow('array');
        if ($existing) {
            return problem(409, 'conflict', 'A user with this email address already exists.');
        }

        // Determine organization ID
        $staffOrg = $db->table('organisations')->where('type', 'staff')->where('plan', 'staff')->get()->getFirstRow('array');
        if (! $staffOrg) {
            return problem(503, 'staff_org_unavailable', 'The staff organisation is not configured.');
        }
        $orgId = (int) $staffOrg['id'];

        $now = date('Y-m-d H:i:s');
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $data = [
            'org_id'            => $orgId,
            'name'              => $name,
            'email'             => $email,
            'phone'             => $phone,
            'nic'               => $nic,
            'password_hash'     => $hash,
            'role'              => $role,
            'user_group'        => $userGroup,
            'status'            => 'active',
            'email_verified_at' => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];

        $db->table('users')->insert($data);
        $newId = (int) $db->insertID();

        service('eventLedger')->record('user', $newId, 'user.created', "User #{$newId} ({$email}) created by administrator #{$this->request->userId}", [
            'user_id' => $newId,
            'email'   => $email,
            'role'    => $role,
            'group'   => $userGroup,
            'actor'   => (int) $this->request->userId,
        ]);

        unset($data['password_hash']);
        $data['id'] = $newId;

        return $this->ok($data, [], 201);
    }

    public function delete(int $id)
    {
        if ($denied = $this->requireAdministrator()) { return $denied; }
        $db = db_connect();
        $user = $db->table('users')->where('id', $id)->get()->getFirstRow('array');
        if (! $user) {
            return problem(404, 'not_found', 'No such user.');
        }

        if ($id === (int) $this->request->userId) {
            return problem(400, 'cannot_delete_self', 'You cannot delete your own active administrator account.');
        }

        if (PrimaryOwner::isPrimaryOwner($user['email'] ?? null)) {
            return problem(403, 'protected_owner', 'The primary owner account cannot be deleted.');
        }

        $db->transBegin();
        try {
            $db->table('refresh_tokens')->where('user_id', $id)->delete();
            $db->table('alert_profiles')->where('user_id', $id)->delete();
            $db->table('notifications')->where('user_id', $id)->delete();
            $db->table('document_downloads')->where('user_id', $id)->delete();
            // A deleted user's stored WorkSuite key must not outlive them.
            if ($db->tableExists('worksuite_connections')) {
                $db->table('worksuite_connections')->where('user_id', $id)->where('status !=', 'revoked')
                    ->update(['status' => 'revoked', 'api_key_cipher' => null, 'revoked_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            }
            $db->table('users')->where('id', $id)->delete();
            if (! $db->transStatus()) {
                throw new \RuntimeException('Account deletion failed');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            return problem(409, 'cannot_delete', 'This user has related records (e.g. audit logs, documents) and cannot be hard deleted. Suspend them instead.');
        }

        service('eventLedger')->record('user', $id, 'user.deleted', "User #{$id} ({$user['email']}) deleted by administrator #{$this->request->userId}", [
            'user_id' => $id,
            'email'   => $user['email'],
            'actor'   => (int) $this->request->userId,
        ]);

        return $this->ok(['status' => 'deleted', 'id' => $id]);
    }
}
