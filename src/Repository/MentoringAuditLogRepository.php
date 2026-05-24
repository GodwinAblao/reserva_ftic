<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MentoringAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MentoringAuditLog>
 */
class MentoringAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MentoringAuditLog::class);
    }

    /**
     * Returns true if an identical log entry already exists within the last $seconds seconds.
     * Used to prevent duplicate audit rows when a form is submitted more than once.
     */
    public function existsRecent(
        string $subjectType,
        ?int $subjectId,
        string $action,
        ?string $newStatus,
        ?int $performedById,
        int $seconds = 30
    ): bool {
        $since = (new \DateTime())->modify("-{$seconds} seconds");
        $qb = $this->createQueryBuilder('a')
            ->select('1')
            ->where('a.subjectType = :st')
            ->andWhere('a.action = :ac')
            ->andWhere('a.loggedAt >= :since')
            ->setParameter('st', $subjectType)
            ->setParameter('ac', $action)
            ->setParameter('since', $since)
            ->setMaxResults(1);

        if ($subjectId !== null) {
            $qb->andWhere('a.subjectId = :sid')->setParameter('sid', $subjectId);
        }
        if ($newStatus !== null) {
            $qb->andWhere('a.newStatus = :ns')->setParameter('ns', $newStatus);
        }
        if ($performedById !== null) {
            $qb->andWhere('a.performedBy = :uid')->setParameter('uid', $performedById);
        }

        return (bool) $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return MentoringAuditLog[]
     */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->select('a')
            ->leftJoin('a.performedBy', 'u')
            ->addSelect('u')
            ->distinct(true)
            ->orderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
