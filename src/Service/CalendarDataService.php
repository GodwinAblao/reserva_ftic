<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\FacilityRepository;
use App\Repository\FacilityScheduleBlockRepository;
use App\Repository\ReservationRepository;

class CalendarDataService
{
    public function __construct(
        private readonly ReservationRepository $reservationRepo,
        private readonly FacilityRepository $facilityRepo,
        private readonly FacilityScheduleBlockRepository $blockRepo,
    ) {
    }

    /**
     * @return array{reservations: list<array<string, mixed>>}
     */
    public function buildCalendarPayload(
        string $start,
        string $end,
        ?string $facilityId,
        ?string $status,
        bool $includeBlocks = true,
    ): array {
        $data = [];
        $startDate = new \DateTime($start);
        $endDate = new \DateTime($end);

        if (!$status || !in_array($status, ['Class Schedule', 'Blocked', 'Manual', 'Maintenance'], true)) {
            $qb = $this->reservationRepo->createQueryBuilder('r')
                ->select('r.id', 'r.name', 'r.eventName', 'r.email', 'r.contact', 'r.reservationDate', 'r.reservationStartTime', 'r.reservationEndTime', 'r.capacity', 'r.purpose', 'r.status', 'f.id as facilityId', 'f.name as facilityName', 'f.capacity as facilityCapacity')
                ->innerJoin('r.facility', 'f')
                ->where('r.reservationDate BETWEEN :start AND :end')
                ->setParameter('start', $startDate)
                ->setParameter('end', $endDate)
                ->orderBy('r.reservationDate', 'ASC')
                ->addOrderBy('r.reservationStartTime', 'ASC');

            if ($facilityId) {
                $qb->andWhere('f.id = :facilityId')
                    ->setParameter('facilityId', (int) $facilityId);
            }

            if ($status) {
                $qb->andWhere('r.status = :status')
                    ->setParameter('status', $status);
            }

            foreach ($qb->getQuery()->getArrayResult() as $r) {
                $data[] = [
                    'id' => $r['id'],
                    'name' => $r['name'],
                    'eventName' => $r['eventName'],
                    'email' => $r['email'],
                    'contact' => $r['contact'],
                    'reservationDate' => $r['reservationDate']->format('Y-m-d'),
                    'reservationStartTime' => $r['reservationStartTime']->format('H:i'),
                    'reservationEndTime' => $r['reservationEndTime']->format('H:i'),
                    'capacity' => $r['capacity'],
                    'purpose' => $r['purpose'],
                    'status' => $r['status'],
                    'isBlock' => false,
                    'facility' => [
                        'id' => $r['facilityId'],
                        'name' => $r['facilityName'],
                        'capacity' => $r['facilityCapacity'],
                    ],
                ];
            }
        }

        $blockStatuses = ['Class Schedule', 'Blocked', 'Manual', 'Maintenance', 'Imported'];

        if ($includeBlocks && (!$status || in_array($status, $blockStatuses, true))) {
            $blockFacility = $facilityId ? $this->facilityRepo->find((int) $facilityId) : null;
            $blockType = $status && in_array($status, $blockStatuses, true) ? $status : null;
            $blocks = $this->blockRepo->findBetween($startDate, $endDate, $blockFacility, $blockType);

            foreach ($blocks as $block) {
                $facility = $block->getFacility();
                if ($facilityId && $facility->getId() != $facilityId) {
                    continue;
                }
                $data[] = [
                    'id' => 'block_' . $block->getId(),
                    'name' => $block->getTitle(),
                    'email' => '',
                    'contact' => '',
                    'purpose' => $block->getNotes(),
                    'status' => $block->getType() ?: 'Class Schedule',
                    'capacity' => 0,
                    'reservationDate' => $block->getBlockDate()->format('Y-m-d'),
                    'reservationStartTime' => $block->getStartTime()->format('H:i'),
                    'reservationEndTime' => $block->getEndTime()->format('H:i'),
                    'facility' => [
                        'id' => $facility->getId(),
                        'name' => $facility->getName(),
                        'capacity' => $facility->getCapacity(),
                    ],
                    'isBlock' => true,
                ];
            }
        }

        return ['reservations' => $data];
    }
}
