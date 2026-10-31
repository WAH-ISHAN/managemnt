<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class Throttle implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $limit  = (int) ($arguments[0] ?? 60);
        $bucket = 'throttle_' . md5($this->clientKey($request) . $request->getUri()->getPath());
        $cache  = service('cache');
        $hits   = (int) ($cache->get($bucket) ?? 0);

        if ($hits >= $limit) {
            $resp = problem(429, 'too_many_requests', 'Too many requests. Try again shortly.', [
                'retry_after' => 60,
            ]);
            return $resp->setHeader('Retry-After', '60');
        }

        $cache->save($bucket, $hits + 1, 60);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}

    /**
     * The real client identifier for rate limiting.
     *
     * Behind the BFF/nginx edge every request reaches the API from the same
     * upstream container, so getIPAddress() alone collapses all users into one
     * bucket — making the limit a platform-wide DoS lever and useless against
     * brute force (TH-SEC-003). The API is not publicly reachable (nginx :8080 is
     * unpublished; the BFF is the only caller), so the client IP forwarded by the
     * edge is trustworthy here. Fall back to the peer address when no forwarded
     * header is present (e.g. direct/local calls and unit tests).
     */
    private function clientKey(RequestInterface $request): string
    {
        $fwd = $request->getHeaderLine('X-Forwarded-For');
        if ($fwd !== '') {
            $first = trim(explode(',', $fwd)[0]);
            if ($first !== '') {
                return $first;
            }
        }

        return $request->getIPAddress();
    }
}
