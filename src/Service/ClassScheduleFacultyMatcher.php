<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ClassSchedule;
use App\Entity\User;
use App\Repository\UserRepository;

class ClassScheduleFacultyMatcher
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    public function isVerifiedFaculty(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return $user->isVerified() && in_array('ROLE_FACULTY', $user->getRoles(), true);
    }

    public function resolveFacultyUser(?string $email): ?User
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        $user = $this->userRepository->findOneBy(['email' => strtolower(trim($email))]);

        return $this->isVerifiedFaculty($user) ? $user : null;
    }

    public function attachFacultyUser(ClassSchedule $schedule): void
    {
        $schedule->setFacultyUser($this->resolveFacultyUser($schedule->getFacultyEmail()));
    }
}
