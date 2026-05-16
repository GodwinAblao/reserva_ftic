<?php

namespace App\Controller;

use App\Entity\Facility;
use App\Entity\FacilityScheduleBlock;
use App\Entity\Reservation;
use App\Repository\FacilityRepository;
use App\Repository\FacilityScheduleBlockRepository;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/super-admin')]
#[IsGranted('ROLE_ADMIN')]
class SuperAdminReservationController extends AbstractController
{
    #[Route('/reservations', name: 'admin_reservations')]
    public function listReservations(ReservationRepository $reservationRepo): Response
    {
        $pending = $reservationRepo->findBy(['status' => 'Pending'], ['createdAt' => 'DESC']);
        $approved = $reservationRepo->findBy(['status' => 'Approved'], ['reservationDate' => 'DESC']);
        $rejected = $reservationRepo->findBy(['status' => 'Rejected'], ['reservationDate' => 'DESC']);
        $suggested = $reservationRepo->findBy(['status' => 'Suggested'], ['updatedAt' => 'DESC']);

        return $this->render('super_admin/reservations.html.twig', [
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'suggested' => $suggested,
        ]);
    }

    #[Route('/reservations/{id}/approve', name: 'admin_approve_reservation', methods: ['POST'])]
    public function approveReservation(
        Reservation $reservation,
        Request $request,
        ReservationRepository $reservationRepo,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isCsrfTokenValid('approve_reservation_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Check if the reservation date is Sunday
        if ($reservation->getReservationDate()->format('w') == '0') {
            $this->addFlash('error', 'Cannot approve reservation: All facilities are closed on Sundays.');
            return $this->redirectToRoute('admin_reservations');
        }

        if ($reservationRepo->isTimeRangeBooked($reservation->getFacility(), $reservation->getReservationDate(), $reservation->getReservationStartTime(), $reservation->getReservationEndTime(), $reservation->getId(), ['Approved', 'Pending'])) {
            $this->addFlash('error', 'Cannot approve: this time slot is already booked for this facility.');
            return $this->redirectToRoute('admin_reservations');
        }

        $reservation->setStatus('Approved');
        $reservation->setUpdatedAt(new \DateTime());
        $em->flush();

        $this->addFlash('success', 'Reservation approved successfully.');

        return $this->redirectToRoute('admin_reservations');
    }

    #[Route('/reservations/{id}/reject', name: 'admin_reject_reservation', methods: ['POST'])]
    public function rejectReservation(
        Reservation $reservation,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isCsrfTokenValid('reject_reservation_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $reason = $request->request->get('reason') ?? 'Not specified';
        $reservation->setStatus('Rejected');
        $reservation->setRejectionReason($reason);
        $em->flush();

        $this->addFlash('success', 'Reservation rejected successfully.');

        return $this->redirectToRoute('admin_reservations');
    }

    #[Route('/reservations/{id}/suggest-alternatives', name: 'admin_suggest_alternatives', methods: ['GET'])]
    public function suggestAlternatives(
        Reservation $reservation,
        ReservationRepository $reservationRepo
    ): Response {
        $alternatives = $reservationRepo->findAvailableAlternatives(
            $reservation->getCapacity(),
            $reservation->getReservationDate(),
            $reservation->getReservationStartTime(),
            $reservation->getReservationEndTime(),
            $reservation->getFacility()
        );

        // Calculate duration in minutes
        $startMinutes = $reservation->getReservationStartTime()->format('H') * 60 + $reservation->getReservationStartTime()->format('i');
        $endMinutes = $reservation->getReservationEndTime()->format('H') * 60 + $reservation->getReservationEndTime()->format('i');
        $durationMinutes = abs($endMinutes - $startMinutes);

        return $this->render('super_admin/suggest_alternatives.html.twig', [
            'reservation' => $reservation,
            'alternatives' => $alternatives,
            'isEditingSuggestion' => $reservation->getStatus() === 'Suggested',
            'durationMinutes' => $durationMinutes,
        ]);
    }

    #[Route('/reservations/{id}/suggest-alternatives/{facilityId}', name: 'admin_confirm_suggest', methods: ['POST'])]
    public function confirmSuggestion(
        Reservation $reservation,
        int $facilityId,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isCsrfTokenValid('admin_suggest_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $facility = $em->getRepository(Facility::class)->find($facilityId);
        if (!$facility) {
            $this->addFlash('error', 'Suggested facility was not found.');

            return $this->redirectToRoute('admin_reservations');
        }

        $reservation->setSuggestedFacility($facility);
        $reservation->setStatus('Suggested');
        $reservation->setUpdatedAt(new \DateTime());
        $em->flush();

        $this->addFlash('success', 'Alternative facility suggested to the requester.');

        return $this->redirectToRoute('admin_reservations');
    }

    #[Route('/reservations/{id}/suggest-datetime', name: 'admin_suggest_datetime', methods: ['POST'])]
    public function suggestDateTime(
        Reservation $reservation,
        Request $request,
        ReservationRepository $reservationRepo,
        FacilityRepository $facilityRepo,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isCsrfTokenValid('admin_suggest_datetime_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $facilityId = $request->request->get('facility_id');
        $date = \DateTime::createFromFormat('!Y-m-d', $request->request->get('date'));
        $startTime = \DateTime::createFromFormat('!H:i', $request->request->get('start_time'));
        $endTime = \DateTime::createFromFormat('!H:i', $request->request->get('end_time'));

        if (!$facilityId || !$date || !$startTime || !$endTime || $endTime <= $startTime) {
            $this->addFlash('error', 'Invalid data. Please check facility, date, and time.');
            return $this->redirectToRoute('admin_suggest_alternatives', ['id' => $reservation->getId()]);
        }

        $facility = $facilityRepo->find($facilityId);
        if (!$facility) {
            $this->addFlash('error', 'Facility not found.');
            return $this->redirectToRoute('admin_suggest_alternatives', ['id' => $reservation->getId()]);
        }

        // Check if the date is Sunday (0 = Sunday in PHP)
        if ($date->format('w') == '0') {
            $this->addFlash('error', 'All facilities are closed on Sundays. Please choose a different date.');
            return $this->redirectToRoute('admin_suggest_alternatives', ['id' => $reservation->getId()]);
        }

        // Check if the suggested time slot is available
        if ($reservationRepo->isTimeRangeBooked($facility, $date, $startTime, $endTime, $reservation->getId(), ['Approved', 'Pending', 'Suggested'])) {
            $this->addFlash('error', 'The suggested time slot is already booked. Please choose a different time.');
            return $this->redirectToRoute('admin_suggest_alternatives', ['id' => $reservation->getId()]);
        }

        // Update reservation with suggested facility, date/time and set status to Suggested
        $reservation->setFacility($facility);
        $reservation->setReservationDate($date);
        $reservation->setReservationStartTime($startTime);
        $reservation->setReservationEndTime($endTime);
        $reservation->setStatus('Suggested');
        $reservation->setSuggestedFacility($facility);
        $reservation->setUpdatedAt(new \DateTime());
        $em->flush();

        $this->addFlash('success', 'Alternative schedule suggested successfully. The requester will be notified and can accept or reject this suggestion.');

        return $this->redirectToRoute('admin_reservations');
    }

    #[Route('/check-availability', name: 'admin_check_availability', methods: ['POST'])]
    public function checkAvailability(
        Request $request,
        ReservationRepository $reservationRepo,
        FacilityRepository $facilityRepo
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        $facilityId = $data['facility_id'] ?? null;
        $date = $data['date'] ?? null;
        $startTime = $data['start_time'] ?? null;
        $endTime = $data['end_time'] ?? null;
        $excludeReservationId = $data['exclude_reservation_id'] ?? null;

        if (!$facilityId || !$date || !$startTime || !$endTime) {
            return $this->json(['available' => false, 'message' => 'Missing required fields']);
        }

        $facility = $facilityRepo->find($facilityId);
        if (!$facility) {
            return $this->json(['available' => false, 'message' => 'Facility not found']);
        }

        $reservationDate = \DateTime::createFromFormat('!Y-m-d', $date);
        $start = \DateTime::createFromFormat('!H:i', $startTime);
        $end = \DateTime::createFromFormat('!H:i', $endTime);

        if (!$reservationDate || !$start || !$end || $end <= $start) {
            return $this->json(['available' => false, 'message' => 'Invalid date or time']);
        }

        // Check if the date is Sunday (0 = Sunday in PHP)
        if ($reservationDate->format('w') == '0') {
            return $this->json(['available' => false, 'message' => 'All facilities are closed on Sundays']);
        }

        $isBooked = $reservationRepo->isTimeRangeBooked($facility, $reservationDate, $start, $end, $excludeReservationId, ['Approved', 'Pending', 'Suggested']);

        return $this->json([
            'available' => !$isBooked,
            'message' => $isBooked ? 'This time slot is already booked' : 'This time slot is available'
        ]);
    }

    #[Route('/get-available-slots', name: 'admin_get_available_slots', methods: ['POST'])]
    public function getAvailableSlots(
        Request $request,
        ReservationRepository $reservationRepo,
        FacilityRepository $facilityRepo
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        $facilityId = $data['facility_id'] ?? null;
        $date = $data['date'] ?? null;
        $durationMinutes = $data['duration_minutes'] ?? 60;
        $excludeReservationId = $data['exclude_reservation_id'] ?? null;

        if (!$facilityId || !$date) {
            return $this->json(['slots' => []]);
        }

        $facility = $facilityRepo->find($facilityId);
        if (!$facility) {
            return $this->json(['slots' => []]);
        }

        $reservationDate = \DateTime::createFromFormat('!Y-m-d', $date);
        if (!$reservationDate) {
            return $this->json(['slots' => []]);
        }

        // Check if the date is Sunday (0 = Sunday in PHP)
        if ($reservationDate->format('w') == '0') {
            return $this->json(['slots' => [], 'message' => 'All facilities are closed on Sundays']);
        }

        // Generate time slots from 7 AM to 8 PM
        $slots = [];
        $startHour = 7;
        $endHour = 20;

        for ($hour = $startHour; $hour < $endHour; $hour++) {
            $startTime = new \DateTime($date . ' ' . sprintf('%02d:00', $hour));
            $endTime = clone $startTime;
            $endTime->add(new \DateInterval('PT' . $durationMinutes . 'M'));

            // Skip if end time goes past 8 PM
            if ($endTime->format('H') >= 20) {
                continue;
            }

            // Check if this slot is available
            $isBooked = $reservationRepo->isTimeRangeBooked($facility, $reservationDate, $startTime, $endTime, $excludeReservationId, ['Approved', 'Pending', 'Suggested']);

            if (!$isBooked) {
                $slots[] = [
                    'start' => $startTime->format('H:i'),
                    'end' => $endTime->format('H:i'),
                ];
            }
        }

        return $this->json(['slots' => $slots]);
    }

    #[Route('/calendar', name: 'admin_calendar')]
    public function calendar(
        Request $request,
        ReservationRepository $reservationRepo,
        FacilityRepository $facilityRepo
    ): Response {
        $facilities = $facilityRepo->findAll();
        $initialDate = $request->query->get('date');
        $parsedInitialDate = $initialDate ? \DateTime::createFromFormat('!Y-m-d', $initialDate) : null;
        
        return $this->render('super_admin/calendar.html.twig', [
            'facilities' => $facilities,
            'initialDate' => $parsedInitialDate ? $parsedInitialDate->format('Y-m-d') : null,
        ]);
    }

    #[Route('/calendar/data', name: 'admin_calendar_data')]
    public function calendarData(
        Request $request,
        ReservationRepository $reservationRepo,
        FacilityRepository $facilityRepo,
        FacilityScheduleBlockRepository $blockRepo
    ): JsonResponse {
        $start = $request->query->get('start');
        $end = $request->query->get('end');
        $facilityId = $request->query->get('facility');
        $status = $request->query->get('status');

        if (!$start || !$end) {
            return $this->json(['reservations' => []]);
        }

        $data = [];
        $startDate = new \DateTime($start);
        $endDate = new \DateTime($end);

        // Debug logging
        error_log("Calendar Data API called - Start: $start, End: $end, Facility: $facilityId, Status: $status");

        // Only fetch reservations if not filtering by block-only status
        if (!$status || !in_array($status, ['Class Schedule', 'Blocked', 'Manual', 'Maintenance'], true)) {
            $qb = $reservationRepo->createQueryBuilder('r')
                ->select('r.id', 'r.name', 'r.email', 'r.contact', 'r.reservationDate', 'r.reservationStartTime', 'r.reservationEndTime', 'r.capacity', 'r.purpose', 'r.status', 'f.id as facilityId', 'f.name as facilityName', 'f.capacity as facilityCapacity')
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

            $reservations = $qb->getQuery()->getArrayResult();

            // Debug logging
            error_log("Calendar Data API - Found " . count($reservations) . " reservations");

            foreach ($reservations as $r) {
                $data[] = [
                    'id' => $r['id'],
                    'name' => $r['name'],
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

        $reservationOnlyStatuses = ['Approved', 'Pending', 'Rejected'];
        $blockStatuses = ['Class Schedule', 'Blocked', 'Manual', 'Maintenance', 'Imported'];
        
        // Only fetch blocks if no status filter, or if status is a block type
        if (!$status || in_array($status, $blockStatuses, true)) {
            $blockFacility = $facilityId ? $facilityRepo->find((int) $facilityId) : null;
            $blockType = $status && in_array($status, $blockStatuses, true) ? $status : null;
            $blocks = $blockRepo->findBetween($startDate, $endDate, $blockFacility, $blockType);
            
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
                    'facility' => ['id' => $facility->getId(), 'name' => $facility->getName(), 'capacity' => $facility->getCapacity()],
                    'isBlock' => true,
                ];
            }
        }

        // Debug logging
        error_log("Calendar Data API - Returning " . count($data) . " total items (reservations + blocks)");

        return $this->json(['reservations' => $data]);
    }

    #[Route('/calendar/block', name: 'admin_calendar_block', methods: ['POST'])]
    public function createBlock(
        Request $request,
        FacilityRepository $facilityRepo,
        ReservationRepository $reservationRepo,
        EntityManagerInterface $em
    ): Response {
        $isAjax = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('manual_schedule_block', (string) $request->request->get('_token'))) {
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => 'Invalid security token. Please refresh the page and try again.'], Response::HTTP_FORBIDDEN);
            }

            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $facility = $facilityRepo->find($request->request->get('facility'));
        $date = \DateTime::createFromFormat('!Y-m-d', (string) $request->request->get('date'));
        $start = \DateTime::createFromFormat('!H:i', (string) $request->request->get('start_time'));
        $end = \DateTime::createFromFormat('!H:i', (string) $request->request->get('end_time'));

        if (!$facility || !$date || !$start || !$end || $end <= $start) {
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => 'Invalid schedule block data. Check facility, date, start time, and end time.'], Response::HTTP_BAD_REQUEST);
            }

            $this->addFlash('error', 'Invalid schedule block data.');
            return $this->redirectToRoute('admin_calendar');
        }

