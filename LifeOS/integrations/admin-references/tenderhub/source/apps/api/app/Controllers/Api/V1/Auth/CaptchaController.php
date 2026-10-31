<?php

namespace App\Controllers\Api\V1\Auth;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Turnstile;

class CaptchaController extends BaseApiController
{
    public function config()
    {
        $enabled = Turnstile::enabled();
        $this->response->setHeader('Cache-Control', 'private, no-store');
        return $this->ok(['enabled' => $enabled, 'site_key' => $enabled ? (string) env('TURNSTILE_SITE_KEY', '') : null]);
    }
}
