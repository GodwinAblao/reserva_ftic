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
        $conn = $this->getEntityManager()->getConnection();

        // Use native SQL to delete notification logs first (foreign key constraint)
        try {
            $conn->executeStatement('DELETE FROM class_schedule_notification_log');
        } catch (\Exception $e) {
            error_log('[DELETE ALL] Failed to clear notification logs: ' . $e->getMessage());
        }

        // Now delete class schedules using native SQL
        try {
            return $conn->executeStatement('DELETE FROM class_schedule');
        } catch (\Exception $e) {
            error_log('[DELETE ALL] Failed to clear class schedules: ' . $e->getMessage());
            throw $e;
        }
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

    /**
     * Find all class schedules by import batch ID.
     * @return ClassSchedule[]
     */
    public function findByImportBatchId(string $importBatchId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.importBatchId = :importBatchId')
            ->setParameter('importBatchId', $importBatchId)
            ->orderBy('c.scheduleDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Delete all class schedules by import batch ID.
     * Returns the number of deleted records.
     */
    public function deleteByImportBatchId(string $importBatchId): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->andWhere('c.importBatchId = :importBatchId')
            ->setParameter('importBatchId', $importBatchId)
            ->getQuery()
            ->execute();
    }

    /**
     * Delete all class schedules in the same series (same course, section, faculty, day, and time).
     * Returns the number of deleted records.
     */
    public function deleteBySeries(string $courseCode, ?string $section, ?string $facultyName, string $dayOfWeek, string $startTime, string $endTime): int
    {
        $qb = $this->createQueryBuilder('c')
            ->delete()
            ->andWhere('c.courseCode = :courseCode')
            ->andWhere('c.dayOfWeek = :dayOfWeek')
            ->andWhere('c.startTime = :startTime')
            ->andWhere('c.endTime = :endTime')
            ->setParameter('courseCode', $courseCode)
            ->setParameter('dayOfWeek', $dayOfWeek)
            ->setParameter('startTime', $startTime)
            ->setParameter('endTime', $endTime);

        if ($section !== null) {
            $qb->andWhere('c.section = :section')
               ->setParameter('section', $section);
        } else {
            $qb->andWhere('c.section IS NULL');
        }

        if ($facultyName !== null) {
            $qb->andWhere('c.facultyName = :facultyName')
               ->setParameter('facultyName', $facultyName);
        } else {
            $qb->andWhere('c.facultyName IS NULL');
        }

        return $qb->getQuery()->execute();
    }

    /**
     * Get existing schedules with details for conflict checking.
     * @return array<int, array{scheduleDate: string, startTime: string, endTime: string, facility: string, courseCode: string, section: ?string}>
     */
    public function getExistingSchedulesWithDetails(): array
    {
        $schedules = $this->createQueryBuilder('c')
            ->leftJoin('c.facility', 'f')
            ->addSelect('f')
            ->orderBy('c.scheduleDate', 'ASC')
            ->addOrderBy('c.startTime', 'ASC')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($schedules as $schedule) {
            \assert($schedule instanceof ClassSchedule);
            $result[] = [
                'id' => $schedule->getId(),
                'scheduleDate' => $schedule->getScheduleDate()?->format('Y-m-d') ?? '',
                'startTime' => $schedule->getStartTime()?->format('H:i') ?? '',
                'endTime' => $schedule->getEndTime()?->format('H:i') ?? '',
                'facility' => $schedule->getFacility()?->getName() ?? 'Unknown',
                'courseCode' => $schedule->getCourseCode(),
                'section' => $schedule->getSection(),
                'facultyName' => $schedule->getFacultyName(),
                'dayOfWeek' => $schedule->getDayOfWeek(),
            ];
        }

        return $result;
    }
}