        // Check if the date is Sunday (0 = Sunday in PHP)
        if ($date->format('w') == '0') {
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => 'All facilities are closed on Sundays.'], Response::HTTP_BAD_REQUEST);
            }

            $this->addFlash('error', 'All facilities are closed on Sundays.');
            return $this->redirectToRoute('admin_calendar');
        }

        $dayStart = \DateTime::createFromFormat('!H:i', '07:00');
        $dayEnd = \DateTime::createFromFormat('!H:i', '20:00');
        if ($start < $dayStart || $end > $dayEnd) {
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => 'Schedule blocks must be between 7:00 AM and 8:00 PM.'], Response::HTTP_BAD_REQUEST);
            }

            $this->addFlash('error', 'Schedule blocks must be between 7:00 AM and 8:00 PM.');
            return $this->redirectToRoute('admin_calendar');
        }

        if ($reservationRepo->isTimeRangeBooked($facility, $date, $start, $end, null, ['Approved', 'Pending', 'Suggested'])) {
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => 'Cannot add block: this time range already has a reservation, class schedule, or another block.'], Response::HTTP_CONFLICT);
            }

            $this->addFlash('error', 'Cannot add block: this time range already has a reservation, class schedule, or another block.');
            return $this->redirectToRoute('admin_calendar');
        }

        $type = (string) $request->request->get('type', 'Manual');
        if (!in_array($type, ['Manual', 'Blocked', 'Maintenance'], true)) {
            $type = 'Manual';
        }

        $block = null;
        $em->getConnection()->beginTransaction();
        try {
            if ($reservationRepo->isTimeRangeBooked($facility, $date, $start, $end, null, ['Approved', 'Pending', 'Suggested'])) {
                throw new \RuntimeException('Cannot add block: this time range was just reserved or blocked by another request.');
            }

            $block = new FacilityScheduleBlock();
            $block->setFacility($facility);
            $block->setBlockDate($date);
            $block->setStartTime($start);
            $block->setEndTime($end);
            $block->setTitle(trim((string) $request->request->get('title')) ?: 'Unavailable');
            $block->setType($type);
            $block->setNotes($request->request->get('notes'));
            $block->setSource('Manual Entry');
            $em->persist($block);
            $em->flush();
            $em->getConnection()->commit();
        } catch (\Throwable $exception) {
            $em->getConnection()->rollBack();

            if ($isAjax) {
                return $this->json(['success' => false, 'message' => $exception->getMessage()], Response::HTTP_CONFLICT);
            }

            $this->addFlash('error', $exception->getMessage());
            return $this->redirectToRoute('admin_calendar');
        }

        $message = 'Schedule block added successfully. The selected time is now unavailable for reservations.';

        if ($isAjax) {
            return $this->json([
                'success' => true,
                'message' => $message,
                'block' => $this->formatScheduleBlockForCalendar($block),
            ]);
        }

        $this->addFlash('success', $message);
        return $this->redirectToRoute('admin_calendar');
    }

    #[Route('/calendar/block/{id}/update', name: 'admin_calendar_block_update', methods: ['POST'])]
    public function updateBlock(
        FacilityScheduleBlock $block,
        Request $request,
        FacilityRepository $facilityRepo,
        ReservationRepository $reservationRepo,
        FacilityScheduleBlockRepository $blockRepo,
        EntityManagerInterface $em
    ): Response {
        $isAjax = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('manual_schedule_block', (string) $request->request->get('_token'))) {
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => 'Invalid security token. Please refresh the page and try again.'], Response::HTTP_FORBIDDEN);
            }

            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }


        $facility = $facilityRepo->find($request->request->get('facility'));
        $date = \DateTime::createFromFormat('!Y-m-d', (string) $request->request->get('date'));
        $start = \DateTime::createFromFormat('!H:i', (string) $request->request->get('start_time'));
        $end = \DateTime::createFromFormat('!H:i', (string) $request->request->get('end_time'));

        if (!$facility || !$date || !$start || !$end || $end <= $start) {
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => 'Invalid schedule block data. Check facility, date, start time, and end time.'], Response::HTTP_BAD_REQUEST);
            }

            $this->addFlash('error', 'Invalid schedule block data.');
            return $this->redirectToRoute('admin_calendar');
        }

        // Check if the date is Sunday (0 = Sunday in PHP)
        if ($date->format('w') == '0') {
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => 'All facilities are closed on Sundays.'], Response::HTTP_BAD_REQUEST);
            }

            $this->addFlash('error', 'All facilities are closed on Sundays.');
            return $this->redirectToRoute('admin_calendar');
        }

        $dayStart = \DateTime::createFromFormat('!H:i', '07:00');
        $dayEnd = \DateTime::createFromFormat('!H:i', '20:00');
        if ($start < $dayStart || $end > $dayEnd) {
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => 'Schedule blocks must be between 7:00 AM and 8:00 PM.'], Response::HTTP_BAD_REQUEST);
            }

            $this->addFlash('error', 'Schedule blocks must be between 7:00 AM and 8:00 PM.');
            return $this->redirectToRoute('admin_calendar');
        }

        $reservationConflict = $this->reservationConflictExists($reservationRepo, $facility, $date, $start, $end);
        $blockConflict = $blockRepo->isBlocked($facility, $date, $start, $end, $block->getId());

        if ($reservationConflict || $blockConflict) {
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => 'Cannot update block: this time range conflicts with a reservation, class schedule, or another block.'], Response::HTTP_CONFLICT);
            }

            $this->addFlash('error', 'Cannot update block: this time range conflicts with a reservation, class schedule, or another block.');
            return $this->redirectToRoute('admin_calendar');
        }

        $type = (string) $request->request->get('type', 'Manual');
        if (!in_array($type, ['Manual', 'Blocked', 'Maintenance', 'Class Schedule'], true)) {
            $type = 'Manual';
        }

        $block->setFacility($facility);
        $block->setBlockDate($date);
        $block->setStartTime($start);
        $block->setEndTime($end);
        $block->setTitle(trim((string) $request->request->get('title')) ?: 'Unavailable');
        $block->setType($type);
        $block->setNotes($request->request->get('notes'));
        $em->flush();

        $message = 'Schedule block updated successfully. Reservation availability has been refreshed.';

        if ($isAjax) {
            return $this->json([
                'success' => true,
                'message' => $message,
                'block' => $this->formatScheduleBlockForCalendar($block),
            ]);
        }

        $this->addFlash('success', $message);
        return $this->redirectToRoute('admin_calendar');
    }

    #[Route('/reservations/{id}/cancel-suggestion', name: 'admin_cancel_suggestion', methods: ['POST'])]
    public function cancelSuggestion(
        Reservation $reservation,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isCsrfTokenValid('cancel_suggestion_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Reset reservation to pending status
        $reservation->setStatus('Pending');
        $reservation->setSuggestedFacility(null);
        $reservation->setUpdatedAt(new \DateTime());
        $em->flush();

        $this->addFlash('success', 'Suggestion cancelled successfully. The reservation is now back to pending status.');

        return $this->redirectToRoute('admin_reservations');
    }

    #[Route('/calendar/block/{id}/delete', name: 'admin_calendar_block_delete', methods: ['POST'])]
    public function deleteBlock(
        FacilityScheduleBlock $block,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $isAjax = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('manual_schedule_block_delete', (string) $request->request->get('_token'))) {
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => 'Invalid security token. Please refresh the page and try again.'], Response::HTTP_FORBIDDEN);
            }

            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $em->remove($block);
        $em->flush();

        $message = 'Schedule block deleted successfully. The time slot is available again if no other conflict exists.';

        if ($isAjax) {
            return $this->json(['success' => true, 'message' => $message]);
        }

        $this->addFlash('success', $message);
        return $this->redirectToRoute('admin_calendar');
    }

    #[Route('/reservations/{id}/details', name: 'admin_reservation_details')]
    public function reservationDetails(Reservation $reservation): JsonResponse
    {
        return $this->json([
            'id' => $reservation->getId(),
            'name' => $reservation->getName(),
            'email' => $reservation->getEmail(),
            'contact' => $reservation->getContact(),
            'reservationDate' => $reservation->getReservationDate()->format('Y-m-d'),
            'reservationStartTime' => $reservation->getReservationStartTime()->format('H:i'),
            'reservationEndTime' => $reservation->getReservationEndTime()->format('H:i'),
            'capacity' => $reservation->getCapacity(),
            'purpose' => $reservation->getPurpose(),
            'status' => $reservation->getStatus(),
            'facility' => [
                'id' => $reservation->getFacility()->getId(),
                'name' => $reservation->getFacility()->getName(),
                'capacity' => $reservation->getFacility()->getCapacity(),
            ],
        ]);
    }

    #[Route('/reservations/{id}/edit', name: 'admin_edit_reservation', methods: ['GET'])]
    public function editReservation(
        Reservation $reservation,
        FacilityRepository $facilityRepo
    ): Response {
        $facilities = $facilityRepo->findAll();
        
        return $this->render('super_admin/edit_reservation.html.twig', [
            'reservation' => $reservation,
            'facilities' => $facilities,
        ]);
    }

    #[Route('/reservations/{id}/update', name: 'admin_update_reservation', methods: ['POST'])]
    public function updateReservation(
        Reservation $reservation,
        Request $request,
        ReservationRepository $reservationRepo,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isCsrfTokenValid('update_reservation_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Update basic information
        $reservation->setName($request->request->get('name'));
        $reservation->setEmail($request->request->get('email'));
        $reservation->setContact($request->request->get('contact'));
        $reservation->setCapacity((int)$request->request->get('capacity'));
        $reservation->setPurpose($request->request->get('purpose'));
        $reservation->setStatus($request->request->get('status'));

        // Update dates and times
        $reservationDate = new \DateTime($request->request->get('reservationDate'));
        $startTime = \DateTime::createFromFormat('H:i', $request->request->get('reservationStartTime'));
        $endTime = \DateTime::createFromFormat('H:i', $request->request->get('reservationEndTime'));

        $reservation->setReservationDate($reservationDate);
        $reservation->setReservationStartTime($startTime);
        $reservation->setReservationEndTime($endTime);

        // Update facility if provided
        $facilityId = $request->request->get('facility');
        if ($facilityId) {
            $facility = $em->getRepository(Facility::class)->find($facilityId);
            if ($facility) {
                $reservation->setFacility($facility);
            }
        }

        // Check for time conflicts only if the date/time has changed and status is being set to Approved
        if ($request->request->get('status') === 'Approved') {
            if ($reservationRepo->isTimeRangeBooked($reservation->getFacility(), $reservationDate, $startTime, $endTime, $reservation->getId())) {
                $this->addFlash('error', 'Cannot update: this time range is already booked for this facility.');
                return $this->redirectToRoute('admin_edit_reservation', ['id' => $reservation->getId()]);
            }
        }

        $reservation->setUpdatedAt(new \DateTime());
        $em->flush();

        $this->addFlash('success', 'Reservation updated successfully.');

        return $this->redirectToRoute('admin_calendar');
    }

    #[Route('/calendar/import/delete', name: 'admin_calendar_import_delete', methods: ['POST'])]
    public function deleteImportedSchedule(
        Request $request,
        FacilityScheduleBlockRepository $blockRepo,
        EntityManagerInterface $em
    ): Response {
        $isAjax = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('delete_imported_schedule', (string) $request->request->get('_token'))) {
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => 'Invalid security token. Please refresh the page and try again.'], Response::HTTP_FORBIDDEN);
            }

            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $count = $blockRepo->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.type = :type')
            ->setParameter('type', 'Class Schedule')
            ->getQuery()
            ->getSingleScalarResult();

        $blockRepo->deleteByType('Class Schedule');
        $em->flush();

        $message = "Deleted $count imported class schedule block(s).";

        if ($isAjax) {
            return $this->json([
                'success' => true,
                'message' => $message,
                'deleted' => (int) $count,
            ]);
        }

        $this->addFlash('success', $message);
        return $this->redirectToRoute('admin_calendar');
    }

    #[Route('/calendar/import', name: 'admin_calendar_import', methods: ['POST'])]
    public function importSchedule(
        Request $request,
        FacilityRepository $facilityRepo,
        FacilityScheduleBlockRepository $blockRepo,
        EntityManagerInterface $em
    ): Response {
        $file = $request->files->get('schedule_file');
        $pasteData = trim((string) $request->request->get('schedule_paste', ''));
        $isAjax = $request->isXmlHttpRequest();

        if (!$file instanceof UploadedFile && $pasteData === '') {
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => 'Please upload a CSV file or paste schedule data.'], Response::HTTP_BAD_REQUEST);
            }

            $this->addFlash('error', 'Please upload a CSV file or paste schedule data.');
            return $this->redirectToRoute('admin_calendar');
        }

        $rows = $this->readScheduleRows($file, $pasteData);
        if ($rows === []) {
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => 'No schedule rows were found in the uploaded data.'], Response::HTTP_BAD_REQUEST);
            }

            $this->addFlash('error', 'No schedule rows were found in the uploaded data.');
            return $this->redirectToRoute('admin_calendar');
        }

        $blockRepo->deleteByType('Class Schedule');
        $em->flush();

        $created = 0;
        $processed = 0;
        $errors = [];
        $sourceName = $file ? $file->getClientOriginalName() : 'Pasted CSV Data';
        $seen = [];
        $firstCreatedDate = null;

        $row = 0;
        foreach ($rows as $data) {
            $row++;
            $data = array_map(static fn ($value) => trim((string) $value), $data);

            if ($this->isScheduleHeaderRow($data)) {
                continue;
            }

            [$facilityName, $dateStr, $startStr, $endStr, $title, $type] = array_pad($data, 6, '');

            if (empty($facilityName) || empty($dateStr) || empty($startStr) || empty($endStr)) {
                $errors[] = "Row $row: Missing required fields.";
                continue;
            }

            $processed++;
            $facility = $this->findFacilityByName($facilityRepo, trim($facilityName));

            if (!$facility) {
                $errors[] = "Row $row: Facility '$facilityName' not found.";
                continue;
            }

            $dates = $this->parseScheduleDates($dateStr);
            $start = $this->parseScheduleTime($startStr);
            $end = $this->parseScheduleTime($endStr);

            if ($dates === [] || !$start || !$end) {
                $errors[] = "Row $row: Invalid date or time format.";
                continue;
            }

            if ($end <= $start) {
                $errors[] = "Row $row: End time must be after start time.";
                continue;
            }

            foreach ($dates as $date) {
                $key = implode('|', [
                    $facility->getId(),
                    $date->format('Y-m-d'),
                    $start->format('H:i'),
                    $end->format('H:i'),
                    trim($title) ?: 'Class Schedule',
                ]);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                $block = new FacilityScheduleBlock();
                $block->setFacility($facility);
                $block->setTitle(trim($title) ?: 'Class Schedule');
                $block->setType('Class Schedule');
                $block->setBlockDate($date);
                $block->setStartTime(clone $start);
                $block->setEndTime(clone $end);
                $block->setSource($sourceName);
                $block->setScheduleIdentifier(sha1($key));
                $block->setNotes('Imported class schedule');
                $em->persist($block);
                $created++;

                if ($firstCreatedDate === null || $date < $firstCreatedDate) {
                    $firstCreatedDate = clone $date;
                }

                if ($created % 100 === 0) {
                    $em->flush();
                }
            }
        }

        $em->flush();

        $message = $created > 0
            ? "Schedule sync complete. Replaced old class schedules and created $created blocking schedule entries from $processed imported row(s)."
            : 'No class schedule blocks were created.';
        $warnings = [];
        $today = new \DateTimeImmutable('today');
        $weekStart = $today->modify('monday this week');
        $weekEnd = $weekStart->modify('+6 days');

        if ($created > 0 && $firstCreatedDate && ($firstCreatedDate->format('Y-m-d') < $weekStart->format('Y-m-d') || $firstCreatedDate->format('Y-m-d') > $weekEnd->format('Y-m-d'))) {
            $warnings[] = 'Imported schedules are not in the current week, so the calendar opened the imported schedule week.';
        }

        if ($isAjax) {
            return $this->json([
                'success' => $created > 0,
                'message' => $message,
                'warnings' => array_slice(array_merge($warnings, $errors), 0, 10),
                'date' => $firstCreatedDate ? $firstCreatedDate->format('Y-m-d') : null,
                'created' => $created,
                'processed' => $processed,
            ], $created > 0 ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
        }

        if ($created > 0) {
            $this->addFlash('success', $message);
        } else {
            $this->addFlash('error', $message);
        }

        foreach ($warnings as $warning) {
            $this->addFlash('warning', $warning);
        }

        foreach (array_slice($errors, 0, 10) as $error) {
            $this->addFlash('warning', $error);
        }

        return $this->redirectToRoute('admin_calendar', $firstCreatedDate ? ['date' => $firstCreatedDate->format('Y-m-d')] : []);
    }

    private function formatScheduleBlockForCalendar(FacilityScheduleBlock $block): array
    {
        $facility = $block->getFacility();

        return [
            'id' => 'block_' . $block->getId(),
            'name' => $block->getTitle(),
            'email' => '',
            'contact' => '',
            'purpose' => $block->getNotes(),
            'status' => $block->getType() ?: 'Manual',
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

    private function reservationConflictExists(
        ReservationRepository $reservationRepo,
        Facility $facility,
        \DateTimeInterface $date,
        \DateTimeInterface $startTime,
        \DateTimeInterface $endTime
    ): bool {
        $startOfDay = \DateTime::createFromInterface($date)->setTime(0, 0, 0);
        $endOfDay = \DateTime::createFromInterface($date)->setTime(23, 59, 59);

        return (int) $reservationRepo->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.facility = :facility')
            ->andWhere('r.reservationDate BETWEEN :startOfDay AND :endOfDay')
            ->andWhere('r.status IN (:statuses)')
            ->andWhere('r.reservationStartTime < :endTime')
            ->andWhere('r.reservationEndTime > :startTime')
            ->setParameter('facility', $facility)
            ->setParameter('startOfDay', $startOfDay)
            ->setParameter('endOfDay', $endOfDay)
            ->setParameter('statuses', ['Approved', 'Pending', 'Suggested'])
            ->setParameter('startTime', $startTime->format('H:i:s'))
            ->setParameter('endTime', $endTime->format('H:i:s'))
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    private function readScheduleRows(?UploadedFile $file, string $pasteData): array
    {
        $rows = [];

        if ($file instanceof UploadedFile) {
            $handle = fopen($file->getPathname(), 'r');
            if ($handle === false) {
                return [];
            }

            while (($data = fgetcsv($handle)) !== false) {
                if ($data !== [null] && $data !== false) {
                    $rows[] = $data;
                }
            }

            fclose($handle);
            return $rows;
        }

        foreach (explode("\n", str_replace("\r", '', $pasteData)) as $line) {
            if (trim($line) !== '') {
                $rows[] = str_getcsv($line);
            }
        }

        return $rows;
    }

    private function isScheduleHeaderRow(array $data): bool
    {
        $first = strtolower(trim((string) ($data[0] ?? '')));
        $second = strtolower(trim((string) ($data[1] ?? '')));

        return $first === 'facility' && in_array($second, ['date', 'day'], true);
    }

    private function parseScheduleDates(string $value): array
    {
        $normalized = strtolower(trim($value));
        $dayMap = [
            'monday' => 0,
            'mon' => 0,
            'tuesday' => 1,
            'tue' => 1,
            'wednesday' => 2,
            'wed' => 2,
            'thursday' => 3,
            'thu' => 3,
            'friday' => 4,
            'fri' => 4,
            'saturday' => 5,
            'sat' => 5,
            'sunday' => 6,
            'sun' => 6,
        ];

        if (isset($dayMap[$normalized])) {
            $today = new \DateTimeImmutable('today');
            $weekStart = $today->modify('monday this week');
            $dates = [];

            for ($week = 0; $week < 18; $week++) {
                $target = $weekStart
                    ->modify('+' . $week . ' weeks')
                    ->modify('+' . $dayMap[$normalized] . ' days');
                $dates[] = \DateTime::createFromImmutable($target);
            }

            return $dates;
        }

        $date = \DateTime::createFromFormat('!Y-m-d', trim($value));
        if ($date && $date->format('Y-m-d') === trim($value)) {
            return [$date];
        }

        return [];
    }

    private function parseScheduleTime(string $value): ?\DateTimeInterface
    {
        $value = trim($value);

        foreach (['!H:i', '!H:i:s', '!g:i A', '!h:i A'] as $format) {
            $time = \DateTime::createFromFormat($format, $value);
            if ($time instanceof \DateTimeInterface) {
                return $time;
            }
        }

        return null;
    }

    private function findFacilityByName(FacilityRepository $facilityRepo, string $name): ?Facility
    {
        // Try exact match first
        $facility = $facilityRepo->findOneBy(['name' => $name]);
        if ($facility) return $facility;

        // Try case-insensitive exact match
        $facility = $facilityRepo->createQueryBuilder('f')
            ->where('LOWER(f.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if ($facility) return $facility;

        // Try LIKE match (partial)
        $facility = $facilityRepo->createQueryBuilder('f')
            ->where('f.name LIKE :name')
            ->setParameter('name', '%' . $name . '%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if ($facility) return $facility;

        // Handle abbreviation patterns like "PR 1" -> "Presentation Room 1"
        $normalized = strtolower(trim($name));

        // Pattern: PR 1, PR1, PR-1 -> Presentation Room 1
        if (preg_match('/^pr[\s\-]?(\d+)$/i', $name, $matches)) {
            $num = $matches[1];
            $facility = $facilityRepo->createQueryBuilder('f')
                ->where('LOWER(f.name) LIKE :pattern1')
                ->orWhere('LOWER(f.name) LIKE :pattern2')
                ->setParameter('pattern1', '%presentation room ' . $num . '%')
                ->setParameter('pattern2', '%pr ' . $num . '%')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if ($facility) return $facility;
        }

        // Pattern: CS Project Room -> CS Project Room (common variations)
        if (str_contains($normalized, 'cs') && str_contains($normalized, 'project')) {
            $facility = $facilityRepo->createQueryBuilder('f')
                ->where('LOWER(f.name) LIKE :pattern')
                ->setParameter('pattern', '%cs%project%room%')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if ($facility) return $facility;
        }

        // Pattern: 3D Printing -> 3D Printing Lab or similar
        if (str_contains($normalized, '3d') && str_contains($normalized, 'print')) {
            $facility = $facilityRepo->createQueryBuilder('f')
                ->where('LOWER(f.name) LIKE :pattern1')
                ->orWhere('LOWER(f.name) LIKE :pattern2')
                ->setParameter('pattern1', '%3d%print%')
                ->setParameter('pattern2', '%3d%lab%')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if ($facility) return $facility;
        }

        // Pattern: Lounge 1, Lounge 2, Lounge 3, Lounge 4
        if (preg_match('/^lounge\s*(\d+)$/i', $name, $matches)) {
            $num = $matches[1];
            $facility = $facilityRepo->createQueryBuilder('f')
                ->where('LOWER(f.name) LIKE :pattern')
                ->setParameter('pattern', '%lounge% ' . $num . '%')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if ($facility) return $facility;
        }

        // Try reverse LIKE (facility name contained in search name)
        $allFacilities = $facilityRepo->findAll();
        foreach ($allFacilities as $f) {
            $facilityName = strtolower($f->getName());
            if (str_contains($normalized, $facilityName) || str_contains($facilityName, $normalized)) {
                return $f;
            }
        }

        return null;
    }
}
