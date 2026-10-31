<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Correlates API requests with the BFF without logging tokens or bodies. */
class RequestTrace implements FilterInterface
{
    private float $startedAt = 0;
    private string $requestId = '';

    public function before(RequestInterface $request, $arguments = null)
    {
        $this->startedAt = microtime(true);
        $incoming = $request->getHeaderLine('X-Request-ID');
        $this->requestId = preg_match('/^[0-9a-f-]{36}$/i', $incoming) ? $incoming : bin2hex(random_bytes(16));
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('X-Request-ID', $this->requestId);
        $path = ltrim($request->getUri()->getPath(), '/');
        if (! str_starts_with($path, 'api/')) return;
        $durationMs = (int) round((microtime(true) - $this->startedAt) * 1000);
        if ($response->getStatusCode() < 500 && $durationMs < 1500) return;
        log_message('info', json_encode([
            'event' => 'api_request', 'request_id' => $this->requestId,
            'method' => $request->getMethod(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
        ], JSON_UNESCAPED_SLASHES));
    }
}
