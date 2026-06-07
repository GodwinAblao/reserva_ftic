<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    private const ACCOUNT_ROLE_MAP = [
        'superadmin' => 'ROLE_SUPER_ADMIN',
        'admin' => 'ROLE_ADMIN',
        'faculty' => 'ROLE_FACULTY',
        'mentor' => 'ROLE_MENTOR',
        'student' => 'ROLE_STUDENT',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * @return array{items: User[], total: int}
     */
    public function findPaginatedForAccountManagement(int $page, int $limit, ?string $search = null, ?string $role = null): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $offset = ($page - 1) * $limit;
        $conn = $this->getEntityManager()->getConnection();
        $platform = $conn->getDatabasePlatform();
        $table = $conn->quoteIdentifier('user');
        $roleColumn = $platform instanceof PostgreSQLPlatform ? 'u.roles::text' : 'CAST(u.roles AS CHAR)';

        $where = [];
        $params = [];

        $search = trim((string) $search);
        if ($search !== '') {
            $where[] = '(LOWER(COALESCE(u.first_name, \'\')) LIKE :search OR LOWER(COALESCE(u.last_name, \'\')) LIKE :search OR LOWER(u.email) LIKE :search)';
            $params['search'] = '%' . strtolower($search) . '%';
        }

        $roleValue = self::ACCOUNT_ROLE_MAP[$role ?? ''] ?? null;
        if ($roleValue !== null) {
            $where[] = $roleColumn . ' LIKE :role';
            $params['role'] = '%' . $roleValue . '%';
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total = (int) $conn->executeQuery('SELECT COUNT(*) FROM ' . $table . ' u' . $whereSql, $params)->fetchOne();

        $idRows = $conn->executeQuery(
            'SELECT u.id FROM ' . $table . ' u' . $whereSql . ' ORDER BY u.last_name ASC, u.first_name ASC, u.email ASC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]
        )->fetchFirstColumn();

        if ($idRows === []) {
            return ['items' => [], 'total' => $total];
        }

        $users = $this->createQueryBuilder('u')
            ->andWhere('u.id IN (:ids)')
            ->setParameter('ids', array_map('intval', $idRows))
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->addOrderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();

        return ['items' => $users, 'total' => $total];
    }

    /**
     * Find all admin users (ROLE_SUPER_ADMIN and future ROLE_ADMIN accounts).
     *
     * @return User[]
     */
    public function findAdmins(): array
    {
        $sql = "SELECT id FROM \"user\" u WHERE u.roles::text LIKE ? OR u.roles::text LIKE ?";
        $ids = $this->getEntityManager()->getConnection()
            ->executeQuery($sql, ['%ROLE_SUPER_ADMIN%', '%ROLE_ADMIN%'])
            ->fetchFirstColumn();

        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', array_map('intval', $ids))
            ->getQuery()
            ->getResult();
    }

    /**
     * Find user by email (case-insensitive)
     */
    public function findOneByEmailCaseInsensitive(string $email): ?User
    {
        $qb = $this->createQueryBuilder('u');
        $qb->where('LOWER(u.email) = LOWER(:email)')
           ->setParameter('email', $email);
        
        return $qb->getQuery()->getOneOrNullResult();
    }
}
