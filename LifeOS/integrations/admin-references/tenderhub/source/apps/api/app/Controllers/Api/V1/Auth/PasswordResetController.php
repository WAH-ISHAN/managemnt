<?php

namespace App\Controllers\Api\V1\Auth;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Validation\IdentityValidator;

class PasswordResetController extends BaseApiController
{
    /**
     * POST /api/v1/auth/forgot-password
     * Generates a 6-digit verification code (OTP) and a single-use cryptographically
     * secure reset token with 15-minute expiry.
     */
    public function forgotPassword()
    {
        $in = $this->body();

        if (isset($in['email'])) {
            $in['email'] = IdentityValidator::normalizeEmail($in['email']);
        }

        $rules = [
            'email' => 'required|valid_email',
        ];

        if (! $this->validateData($in, $rules)) {
            return problem(422, 'validation_failed', 'A valid email is required.', ['errors' => $this->validator->getErrors()]);
        }

        $email = $in['email'];
        $users = model('App\Models\UserModel');
        $user  = $users->where('email', $email)->first();

        // Always return generic success to prevent email enumeration / user discovery attacks
        if (! $user) {
            return $this->ok([
                'message' => 'If an account exists with that email, a password reset code has been dispatched.',
            ]);
        }

        // SMTP must never delay authentication endpoints. The worker creates
        // fresh reset credentials immediately before it attempts delivery.
        try {
            \App\Libraries\Notifications\RegistrationEmailQueue::enqueue(
                (int) $user['id'],
                'password_reset'
            );
        } catch (\Throwable $e) {
            // Keep the response indistinguishable from an unknown account.
            log_message('error', '[PasswordResetController:forgotPassword] Reset email queue error: ' . $e->getMessage());
        }

        return $this->ok([
            'message' => 'If an account exists with that email, a password reset code has been dispatched.',
        ]);
    }

    /**
     * POST /api/v1/auth/reset-password
     * Verifies the 6-digit OTP code or reset token, updates password hash,
     * and invalidates existing sessions.
     */
    public function resetPassword()
    {
        $in = $this->body();

        $hasToken = ! empty($in['token']);
        $hasOtp   = ! empty($in['otp']);

        if (! $hasToken && ! $hasOtp) {
            return problem(422, 'validation_failed', 'Either a 6-digit verification code (OTP) or reset token is required.');
        }

        if ($hasOtp && empty($in['email'])) {
            return problem(422, 'validation_failed', 'Email address is required when verifying with an OTP code.');
        }

        $rules = [
            'password' => 'required|min_length[8]',
        ];
        if ($hasToken) {
            $rules['token'] = 'required|min_length[32]';
        }
        if ($hasOtp) {
            $rules['email'] = 'required|valid_email';
            $rules['otp']   = 'required|min_length[6]|max_length[6]';
        }

        if (! $this->validateData($in, $rules)) {
            return problem(422, 'validation_failed', 'Valid code/token and new password (min 8 chars) required.', ['errors' => $this->validator->getErrors()]);
        }

        // Password complexity check
        $pwdCheck = IdentityValidator::validatePassword($in['password'] ?? '');
        if (! $pwdCheck['valid']) {
            return problem(422, 'weak_password', $pwdCheck['error']);
        }

        $db = db_connect('default');
        $record = null;

        if ($hasOtp) {
            $email = IdentityValidator::normalizeEmail($in['email']);
            $otp   = trim((string) $in['otp']);

            $record = $db->table('password_resets')
                ->where('email', $email)
                ->where('expires_at >=', date('Y-m-d H:i:s'))
                ->get()
                ->getFirstRow('array');

            if (! $record) {
                return problem(400, 'invalid_or_expired_otp', 'The verification code is invalid or has expired. Please request a new code.');
            }

            // Check brute-force attempts
            if ((int) ($record['otp_attempts'] ?? 0) >= 5) {
                $db->table('password_resets')->where('id', $record['id'])->delete();
                return problem(429, 'too_many_attempts', 'Too many invalid verification attempts. For your security, this code has been invalidated. Please request a new code.');
            }

            // Verify OTP hash
            $inputOtpHash = hash('sha256', $otp);
            if (! hash_equals((string) ($record['otp_hash'] ?? ''), $inputOtpHash)) {
                $db->table('password_resets')->where('id', $record['id'])->increment('otp_attempts');
                return problem(400, 'invalid_otp', 'The 6-digit verification code is incorrect. Please check the code sent to your email.');
            }
        } else {
            $rawToken  = trim($in['token']);
            $tokenHash = hash('sha256', $rawToken);

            $record = $db->table('password_resets')
                ->where('token_hash', $tokenHash)
                ->where('expires_at >=', date('Y-m-d H:i:s'))
                ->get()
                ->getFirstRow('array');

            if (! $record) {
                return problem(400, 'invalid_or_expired_token', 'The password reset token is invalid or has expired. Please request a new link.');
            }
        }

        $users = model('App\Models\UserModel');
        $user  = $users->where('email', $record['email'])->first();

        if (! $user) {
            return problem(400, 'invalid_or_expired_token', 'The account associated with this request could not be located.');
        }

        // Update password hash and revoke reset record within transaction
        $db->transBegin();
        try {
            $users->update($user['id'], [
                'password_hash' => password_hash($in['password'], PASSWORD_DEFAULT),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

            // Delete used token/otp record to prevent replay attacks
            $db->table('password_resets')->where('email', $record['email'])->delete();

            // Invalidate all active refresh token families for security
            $db->table('refresh_tokens')->where('user_id', $user['id'])->delete();

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            return problem(500, 'reset_failed', 'Could not complete password reset. Please try again.');
        }

        return $this->ok([
            'message' => 'Password has been successfully reset. You may now sign in with your new credentials.',
        ]);
    }
}
