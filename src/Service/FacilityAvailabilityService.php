<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Facility;

/**
 * Builds facility availability for the public reservation page.
 * Uses the same calendar payload as /super-admin/calendar (CalendarDataService).
 */
class FacilityAvailabilityService
{
    private const BLOCKING_RESERVATION_STATUSES = ['Approved'];
    private const PENDING_RESERVATION_STATUSES = ['Pending'];

    public function __construct(
        private readonly CalendarDataService $calendarData,
    ) {
    }

    /**
     * @return array{
     *   bookedTimes: array<string, list<array{start: string, end: string}>>,
     *   pendingTimes: array<string, list<array{start: string, end: string}>>,
     *   classTimes: array<string, list<array{start: string, end: string}>>,
     *   blockedTimes: array<string, list<array{start: string, end: string}>>,
     *   maintenanceTimes: array<string, list<array{start: string, end: string}>>
     * }
     */
    public function buildAvailabilityMap(
        Facility $facility,
        \DateTimeInterface $startDate,
        int $days,
    ): array {
        $days = max(1, min(120, $days));
        $endDate = \DateTime::createFromInterface($startDate)->modify('+' . ($days - 1) . ' days');

        $payload = $this->calendarData->buildCalendarPayload(
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d'),
            (string) $facility->getId(),
            null,
            true,
            false,
        );

        $bookedTimes = [];
        $pendingTimes = [];
        $classTimes = [];
        $blockedTimes = [];
        $maintenanceTimes = [];

        foreach ($payload['reservations'] as $event) {
            $dateStr = $event['reservationDate'] ?? '';
            if ($dateStr === '') {
                continue;
            }

            if (!isset($bookedTimes[$dateStr])) {
                $bookedTimes[$dateStr] = [];
                $pendingTimes[$dateStr] = [];
                $classTimes[$dateStr] = [];
                $blockedTimes[$dateStr] = [];
                $maintenanceTimes[$dateStr] = [];
            }

            $range = [
                'start' => $event['reservationStartTime'],
                'end' => $event['reservationEndTime'],
            ];

            $itemType = $event['itemType'] ?? (!empty($event['isBlock']) ? 'block' : 'reservation');
            $status = (string) ($event['status'] ?? '');

            if ($itemType === 'class_schedule') {
                // Check if class schedule has blocked/maintenance status
                if ($status === 'Blocked') {
                    $blockedTimes[$dateStr][] = $range;
                } elseif ($status === 'Maintenance') {
                    $maintenanceTimes[$dateStr][] = $range;
                } else {
                    $classTimes[$dateStr][] = $range;
                }
                continue;
            }

            if ($itemType === 'block') {
                // Separate blocks by type
                if ($status === 'Maintenance') {
                    $maintenanceTimes[$dateStr][] = $range;
                } else {
                    $blockedTimes[$dateStr][] = $range;
                }
                continue;
            }

            if (in_array($status, self::BLOCKING_RESERVATION_STATUSES, true)) {
                $bookedTimes[$dateStr][] = $range;
            } elseif (in_array($status, self::PENDING_RESERVATION_STATUSES, true)) {
                $pendingTimes[$dateStr][] = $range;
            }
        }

        return [
            'bookedTimes' => $bookedTimes,
            'pendingTimes' => $pendingTimes,
            'classTimes' => $classTimes,
            'blockedTimes' => $blockedTimes,
            'maintenanceTimes' => $maintenanceTimes,
        ];
    }
}
