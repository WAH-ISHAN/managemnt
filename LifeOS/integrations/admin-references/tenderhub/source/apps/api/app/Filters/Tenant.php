<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** The org id rides on the request, resolved from the verified token — never
 *  from the query string or the body. Tenant isolation that a caller can
 *  influence is not isolation. */
class Tenant implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $orgId = (int) ($request->claims['org'] ?? 0);

        if ($orgId <= 0) {
            return problem(403, 'no_org_context', 'This account is not attached to an organisation.');
        }

        $request->orgId = $orgId;

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}
}
