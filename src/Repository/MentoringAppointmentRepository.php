<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MentoringAppointment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MentoringAppointment>
 */
class MentoringAppointmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MentoringAppointment::class);
    }

    public function findByStudent(User $user): array
    {
        return $this->createQueryBuilder('ma')
            ->where('ma.student = :student')
            ->setParameter('student', $user)
            ->getQuery()
            ->getResult();
    }

    public function findByMentorUser(User $user): array
    {
        return $this->createQueryBuilder('ma')
            ->join('ma.mentor', 'mp')
            ->join('mp.user', 'u')
            ->where('u = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }
}

