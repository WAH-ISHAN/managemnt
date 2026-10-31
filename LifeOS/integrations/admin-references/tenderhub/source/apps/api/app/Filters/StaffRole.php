<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * WHICH staff member may change things. group:staff only answers "is this a
 * TenderHub operator?" — on its own it let a read-only 'viewer' confirm bank
 * payments (activating paid access with no money behind it), issue refunds,
 * approve KYC, suspend organisations and approve partner API keys.
 *
 * Reads stay open to every staff role. Mutations require an administrator,
 * except the content-moderation and import controls an 'editor' exists for.
 * The role comes from the database row AuthJwt re-reads on every request, so a
 * demotion takes effect immediately rather than when the token expires.
 */
class StaffRole implements FilterInterface
{
    public const ADMIN_ROLES = ['owner', 'admin'];

    /** Route-path patterns (relative to api/v1/) an editor may mutate. */
    private const EDITOR_MUTATIONS = [
        '#^admin/notices/\d+/(publish|merge)$#',
        '#^admin/ingest/sources/\d+/(run|pause|resume|collect)$#',
    ];

    public function before(RequestInterface $request, $arguments = null)
    {
        if (in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }

        $role = (string) ($request->claims['role'] ?? '');
        if (in_array($role, self::ADMIN_ROLES, true)) {
            return null;
        }

        if ($role === 'editor') {
            $path = $this->routePath($request);
            foreach (self::EDITOR_MUTATIONS as $pattern) {
                if (preg_match($pattern, $path) === 1) {
                    return null;
                }
            }
        }

        return problem(403, 'staff_role_forbidden', 'Your staff role does not allow this action.');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}

    private function routePath(RequestInterface $request): string
    {
        $uri  = $request->getUri();
        $path = $uri instanceof \CodeIgniter\HTTP\SiteURI ? $uri->getRoutePath() : $uri->getPath();
        $path = trim($path, '/');

        return str_starts_with($path, 'api/v1/') ? substr($path, 7) : $path;
    }
}
