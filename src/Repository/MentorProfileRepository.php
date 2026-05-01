<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MentorProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MentorProfile>
 */
class MentorProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MentorProfile::class);
    }

    public function findByUser(User $user): ?MentorProfile
    {
        return $this->createQueryBuilder('mp')
            ->where('mp.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

