<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

abstract class BaseApiController extends Controller
{
    protected $helpers = ['api'];

    protected function ok(mixed $data, array $meta = [], int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)
            ->setContentType('application/json')
            ->setBody(json_encode(envelope($data, $meta), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function body(): array
    {
        try {
            $raw = (string) $this->request->getBody();
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
            }
            $json = $this->request->getJSON(true);

            return is_array($json) ? $json : ($this->request->getPost() ?: []);
        } catch (\Throwable) {
            return $this->request->getPost() ?: [];
        }
    }

    /**
     * Override validateData to reset the shared validator service before each run,
     * preventing error state leakage across multiple requests in the same process.
     */
    protected function validateData(array $data, $rules, array $messages = [], ?string $dbGroup = null): bool
    {
        $this->validator = service('validation');
        $this->validator->reset();
        $this->validator->setRules($rules, $messages);

        return $this->validator->run($data, null, $dbGroup);
    }

    /**
     * Repeated query parameters.
     *
     * PHP collapses ?district=1&district=2 to the LAST value unless the key is
     * written district[]. Every multi-select facet silently filtered on one
     * value while appearing to work — two districts returned 4 notices where it
     * should have returned 13. We parse the raw query string ourselves and
     * accept all three spellings: repeated, bracketed, and comma-separated.
     *
     * @return string[]
     */
    protected function multi(string $key): array
    {
        $raw = $_SERVER['QUERY_STRING'] ?? '';
        $out = [];

        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $k = urldecode($k);
            if ($k !== $key && $k !== $key . '[]') {
                continue;
            }
            foreach (explode(',', urldecode($v)) as $piece) {
                $piece = trim($piece);
                if ($piece !== '') {
                    $out[] = $piece;
                }
            }
        }

        return array_values(array_unique($out));
    }

    protected function page(): int
    {
        return max(1, (int) ($this->request->getGet('page') ?? 1));
    }

    protected function per(int $default = 20, int $max = 1000): int
    {
        $requested = $this->request->getGet('per_page') ?? $this->request->getGet('limit') ?? $default;
        return min($max, max(1, (int) $requested));
    }
}
