<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Is this the right KIND of account? Runs BEFORE the entitlement filter on
 *  every group — see Config\Filters. A bidder who reaches a workspace URL is
 *  the wrong role, not a customer who needs a bigger plan. */
class Group implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $want = $arguments[0] ?? '';
        $have = (string) ($request->claims['grp'] ?? '');

        // Staff (platform administrators) have overarching access to all groups
        if ($have !== $want && $have !== 'staff') {
            return problem(403, 'forbidden', 'This area is for ' . $want . ' accounts.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}
}
