<?php

namespace App\Filters;

use App\Libraries\Turnstile;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class Captcha implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (!Turnstile::enabled()) return null;
        try {
            $body = json_decode($request->getBody(), true);
            $token = is_array($body) ? ($body['captcha_token'] ?? null) : null;
            if ($token === null) {
                $token = $request->getPost('captcha_token');
            }
            if (!(new Turnstile())->verify($token, (string) ($arguments[0] ?? ''))) {
                return problem(403, 'captcha_failed', 'Please complete the security check and try again.');
            }
        } catch (\Throwable $error) {
            // Never log the request, response token or provider secret.
            log_message('error', 'CAPTCHA verification service unavailable.');
            return problem(503, 'captcha_unavailable', 'Security verification is temporarily unavailable. Please try again shortly.');
        }
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}
}
