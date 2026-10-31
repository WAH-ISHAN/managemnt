<?php

namespace App\Filters;

use App\Libraries\OrganisationPortal;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Enforces the Organisation Portal switch on the server. Runs AFTER auth-jwt,
 * which re-reads the user's group from the database on every request, so a
 * change by an administrator applies to the very next call: no session is
 * revoked and no token has to expire.
 *
 * Only company-group users are affected; bidders and staff pass straight
 * through, so shared routes (account/*, me/alert-profiles, payments/*) keep
 * working exactly as before for them.
 */
class OrganisationPortalGuard implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $group = (string) ($request->claims['grp'] ?? '');
        if (! OrganisationPortal::blocks($group)) {
            return null;
        }
        if (OrganisationPortal::allowedWhileDisabled($request->getMethod(), $request->getUri()->getPath())) {
            return null;
        }

        return OrganisationPortal::refusal();
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}
}
