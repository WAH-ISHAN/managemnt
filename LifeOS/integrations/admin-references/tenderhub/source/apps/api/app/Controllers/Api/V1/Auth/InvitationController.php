<?php

namespace App\Controllers\Api\V1\Auth;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Auth\PrimaryOwner;
use App\Libraries\Jwt;
use App\Libraries\Validation\IdentityValidator;

/**
 * POST /api/v1/auth/invitation/accept
 *
 * Validates a raw invitation token, creates (or adopts) a user account,
 * and returns a full session identical to a regular login response so the
 * BFF can set cookies without any additional round-trips.
 */
class InvitationController extends BaseApiController
{
    public function accept()
    {
        $in    = $this->body();
        $token = trim((string) ($in['token'] ?? ''));
        $name  = trim((string) ($in['name'] ?? ''));
        $pass  = (string) ($in['password'] ?? '');

        if ($token === '' || $name === '' || $pass === '') {
            return problem(422, 'validation_failed', 'token, name, and password are required.');
        }

        // Password strength check before hitting the database.
        $pwdCheck = IdentityValidator::validatePassword($pass);
        if (! $pwdCheck['valid']) {
            return problem(422, 'weak_password', $pwdCheck['error']);
        }

        $db   = db_connect();
        $hash = hash('sha256', $token);
        $inv  = $db->table('invitations')
                   ->where('token_hash', $hash)
                   ->where('state', 'pending')
                   ->get()
                   ->getFirstRow('array');

        if (! $inv) {
            return problem(404, 'invalid_token', 'This invitation link is not valid or has already been used.');
        }

        if (strtotime((string) $inv['expires_at']) < time()) {
            $db->table('invitations')->where('id', $inv['id'])->update(['state' => 'expired']);
            return problem(410, 'invitation_expired', 'This invitation has expired. Ask the workspace owner for a new one.');
        }

        // Every invitation joins a buyer workspace (a company-group account).
        // While the Organisation Portal is closed, nothing is created or
        // changed: the invitation stays pending and works once it reopens.
        if (! \App\Libraries\OrganisationPortal::enabled()) {
            return \App\Libraries\OrganisationPortal::refusal();
        }

        $email = strtolower((string) $inv['email']);

        // ----------------------------------------------------------
        // If the invited address already has an account, re-home it into the
        // inviting org — but ONLY on proof that the person accepting controls
        // that account. Without this check any workspace owner could invite a
        // known e-mail, accept it themselves, and receive that user's session:
        // an account takeover (the invite endpoint hands the raw token to the
        // inviter, and e-mail delivery is not the gate). Requiring the existing
        // account's CURRENT password closes that — an attacker does not know it,
        // the legitimate owner does. A staff/platform account is never
        // reassignable via an invitation regardless.
        // ----------------------------------------------------------
        if ($db->table('users')->where('email', $email)->countAllResults() > 0) {
            $existingUser = $db->table('users')->where('email', $email)->get()->getFirstRow('array');

            if (PrimaryOwner::isPrimaryOwner($existingUser['email'] ?? null)) {
                return problem(409, 'protected_owner',
                    'The primary owner account cannot be reassigned through an organisation invitation.');
            }

            if (($existingUser['user_group'] ?? '') === 'staff') {
                return problem(409, 'staff_immutable',
                    'This e-mail belongs to a staff account, which cannot be reassigned to an organisation via an invitation.');
            }

            if (empty($existingUser['password_hash']) || ! password_verify($pass, $existingUser['password_hash'])) {
                // Identical whether the account exists with a different password
                // or not — do not turn this into a password oracle, and do not
                // reveal that the invited address is already registered.
                return problem(403, 'account_exists',
                    'An account already exists for this e-mail. Enter that account\'s current password to accept the invitation, or sign in to that account first.');
            }

            $db->table('users')->where('id', $existingUser['id'])->update([
                'org_id'     => (int) $inv['org_id'],
                'role'       => $inv['role'],
                'user_group' => 'company',
                'status'     => 'active',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $db->table('invitations')->where('id', $inv['id'])->update(['state' => 'accepted']);

            return $this->ok($this->session((int) $existingUser['id']));
        }

        // ----------------------------------------------------------
        // Create a brand-new user account.
        // ----------------------------------------------------------
        $db->transBegin();

        $userId = $db->table('users')->insert([
            'org_id'        => (int) $inv['org_id'],
            'name'          => $name,
            'email'         => $email,
            'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
            'role'          => $inv['role'],
            'user_group'    => 'company',
            'status'        => 'active',
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ], true) ? $db->insertID() : 0;

        if (! $userId) {
            $db->transRollback();
            return problem(500, 'account_creation_failed', 'Could not create the account. Please try again.');
        }

        $db->table('invitations')->where('id', $inv['id'])->update(['state' => 'accepted']);
        $db->transCommit();

        return $this->ok($this->session($userId), [], 201);
    }

    // ------------------------------------------------------------------
    // Shared session helper — produces the same payload shape as
    // AuthController::session() so the BFF proxy can set cookies with
    // no additional changes.
    // ------------------------------------------------------------------
    private function session(int $userId): array
    {
        $user = model('App\\Models\\UserModel')->find($userId);
        $org  = model('App\\Models\\OrganisationModel')->find((int) $user['org_id']);

        $access = Jwt::issue([
            'sub'  => (int) $user['id'],
            'org'  => (int) $org['id'],
            'role' => $user['role'],
            'grp'  => $user['user_group'],
            'st'   => $org['sub_status'],
            'plan' => $org['plan'],
            'nm'   => $user['name'],
        ]);

        $refresh = bin2hex(random_bytes(32));
        db_connect('default')->table('refresh_tokens')->insert([
            'user_id'    => $user['id'],
            'family_id'  => bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', $refresh),
            'expires_at' => date('Y-m-d H:i:s', time() + 2592000), // 30 days
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        model('App\\Models\\UserModel')->update($userId, ['last_login_at' => date('Y-m-d H:i:s')]);

        return [
            'access_token'  => $access,
            'expires_in'    => Jwt::TTL,
            'refresh_token' => $refresh,
            'user' => [
                'id'    => (int) $user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'role'  => $user['role'],
                'group' => $user['user_group'],
            ],
            'org' => [
                'id'         => (int) $org['id'],
                'name'       => $org['name'],
                'type'       => $org['type'],
                'plan'       => $org['plan'],
                'sub_status' => $org['sub_status'],
            ],
        ];
    }
}
