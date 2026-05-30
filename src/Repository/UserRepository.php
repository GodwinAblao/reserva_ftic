<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Find all admin users (ROLE_SUPER_ADMIN and future ROLE_ADMIN accounts).
     *
     * @return User[]
     */
    public function findAdmins(): array
    {
        // Use native SQL to avoid PostgreSQL JSON issues
        $sql = "SELECT id FROM \"user\" u WHERE u.roles::text LIKE ? OR u.roles::text LIKE ?";
        $result = $this->getEntityManager()->getConnection()->executeQuery($sql, ['%ROLE_SUPER_ADMIN%', '%ROLE_ADMIN%']);
        
        $users = [];
        while ($row = $result->fetchAssociative()) {
            $user = $this->getEntityManager()->find(User::class, $row['id']);
            if ($user) {
                $users[] = $user;
            }
        }
        
        return $users;
    }
}
