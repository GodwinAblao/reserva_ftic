<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Specialization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Specialization>
 */
class SpecializationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Specialization::class);
    }

    public function findAllOrderedByName(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(Specialization $specialization): void
    {
        $this->getEntityManager()->persist($specialization);
        $this->getEntityManager()->flush();
    }

    public function remove(Specialization $specialization): void
    {
        $this->getEntityManager()->remove($specialization);
        $this->getEntityManager()->flush();
    }
}
