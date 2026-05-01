<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MentorApplication;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MentorApplication>
 */
class MentorApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MentorApplication::class);
    }

    public function findByStudent(User $user): array
    {
        return $this->createQueryBuilder('ma')
            ->where('ma.student = :student')
            ->setParameter('student', $user)
            ->getQuery()
            ->getResult();
    }
}

