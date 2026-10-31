<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Kyc\KycWorkflow as Kyc;
use App\Libraries\Kyc\KycRequirements;
use App\Models\OrganisationModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Staff review of KYC submissions — the ONLY path to a verified organisation.
 *
 * Routes sit behind the admin filter chain (auth-jwt, tenant, group:staff,
 * staff-role, entitlement:admin): customers can never reach them. Reading is
 * open to staff; every state change needs an active owner/admin (StaffRole
 * for mutations, re-checked here against the database).
 *
 * Approval requires: an open submission, every required document present and
 * intact on disk, the identity fields, and an explicit "documents reviewed"
 * confirmation. Every decision writes its audit entry (previous and new
 * status, reviewer, reason) inside the same transaction as the decision, so a
 * decision without an audit record cannot be committed.
 */
class KycController extends BaseApiController
{
    public function pending(): ResponseInterface
    {
        $status = (string) ($this->request->getGet('status') ?? 'pending');
        if (! in_array($status, [...Kyc::STATUSES, 'open', 'all'], true)) {
            return problem(422, 'bad_status', 'Choose ' . implode(', ', Kyc::STATUSES) . ', open or all.');
        }
        $builder = db_connect()->table('kyc_submissions')
            ->select('kyc_submissions.*, organisations.name AS org_name, organisations.verify_state, users.name AS submitted_name, users.email AS submitted_email')
            ->join('organisations', 'organisations.id = kyc_submissions.org_id')
            ->join('users', 'users.id = kyc_submissions.submitted_by', 'left');
        if ($status === 'open') {
            $builder->whereIn('kyc_submissions.status', Kyc::OPEN);
        } elseif ($status !== 'all') {
            $builder->where('kyc_submissions.status', $status);
        }
        $rows = $builder->orderBy('kyc_submissions.id', 'DESC')->get()->getResultArray();

        return $this->ok(array_map(fn (array $row): array => $this->withDocuments($row), $rows));
    }

    /** GET admin/kyc/{id} — everything an administrator needs to decide. */
    public function show(int $id): ResponseInterface
    {
        $db  = db_connect();
        $row = $db->table('kyc_submissions')
            ->select('kyc_submissions.*, organisations.name AS org_name, organisations.verify_state, users.name AS submitted_name, users.email AS submitted_email')
            ->join('organisations', 'organisations.id = kyc_submissions.org_id')
            ->join('users', 'users.id = kyc_submissions.submitted_by', 'left')
            ->where('kyc_submissions.id', $id)->get()->getFirstRow('array');
        if (! $row) {
            return problem(404, 'not_found', 'No such submission.');
        }
        $sub = $this->withDocuments($row);

        $applicant = $sub['submitted_by'] ? $db->table('users')
            ->select('id, name, email, phone, nic, user_group, role, status, email_verified_at, created_at, last_login_at')
            ->where('id', (int) $sub['submitted_by'])->get()->getFirstRow('array') : null;
        if ($applicant) {
            $profile = $db->table('alert_profiles')->select('name')->where('user_id', (int) $applicant['id'])
                ->like('name', 'Alerts: ', 'after')->orderBy('id', 'ASC')->get()->getFirstRow('array');
            $applicant['registration_category'] = $profile ? (trim(substr($profile['name'], strlen('Alerts: '))) ?: null) : null;
        }
        $organisation = $db->table('organisations o')
            ->select('o.id, o.name, o.type, o.entity_type, o.reg_no, o.cida_grade, o.contact_email, o.contact_phone, o.verify_state, o.verified_at, o.plan, o.created_at, d.name AS district')
            ->join('districts d', 'd.id = o.district_id', 'left')
            ->where('o.id', (int) $sub['org_id'])->get()->getFirstRow('array');

        $submissions = $db->table('kyc_submissions')
            ->select('id, status, submitted_at, review_started_name, review_started_at, reviewer_name, reviewed_at, reason')
            ->where('org_id', (int) $sub['org_id'])->orderBy('id', 'DESC')->get()->getResultArray();
        $audit = $db->table('event_ledger')
            ->select('id, event_type, summary, actor_name, actor_role, payload, created_at')
            ->where('entity_type', 'org')->where('entity_id', (int) $sub['org_id'])
            ->groupStart()->like('event_type', 'kyc.', 'after')->orWhere('event_type', 'org.verify_state')->groupEnd()
            ->orderBy('id', 'DESC')->limit(100)->get()->getResultArray();
        foreach ($audit as &$event) {
            $event['payload'] = json_decode((string) $event['payload'], true) ?: [];
        }
        unset($event);

        return $this->ok($sub + [
            'applicant'    => $applicant,
            'organisation' => $organisation,
            'readiness'    => $this->readiness($sub),
            'history'      => ['submissions' => $submissions, 'audit' => $audit],
        ]);
    }

