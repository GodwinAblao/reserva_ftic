<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Facility;
use App\Entity\FacilityImage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FacilityImage>
 */
class FacilityImageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FacilityImage::class);
    }

    public function save(FacilityImage $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(FacilityImage $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findForFacility(Facility $facility): array
    {
        return $this->findBy(['facility' => $facility], ['position' => 'ASC', 'id' => 'ASC']);
    }
}
