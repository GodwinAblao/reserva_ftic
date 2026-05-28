<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ClassSchedule;
use App\Repository\ClassScheduleRepository;
use App\Repository\FacilityRepository;
use App\Repository\FacilityScheduleBlockRepository;
use App\Repository\ReservationRepository;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class CalendarDataService
{
    public function __construct(
        private readonly ReservationRepository $reservationRepo,
        private readonly FacilityRepository $facilityRepo,
        private readonly FacilityScheduleBlockRepository $blockRepo,
        private readonly ClassScheduleRepository $classScheduleRepo,
        private readonly ClassScheduleFacultyMatcher $facultyMatcher,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
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
        bool $includeNotifyTokens = false,
    ): array {
        $data = [];
        $startDate = new \DateTime($start);
        $endDate = new \DateTime($end);
        $reservationStatuses = ['Pending', 'Approved', 'Rejected', 'Cancelled'];
        $blockStatuses = ['Blocked', 'Manual', 'Maintenance', 'Imported'];

        if (!$status || in_array($status, $reservationStatuses, true)) {
            $qb = $this->reservationRepo->createQueryBuilder('r')
                ->select('r.id', 'r.name', 'r.eventName', 'r.email', 'r.contact', 'r.reservationDate', 'r.reservationStartTime', 'r.reservationEndTime', 'r.capacity', 'r.purpose', 'r.status', 'f.id as facilityId', 'f.name as facilityName', 'f.capacity as facilityCapacity')
                ->innerJoin('r.facility', 'f')
                ->where('r.reservationDate BETWEEN :start AND :end')
                ->andWhere('r.status != :suggestedStatus')
                ->setParameter('start', $startDate)
                ->setParameter('end', $endDate)
                ->setParameter('suggestedStatus', 'Suggested')
                ->orderBy('r.reservationDate', 'ASC')
                ->addOrderBy('r.reservationStartTime', 'ASC');

            if ($facilityId) {
                $qb->andWhere('f.id = :facilityId')->setParameter('facilityId', (int) $facilityId);
            }

            if ($status && in_array($status, $reservationStatuses, true)) {
                $qb->andWhere('r.status = :status')->setParameter('status', $status);
            }

            foreach ($qb->getQuery()->getArrayResult() as $r) {
                $data[] = [
                    'id' => $r['id'],
                    'itemType' => 'reservation',
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

        if (!$status || $status === 'Class Schedule' || $status === 'Blocked' || $status === 'Maintenance') {
            $classFacility = $facilityId ? $this->facilityRepo->find((int) $facilityId) : null;
            $schedules = $this->classScheduleRepo->findBetween($startDate, $endDate, $classFacility);

            foreach ($schedules as $schedule) {
                $scheduleStatus = $schedule->getStatus();
                // Filter by status if specified
                if ($status && $status !== 'Class Schedule') {
                    if ($scheduleStatus !== $status) {
                        continue;
                    }
                } else if ($status === 'Class Schedule' && $scheduleStatus) {
                    // When filtering by Class Schedule, only show normal schedules (no status)
                    continue;
                }
                $data[] = $this->formatClassScheduleForCalendar($schedule, $includeNotifyTokens);
            }
        }

        if ($includeBlocks && (!$status || in_array($status, $blockStatuses, true))) {
            $blockFacility = $facilityId ? $this->facilityRepo->find((int) $facilityId) : null;
            $blockType = $status && in_array($status, $blockStatuses, true) ? $status : null;
            $blocks = $this->blockRepo->findBetween($startDate, $endDate, $blockFacility, $blockType);

            foreach ($blocks as $block) {
                if ($block->getType() === 'Class Schedule') {
                    continue;
                }

                $facility = $block->getFacility();
                if ($facilityId && $facility->getId() != $facilityId) {
                    continue;
                }

                $data[] = [
                    'id' => 'block_' . $block->getId(),
                    'itemType' => 'block',
                    'name' => $block->getTitle(),
                    'eventName' => $block->getTitle(),
                    'email' => '',
                    'contact' => '',
                    'purpose' => $block->getNotes(),
                    'status' => $block->getType() ?: 'Manual',
                    'capacity' => 0,
                    'reservationDate' => $block->getBlockDate()->format('Y-m-d'),
                    'reservationStartTime' => $block->getStartTime()->format('H:i'),
                    'reservationEndTime' => $block->getEndTime()->format('H:i'),
                    'originalItemType' => $block->getOriginalItemType(),
                    'originalItemId' => $block->getOriginalItemId(),
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

    /**
     * @return array<string, mixed>
     */
    private function formatClassScheduleForCalendar(ClassSchedule $schedule, bool $includeNotifyTokens): array
    {
        $facility = $schedule->getFacility();
        $facultyUser = $schedule->getFacultyUser();
        $verified = $this->facultyMatcher->isVerifiedFaculty($facultyUser);

        $payload = [
            'id' => 'class_' . $schedule->getId(),
            'itemType' => 'class_schedule',
            'name' => $schedule->getFacultyName() ?? $schedule->getCourseCode(),
            'eventName' => $schedule->getDisplayTitle(),
            'email' => $schedule->getFacultyEmail() ?? '',
            'contact' => '',
            'purpose' => '',
            'status' => $schedule->getStatus() ?: 'Class Schedule',
            'capacity' => 0,
            'courseCode' => $schedule->getCourseCode(),
            'section' => $schedule->getSection(),
            'facultyName' => $schedule->getFacultyName(),
            'facultyEmail' => $schedule->getFacultyEmail(),
            'facultyVerified' => $verified,
            'isRelocated' => $schedule->isRelocated(),
            'previousFacilityName' => $schedule->getPreviousFacility()?->getName(),
            'reservationDate' => $schedule->getScheduleDate()->format('Y-m-d'),
            'reservationStartTime' => $schedule->getStartTime()->format('H:i'),
            'reservationEndTime' => $schedule->getEndTime()->format('H:i'),
            'startDate' => $schedule->getStartDate()?->format('Y-m-d'),
            'endDate' => $schedule->getEndDate()?->format('Y-m-d'),
            'facility' => [
                'id' => $facility->getId(),
                'name' => $facility->getName(),
                'capacity' => $facility->getCapacity(),
            ],
            'isBlock' => false,
        ];

        if ($includeNotifyTokens && $schedule->getId()) {
            $id = $schedule->getId();
            $payload['notifyCsrfToken'] = $this->csrfTokenManager
                ->getToken('class_schedule_notify_' . $id)
                ->getValue();
            $payload['updateCsrfToken'] = $this->csrfTokenManager
                ->getToken('class_schedule_update_' . $id)
                ->getValue();
            $payload['deleteCsrfToken'] = $this->csrfTokenManager
                ->getToken('class_schedule_delete_' . $id)
                ->getValue();
        }

        return $payload;
    }
}
