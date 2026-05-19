<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Reservation;
use App\Entity\ReservationStatusLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class ReservationAuditLogger
{
    public const MANAGEABLE_STATUSES = ['Pending', 'Approved', 'Rejected', 'Cancelled'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function logStatusChange(
        Reservation $reservation,
        string $previousStatus,
        string $newStatus,
        string $action,
        ?string $note = null,
        ?User $actor = null,
    ): void {
        if ($previousStatus === $newStatus) {
            return;
        }

        $actor ??= $this->security->getUser();
        if (!$actor instanceof User) {
            return;
        }

        $log = new ReservationStatusLog();
        $log->setReservation($reservation);
        $log->setPreviousStatus($previousStatus);
        $log->setNewStatus($newStatus);
        $log->setChangedBy($actor);
        $log->setActorRoleLabel($this->resolveActorRoleLabel($actor));
        $log->setAction($action);
        $log->setNote($note);

        $this->em->persist($log);
    }

    public function resolveActorRoleLabel(User $user): string
    {
        $roles = $user->getRoles();
        if (in_array('ROLE_SUPER_ADMIN', $roles, true)) {
            return 'Super Admin';
        }
        if (in_array('ROLE_ADMIN', $roles, true)) {
            return 'Admin';
        }

        return 'User';
    }

    public static function isManageableStatus(string $status): bool
    {
        return in_array($status, self::MANAGEABLE_STATUSES, true);
    }
}
