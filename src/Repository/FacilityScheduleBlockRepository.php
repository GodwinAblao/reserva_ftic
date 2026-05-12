<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Facility;
use App\Entity\FacilityScheduleBlock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FacilityScheduleBlock>
 */
class FacilityScheduleBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FacilityScheduleBlock::class);
    }

    public function save(FacilityScheduleBlock $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(FacilityScheduleBlock $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function isBlocked(Facility $facility, \DateTimeInterface $date, \DateTimeInterface $startTime, \DateTimeInterface $endTime, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->andWhere('b.facility = :facility')
            ->andWhere('b.blockDate = :date')
            ->andWhere('b.startTime < :endTime')
            ->andWhere('b.endTime > :startTime')
            ->setParameter('facility', $facility)
            ->setParameter('date', $date->format('Y-m-d'))
            ->setParameter('startTime', $startTime)
            ->setParameter('endTime', $endTime);

        if ($excludeId !== null) {
            $qb->andWhere('b.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function findForDate(Facility $facility, \DateTimeInterface $date): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.facility = :facility')
            ->andWhere('b.blockDate = :date')
            ->setParameter('facility', $facility)
            ->setParameter('date', $date->format('Y-m-d'))
            ->orderBy('b.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findBetween(\DateTimeInterface $start, \DateTimeInterface $end, ?Facility $facility = null, ?string $type = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->leftJoin('b.facility', 'f')
            ->addSelect('f')
            ->andWhere('b.blockDate BETWEEN :start AND :end')
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->orderBy('b.blockDate', 'ASC')
            ->addOrderBy('b.startTime', 'ASC');

        if ($facility !== null) {
            $qb->andWhere('b.facility = :facility')->setParameter('facility', $facility);
        }

        if ($type !== null) {
            $qb->andWhere('b.type = :type')->setParameter('type', $type);
        }

        return $qb->getQuery()->getResult();
    }
}
