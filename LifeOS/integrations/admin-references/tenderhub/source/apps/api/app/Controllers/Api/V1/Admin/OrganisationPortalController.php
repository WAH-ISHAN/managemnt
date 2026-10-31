<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Filters\StaffRole;
use App\Libraries\OrganisationPortal;
use App\Libraries\SystemSettings;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The Organisation Portal switch for platform administrators.
 *
 * GET  admin/portals/organisation  current state, who changed it and when,
 *                                  and how many organisation accounts it covers.
 * POST admin/portals/organisation  {"enabled": bool}; owners and admins only.
 *
 * Switching only changes access. Organisation accounts, tenders, documents,
 * contracts and bids are never modified, and every change is written to the
 * hash-chained event ledger.
 */
class OrganisationPortalController extends BaseApiController
{
    public function show(): ResponseInterface
    {
        return $this->ok($this->state());
    }

    public function update(): ResponseInterface
    {
        $actor = db_connect()->table('users')->where('id', (int) $this->request->userId)->get()->getFirstRow('array');
        if (! $actor || $actor['user_group'] !== 'staff' || ! in_array($actor['role'], StaffRole::ADMIN_ROLES, true) || $actor['status'] !== 'active') {
            return problem(403, 'forbidden', 'Only active administrators can change the Organisation Portal.');
        }
        $body = $this->request->getJSON(true) ?? [];
        if (! is_bool($body['enabled'] ?? null)) {
            return problem(422, 'validation_failed', 'enabled must be true or false.');
        }

        $before = OrganisationPortal::enabled();
        $after = $body['enabled'];
        SystemSettings::set(SystemSettings::ORGANISATION_PORTAL, $after ? '1' : '0', (int) $this->request->userId);
        service('eventLedger')->record('settings', 0, 'settings.organisation_portal',
            'Organisation Portal ' . ($after ? 'enabled' : 'disabled') . '.', [
                'actor_id' => (int) $this->request->userId,
                'before'   => $before ? 'enabled' : 'disabled',
                'after'    => $after ? 'enabled' : 'disabled',
            ]);

        return $this->ok($this->state());
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $db = db_connect();
        $setting = SystemSettings::describe(SystemSettings::ORGANISATION_PORTAL);
        $by = $setting['updated_by']
            ? $db->table('users')->select('name')->where('id', $setting['updated_by'])->get()->getFirstRow('array')['name'] ?? null
            : null;

        return [
            'enabled'      => OrganisationPortal::enabled(),
            'updated_at'   => $setting['updated_at'],
            'updated_by'   => $by,
            'message'      => OrganisationPortal::MESSAGE,
            'organisation_users' => $db->table('users')->where('user_group', 'company')->where('status', 'active')->countAllResults(),
            'organisations'      => $db->table('organisations')->where('type', 'company')->countAllResults(),
        ];
    }
}
