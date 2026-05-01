<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ResearchContent;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ResearchContent>
 */
class ResearchContentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResearchContent::class);
    }

    public function findByAuthor(User $user): array
    {
        return $this->createQueryBuilder('rc')
            ->where('rc.author = :author')
            ->setParameter('author', $user)
            ->getQuery()
            ->getResult();
    }
}

