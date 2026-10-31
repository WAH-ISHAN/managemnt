<?php

namespace App\Controllers\Api\V1\Auth;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Validation\IdentityValidator;

class EmailVerificationController extends BaseApiController
{
    /**
     * POST /api/v1/auth/verify-email
     * Verifies user email via confirmation token.
     */
    public function verify()
    {
        $in = $this->body();
        $rules = [
            'token' => 'required|min_length[16]',
        ];

        if (! $this->validateData($in, $rules)) {
            return problem(422, 'validation_failed', 'A verification token is required.', ['errors' => $this->validator->getErrors()]);
        }

        $rawToken  = trim($in['token']);
        $tokenHash = hash('sha256', $rawToken);

        $db = db_connect('default');
        $record = $db->table('email_verifications')
            ->where('token_hash', $tokenHash)
            ->where('expires_at >=', date('Y-m-d H:i:s'))
            ->get()
            ->getFirstRow('array');

        if (! $record) {
            return problem(400, 'invalid_or_expired_token', 'Verification token is invalid or has expired.');
        }

        $users = model('App\Models\UserModel');
        $user  = $users->where('email', $record['email'])->first();

        if ($user) {
            $users->update($user['id'], [
                'email_verified_at' => date('Y-m-d H:i:s'),
                'status'            => 'active',
            ]);
        }

        // Clean up token (single-use guarantee)
        $db->table('email_verifications')->where('email', $record['email'])->delete();

        $sessionData = [];
        if ($user) {
            try {
                $auth = new AuthController();
                $sessionData = $auth->session((int) $user['id']);
            } catch (\Throwable $e) {
                log_message('error', '[EmailVerificationController] Session generation error: ' . $e->getMessage());
            }
        }

        return $this->ok(array_merge([
            'verified' => true,
            'message'  => 'Your email address has been verified successfully.',
        ], $sessionData));
    }

    /**
     * POST /api/v1/auth/resend-verification
     * Generates a new verification token and dispatches verification email.
     */
    public function resend()
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

        // Strict email verification: reject disposable/burner emails
        $emailCheck = IdentityValidator::validateEmailStrict($email);
        if (! $emailCheck['valid']) {
            return problem(422, $emailCheck['code'], $emailCheck['error']);
        }

        $users = model('App\Models\UserModel');
        $user  = $users->where('email', $email)->first();

        if (! $user) {
            return $this->ok([
                'message' => 'If an account exists with that email, a verification link will be sent.',
            ]);
        }

        if (! empty($user['email_verified_at'])) {
            return problem(400, 'already_verified', 'This email address is already verified.');
        }

        // The worker creates a fresh token when it can actually attempt delivery.
        // Keep the old token valid until then, and never run SMTP in auth requests.
        try {
            \App\Libraries\Notifications\RegistrationEmailQueue::enqueue((int) $user['id'], 'verification');
        } catch (\Throwable $e) {
            log_message('error', '[EmailVerificationController:resend] Verification queue error: ' . $e->getMessage());
            return problem(503, 'email_queue_unavailable', 'Could not queue the verification email. Please try again.');
        }

        return $this->ok([
            'message' => 'If an account exists with that email, a verification link will be sent.',
        ]);
    }
}
