<?php

namespace App\Controllers\Api\V1\Auth;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Jwt;
use App\Libraries\Validation\IdentityValidator;

class AuthController extends BaseApiController
{
    private const REFRESH_TTL = 2592000; // 30 days
    private const OTP_MAX_ATTEMPTS = 5;
    private const REFRESH_REUSE_GRACE = 30; // seconds

    public function register()
    {
        $in   = $this->body();
        $kind = ($in['account_type'] ?? 'bidder') === 'company' ? 'company' : 'bidder';

        if ($kind === 'company') {
            return problem(403, 'company_registration_disabled', 'Company registration is currently unavailable. Please contact TenderHub support.');
        }

        if (isset($in['email'])) {
            $in['email'] = IdentityValidator::normalizeEmail($in['email']);
        }

        $rules = [
            'name'     => 'required|min_length[2]',
            'email'    => 'required|valid_email',
            'password' => 'required|min_length[8]',
        ];
        if (! $this->validateData($in, $rules)) {
            return problem(422, 'validation_failed', 'Check the form.', ['errors' => $this->validator->getErrors()]);
        }

        $email = $in['email'];
        $emailCheck = IdentityValidator::validateEmailStrict($email);
        if (! $emailCheck['valid']) {
            return problem(422, $emailCheck['code'], $emailCheck['error']);
        }

        $pwdCheck = IdentityValidator::validatePassword($in['password'] ?? '');
        if (! $pwdCheck['valid']) {
            return problem(422, 'weak_password', $pwdCheck['error']);
        }

        $phone = trim((string) ($in['phone'] ?? '')) ?: null;
        $nic = trim((string) ($in['nic'] ?? '')) ?: null;
        if ($nic !== null && ! IdentityValidator::isValidNic($nic)) {
            return problem(422, 'invalid_nic', 'Invalid Sri Lankan National Identity Card (NIC) format. You can leave NIC empty and complete it in KYC.');
        }

        $users = model('App\Models\UserModel');
        if ($users->where('email', $email)->first()) {
            return problem(409, 'email_taken', 'That e-mail already has an account.');
        }

        $orgs = model('App\Models\OrganisationModel');
        $db   = db_connect('default');
        $db->transBegin();

        try {
            $now = date('Y-m-d H:i:s');
            $personName = trim((string) $in['name']);
            $defaultOrgName = trim((string) ($in['company_name'] ?? $in['org_name'] ?? ''));
            if ($defaultOrgName === '') {
                $mailbox = preg_replace('/[^a-z0-9]+/i', ' ', strtok($email, '@') ?: 'TenderHub User');
                $defaultOrgName = trim($mailbox) !== '' ? trim($mailbox) : $personName;
            }
            $slug = url_title($defaultOrgName, '-', true) . '-' . bin2hex(random_bytes(2));
            $trialEndsAt = date('Y-m-d H:i:s', strtotime('+60 days', strtotime($now)));
            $regNo = trim((string) ($in['company_br'] ?? $in['reg_no'] ?? $in['business_registration_number'] ?? '')) ?: null;

            $orgId = $orgs->insert([
                'name'          => $defaultOrgName,
                'slug'          => $slug,
                'type'          => $kind,
                'plan'          => $kind === 'company' ? 'publish' : 'business',
                'sub_status'    => 'active',
                'trial_started_at' => $now,
                'renews_at'     => $trialEndsAt,
                'district_id'   => $in['district_id'] ?? null,
                'reg_no'        => $regNo,
                'contact_email' => $email,
                'contact_phone' => $phone,
                'verify_state'  => 'verified',
                'verified_at'   => $now,
                'created_at'    => $now,
                'updated_at'    => $now,
            ], true);

            if (! $orgId) {
                throw new \RuntimeException('Failed to create organisation.');
            }

            $userData = [
                'org_id'        => $orgId,
                'name'          => $personName,
                'email'         => $email,
                'phone'         => $phone,
                'nic'           => $nic,
                'password_hash' => password_hash($in['password'], PASSWORD_DEFAULT),
                'role'          => $kind === 'company' ? 'owner' : 'user',
                'user_group'    => $kind,
                'status'        => 'active',
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
            // Keep older API clients compatible while storing the new field for
            // registrations that explicitly provide it.
            if (array_key_exists('discovery_source', $in)) {
                $userData['discovery_source'] = trim((string) $in['discovery_source']) ?: null;
            }
            $userId = $users->insert($userData, true);

            if (! $userId) {
                throw new \RuntimeException('Failed to create user account.');
            }

            if ($db->tableExists('user_acquisition') && is_array($in['acquisition'] ?? null)) {
                $acquisition = $this->normaliseAcquisition($in['acquisition']);
                $db->table('user_acquisition')->insert([
                    'user_id' => $userId,
                    ...$this->touchColumns('first', $acquisition['first_touch']),
                    ...$this->touchColumns('last', $acquisition['last_touch']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $categoryInput = trim((string) ($in['category'] ?? ''));
            if ($categoryInput !== '') {
                $resolvedSlugs = \App\Libraries\CategoryResolver::resolveSlugs($categoryInput);
                $db->table('alert_profiles')->insert([
                    'org_id'         => $orgId,
                    'user_id'        => $userId,
                    'name'           => "Alerts: {$categoryInput}",
                    'kinds'          => 'tender',
                    'category_slugs' => $resolvedSlugs !== [] ? implode(',', $resolvedSlugs) : null,
                    'channels'       => 'in_app,email',
                    'active'         => 1,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            }

            $rawToken  = bin2hex(random_bytes(24));
            $db->table('email_verifications')->insert([
                'email'      => $email,
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
                'created_at' => $now,
            ]);

            \App\Libraries\Notifications\RegistrationEmailQueue::enqueue((int) $userId, 'verification', $db);
            \App\Libraries\Notifications\RegistrationEmailQueue::enqueue((int) $userId, 'welcome', $db);
            if (! $db->transStatus()) {
                throw new \RuntimeException('Registration email jobs could not be queued.');
            }
            $db->transCommit();

            return $this->ok($this->session((int) $userId), [], 201);
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[AuthController:register] ' . $e->getMessage());
            return problem(500, 'registration_failed', 'Could not complete registration. Please try again.');
        }
    }

    private function normaliseAcquisition(array $value): array
    {
        $touch = static function ($raw): array {
            $raw = is_array($raw) ? $raw : [];
            $clean = static fn ($v, int $max = 240): ?string => ($v = trim((string) ($v ?? ''))) !== '' ? mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?: '', 0, $max) : null;
            return [
                'source' => $clean($raw['source'] ?? null, 40), 'medium' => $clean($raw['medium'] ?? null, 40),
                'campaign' => $clean($raw['campaign'] ?? null, 120), 'content' => $clean($raw['content'] ?? null, 120),
                'term' => $clean($raw['term'] ?? null, 120), 'landing_path' => $clean($raw['landing_path'] ?? null, 240),
                'referrer_host' => $clean($raw['referrer_host'] ?? null, 160), 'referrer_path' => $clean($raw['referrer_path'] ?? null, 240),
                'click_id' => $clean($raw['click_id'] ?? null, 160),
            ];
        };
        return ['first_touch' => $touch($value['first_touch'] ?? []), 'last_touch' => $touch($value['last_touch'] ?? [])];
    }

    private function touchColumns(string $prefix, array $touch): array
    {
        return [
            "{$prefix}_source" => $touch['source'], "{$prefix}_medium" => $touch['medium'],
            "{$prefix}_campaign" => $touch['campaign'], "{$prefix}_content" => $touch['content'],
            "{$prefix}_term" => $touch['term'], "{$prefix}_landing_path" => $touch['landing_path'],
            "{$prefix}_referrer_host" => $touch['referrer_host'], "{$prefix}_referrer_path" => $touch['referrer_path'],
            "{$prefix}_click_id" => $touch['click_id'],
        ];
    }

    private function validateIdentityUpload(\CodeIgniter\HTTP\Files\UploadedFile $file): ?string
    {
        if ($file->getSize() > 5 * 1024 * 1024) {
            return 'Document uploads are limited to 5 MB.';
        }

        if (! in_array((string) $file->getMimeType(), ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) {
            return 'Upload a PDF, JPG, PNG, or WebP document.';
        }
        $scan = \App\Libraries\Security\VirusScanner::scan($file->getTempName());
        if (! $scan['clean']) {
            return $scan['reason'] ?? 'The document failed security validation.';
        }

        return null;
    }

    private function storeIdentityDocument(\CodeIgniter\HTTP\Files\UploadedFile $file, int $orgId, string $kind): void
    {
        $dir = WRITEPATH . 'uploads/identity_documents';
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Could not prepare identity document storage.');
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . ($file->guessExtension() ?: 'bin');
        if (! $file->move($dir, $storedName)) {
            throw new \RuntimeException('Could not save identity document.');
        }

        db_connect('default')->table('document_assets')->insert([
            'org_id' => $orgId,
            'name' => mb_substr($file->getClientName(), 0, 160),
            'kind' => $kind,
            'path' => 'identity_documents/' . $storedName,
            'size_bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $dir . DIRECTORY_SEPARATOR . $storedName),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function login()
    {
        $in = $this->body();

        $email = IdentityValidator::normalizeEmail($in['email'] ?? '');
        $user  = model('App\Models\UserModel')->where('email', $email)->first();

        // Identical error for an unknown account and a wrong password. Neither
        // is allowed to become an account-enumeration oracle.
        $deny = static fn () => problem(401, 'invalid_credentials', 'E-mail or password is incorrect.');

        // Run a bcrypt verify even when the account does not exist, against a
        // fixed dummy hash, so response time does not reveal whether the e-mail
        // is registered (TH-SEC-008). Cost matches PASSWORD_DEFAULT.
        $hashToCheck = ($user && $user['password_hash']) ? $user['password_hash']
            : '$2y$10$EpyyZyzXGyEeF3gIDreK7.Qs3oIgZzmhQ7mSRRgySy3ZkGBYhAgcS';
        $passwordOk  = password_verify((string) ($in['password'] ?? ''), $hashToCheck);

        if (! $user || ! $user['password_hash'] || ! $passwordOk) {
            $emailAttempted = substr((string) ($in['email'] ?? ''), 0, 120);
            $detail = 'failed login for ' . $emailAttempted;
            if ($user) {
                $detail .= ' | Name: ' . ($user['name'] ?? '—')
                    . ' | Group: ' . ($user['user_group'] ?? '—');
            }
            \App\Libraries\Monitoring\SecurityMonitor::record('auth_failure', 'warning', $user['id'] ?? null, $detail);
            return $deny();
        }
        if ($user['status'] !== 'active') {
            return problem(403, 'account_suspended', 'This account is not active.');
        }

        // Queue the digest; SMTP work must never delay authentication.
        if (in_array(($user['user_group'] ?? ''), ['bidder', 'company'], true)) {
            try {
                \App\Libraries\Notifications\LoginTenderEmailQueue::enqueue((int) $user['id']);
            } catch (\Throwable $e) {
                log_message('error', '[AuthController:login] Tender recommendation dispatch error: ' . $e->getMessage());
            }
        }

        return $this->ok($this->session((int) $user['id']));
    }

    public function google()
    {
        $in = $this->body();
        $credential  = (string) ($in['credential'] ?? $in['id_token'] ?? $in['token'] ?? '');
        $accessToken = (string) ($in['access_token'] ?? '');

        if (empty($credential) && empty($accessToken)) {
            return problem(400, 'missing_token', 'Google credential or access token is required.');
        }

        $verified = $this->verifyGoogleIdentity($credential, $accessToken);
        if (! is_array($verified)) {
            return $verified;
        }
        $userData = $verified;

        $sub     = (string) ($userData['sub'] ?? '');
        $email   = IdentityValidator::normalizeEmail($userData['email']);
        $name    = trim((string) ($userData['name'] ?? ($userData['given_name'] ?? 'User')));
        $picture = (string) ($userData['picture'] ?? '');

        $users         = model('App\Models\UserModel');
        $identityModel = model('App\Models\AuthIdentityModel');

        // Step 1: Search auth_identities (provider = google, provider_user_id = sub)
        $identity = ! empty($sub) ? $identityModel->findIdentity('google', $sub) : null;

        if ($identity) {
            $user = $users->find((int) $identity['user_id']);
            if (! $user) {
                return problem(404, 'user_not_found', 'The user account linked to this Google identity no longer exists.');
            }

            if ($user['status'] !== 'active') {
                return problem(403, 'account_suspended', 'This account is not active.');
            }

            // Sync avatar or verified timestamp if missing
            $updates = [];
            if (empty($user['email_verified_at'])) {
                $updates['email_verified_at'] = date('Y-m-d H:i:s');
            }
            if (empty($user['avatar_url']) && ! empty($picture)) {
                $updates['avatar_url'] = $picture;
            }
            if (! empty($updates)) {
                $users->update($user['id'], $updates);
            }

            if (in_array(($user['user_group'] ?? ''), ['bidder', 'company'], true)) {
                try {
                    \App\Libraries\Notifications\LoginTenderEmailQueue::enqueue((int) $user['id']);
                } catch (\Throwable $e) {
                    log_message('error', '[AuthController:google] Tender digest queue error: ' . $e->getMessage());
                }
            }

            return $this->ok($this->session((int) $user['id']));
        }

        // Step 2: Identity not found -> check if existing email/password user exists
        $existingUser = $users->where('email', $email)->first();

        if ($existingUser) {
            // Existing user -> Link this Google identity seamlessly (no duplicates!)
            if ($existingUser['status'] !== 'active') {
                return problem(403, 'account_suspended', 'This account is not active.');
            }

            if (! empty($sub)) {
                $identityModel->linkIdentity(
                    (int) $existingUser['id'],
                    'google',
                    $sub,
                    $email,
                    $picture ?: null
                );
            }

            $updates = [];
            if (empty($existingUser['email_verified_at'])) {
                $updates['email_verified_at'] = date('Y-m-d H:i:s');
            }
            if (empty($existingUser['avatar_url']) && ! empty($picture)) {
                $updates['avatar_url'] = $picture;
            }
            if (! empty($updates)) {
                $users->update($existingUser['id'], $updates);
            }

            if (in_array(($existingUser['user_group'] ?? ''), ['bidder', 'company'], true)) {
                try {
                    \App\Libraries\Notifications\LoginTenderEmailQueue::enqueue((int) $existingUser['id']);
                } catch (\Throwable $e) {
                    log_message('error', '[AuthController:google] Tender digest queue error: ' . $e->getMessage());
                }
            }

            return $this->ok($this->session((int) $existingUser['id']));
        }

        // Step 3: Brand new user registering via Google OAuth
        $accountKind = ($in['role'] ?? ($in['account_type'] ?? 'bidder')) === 'company' ? 'company' : 'bidder';
        if ($accountKind === 'company') {
            return problem(403, 'company_registration_disabled', 'Company registration is currently unavailable. Please contact TenderHub support.');
        }

        $orgs = model('App\Models\OrganisationModel');
        $db   = db_connect('default');
        $db->transBegin();

        try {
            $orgName     = $name . ($accountKind === 'company' ? ' Procurement' : ' Enterprises');
            $slug        = url_title($orgName, '-', true) . '-' . bin2hex(random_bytes(2));

            $orgId = $orgs->insert([
                'name'          => $orgName,
                'slug'          => $slug,
                'type'          => $accountKind,
                'plan'          => $accountKind === 'company' ? 'publish' : 'business',
                'sub_status'    => 'active',
                'trial_started_at' => date('Y-m-d H:i:s'),
                'renews_at'     => date('Y-m-d H:i:s', strtotime('+60 days')),
                'verify_state'  => 'verified',
                'verified_at'   => date('Y-m-d H:i:s'),
                'contact_email' => $email,
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ], true);

            if (! $orgId) {
                throw new \RuntimeException('Failed to create organisation for Google user.');
            }

            $userId = $users->insert([
                'org_id'            => $orgId,
                'name'              => $name,
                'email'             => $email,
                'avatar_url'        => $picture ?: null,
                'password_hash'     => null, // Nullable as per scalable SSO pattern
                'role'              => 'owner',
                'user_group'        => $accountKind,
                'status'            => 'active',
                'email_verified_at' => date('Y-m-d H:i:s'),
                'created_at'        => date('Y-m-d H:i:s'),
                'updated_at'        => date('Y-m-d H:i:s'),
            ], true);

            if (! $userId) {
                throw new \RuntimeException('Failed to create user account for Google user.');
            }

            if ($db->tableExists('user_acquisition') && is_array($in['acquisition'] ?? null)) {
                $acquisition = $this->normaliseAcquisition($in['acquisition']);
                $db->table('user_acquisition')->insert([
                    'user_id' => $userId,
                    ...$this->touchColumns('first', $acquisition['first_touch']),
                    ...$this->touchColumns('last', $acquisition['last_touch']),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            // Link Google identity in auth_identities
            if (! empty($sub)) {
                $identityModel->linkIdentity(
                    (int) $userId,
                    'google',
                    $sub,
                    $email,
                    $picture ?: null
                );
            }

            $db->table('alert_profiles')->insert([
                'org_id'         => $orgId,
                'user_id'        => $userId,
                'name'           => "Alerts: General Tenders",
                'kinds'          => 'tender',
                'channels'       => 'in_app,email',
                'active'         => 1,
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);

            \App\Libraries\Notifications\RegistrationEmailQueue::enqueue((int) $userId, 'welcome', $db);

            $db->transCommit();
            return $this->ok($this->session((int) $userId), [], 201);
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[AuthController:google] ' . $e->getMessage());
            return problem(500, 'google_auth_failed', 'Could not complete Google authentication.');
        }
    }

    /**
     * Link Google account to currently authenticated user (Settings > Connect Google)
     */
    public function googleLink()
    {
        // AuthJwt attaches the verified identity as $request->userId. This used
        // to read a non-existent $request->jwtPayload, so linking always 401'd.
        $userId = (int) ($this->request->userId ?? 0);
        if ($userId <= 0) {
            return problem(401, 'unauthorized', 'Authentication required.');
        }

        $in         = $this->body();
        $credential = (string) ($in['credential'] ?? $in['id_token'] ?? '');
        if (empty($credential)) {
            return problem(400, 'missing_credential', 'Google credential is required.');
        }

        $verified = $this->verifyGoogleIdentity($credential, '');
        if (! is_array($verified)) {
            return $verified;
        }

        $userData = $verified;
        $sub      = (string) ($userData['sub'] ?? '');
        $email    = IdentityValidator::normalizeEmail($userData['email'] ?? '');
        $picture  = (string) ($userData['picture'] ?? '');

        if (empty($sub)) {
            return problem(400, 'invalid_sub', 'Could not determine Google user identifier.');
        }

        $identityModel = model('App\Models\AuthIdentityModel');
        $existing      = $identityModel->findIdentity('google', $sub);

        if ($existing && (int) $existing['user_id'] !== $userId) {
            return problem(409, 'google_account_already_linked', 'This Google account is already connected to another user.');
        }

        $identityModel->linkIdentity($userId, 'google', $sub, $email, $picture ?: null);

        // Update user's avatar if empty
        $users = model('App\Models\UserModel');
        $user  = $users->find($userId);
        if ($user && empty($user['avatar_url']) && ! empty($picture)) {
            $users->update($userId, ['avatar_url' => $picture]);
        }

        return $this->ok([
            'linked'         => true,
            'provider'       => 'google',
            'provider_email' => $email,
        ]);
    }

    /**
     * Unlink Google account from currently authenticated user
     */
    public function googleUnlink()
    {
        $userId = (int) ($this->request->userId ?? 0);
        if ($userId <= 0) {
            return problem(401, 'unauthorized', 'Authentication required.');
        }

        $users = model('App\Models\UserModel');
        $user  = $users->find($userId);
        if (! $user) {
            return problem(404, 'user_not_found', 'User not found.');
        }

        // Safety check: ensure user has a password set so they are not locked out
        if (empty($user['password_hash'])) {
            return problem(400, 'password_required_to_unlink', 'Please set an account password before disconnecting Google Sign-In.');
        }

        $identityModel = model('App\Models\AuthIdentityModel');
        $identityModel->unlinkIdentity($userId, 'google');

        return $this->ok(['unlinked' => true, 'provider' => 'google']);
    }

    /**
     * Verify a Google credential was issued to THIS application for a verified
     * address. Returns the token claims, or a problem response.
     *
     * Fails closed: without GOOGLE_CLIENT_ID there is nothing to bind the token
     * to, and accepting it would let any OAuth app the victim ever signed into
     * replay their Google token here and take over the matching account. The
     * bare access-token path used /userinfo, which carries no audience at all;
     * access tokens are now checked through tokeninfo, which reports `aud`.
     *
     * @return array<string,mixed>|\CodeIgniter\HTTP\ResponseInterface
     */
    protected function verifyGoogleIdentity(string $idToken, string $accessToken)
    {
        $clientId = trim((string) (env('GOOGLE_CLIENT_ID') ?: (env('NEXT_PUBLIC_GOOGLE_CLIENT_ID') ?: '')));
        if ($clientId === '' || str_contains($clientId, 'your_google_client_id')) {
            log_message('error', '[AuthController] Google sign-in refused: GOOGLE_CLIENT_ID is not configured for the API.');
            return problem(503, 'google_not_configured', 'Google sign-in is not available right now.');
        }

        $claims = null;
        if ($idToken !== '') {
            $claims = $this->fetchGoogleTokenInfo('id_token', $idToken);
        } elseif ($accessToken !== '') {
            $claims = $this->fetchGoogleTokenInfo('access_token', $accessToken);
        }

        if (! is_array($claims) || empty($claims['email'])) {
            return problem(401, 'invalid_google_token', 'Google verification failed or token expired.');
        }

        // An ID token names the client in `aud`; an access token's tokeninfo
        // reports it in `aud`/`azp`. Either way it must be ours.
        $aud = (string) ($claims['aud'] ?? '');
        $azp = (string) ($claims['azp'] ?? '');
        if (! hash_equals($clientId, $aud) && ! ($aud === '' && hash_equals($clientId, $azp))) {
            return problem(401, 'invalid_audience', 'Google token audience does not match this application.');
        }

        if (isset($claims['iss']) && ! in_array($claims['iss'], ['accounts.google.com', 'https://accounts.google.com'], true)) {
            return problem(401, 'invalid_issuer', 'Google token issuer is invalid.');
        }

        if (! isset($claims['exp']) || (int) $claims['exp'] < time()) {
            return problem(401, 'token_expired', 'Google token has expired.');
        }

        // Accounts are linked BY E-MAIL. An unverified Google address must never
        // be allowed to claim an existing TenderHub account.
        $emailVerified = $claims['email_verified'] ?? false;
        if ($emailVerified !== true && $emailVerified !== 'true') {
            return problem(401, 'google_email_unverified', 'Your Google e-mail address is not verified.');
        }

        if (empty($claims['sub'])) {
            return problem(401, 'invalid_google_token', 'Google verification failed or token expired.');
        }

        return $claims;
    }

    /** The single network call to Google; isolated so tests can substitute it. */
    protected function fetchGoogleTokenInfo(string $param, string $token): ?array
    {
        $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?' . http_build_query([$param => $token]));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || ! is_string($res) || $res === '') {
            return null;
        }

        $data = json_decode($res, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Resolve the OTP account. Phone lookups match the number's last 9 digits
     * (TH-SEC-009) and require at least 9 digits: a shorter value such as "1"
     * became LIKE '%1' and selected an arbitrary account.
     */
    private function otpUser(string $phone, string $email): ?array
    {
        $users = model('App\Models\UserModel');
        if ($phone !== '') {
            if (strlen($phone) < 9) {
                return null;
            }

            return $users->like('phone', substr($phone, -9), 'before')->first();
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $users->where('email', IdentityValidator::normalizeEmail($email))->first();
        }

        return null;
    }

    /** Identical response whether or not the account exists. */
    public function otpRequest()
    {
        $in    = $this->body();
        $phone = preg_replace('/\D/', '', (string) ($in['phone'] ?? ''));
        $email = trim((string) ($in['email'] ?? ''));
        $users = model('App\Models\UserModel');

        $user = $this->otpUser($phone, $email);

        if ($user) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $users->update($user['id'], [
                'otp_hash' => password_hash($code, PASSWORD_DEFAULT),
                'otp_expires_at' => date('Y-m-d H:i:s', time() + 300),
            ]);

            // Dispatch OTP email via SMTP
            if (! empty($user['email'])) {
                try {
                    $otpService = new \App\Libraries\Notifications\OtpEmailService();
                    $otpService->sendOtpEmail($user['email'], $user['name'] ?? '', $code, (int) $user['id']);
                } catch (\Throwable $e) {
                    log_message('error', '[AuthController:otpRequest] OTP email dispatch error: ' . $e->getMessage());
                }
            }

            // The OTP is a secret and is never returned in the response.
            // In local development only, it is written to the server log so the flow stays testable.
            if (ENVIRONMENT === 'development') {
                log_message('debug', 'DEV OTP for user ' . $user['id'] . ': ' . $code);
            }
        }

        return $this->ok(['sent' => true], [
            'note' => 'If that account exists, an authentication code has been sent.',
        ]);
    }

    public function otpVerify()
    {
        $in    = $this->body();
        $phone = preg_replace('/\D/', '', (string) ($in['phone'] ?? ''));
        $email = trim((string) ($in['email'] ?? ''));
        $users = model('App\Models\UserModel');

        $user = $this->otpUser($phone, $email);

        if (! $user || ! $user['otp_hash'] || strtotime((string) $user['otp_expires_at']) < time()) {
            return problem(401, 'invalid_code', 'That code is not valid.');
        }

        // A 6-digit code is only safe with a per-account attempt cap. The IP
        // throttle alone allows unlimited guesses from rotating addresses.
        $failKey  = 'otp_fail_' . (int) $user['id'];
        $failures = (int) (cache($failKey) ?? 0);
        if (! password_verify((string) ($in['code'] ?? ''), $user['otp_hash'])) {
            $failures++;
            if ($failures >= self::OTP_MAX_ATTEMPTS) {
                $users->update($user['id'], ['otp_hash' => null, 'otp_expires_at' => null]);
                cache()->delete($failKey);
            } else {
                cache()->save($failKey, $failures, 300);
            }

            return problem(401, 'invalid_code', 'That code is not valid.');
        }
        cache()->delete($failKey);

        $users->update($user['id'], ['otp_hash' => null, 'otp_expires_at' => null]);

        // Queue the digest; SMTP work must never delay authentication.
        if (in_array(($user['user_group'] ?? ''), ['bidder', 'company'], true)) {
            try {
                \App\Libraries\Notifications\LoginTenderEmailQueue::enqueue((int) $user['id']);
            } catch (\Throwable $e) {
                log_message('error', '[AuthController:otpVerify] Tender recommendation dispatch error: ' . $e->getMessage());
            }
        }

        return $this->ok($this->session((int) $user['id']));
    }

    /**
     * Refresh tokens rotate. Presenting one that has already been used revokes
     * the whole family — that is the signature of a stolen token being replayed
     * alongside the legitimate one.
     */
    public function refresh()
    {
        $given = (string) ($this->body()['refresh_token'] ?? '');
        $hash  = hash('sha256', $given);
        $db    = db_connect('default');
        $row   = $db->table('refresh_tokens')->where('token_hash', $hash)->get()->getFirstRow('array');

        if (! $row) {
            return problem(401, 'invalid_refresh', 'Sign in again.');
        }

        if ($row['revoked_at'] !== null) {
            return problem(401, 'refresh_reuse', 'Session revoked. Sign in again.');
        }
        if ($row['used_at'] !== null && ! $this->withinReuseGrace($row)) {
            $db->table('refresh_tokens')->where('family_id', $row['family_id'])
                ->update(['revoked_at' => date('Y-m-d H:i:s')]);

            return problem(401, 'refresh_reuse', 'Session revoked. Sign in again.');
        }

        if (strtotime($row['expires_at']) < time()) {
            return problem(401, 'invalid_refresh', 'Sign in again.');
        }

        // Re-check the account is still active before minting a fresh session.
        // Otherwise a suspended user could keep refreshing for the 30-day token
        // lifetime (TH-SEC-010).
        $user = model('App\Models\UserModel')->find((int) $row['user_id']);
        if (! $user || $user['status'] !== 'active') {
            $db->table('refresh_tokens')->where('family_id', $row['family_id'])
                ->update(['revoked_at' => date('Y-m-d H:i:s')]);

            return problem(401, 'account_inactive', 'Session ended. Sign in again.');
        }

        // Claim the token atomically. Two concurrent refreshes with the same
        // token both passed the read above; only the one that wins this
        // conditional UPDATE may mint a session, the loser is a replay.
        if ($row['used_at'] === null) {
            $db->table('refresh_tokens')->where('id', $row['id'])->where('used_at', null)->where('revoked_at', null)
                ->update(['used_at' => date('Y-m-d H:i:s')]);
            if ($db->affectedRows() !== 1) {
                $fresh = $db->table('refresh_tokens')->where('id', $row['id'])->get()->getFirstRow('array');
                if (! $fresh || $fresh['revoked_at'] !== null || ! $this->withinReuseGrace($fresh)) {
                    $db->table('refresh_tokens')->where('family_id', $row['family_id'])
                        ->update(['revoked_at' => date('Y-m-d H:i:s')]);

                    return problem(401, 'refresh_reuse', 'Session revoked. Sign in again.');
                }
            }
        }

        return $this->ok($this->session((int) $row['user_id'], $row['family_id']));
    }

    /**
     * A page load fires several requests at once; when the access token has
     * just expired each of them refreshes with the same token. Treating the
     * second one as theft revoked the whole family and signed the user out.
     * A rotated token stays usable for a short grace window; replay after that
     * is still treated as theft and revokes the family.
     */
    private function withinReuseGrace(array $row): bool
    {
        return $row['used_at'] !== null && (time() - strtotime((string) $row['used_at'])) <= self::REFRESH_REUSE_GRACE;
    }

    public function logout()
    {
        $given = (string) ($this->body()['refresh_token'] ?? '');
        if ($given !== '') {
            $db  = db_connect('default');
            $row = $db->table('refresh_tokens')->where('token_hash', hash('sha256', $given))->get()->getFirstRow('array');
            if ($row) {
                $db->table('refresh_tokens')->where('family_id', $row['family_id'])
                    ->update(['revoked_at' => date('Y-m-d H:i:s')]);
            }
        }

        return $this->ok(['signed_out' => true]);
    }

    public function session(int $userId, ?string $family = null): array
    {
        $user = model('App\Models\UserModel')->find($userId);
        $org  = model('App\Models\OrganisationModel')->find((int) $user['org_id']);

        $isVerified = ! empty($user['email_verified_at']);

        $access = Jwt::issue([
            'sub'  => (int) $user['id'],
            'org'  => (int) $org['id'],
            'role' => $user['role'],
            'grp'  => $user['user_group'],
            'st'   => $org['sub_status'],
            'plan' => $org['plan'],
            'nm'   => $user['name'],
            'ev'   => $isVerified,
            'vs'   => 'verified',
        ]);

        $refresh = bin2hex(random_bytes(32));
        db_connect('default')->table('refresh_tokens')->insert([
            'user_id'    => $user['id'],
            'family_id'  => $family ?: bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', $refresh),
            'expires_at' => date('Y-m-d H:i:s', time() + self::REFRESH_TTL),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        model('App\Models\UserModel')->update($userId, ['last_login_at' => date('Y-m-d H:i:s')]);

        return [
            'access_token'  => $access,
            'expires_in'    => Jwt::TTL,
            'refresh_token' => $refresh,
            'user' => [
                'id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'],
                'role' => $user['role'], 'group' => $user['user_group'],
                'free_views_used' => (int) $user['free_views'],
                'email_verified' => $isVerified,
                'email_verified_at' => $user['email_verified_at'] ?? null,
            ],
            'org' => [
                'id' => (int) $org['id'], 'name' => $org['name'], 'type' => $org['type'],
                'plan' => $org['plan'], 'sub_status' => $org['sub_status'],
                'renews_at' => $org['renews_at'], 'verify_state' => $org['verify_state'],
            ],
        ];
    }
}
