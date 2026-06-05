<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Facility;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Facility>
 */
class FacilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Facility::class);
    }

    public function findAll(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    public function findEnabled(): array
    {
        return $this->findBy(['availableForReservation' => true], ['name' => 'ASC']);
    }

    /**
     * @return Facility[]
     */
    public function findEnabledWithImages(): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.images', 'i')
            ->addSelect('i')
            ->andWhere('f.availableForReservation = :available')
            ->setParameter('available', true)
            ->orderBy('f.name', 'ASC')
            ->addOrderBy('i.position', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(Facility $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Facility $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
