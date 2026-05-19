<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClassScheduleNotificationLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClassScheduleNotificationLog>
 */
class ClassScheduleNotificationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassScheduleNotificationLog::class);
    }

    /**
     * @return ClassScheduleNotificationLog[]
     */
    public function findRecent(int $limit = 30): array
    {
        return $this->createQueryBuilder('l')
            ->innerJoin('l.classSchedule', 'c')
            ->addSelect('c')
            ->innerJoin('l.notifiedBy', 'nb')
            ->addSelect('nb')
            ->leftJoin('l.facultyUser', 'fu')
            ->addSelect('fu')
            ->leftJoin('l.newFacility', 'nf')
            ->addSelect('nf')
            ->leftJoin('l.previousFacility', 'pf')
            ->addSelect('pf')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
