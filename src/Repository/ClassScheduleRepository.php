<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClassSchedule;
use App\Entity\Facility;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClassSchedule>
 */
class ClassScheduleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassSchedule::class);
    }

    public function deleteAll(): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->getQuery()
            ->execute();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Changes whenever class schedules are imported, deleted, or edited (used to sync user calendars).
     */
    public function getGlobalRevisionToken(): string
    {
        $schedules = $this->createQueryBuilder('c')
            ->leftJoin('c.facility', 'f')
            ->addSelect('f')
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        $parts = [];
        foreach ($schedules as $schedule) {
            \assert($schedule instanceof ClassSchedule);
            $parts[] = implode('|', [
                $schedule->getId(),
                $schedule->getFacility()?->getId() ?? '',
                $schedule->getScheduleDate()?->format('Y-m-d') ?? '',
                $schedule->getStartTime()?->format('H:i:s') ?? '',
                $schedule->getEndTime()?->format('H:i:s') ?? '',
                $schedule->getCourseCode(),
                $schedule->getSection() ?? '',
                $schedule->getUpdatedAt()->format('Y-m-d H:i:s.u'),
            ]);
        }

        return count($parts) . ':' . sha1(implode("\n", $parts));
    }

    /**
     * @return ClassSchedule[]
     */
    public function findBetween(\DateTimeInterface $start, \DateTimeInterface $end, ?Facility $facility = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.facility', 'f')
            ->addSelect('f')
            ->leftJoin('c.facultyUser', 'u')
            ->addSelect('u')
            ->leftJoin('c.previousFacility', 'pf')
            ->addSelect('pf')
            ->andWhere('c.scheduleDate BETWEEN :start AND :end')
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->orderBy('c.scheduleDate', 'ASC')
            ->addOrderBy('c.startTime', 'ASC');

        if ($facility !== null) {
            $qb->andWhere('c.facility = :facility')->setParameter('facility', $facility);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return ClassSchedule[]
     */
    public function findForDate(Facility $facility, \DateTimeInterface $date): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.facility = :facility')
            ->andWhere('c.scheduleDate = :date')
            ->setParameter('facility', $facility)
            ->setParameter('date', $date->format('Y-m-d'))
            ->orderBy('c.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function conflictsWith(
        Facility $facility,
        \DateTimeInterface $date,
        \DateTimeInterface $startTime,
        \DateTimeInterface $endTime,
        ?int $excludeId = null,
    ): bool {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.facility = :facility')
            ->andWhere('c.scheduleDate = :date')
            ->andWhere('c.startTime < :endTime')
            ->andWhere('c.endTime > :startTime')
            ->setParameter('facility', $facility)
            ->setParameter('date', $date->format('Y-m-d'))
            ->setParameter('startTime', $startTime->format('H:i:s'))
            ->setParameter('endTime', $endTime->format('H:i:s'));

        if ($excludeId !== null) {
            $qb->andWhere('c.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * @return array<string, int> matchKey => facilityId
     */
    public function buildFacilityMapForImportDiff(): array
    {
        $map = [];
        /** @var ClassSchedule $schedule */
        foreach ($this->findAll() as $schedule) {
            $key = self::buildMatchKey(
                $schedule->getCourseCode(),
                $schedule->getSection() ?? '',
                $schedule->getDayOfWeek() ?? '',
                $schedule->getStartTime()?->format('H:i') ?? '',
                $schedule->getEndTime()?->format('H:i') ?? '',
            );
            $map[$key] = $schedule->getFacility()?->getId() ?? 0;
        }

        return $map;
    }

    /**
     * Facilities with no reservation, block, or other class conflict for the given slot.
     *
     * @return Facility[]
     */
    public function findAvailableFacilitiesForSlot(
        \DateTimeInterface $date,
        \DateTimeInterface $startTime,
        \DateTimeInterface $endTime,
        ?int $excludeScheduleId = null,
    ): array {
        $em = $this->getEntityManager();
        /** @var Facility[] $facilities */
        $facilities = $em->getRepository(Facility::class)->findBy([], ['name' => 'ASC']);
        /** @var ReservationRepository $reservationRepo */
        $reservationRepo = $em->getRepository(Reservation::class);

        $available = [];
        foreach ($facilities as $facility) {
            if ($reservationRepo->isTimeRangeBooked(
                $facility,
                $date,
                $startTime,
                $endTime,
                null,
                ['Approved', 'Pending'],
                $excludeScheduleId,
            )) {
                continue;
            }

            $available[] = $facility;
        }

        return $available;
    }

    public static function buildMatchKey(
        string $courseCode,
        string $section,
        string $dayOfWeek,
        string $startTime,
        string $endTime,
    ): string {
        return strtolower(implode('|', [
            trim($courseCode),
            trim($section),
            trim($dayOfWeek),
            trim($startTime),
            trim($endTime),
        ]));
    }
}
