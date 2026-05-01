<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MentorCustomRequest;
use App\Entity\MentorProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MentorCustomRequest>
 */
class MentorCustomRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MentorCustomRequest::class);
    }

    public function findByMentor(MentorProfile $mentor): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.mentorProfile = :mentor')
            ->orderBy('r.createdAt', 'DESC')
            ->setParameter('mentor', $mentor)
            ->getQuery()
            ->getResult();
    }

    public function findByStudent(User $student): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.student = :student')
            ->orderBy('r.createdAt', 'DESC')
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();
    }
}

