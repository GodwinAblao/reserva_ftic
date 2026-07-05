<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ClassSchedule;
use App\Entity\Facility;
use App\Entity\FacilityScheduleBlock;
use App\Entity\Reservation;
use App\Entity\ReservationConflict;
use App\Repository\ClassScheduleRepository;
use App\Repository\FacilityScheduleBlockRepository;
use App\Repository\ReservationConflictRepository;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;

class ConflictDetectionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReservationRepository $reservationRepo,
        private readonly ClassScheduleRepository $classScheduleRepo,
        private readonly FacilityScheduleBlockRepository $blockRepo,
        private readonly ReservationConflictRepository $conflictRepo,
    ) {
    }

    /**
     * Detect all conflicts for an institutional event reservation and persist them.
     */
    public function detectAndStoreConflicts(Reservation $reservation): int
    {
        $facility = $reservation->getFacility();
        $date     = $reservation->getReservationDate();
        $start    = $reservation->getReservationStartTime();
        $end      = $reservation->getReservationEndTime();

        if (!$facility || !$date || !$start || !$end) {
            return 0;
        }

        $count = 0;

        // 1. Conflicting reservations (Pending + Approved, excluding self)
        $count += $this->detectReservationConflicts($reservation, $facility, $date, $start, $end);

        // 2. Conflicting class schedules
        $count += $this->detectClassConflicts($reservation, $facility, $date, $start, $end);

        // 3. Conflicting schedule blocks (Blocked + Maintenance)
        $count += $this->detectBlockConflicts($reservation, $facility, $date, $start, $end);

        return $count;
    }

    private function detectReservationConflicts(
        Reservation $institutional,
        Facility $facility,
        \DateTimeInterface $date,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
    ): int {
        $startOfDay = (clone $date)->setTime(0, 0, 0);
        $endOfDay   = (clone $date)->setTime(23, 59, 59);

        $conflicting = $this->reservationRepo->createQueryBuilder('r')
            ->andWhere('r.facility = :facility')
            ->andWhere('r.reservationDate BETWEEN :startOfDay AND :endOfDay')
            ->andWhere('r.status IN (:statuses)')
            ->andWhere('r.reservationStartTime < :endTime')
            ->andWhere('r.reservationEndTime > :startTime')
            ->andWhere('r.id != :selfId')
            ->setParameter('facility', $facility)
            ->setParameter('startOfDay', $startOfDay)
            ->setParameter('endOfDay', $endOfDay)
            ->setParameter('statuses', ['Approved', 'Pending'])
            ->setParameter('startTime', $start->format('H:i:s'))
            ->setParameter('endTime', $end->format('H:i:s'))
            ->setParameter('selfId', $institutional->getId())
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($conflicting as $res) {
            /** @var Reservation $res */
            if ($this->conflictAlreadyExists($institutional, ReservationConflict::TYPE_RESERVATION, $res->getId())) {
                continue;
            }

            $conflict = new ReservationConflict();
            $conflict->setReservation($institutional);
            $conflict->setConflictType(ReservationConflict::TYPE_RESERVATION);
            $conflict->setConflictItemId($res->getId());
            $conflict->setConflictItemLabel($res->getEventName() ?: ('Reservation #' . $res->getId()));
            $conflict->setConflictItemFacility($res->getFacility()?->getName());
            $conflict->setConflictDate($res->getReservationDate());
            $conflict->setConflictStartTime($res->getReservationStartTime());
            $conflict->setConflictEndTime($res->getReservationEndTime());
            $conflict->setConflictStatus($res->getStatus());
            $conflict->setConflictOwner($res->getName());
            $conflict->setConflictOwnerEmail($res->getEmail());

            $this->em->persist($conflict);
            ++$count;
        }

        if ($count > 0) {
            $this->em->flush();
        }

        return $count;
    }

    private function detectClassConflicts(
        Reservation $institutional,
        Facility $facility,
        \DateTimeInterface $date,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
    ): int {
        $conflicting = $this->classScheduleRepo->createQueryBuilder('c')
            ->andWhere('c.facility = :facility')
            ->andWhere('c.scheduleDate = :date')
            ->andWhere('c.startTime < :endTime')
            ->andWhere('c.endTime > :startTime')
            ->setParameter('facility', $facility)
            ->setParameter('date', $date->format('Y-m-d'))
            ->setParameter('startTime', $start->format('H:i:s'))
            ->setParameter('endTime', $end->format('H:i:s'))
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($conflicting as $schedule) {
            /** @var ClassSchedule $schedule */
            if ($this->conflictAlreadyExists($institutional, ReservationConflict::TYPE_CLASS_SCHEDULE, $schedule->getId())) {
                continue;
            }

            $conflict = new ReservationConflict();
            $conflict->setReservation($institutional);
            $conflict->setConflictType(ReservationConflict::TYPE_CLASS_SCHEDULE);
            $conflict->setConflictItemId($schedule->getId());
            $conflict->setConflictItemLabel($schedule->getDisplayTitle());
            $conflict->setConflictItemFacility($schedule->getFacility()?->getName());
            $conflict->setConflictDate($schedule->getScheduleDate());
            $conflict->setConflictStartTime($schedule->getStartTime());
            $conflict->setConflictEndTime($schedule->getEndTime());
            $conflict->setConflictProfessor($schedule->getFacultyName());
            $conflict->setConflictProfessorEmail($schedule->getFacultyEmail());
            $conflict->setConflictCourse($schedule->getCourseCode());
            $conflict->setConflictSection($schedule->getSection());
            $conflict->setConflictStatus($schedule->getStatus() ?: 'Active');

            $this->em->persist($conflict);
            ++$count;
        }

        if ($count > 0) {
            $this->em->flush();
        }

        return $count;
    }

    private function detectBlockConflicts(
        Reservation $institutional,
        Facility $facility,
        \DateTimeInterface $date,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
    ): int {
        $conflicting = $this->blockRepo->createQueryBuilder('b')
            ->andWhere('b.facility = :facility')
            ->andWhere('b.blockDate = :date')
            ->andWhere('b.startTime < :endTime')
            ->andWhere('b.endTime > :startTime')
            ->setParameter('facility', $facility)
            ->setParameter('date', $date->format('Y-m-d'))
            ->setParameter('startTime', $start->format('H:i:s'))
            ->setParameter('endTime', $end->format('H:i:s'))
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($conflicting as $block) {
            /** @var FacilityScheduleBlock $block */
            if ($this->conflictAlreadyExists($institutional, ReservationConflict::TYPE_BLOCK, $block->getId())) {
                continue;
            }

            $conflict = new ReservationConflict();
            $conflict->setReservation($institutional);
            $conflict->setConflictType(ReservationConflict::TYPE_BLOCK);
            $conflict->setConflictItemId($block->getId());
            $conflict->setConflictItemLabel($block->getTitle());
            $conflict->setConflictItemFacility($block->getFacility()?->getName());
            $conflict->setConflictDate($block->getBlockDate());
            $conflict->setConflictStartTime($block->getStartTime());
            $conflict->setConflictEndTime($block->getEndTime());
            $conflict->setConflictStatus($block->getType());

            $this->em->persist($conflict);
            ++$count;
        }

        if ($count > 0) {
            $this->em->flush();
        }

        return $count;
    }

    private function conflictAlreadyExists(Reservation $reservation, string $type, int $itemId): bool
    {
        return (int) $this->conflictRepo->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.reservation = :res')
            ->andWhere('c.conflictType = :type')
            ->andWhere('c.conflictItemId = :itemId')
            ->setParameter('res', $reservation)
            ->setParameter('type', $type)
            ->setParameter('itemId', $itemId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Returns true if the reservation has any unresolved conflicts.
     */
    public function hasUnresolvedConflicts(Reservation $reservation): bool
    {
        return $this->conflictRepo->hasUnresolvedConflicts($reservation);
    }

    /**
     * @return ReservationConflict[]
     */
    public function getConflicts(Reservation $reservation): array
    {
        return $this->conflictRepo->findByReservation($reservation);
    }
}
