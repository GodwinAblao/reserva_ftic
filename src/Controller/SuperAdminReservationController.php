<?php

namespace App\Controller;

use App\Entity\Facility;
use App\Entity\FacilityScheduleBlock;
use App\Entity\Reservation;
use App\Repository\FacilityRepository;
use App\Repository\FacilityScheduleBlockRepository;
use App\Repository\ReservationRepository;
use App\Repository\ClassScheduleNotificationLogRepository;
use App\Repository\ReservationStatusLogRepository;
use App\Entity\ClassSchedule;
use App\Repository\ClassScheduleRepository;
use App\Service\CalendarDataService;
use App\Service\ClassScheduleFacultyMatcher;
use App\Service\ScheduleRevisionService;
use App\Service\ClassScheduleImportService;
use App\Service\ClassScheduleNotificationService;
use App\Service\ReservationAuditLogger;
use App\Service\ReservationStatusManager;
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
    public function listReservations(
        ReservationRepository $reservationRepo,
        ReservationStatusLogRepository $auditRepo,
        ClassScheduleNotificationLogRepository $classNotifyAuditRepo,
    ): Response {
        $pending = $reservationRepo->findBy(['status' => 'Pending'], ['createdAt' => 'DESC']);
        $approved = $reservationRepo->findBy(['status' => 'Approved'], ['reservationDate' => 'DESC']);
        $rejected = $reservationRepo->findBy(['status' => 'Rejected'], ['reservationDate' => 'DESC']);
        $suggested = $reservationRepo->findBy(['status' => 'Suggested'], ['updatedAt' => 'DESC']);

        return $this->render('super_admin/reservations.html.twig', [
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'suggested' => $suggested,
            'statusAuditLogs' => $auditRepo->findRecent(30),
            'classScheduleNotifyLogs' => $classNotifyAuditRepo->findRecent(30),
        ]);
    }

    #[Route('/reservations/{id}/approve', name: 'admin_approve_reservation', methods: ['POST'])]
    public function approveReservation(
        Reservation $reservation,
        Request $request,
        ReservationStatusManager $statusManager
    ): Response {
        if (!$this->isCsrfTokenValid('approve_reservation_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
        $result = $statusManager->approve($reservation, $isAjax);

        if ($result instanceof JsonResponse) {
            return $result;
        }

        if (!$result['success']) {
            $this->addFlash('error', $result['message']);
            return $this->redirectToRoute('admin_reservations');
        }

        $this->addFlash('success', $result['message']);
        return $this->redirectToRoute('admin_reservations');
    }

    #[Route('/reservations/{id}/reject', name: 'admin_reject_reservation', methods: ['POST'])]
    public function rejectReservation(
        Reservation $reservation,
        Request $request,
        ReservationStatusManager $statusManager
    ): Response {
        if (!$this->isCsrfTokenValid('reject_reservation_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
        $reason = (string) ($request->request->get('reason') ?? 'Not specified');
        $result = $statusManager->reject($reservation, $reason, $isAjax);

        if ($result instanceof JsonResponse) {
            return $result;
        }

        if (!$result['success']) {
            $this->addFlash('error', $result['message']);
            return $this->redirectToRoute('admin_reservations');
        }

        $this->addFlash('success', $result['message']);
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
        FacilityRepository $facilityRepo
    ): Response {
        $facilities = $facilityRepo->findAll();
        $initialDate = $request->query->get('date');
        $parsedInitialDate = $initialDate ? \DateTime::createFromFormat('!Y-m-d', $initialDate) : null;

        return $this->render('super_admin/calendar.html.twig', [
            'facilities' => $facilities,
            'initialDate' => $parsedInitialDate ? $parsedInitialDate->format('Y-m-d') : null,
            'calendar_full_mode' => true,
            'calendar_back_url' => $this->generateUrl('admin_reservations'),
            'calendar_data_url' => $this->generateUrl('admin_calendar_data'),
            'calendar_edit_url_pattern' => '/super-admin/reservations/{id}/edit',
            'calendar_block_create_url' => $this->generateUrl('admin_calendar_block'),
            'calendar_import_url' => $this->generateUrl('admin_calendar_import'),
            'calendar_import_delete_url' => $this->generateUrl('admin_calendar_import_delete'),
            'calendar_block_update_pattern' => '/super-admin/calendar/block/{id}/update',
            'calendar_block_delete_pattern' => '/super-admin/calendar/block/{id}/delete',
            'calendar_notify_url_pattern' => '/super-admin/class-schedule/{id}/notify',
            'calendar_class_schedule_update_pattern' => '/super-admin/class-schedule/{id}/update',
            'calendar_class_schedule_delete_pattern' => '/super-admin/class-schedule/{id}/delete',
            'calendar_class_schedule_available_url' => $this->generateUrl('admin_class_schedule_available_facilities'),
        ]);
    }

    #[Route('/calendar/data', name: 'admin_calendar_data')]
    public function calendarData(Request $request, CalendarDataService $calendarData): JsonResponse
    {
        $start = $request->query->get('start');
        $end = $request->query->get('end');

        if (!$start || !$end) {
            return $this->json(['reservations' => []]);
        }

        return $this->json($calendarData->buildCalendarPayload(
            $start,
            $end,
            $request->query->get('facility'),
            $request->query->get('status'),
            true,
            true,
        ));
    }

    #[Route('/class-schedule/{id}/notify', name: 'admin_class_schedule_notify', methods: ['POST'])]
    public function notifyClassScheduleFaculty(
        ClassSchedule $schedule,
        Request $request,
        ClassScheduleNotificationService $notificationService,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('class_schedule_notify_' . $schedule->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], Response::HTTP_FORBIDDEN);
        }

        $result = $notificationService->notifyFaculty($schedule);

        return $this->json(
            ['success' => $result['success'], 'message' => $result['message'], 'channels' => $result['channels']],
            $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST,
        );
    }

    #[Route('/calendar/class-schedule/available-facilities', name: 'admin_class_schedule_available_facilities', methods: ['GET'])]
    public function availableFacilitiesForClassSchedule(
        Request $request,
        ClassScheduleRepository $classScheduleRepo,
    ): JsonResponse {
        $date = \DateTime::createFromFormat('!Y-m-d', (string) $request->query->get('date'));
        $start = \DateTime::createFromFormat('!H:i', (string) $request->query->get('start'));
        $end = \DateTime::createFromFormat('!H:i', (string) $request->query->get('end'));

        if (!$date || !$start || !$end || $end <= $start) {
            return $this->json(['facilities' => [], 'message' => 'Invalid date or time.'], Response::HTTP_BAD_REQUEST);
        }

        $excludeId = $request->query->get('exclude_id');
        $excludeScheduleId = is_numeric($excludeId) ? (int) $excludeId : null;

        $facilities = $classScheduleRepo->findAvailableFacilitiesForSlot($date, $start, $end, $excludeScheduleId);

        return $this->json([
            'facilities' => array_map(static fn (Facility $f) => [
                'id' => $f->getId(),
                'name' => $f->getName(),
                'capacity' => $f->getCapacity(),
            ], $facilities),
        ]);
    }

    #[Route('/class-schedule/{id}/update', name: 'admin_class_schedule_update', methods: ['POST'])]
    public function updateClassSchedule(
        ClassSchedule $schedule,
        Request $request,
        FacilityRepository $facilityRepo,
        ReservationRepository $reservationRepo,
        ClassScheduleFacultyMatcher $facultyMatcher,
        EntityManagerInterface $em,
        ScheduleRevisionService $scheduleRevision,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('class_schedule_update_' . $schedule->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], Response::HTTP_FORBIDDEN);
        }

        $facility = $facilityRepo->find($request->request->get('facility'));
        $date = \DateTime::createFromFormat('!Y-m-d', (string) $request->request->get('date'));
        $start = \DateTime::createFromFormat('!H:i', (string) $request->request->get('start_time'));
        $end = \DateTime::createFromFormat('!H:i', (string) $request->request->get('end_time'));

        if (!$facility || !$date || !$start || !$end || $end <= $start) {
            return $this->json(['success' => false, 'message' => 'Invalid class schedule data.'], Response::HTTP_BAD_REQUEST);
        }

        if ($date->format('w') === '0') {
            return $this->json(['success' => false, 'message' => 'All facilities are closed on Sundays.'], Response::HTTP_BAD_REQUEST);
        }

        $dayStart = \DateTime::createFromFormat('!H:i', '07:00');
        $dayEnd = \DateTime::createFromFormat('!H:i', '20:00');
        if ($start < $dayStart || $end > $dayEnd) {
            return $this->json(['success' => false, 'message' => 'Class schedules must be between 7:00 AM and 8:00 PM.'], Response::HTTP_BAD_REQUEST);
        }

        if ($reservationRepo->isTimeRangeBooked(
            $facility,
            $date,
            $start,
            $end,
            null,
            ['Approved', 'Pending', 'Suggested'],
            $schedule->getId(),
        )) {
            return $this->json(['success' => false, 'message' => 'Selected facility is not available for this date and time.'], Response::HTTP_CONFLICT);
        }

        $previousFacility = $schedule->getFacility();
        if ($previousFacility && $previousFacility->getId() !== $facility->getId()) {
            $schedule->setPreviousFacility($previousFacility);
            $schedule->setIsRelocated(true);
        }

        $schedule->setFacility($facility);
        $schedule->setScheduleDate($date);
        $schedule->setStartTime($start);
        $schedule->setEndTime($end);
        $schedule->setUpdatedAt(new \DateTime());

        $courseCode = trim((string) $request->request->get('course_code', $schedule->getCourseCode()));
        if ($courseCode !== '') {
            $schedule->setCourseCode($courseCode);
        }
        $section = trim((string) $request->request->get('section', $schedule->getSection() ?? ''));
        $schedule->setSection($section !== '' ? $section : null);
        $facultyName = trim((string) $request->request->get('faculty_name', $schedule->getFacultyName() ?? ''));
        $schedule->setFacultyName($facultyName !== '' ? $facultyName : null);
        $facultyEmail = trim((string) $request->request->get('faculty_email', $schedule->getFacultyEmail() ?? ''));
        $schedule->setFacultyEmail($facultyEmail !== '' ? $facultyEmail : null);
        $schedule->setFacultyUser($facultyMatcher->resolveFacultyUser($schedule->getFacultyEmail()));

        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Class schedule updated successfully.',
            'schedule' => [
                'id' => $schedule->getId(),
                'reservationDate' => $schedule->getScheduleDate()->format('Y-m-d'),
                'isRelocated' => $schedule->isRelocated(),
            ],
            'scheduleRevision' => $scheduleRevision->getRevision(),
        ]);
    }

    #[Route('/class-schedule/{id}/delete', name: 'admin_class_schedule_delete', methods: ['POST'])]
    public function deleteClassSchedule(
        ClassSchedule $schedule,
        Request $request,
        EntityManagerInterface $em,
        ScheduleRevisionService $scheduleRevision,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('class_schedule_delete_' . $schedule->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], Response::HTTP_FORBIDDEN);
        }

        $em->remove($schedule);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Class schedule entry deleted.',
            'scheduleRevision' => $scheduleRevision->getRevision(),
        ]);
    }

    #[Route('/calendar/block', name: 'admin_calendar_block', methods: ['POST'])]
    public function createBlock(
        Request $request,
        FacilityRepository $facilityRepo,
        ReservationRepository $reservationRepo,
        EntityManagerInterface $em,
        ScheduleRevisionService $scheduleRevision,
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
                'scheduleRevision' => $scheduleRevision->getRevision(),
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
        EntityManagerInterface $em,
        ScheduleRevisionService $scheduleRevision,
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
        if (!in_array($type, ['Manual', 'Blocked', 'Maintenance'], true)) {
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
                'scheduleRevision' => $scheduleRevision->getRevision(),
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
        EntityManagerInterface $em,
        ScheduleRevisionService $scheduleRevision,
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
            return $this->json([
                'success' => true,
                'message' => $message,
                'scheduleRevision' => $scheduleRevision->getRevision(),
            ]);
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
            'eventName' => $reservation->getEventName(),
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
        EntityManagerInterface $em,
        ReservationAuditLogger $auditLogger,
    ): Response {
        if (!$this->isCsrfTokenValid('update_reservation_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $previousStatus = $reservation->getStatus();
        $newStatus = (string) $request->request->get('status');

        // Update basic information
        $reservation->setName($request->request->get('name'));
        $reservation->setEventName(trim((string)$request->request->get('event_name')) ?: null);
        $reservation->setEmail($request->request->get('email'));
        $reservation->setContact($request->request->get('contact'));
        $reservation->setCapacity((int)$request->request->get('capacity'));
        $reservation->setPurpose($request->request->get('purpose'));
        $reservation->setStatus($newStatus);

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
        if ($newStatus === 'Approved') {
            if ($reservationRepo->isTimeRangeBooked($reservation->getFacility(), $reservationDate, $startTime, $endTime, $reservation->getId())) {
                $this->addFlash('error', 'Cannot update: this time range is already booked for this facility.');
                return $this->redirectToRoute('admin_edit_reservation', ['id' => $reservation->getId()]);
            }
        }

        $reservation->setUpdatedAt(new \DateTime());
        $auditLogger->logStatusChange($reservation, $previousStatus, $newStatus, 'update');
        $em->flush();

        $this->addFlash('success', 'Reservation updated successfully.');

        return $this->redirectToRoute('admin_calendar');
    }

    #[Route('/calendar/import/delete', name: 'admin_calendar_import_delete', methods: ['POST'])]
    public function deleteImportedSchedule(
        Request $request,
        ClassScheduleRepository $classScheduleRepo,
        EntityManagerInterface $em,
        ScheduleRevisionService $scheduleRevision,
    ): Response {
        $isAjax = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('delete_imported_schedule', (string) $request->request->get('_token'))) {
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => 'Invalid security token. Please refresh the page and try again.'], Response::HTTP_FORBIDDEN);
            }

            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $count = $classScheduleRepo->countAll();
        $classScheduleRepo->deleteAll();
        $em->flush();

        $message = "Deleted $count imported class schedule(s).";

        if ($isAjax) {
            return $this->json([
                'success' => true,
                'message' => $message,
                'deleted' => (int) $count,
                'scheduleRevision' => $scheduleRevision->getRevision(),
            ]);
        }

        $this->addFlash('success', $message);
        return $this->redirectToRoute('admin_calendar');
    }

    #[Route('/calendar/import', name: 'admin_calendar_import', methods: ['POST'])]
    public function importSchedule(
        Request $request,
        ClassScheduleImportService $importService,
        ScheduleRevisionService $scheduleRevision,
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

        $sourceName = $file instanceof UploadedFile ? $file->getClientOriginalName() : 'Pasted CSV Data';
        $result = $importService->import($file instanceof UploadedFile ? $file : null, $pasteData, $sourceName);

        if ($isAjax) {
            return $this->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'warnings' => $result['warnings'],
                'date' => $result['date'],
                'created' => $result['created'],
                'processed' => $result['processed'],
                'relocated' => $result['relocated'],
                'scheduleRevision' => $scheduleRevision->getRevision(),
            ], $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
        }

        if ($result['success']) {
            $this->addFlash('success', $result['message']);
        } else {
            $this->addFlash('error', $result['message']);
        }

        foreach ($result['warnings'] as $warning) {
            $this->addFlash('warning', $warning);
        }

        return $this->redirectToRoute('admin_calendar', $result['date'] ? ['date' => $result['date']] : []);
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
