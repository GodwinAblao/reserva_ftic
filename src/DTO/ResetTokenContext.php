<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class ResetTokenContext
{
    public function __construct(
        public readonly SessionInterface $session,
        public readonly ?string $resetEmail,
        public readonly ?string $resetToken,
        public readonly int $expires,
    ) {}

    public function isExpired(): bool
    {
        return time() > $this->expires;
    }

    public function tokenMatches(string $token): bool
    {
        return $this->resetToken !== null && $token === $this->resetToken;
    }

    public function isValid(string $token): bool
    {
        return $this->resetEmail !== null
            && $this->resetToken !== null
            && !$this->isExpired()
            && $this->tokenMatches($token);
    }

    public function clearSession(): void
    {
        $this->session->remove('reset_email');
        $this->session->remove('reset_token');
        $this->session->remove('reset_token_expires');
    }
}
