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
        return $this->createQueryBuilder('u')
            ->where('u.roles LIKE :superAdminRole OR u.roles LIKE :adminRole')
            ->setParameter('superAdminRole', '%ROLE_SUPER_ADMIN%')
            ->setParameter('adminRole', '%ROLE_ADMIN%')
            ->getQuery()
            ->getResult();
    }
}
