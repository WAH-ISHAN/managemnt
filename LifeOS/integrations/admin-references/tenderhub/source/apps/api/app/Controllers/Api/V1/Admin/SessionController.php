<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Filters\StaffRole;

/** Live authorization for BFF services that use their own service credential. */
class SessionController extends BaseApiController
{
    public function show()
    {
        // AuthJwt has already re-read status, group and role from the database.
        $claims = (array) $this->request->claims;
        if (($claims['grp'] ?? '') !== 'staff' || !in_array($claims['role'] ?? '', StaffRole::ADMIN_ROLES, true)) {
            return problem(403, 'staff_role_forbidden', 'Administrator access is required.');
        }
        return $this->ok(['id' => (int) $this->request->userId, 'role' => $claims['role'], 'group' => 'staff']);
    }
}