    /**
     * GET admin/kyc/documents/{id}[?inline=1] — a KYC document, to staff only.
     * Never a public URL: served through the authenticated admin route, from
     * private storage, integrity-checked, not cached.
     */
    public function document(int $assetId): ResponseInterface
    {
        $doc = db_connect()->table('document_assets')->where('id', $assetId)->get()->getFirstRow('array');
        if (! $doc || empty($doc['path']) || ! str_starts_with((string) $doc['path'], 'kyc_documents/')) {
            return problem(404, 'not_found', 'No such KYC document.');
        }
        $path = $this->filePath($doc);
        if (! is_file($path)) {
            return problem(404, 'not_found', 'KYC document file is unavailable.');
        }
        $bytes = file_get_contents($path);
        if (! empty($doc['sha256']) && ! hash_equals((string) $doc['sha256'], hash('sha256', $bytes))) {
            return problem(500, 'integrity_check_failed', 'Document integrity check failed.');
        }
        $mime = mime_content_type($path) ?: 'application/octet-stream';
        $inline = $this->request->getGet('inline') === '1' && in_array($mime, Kyc::VIEWABLE_MIME, true);
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string) ($doc['name'] ?? 'kyc-document'));

        return $this->response
            ->setStatusCode(200)
            ->setContentType($mime)
            ->setHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($bytes);
    }

    /** POST admin/kyc/{id}/start-review — pending -> under_review (who and when is recorded). */
    public function startReview(int $id): ResponseInterface
    {
        if ($denied = $this->requireAdministrator()) {
            return $denied;
        }
        $db  = db_connect();
        $sub = $db->table('kyc_submissions')->where('id', $id)->get()->getFirstRow('array');
        if (! $sub) {
            return problem(404, 'not_found', 'No such submission.');
        }
        if ($sub['status'] === Kyc::UNDER_REVIEW) {
            return $this->ok(['submission_id' => $id, 'status' => Kyc::UNDER_REVIEW, 'already' => true]);
        }
        if ($sub['status'] !== Kyc::PENDING) {
            return problem(409, 'not_pending', 'Only a pending submission can be taken into review.');
        }
        $claims = (array) $this->request->claims;
        $db->transBegin();
        try {
            $db->table('kyc_submissions')->where('id', $id)->where('status', Kyc::PENDING)->update([
                'status' => Kyc::UNDER_REVIEW, 'review_started_by' => (int) $this->request->userId,
                'review_started_name' => $claims['nm'] ?? null, 'review_started_at' => date('Y-m-d H:i:s'),
            ]);
            if ($db->affectedRows() !== 1) {
                $db->transRollback();
                return problem(409, 'state_changed', 'This submission changed. Reload it.');
            }
            service('eventLedger')->record('org', (int) $sub['org_id'], 'kyc.review_started', 'KYC review started', [
                'submission_id' => $id, 'previous_status' => Kyc::PENDING, 'new_status' => Kyc::UNDER_REVIEW,
                'reviewer_id' => (int) $this->request->userId,
            ]);
            if (! $db->transStatus()) {
                throw new \RuntimeException('start review failed');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'KYC start-review failed: {message}', ['message' => $e->getMessage()]);
            return problem(500, 'review_failed', 'The review could not be started. Please try again.');
        }

        return $this->ok(['submission_id' => $id, 'status' => Kyc::UNDER_REVIEW]);
    }

    /**
     * POST admin/kyc/{id}/review
     * { action: approve | reject | request_resubmission, reason?, confirm_reviewed? }
     */
    public function review(int $id): ResponseInterface
    {
        if ($denied = $this->requireAdministrator()) {
            return $denied;
        }
        $db  = db_connect();
        $sub = $db->table('kyc_submissions')->where('id', $id)->get()->getFirstRow('array');
        if (! $sub) {
            return problem(404, 'not_found', 'No such submission.');
        }
        if (! in_array($sub['status'], Kyc::OPEN, true)) {
            return problem(409, 'already_reviewed', 'This submission has already been decided (' . $sub['status'] . ').');
        }

        $in     = $this->body();
        $action = (string) ($in['action'] ?? '');
        if (! isset(Kyc::ACTIONS[$action])) {
            return problem(422, 'bad_action', 'action must be approve, reject or request_resubmission.');
        }
        $reason = trim((string) ($in['reason'] ?? ''));
        if ($action !== 'approve' && mb_strlen($reason) < 5) {
            return problem(422, 'reason_required', 'Give the reason the organisation will see (at least 5 characters).');
        }
        if ($action === 'approve') {
            if (($in['confirm_reviewed'] ?? null) !== true) {
                return problem(422, 'review_confirmation_required', 'Confirm that you reviewed the submitted information and every document.');
            }
            $readiness = $this->readiness($this->withDocuments($sub));
            if (! $readiness['approvable']) {
                return problem(422, 'submission_incomplete', 'This submission cannot be approved: ' . implode(' ', $readiness['problems']), [
                    'missing_documents' => $readiness['missing_documents'],
                ]);
            }
        }

        $org = model(OrganisationModel::class)->find((int) $sub['org_id']);
        if (! $org) {
            return problem(404, 'not_found', 'The organisation for this submission no longer exists.');
        }
        $newStatus = Kyc::ACTIONS[$action];
        $newOrgState = Kyc::ORG_STATE[$newStatus];
        $claims = (array) $this->request->claims;
        $now = date('Y-m-d H:i:s');

        $db->transBegin();
        try {
            $db->table('kyc_submissions')->where('id', $id)->whereIn('status', Kyc::OPEN)->update([
                'status' => $newStatus,
                'reviewer_id' => (int) $this->request->userId, 'reviewer_name' => $claims['nm'] ?? null,
                'reason' => $reason !== '' ? $reason : null, 'reviewed_at' => $now,
            ]);
            if ($db->affectedRows() !== 1) {
                $db->transRollback();
                return problem(409, 'already_reviewed', 'This submission has already been decided. Reload it.');
            }
            model(OrganisationModel::class)->update((int) $sub['org_id'], array_merge(
                ['verify_state' => $newOrgState],
                $newStatus === Kyc::APPROVED ? ['verified_at' => $now] : ['verified_at' => null],
            ));
            // The audit entry is part of the decision: if it cannot be written,
            // the decision is rolled back.
            service('eventLedger')->record('org', (int) $sub['org_id'], 'kyc.' . $action, match ($action) {
                'approve' => 'KYC approved — organisation verified',
                'reject'  => 'KYC rejected',
                default   => 'KYC resubmission requested',
            }, [
                'submission_id'      => $id,
                'previous_status'    => $sub['status'],
                'new_status'         => $newStatus,
                'previous_org_state' => $org['verify_state'],
                'new_org_state'      => $newOrgState,
                'reason'             => $reason !== '' ? $reason : null,
                'reviewer_id'        => (int) $this->request->userId,
                'reviewer_name'      => $claims['nm'] ?? null,
                'documents_reviewed' => $action === 'approve' ? array_column($this->withDocuments($sub)['documents'], 'id') : null,
            ]);
            if (! $db->transStatus()) {
                throw new \RuntimeException('KYC review failed');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'KYC review failed: {message}', ['message' => $e->getMessage()]);
            return problem(500, 'review_failed', 'The decision could not be saved, so nothing changed. Please try again.');
        }

        $this->notifySubmitter($sub, $action, $reason);

        return $this->ok(['submission_id' => $id, 'status' => $newStatus, 'org_id' => (int) $sub['org_id'], 'verify_state' => $newOrgState]);
    }

    public function suspend(int $orgId): ResponseInterface
    {
        if ($denied = $this->requireAdministrator()) {
            return $denied;
        }
        $org = model(OrganisationModel::class)->find($orgId);
        if (! $org) {
            return problem(404, 'not_found', 'No such organisation.');
        }
        $reason = trim((string) ($this->body()['reason'] ?? ''));
        model(OrganisationModel::class)->update($orgId, ['verify_state' => 'suspended']);
        service('eventLedger')->record('org', $orgId, 'kyc.suspended', 'Organisation verification suspended', [
            'previous_org_state' => $org['verify_state'], 'new_org_state' => 'suspended',
            'reason' => $reason !== '' ? $reason : null, 'reviewer_id' => (int) $this->request->userId,
        ]);

        return $this->ok(['org_id' => $orgId, 'verify_state' => 'suspended']);
    }

    // ------------------------------------------------------------------

    /** Can this submission be approved, and if not, exactly why. */
    private function readiness(array $sub): array
    {
        $problems = [];
        $present = [];
        foreach ($sub['documents'] as $doc) {
            if ($doc['available'] && $doc['intact']) {
                $present[$doc['kind']] = true;
            }
        }
        $required = $this->requiredDocuments($sub);
        $missing = array_values(array_diff_key($required, $present));
        if ($missing !== []) {
            $problems[] = 'Required document(s) missing or unreadable: ' . implode(', ', $missing) . '.';
        }
        $payload = $sub['kyc_payload'] ?? [];
        foreach (['reg_no' => 'business registration number', 'nic' => 'signatory NIC'] as $field => $label) {
            if (trim((string) ($payload[$field] ?? '')) === '') {
                $problems[] = "The submission has no {$label}.";
            }
        }
        if (! in_array($sub['status'], Kyc::OPEN, true)) {
            $problems[] = "The submission is already {$sub['status']}.";
        }

        return ['approvable' => $problems === [], 'problems' => $problems, 'missing_documents' => $missing,
            'required_documents' => array_values($required)];
    }

    private function withDocuments(array $row): array
    {
        $payload = json_decode((string) ($row['notes'] ?? ''), true);
        $assetIds = is_array($payload['document_asset_ids'] ?? null) ? array_map('intval', $payload['document_asset_ids']) : [];
        $row['kyc_payload'] = is_array($payload) ? $payload : null;
        $row['documents'] = [];
        if ($assetIds !== []) {
            $docs = db_connect()->table('document_assets')
                ->select('id, name, kind, size_bytes, sha256, path, created_at')
                ->whereIn('id', $assetIds)
                ->orderBy('id', 'ASC')->get()->getResultArray();
            foreach ($docs as $doc) {
                $path = $this->filePath($doc);
                $available = is_file($path);
                $mime = $available ? (mime_content_type($path) ?: null) : null;
                $row['documents'][] = [
                    'id'          => (int) $doc['id'],
                    'name'        => $doc['name'],
                    'kind'        => $doc['kind'],
                    'label'       => Kyc::DOCUMENT_LABELS[$doc['kind']] ?? $doc['kind'],
                    'required'    => in_array($doc['kind'], array_keys($this->requiredDocuments($row)), true),
                    'mime'        => $mime,
                    'size_bytes'  => (int) $doc['size_bytes'],
                    'uploaded_at' => $doc['created_at'],
                    'available'   => $available,
                    'intact'      => $available && (empty($doc['sha256']) || hash_equals((string) $doc['sha256'], (string) hash_file('sha256', $path))),
                    'viewable'    => $available && in_array($mime, Kyc::VIEWABLE_MIME, true),
                    'url'         => '/api/admin/kyc/documents/' . (int) $doc['id'],
                ];
            }
        }

        return $row;
    }

    private function requiredDocuments(array $sub): array
    {
        $rawType = trim((string) ($sub['entity_type'] ?? ''));
        if ($rawType === '') return Kyc::REQUIRED_DOCUMENTS;
        $type = strtoupper($rawType);
        $policy = KycRequirements::policy($type);
        $labels = KycRequirements::documentLabels();
        $required = [];
        foreach ($policy['documents'] as $code) if (isset($labels[$code])) $required[$labels[$code]['kind']] = $labels[$code]['label'];
        // Pre-entity-type submissions were private-company submissions under the old policy.
        return $required ?: Kyc::REQUIRED_DOCUMENTS;
    }

    private function filePath(array $doc): string
    {
        return WRITEPATH . 'uploads/' . ltrim(str_replace('..', '', (string) $doc['path']), '/\\');
    }

    /** Decisions need an ACTIVE owner/admin per the database, not only the token. */
    private function requireAdministrator(): ?ResponseInterface
    {
        $actor = db_connect()->table('users')->select('user_group, role, status')->where('id', (int) $this->request->userId)->get()->getFirstRow('array');
        if (! $actor || $actor['user_group'] !== 'staff' || $actor['status'] !== 'active'
            || ! in_array($actor['role'], \App\Filters\StaffRole::ADMIN_ROLES, true)) {
            return problem(403, 'forbidden', 'Only active TenderHub administrators can review KYC.');
        }

        return null;
    }

    private function notifySubmitter(array $sub, string $action, string $reason): void
    {
        if (empty($sub['submitted_by'])) {
            return;
        }
        $user = db_connect()->table('users')->select('user_group')->where('id', (int) $sub['submitted_by'])->get()->getFirstRow('array');
        $link = ($user['user_group'] ?? '') === 'company' ? '/workspace/kyc' : '/app/kyc';
        [$type, $title, $body] = match ($action) {
            'approve' => ['kyc_approve', 'Your organisation is verified', 'TenderHub reviewed your KYC documents and verified your organisation.'],
            'reject'  => ['kyc_reject', 'KYC submission was not approved', $reason],
            default   => ['kyc_resubmission', 'KYC resubmission required', 'Please correct the following and submit your KYC again: ' . $reason],
        };
        try {
            (new \App\Libraries\Notifications\NotificationService())->notify(
                (int) $sub['submitted_by'], (int) $sub['org_id'], $type, $title, $body, $link, ['in_app', 'email'],
            );
        } catch (\Throwable $e) {
            log_message('error', 'KYC notification failed after the decision was saved: {message}', ['message' => $e->getMessage()]);
        }
    }
}
