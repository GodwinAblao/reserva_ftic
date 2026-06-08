<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Bridges UserChecker (firewall context) → SecurityController (login route).
 *
 * Symfony converts DisabledException → BadCredentialsException before the
 * login controller can inspect it, and the POST body is gone on the redirect.
 * This service records the email of the last unverified login attempt so the
 * login controller can safely detect and handle it.
 */
final class UnverifiedLoginTracker
{
    private ?string $email = null;

    public function record(string $email): void
    {
        $this->email = $email;
    }

    public function consume(): ?string
    {
        $email = $this->email;
        $this->email = null;
        return $email;
    }
}
