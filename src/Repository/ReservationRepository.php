<?php

namespace App\Repository;

use App\Entity\Reservation;
use App\Entity\User;
use App\Entity\Facility;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reservation>
 *
 * @method Reservation|null find($id, $lockMode = null, $lockVersion = null)
 * @method Reservation|null findOneBy(array $criteria, array $orderBy = null)
 * @method Reservation[]    findAll()
 * @method Reservation[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    public function save(Reservation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Reservation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find reservations for a specific user
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->orderBy('r.reservationDate', 'DESC')
            ->addOrderBy('r.reservationStartTime', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all pending reservations for super admin review
     */
    public function findPendingReservations(): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.status = :status')
            ->setParameter('status', 'Pending')
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if a facility is booked for a time range on a specific date
     */
    public function isTimeRangeBooked(
        Facility $facility,
        \DateTimeInterface $date,
        \DateTimeInterface $startTime,
        \DateTimeInterface $endTime,
        ?int $excludeReservationId = null,
        array $statuses = ['Approved']
    ): bool
    {
        $startOfDay = (clone $date)->setTime(0, 0, 0);
        $endOfDay = (clone $date)->setTime(23, 59, 59);

        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.facility = :facility')
            ->andWhere('r.reservationDate BETWEEN :startOfDay AND :endOfDay')
            ->andWhere('r.status IN (:statuses)')
            ->andWhere('r.reservationStartTime < :endTime')
            ->andWhere('r.reservationEndTime > :startTime')
            ->setParameter('facility', $facility)
            ->setParameter('startOfDay', $startOfDay)
            ->setParameter('endOfDay', $endOfDay)
            ->setParameter('statuses', $statuses)
            ->setParameter('startTime', $startTime)
            ->setParameter('endTime', $endTime);

        // Exclude the current reservation if provided (for updates)
        if ($excludeReservationId !== null) {
            $qb->andWhere('r.id != :excludeId')
               ->setParameter('excludeId', $excludeReservationId);
        }

        $count = $qb->getQuery()->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Get booked ranges for a facility on a specific date (approved only)
     */
    public function getBookedRangesForDate(Facility $facility, \DateTimeInterface $date): array
    {
        $startOfDay = (clone $date)->setTime(0, 0, 0);
        $endOfDay = (clone $date)->setTime(23, 59, 59);

        $results = $this->createQueryBuilder('r')
            ->select('r.reservationStartTime, r.reservationEndTime')
            ->andWhere('r.facility = :facility')
            ->andWhere('r.reservationDate BETWEEN :startOfDay AND :endOfDay')
            ->andWhere('r.status = :approved')
            ->setParameter('facility', $facility)
            ->setParameter('startOfDay', $startOfDay)
            ->setParameter('endOfDay', $endOfDay)
            ->setParameter('approved', 'Approved')
            ->getQuery()
            ->getResult();

        return array_map(
            fn($r) => [
                'start' => $r['reservationStartTime'],
                'end' => $r['reservationEndTime'],
            ],
            $results
        );
    }

    /**
     * Get pending ranges for a facility on a specific date
     */
    public function getPendingRangesForDate(Facility $facility, \DateTimeInterface $date): array
    {
        $startOfDay = (clone $date)->setTime(0, 0, 0);
        $endOfDay = (clone $date)->setTime(23, 59, 59);

        $results = $this->createQueryBuilder('r')
            ->select('r.reservationStartTime, r.reservationEndTime')
            ->andWhere('r.facility = :facility')
            ->andWhere('r.reservationDate BETWEEN :startOfDay AND :endOfDay')
            ->andWhere('r.status = :pending')
            ->setParameter('facility', $facility)
            ->setParameter('startOfDay', $startOfDay)
            ->setParameter('endOfDay', $endOfDay)
            ->setParameter('pending', 'Pending')
            ->getQuery()
            ->getResult();

        return array_map(
            fn($r) => [
                'start' => $r['reservationStartTime'],
                'end' => $r['reservationEndTime'],
            ],
            $results
        );
    }

    /**
     * Find available alternatives for a given capacity, date/time, excluding a facility
     * Returns facilities with capacity >= requested capacity that are available on that date/time
     */
    public function findAvailableAlternatives(
        int $capacity,
        \DateTimeInterface $date,
        \DateTimeInterface $startTime,
        \DateTimeInterface $endTime,
        ?Facility $excludeFacility = null
    ): array {
        $startOfDay = (clone $date)->setTime(0, 0, 0);
        $endOfDay = (clone $date)->setTime(23, 59, 59);

        $facilitiesWithCapacity = $this->getEntityManager()
            ->getRepository(Facility::class)
            ->createQueryBuilder('f')
            ->andWhere('f.capacity >= :capacity')
            ->setParameter('capacity', $capacity)
            ->orderBy('f.capacity', 'ASC')
            ->getQuery()
            ->getResult();

        $alternatives = [];

        foreach ($facilitiesWithCapacity as $facility) {
            // Exclude the original facility if provided
            if ($excludeFacility && $facility->getId() === $excludeFacility->getId()) {
                continue;
            }

            // Check if facility is available for the given time range
            $bookingCount = $this->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->andWhere('r.facility = :facility')
                ->andWhere('r.reservationDate BETWEEN :startOfDay AND :endOfDay')
                ->andWhere('r.status IN (:statuses)')
                ->andWhere('r.reservationStartTime < :endTime')
                ->andWhere('r.reservationEndTime > :startTime')
                ->setParameter('facility', $facility)
                ->setParameter('startOfDay', $startOfDay)
                ->setParameter('endOfDay', $endOfDay)
                ->setParameter('statuses', ['Approved', 'Pending'])
                ->setParameter('startTime', $startTime)
                ->setParameter('endTime', $endTime)
                ->getQuery()
                ->getSingleScalarResult();

            if ($bookingCount === 0) {
                $alternatives[] = $facility;
            }
        }

        return $alternatives;
    }

    /**
     * Find suggested facilities for a given capacity and date/time
     */
    public function findSuggestedFacilities(int $capacity, \DateTimeInterface $date, \DateTimeInterface $time): array
    {
        return $this->getEntityManager()
            ->getRepository(Facility::class)
            ->createQueryBuilder('f')
            ->andWhere('f.capacity >= :capacity')
            ->setParameter('capacity', $capacity)
            ->orderBy('f.capacity', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count reservations by status for a user
     */
    public function countByStatusForUser(User $user, string $status): int
    {
        return (int)$this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.user = :user')
            ->andWhere('r.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
