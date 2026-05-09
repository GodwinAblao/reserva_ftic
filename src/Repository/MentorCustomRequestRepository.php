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

    public function findAssistanceRequests(?string $status = null, ?string $department = null, ?string $date = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.student', 's')
            ->addSelect('s')
            ->where('r.mentorProfile IS NULL')
            ->orderBy('r.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('r.status = :status')
                ->setParameter('status', $status);
        }

        if ($department) {
            $qb->andWhere('r.departmentCourse LIKE :department')
                ->setParameter('department', '%' . $department . '%');
        }

        if ($date) {
            $start = \DateTime::createFromFormat('Y-m-d H:i:s', $date . ' 00:00:00');
            $end = \DateTime::createFromFormat('Y-m-d H:i:s', $date . ' 23:59:59');
            if ($start && $end) {
                $qb->andWhere('r.createdAt BETWEEN :startDate AND :endDate')
                    ->setParameter('startDate', $start)
                    ->setParameter('endDate', $end);
            }
        }

        return $qb->getQuery()->getResult();
    }
}

