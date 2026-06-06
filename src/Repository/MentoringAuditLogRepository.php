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
            ->orderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{logs: MentoringAuditLog[], total: int, pages: int}
     */
    public function findPaginated(int $page = 1, int $perPage = 15, string $search = ''): array
    {
        $s = '%' . mb_strtolower($search) . '%';

        $countQb = $this->createQueryBuilder('a')->select('COUNT(a.id)');
        $dataQb  = $this->createQueryBuilder('a')
            ->select('a')
            ->leftJoin('a.performedBy', 'u')
            ->addSelect('u')
            ->orderBy('a.id', 'DESC');

        if ($search !== '') {
            $expr = 'a.subjectLabel LIKE :s OR a.performedByName LIKE :s OR a.action LIKE :s OR a.subjectType LIKE :s';
            $countQb->andWhere($expr)->setParameter('s', $s);
            $dataQb->andWhere($expr)->setParameter('s', $s);
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = max(1, min($page, $pages));

        $logs = $dataQb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['logs' => $logs, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }
}
