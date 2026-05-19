<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReservationStatusLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReservationStatusLog>
 */
class ReservationStatusLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReservationStatusLog::class);
    }

    /**
     * @return ReservationStatusLog[]
     */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('l')
            ->innerJoin('l.reservation', 'r')
            ->addSelect('r')
            ->innerJoin('l.changedBy', 'u')
            ->addSelect('u')
            ->leftJoin('r.facility', 'f')
            ->addSelect('f')
            ->orderBy('l.changedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
