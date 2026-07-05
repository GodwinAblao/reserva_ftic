<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Reservation;
use App\Entity\ReservationConflict;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReservationConflict>
 */
class ReservationConflictRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReservationConflict::class);
    }

    /**
     * @return ReservationConflict[]
     */
    public function findByReservation(Reservation $reservation): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.reservation = :reservation')
            ->setParameter('reservation', $reservation)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function hasUnresolvedConflicts(Reservation $reservation): bool
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.reservation = :reservation')
            ->andWhere('c.resolution IS NULL')
            ->setParameter('reservation', $reservation)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function countUnresolved(Reservation $reservation): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.reservation = :reservation')
            ->andWhere('c.resolution IS NULL')
            ->setParameter('reservation', $reservation)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
