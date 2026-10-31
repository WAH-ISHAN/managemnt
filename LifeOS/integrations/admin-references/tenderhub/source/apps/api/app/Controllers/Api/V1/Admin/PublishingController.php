<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\NoticePublishing;
use App\Libraries\SystemSettings;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public tender publishing switch (see NoticePublishing for the exact rules).
 * Reads are open to staff; changes require an active owner/admin.
 */
class PublishingController extends BaseApiController
{
    /** GET /api/v1/admin/publishing */
    public function status(): ResponseInterface
    {
        return $this->ok($this->shape());
    }

    /** POST /api/v1/admin/publishing  Body: { "enabled": true|false } */
    public function update(): ResponseInterface
    {
        if ($denied = $this->requireAdministrator()) {
            return $denied;
        }
        $body = $this->request->getJSON(true) ?? [];
        if (! array_key_exists('enabled', $body) || ! is_bool($body['enabled'])) {
            return problem(422, 'validation_failed', 'enabled must be true or false.');
        }
        $before = NoticePublishing::enabled();
        SystemSettings::set(SystemSettings::PUBLIC_TENDER_PUBLISHING, $body['enabled'] ? '1' : '0', (int) $this->request->userId);
        service('eventLedger')->record('settings', 0, 'settings.public_tender_publishing',
            'Public tender publishing switched ' . ($body['enabled'] ? 'on' : 'off') . '.', [
                'actor_id' => (int) $this->request->userId,
                'before'   => $before ? 'on' : 'off', 'after' => $body['enabled'] ? 'on' : 'off',
            ]);

        return $this->ok($this->shape());
    }

    /** POST /api/v1/admin/publishing/release-held — publish every held notice. */
    public function releaseHeld(): ResponseInterface
    {
        if ($denied = $this->requireAdministrator()) {
            return $denied;
        }
        if ($off = NoticePublishing::refuseIfDisabled()) {
            return $off;
        }
        $released = NoticePublishing::releaseHeld((int) $this->request->userId);

        return $this->ok(['released' => $released] + $this->shape());
    }

    private function shape(): array
    {
        $db = db_connect();

        return [
            'enabled'    => NoticePublishing::enabled(),
            'setting'    => SystemSettings::describe(SystemSettings::PUBLIC_TENDER_PUBLISHING),
            'held_count' => $db->table('notices')->where('status', NoticePublishing::HELD)->countAllResults(),
            'unverified_count' => $db->table('notices')->where('status', 'unverified')->countAllResults(),
            'public_count' => $db->table('notices')->where('status', 'published')->countAllResults(),
        ];
    }

    private function requireAdministrator(): ?ResponseInterface
    {
        $actor = db_connect()->table('users')->where('id', (int) $this->request->userId)->get()->getFirstRow('array');
        if (! $actor || $actor['user_group'] !== 'staff'
            || ! in_array($actor['role'], \App\Filters\StaffRole::ADMIN_ROLES, true) || $actor['status'] !== 'active') {
            return problem(403, 'forbidden', 'Only active administrators can change public publishing.');
        }

        return null;
    }
}
