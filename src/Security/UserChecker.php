<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isVerified()) {
            // Only flag the session if the submitted password is actually correct.
            // checkPreAuth runs before Symfony's own password check, so we verify here.
            $request         = $this->requestStack->getCurrentRequest();
            $submittedPassword = $request?->request->get('password', '');

            if ($submittedPassword !== '' && $this->passwordHasher->isPasswordValid($user, $submittedPassword)) {
                $session = $this->requestStack->getSession();
                $session->set('_unverified_login_email', $user->getEmail());
            }

            throw new DisabledException('Your account is not verified. Please confirm the code sent to your institutional email.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
