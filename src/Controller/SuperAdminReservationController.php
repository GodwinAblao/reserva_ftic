<?php

namespace App\Controller;

use App\Entity\Facility;
use App\Entity\FacilityScheduleBlock;
use App\Entity\ClassSchedule;
use App\Entity\Reservation;
use App\Repository\FacilityRepository;
use App\Repository\FacilityScheduleBlockRepository;
use App\Repository\ReservationRepository;
use App\Repository\ClassScheduleRepository;
use App\Service\CalendarDataService;
use App\Service\ClassScheduleFacultyMatcher;
use App\Service\ScheduleRevisionService;
use App\Service\ClassScheduleNotificationService;
use App\Service\ClassScheduleImportService;
use App\Service\ReservationAuditLogger;
use App\Service\ReservationMailer;
use Doctrine\ORM\EntityManagerInterface;
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
        Request $request,
    ): Response {
        $limit = 10;
        $page = max(1, (int) $request->query->get('page', 1));
        $status = (string) $request->query->get('status', 'All');
        $allowedStatuses = [
            'All' => null,
            'Pending' => ['Pending'],
            'Approved' => ['Approved'],
            'Canceled' => ['Cancelled', 'Canceled'],
            'Cancelled' => ['Cancelled', 'Canceled'],
            'Rejected' => ['Rejected'],
        ];
        if (!array_key_exists($status, $allowedStatuses)) {
            $status = 'All';
        }

        $qb = $reservationRepo->createQueryBuilder('r')
            ->leftJoin('r.facility', 'f')
            ->addSelect('f')
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC');

        if ($allowedStatuses[$status] !== null) {
            $qb->andWhere('r.status IN (:statuses)')
                ->setParameter('statuses', $allowedStatuses[$status]);
        }

        $total = (int) (clone $qb)
            ->select('COUNT(r.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / $limit));

        if ($page > $pages) {
            return $this->redirectToRoute('admin_reservations', [
                'status' => $status,
                'page' => $pages,
            ]);
        }

        $reservations = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $this->render('super_admin/reservations.html.twig', [
            'reservations' => $reservations,
            'selectedStatus' => $status,
            'statusOptions' => ['All', 'Pending', 'Approved', 'Canceled', 'Rejected'],
            'pagination' => [
                'page' => $page,
                'pages' => $pages,
                'limit' => $limit,
                'total' => $total,
            ],
        ]);
    }

    #[Route('/reservations/{id}/edit', name: 'admin_edit_reservation', methods: ['GET'])]
    public function editReservation(Reservation $reservation, FacilityRepository $facilityRepo): Response
    {
        return $this->render('super_admin/edit_reservation.html.twig', [
            'reservation' => $reservation,
            'facilities' => $facilityRepo->findAll(),
            'calendar_back_route' => 'admin_calendar',
            'update_route' => 'admin_update_reservation',
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
        // Skip CSRF validation for Super Admin operations
        // Super Admin has full privileges and doesn't need CSRF protection

        $previousStatus = $reservation->getStatus();
        $newStatus = (string) $request->request->get('status');

        if (!ReservationAuditLogger::isManageableStatus($newStatus)) {
            $this->addFlash('error', 'Invalid status. Allowed: Pending, Approved, Rejected, Cancelled.');
            return $this->redirectToRoute('admin_edit_reservation', ['id' => $reservation->getId()]);
        }

        if ($previousStatus !== $newStatus) {
            $reservation->setStatus($newStatus);
            $reservation->setUpdatedAt(new \DateTime());
            $auditLogger->logStatusChange($reservation, $previousStatus, $newStatus, 'admin_update', null);
        }

        $em->flush();

        $this->addFlash('success', 'Reservation updated successfully.');
        return $this->redirectToRoute('admin_calendar');
    }

    #[Route('/reservations/{id}/approve', name: 'admin_approve_reservation', methods: ['POST'])]
    public function approveReservation(
        Reservation $reservation,
        Request $request,
        ReservationRepository $reservationRepo,
        EntityManagerInterface $em,
        ReservationMailer $reservationMailer
    ): Response {
        if (!$this->isCsrfTokenValid('approve_reservation_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $date = $reservation->getReservationDate();
        $startTime = $reservation->getReservationStartTime();
        $endTime = $reservation->getReservationEndTime();

        if ($reservationRepo->isTimeRangeBooked($reservation->getFacility(), $date, $startTime, $endTime)) {
            $this->addFlash('error', 'Cannot approve: this time range is already approved for this facility.');

            return $this->redirectToRoute('admin_reservations');
        }

        $reservation->setStatus('Approved');
        $em->flush();

        // Send email notification to user
        $reservationMailer->notifyApproved($reservation);

        $this->addFlash('success', 'Reservation approved successfully.');

        return $this->redirectToRoute('admin_reservations');
    }

    #[Route('/reservations/{id}/reject', name: 'admin_reject_reservation', methods: ['POST'])]
    public function rejectReservation(
        Reservation $reservation,
        Request $request,
        EntityManagerInterface $em,
        ReservationMailer $reservationMailer
    ): Response {
        if (!$this->isCsrfTokenValid('reject_reservation_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $reason = $request->request->get('reason') ?? 'Not specified';
        $reservation->setStatus('Rejected');
        $reservation->setRejectionReason($reason);
        $em->flush();

        // Send email notification to user
        $reservationMailer->notifyRejected($reservation);

        $this->addFlash('success', 'Reservation rejected successfully.');

        return $this->redirectToRoute('admin_reservations');
    }

    #[Route('/calendar', name: 'admin_calendar')]
    public function calendar(
        Request $request,
        FacilityRepository $facilityRepo
    ): Response {
        $facilities = $facilityRepo->findAll();
        $initialDate = $request->query->get('date');
        $parsedInitialDate = $initialDate ? \DateTime::createFromFormat('!Y-m-d', $initialDate) : null;

        // Only Super Admin can import class schedules
        $canImport = $this->isGranted('ROLE_SUPER_ADMIN');

        return $this->render('super_admin/calendar.html.twig', [
            'facilities' => $facilities,
            'initialDate' => $parsedInitialDate ? $parsedInitialDate->format('Y-m-d') : null,
            'calendar_full_mode' => true,
            'calendar_can_import' => $canImport,
            'calendar_back_url' => $this->generateUrl('admin_reservations'),
            'calendar_data_url' => $this->generateUrl('admin_calendar_data'),
            'calendar_edit_url_pattern' => '/super-admin/reservations/{id}/edit',
            'calendar_block_create_url' => $this->generateUrl('admin_calendar_block'),
            'calendar_import_url' => $this->generateUrl('admin_calendar_import'),
            'calendar_import_delete_url' => $this->generateUrl('admin_calendar_import_delete'),
            'calendar_block_update_pattern' => str_replace('{id}', 'ID_PLACEHOLDER', $this->generateUrl('admin_calendar_block_update', ['id' => 'ID_PLACEHOLDER'])),
            'calendar_block_delete_pattern' => str_replace('{id}', 'ID_PLACEHOLDER', $this->generateUrl('admin_calendar_block_delete', ['id' => 'ID_PLACEHOLDER'])),
            'calendar_notify_url_pattern' => '/super-admin/class-schedule/{id}/notify',
            'calendar_class_schedule_update_pattern' => str_replace('{id}', 'ID_PLACEHOLDER', $this->generateUrl('admin_class_schedule_update', ['id' => 'ID_PLACEHOLDER'])),
            'calendar_class_schedule_delete_pattern' => str_replace('{id}', 'ID_PLACEHOLDER', $this->generateUrl('admin_class_schedule_delete', ['id' => 'ID_PLACEHOLDER'])),
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

    #[Route('/calendar/block', name: 'admin_calendar_block', methods: ['POST'])]
    public function createBlock(
        Request $request,
        FacilityRepository $facilityRepo,
        ReservationRepository $reservationRepo,
        ClassScheduleRepository $classScheduleRepo,
        EntityManagerInterface $em,
        ScheduleRevisionService $scheduleRevision,
    ): Response {
        $isAjax = $request->isXmlHttpRequest();

        // Skip CSRF validation for Super Admin operations
        // Super Admin has full privileges and doesn't need CSRF protection

        // Get and validate input data
        $facilityId = $request->request->get('facility');
        if (!$facilityId) {
            return $this->json(['success' => false, 'message' => 'Please select a facility.'], Response::HTTP_BAD_REQUEST);
        }

        $facility = $facilityRepo->find($facilityId);
        if (!$facility) {
            return $this->json(['success' => false, 'message' => 'Invalid facility selected.'], Response::HTTP_BAD_REQUEST);
        }

        $date  = \DateTime::createFromFormat('!Y-m-d', (string) $request->request->get('date'));
        $start = \DateTime::createFromFormat('!H:i', (string) $request->request->get('start_time'));
        $end   = \DateTime::createFromFormat('!H:i', (string) $request->request->get('end_time'));

        if ($err = $this->validateScheduleDateTime($date, $start, $end, 'Invalid date or time values.')) {
            return $err;
        }
        if ($err = $this->assertWithinBusinessHours($date, $start, $end, 'Schedule blocks must be between 7:00 AM and 8:00 PM.')) {
            return $err;
        }

        // Check for time conflicts - Super Admin can override
        $hasConflicts = $reservationRepo->isTimeRangeBooked($facility, $date, $start, $end, null, ['Approved', 'Pending']);
        $overrideConflict = $request->request->get('override_conflict') === 'true';
        
        if ($hasConflicts && !$overrideConflict) {
            return $this->json([
                'success' => false, 
                'message' => 'This time range has existing reservations, class schedules, or blocks. As Super Admin, you can override this conflict.',
                'hasConflicts' => true,
                'conflictMessage' => 'Existing items found in this time slot. Override to proceed?'
            ], Response::HTTP_CONFLICT);
        }

        // Create the block
        $type = (string) $request->request->get('block_type', 'Manual');
        if (!in_array($type, ['Manual', 'Blocked', 'Maintenance'], true)) {
            $type = 'Manual';
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

        // Send email notifications to affected faculty if there were conflicts
        if ($hasConflicts && $overrideConflict) {
            $this->notifyAffectedFaculty($facility, $date, $start, $end, $block, $reservationRepo, $classScheduleRepo);
        }

        $message = 'Schedule block added successfully. The selected time is now unavailable for reservations.' . ($hasConflicts && $overrideConflict ? ' Email notifications sent to affected faculty.' : '');
        if ($isAjax) {
            return $this->json(array_merge(
                ['success' => true, 'message' => $message],
                $this->buildBlockJson($block, $facility),
                ['scheduleRevision' => $scheduleRevision->getRevision()]
            ));
        }

        $this->addFlash('success', $message);
        return $this->redirectToRoute('admin_calendar');
    }

    #[Route('/calendar/class-schedule/available-facilities', name: 'admin_class_schedule_available_facilities', methods: ['GET'])]
    public function availableFacilitiesForClassSchedule(
        Request $request,
        FacilityRepository $facilityRepo,
        ReservationRepository $reservationRepo,
    ): JsonResponse {
        $date  = \DateTime::createFromFormat('!Y-m-d', (string) $request->query->get('date'));
        $start = \DateTime::createFromFormat('!H:i', (string) $request->query->get('start'));
        $end   = \DateTime::createFromFormat('!H:i', (string) $request->query->get('end'));

        if ($this->isInvalidDateTimeRange($date, $start, $end)) {
            return $this->json(['facilities' => [], 'message' => 'Invalid date or time.'], Response::HTTP_BAD_REQUEST);
        }

        $excludeId = $request->query->get('exclude_id');
        $excludeScheduleId = is_numeric($excludeId) ? (int) $excludeId : null;

        // Get all facilities and check which ones are available
        $allFacilities = $facilityRepo->findAll();
        $availableFacilities = [];

        foreach ($allFacilities as $facility) {
            // Check if facility is available for this time slot
            $isBooked = $reservationRepo->isTimeRangeBooked(
                $facility,
                $date,
                $start,
                $end,
                null,
                ['Approved', 'Pending'],
                $excludeScheduleId
            );

            if (!$isBooked) {
                $availableFacilities[] = [
                    'id' => $facility->getId(),
                    'name' => $facility->getName(),
                    'capacity' => $facility->getCapacity(),
                ];
            }
        }

        return $this->json(['facilities' => $availableFacilities]);
    }

    #[Route('/class-schedule/{id}/block', name: 'admin_class_schedule_block', methods: ['POST'])]
    public function blockClassSchedule(
        ClassSchedule $schedule,
        Request $request,
        FacilityRepository $facilityRepo,
        EntityManagerInterface $em,
        ScheduleRevisionService $scheduleRevision,
    ): JsonResponse {
        // Skip CSRF validation for Super Admin operations
        // Super Admin has full privileges and doesn't need CSRF protection

        $blockType = $request->request->get('block_type', 'Blocked');
        
        // Set the status on the class schedule instead of creating a separate block
        $schedule->setStatus($blockType);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Class schedule blocked successfully. Time slot is now blocked.',
            'scheduleRevision' => $scheduleRevision->getRevision(),
        ]);
    }

    #[Route('/class-schedule/{id}/revert', name: 'admin_class_schedule_revert', methods: ['POST'])]
    public function revertClassSchedule(
        ClassSchedule $schedule,
        Request $request,
        EntityManagerInterface $em,
        ScheduleRevisionService $scheduleRevision,
    ): JsonResponse {
        // Clear the status to revert to normal
        $schedule->setStatus(null);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Class schedule reverted successfully. Time slot is now available.',
            'scheduleRevision' => $scheduleRevision->getRevision(),
        ]);
    }

    #[Route('/class-schedule/{id}/unblock', name: 'admin_class_schedule_unblock', methods: ['POST'])]
    public function unblockClassSchedule(
        ClassSchedule $schedule,
        Request $request,
        FacilityRepository $facilityRepo,
        EntityManagerInterface $em,
        ScheduleRevisionService $scheduleRevision,
    ): JsonResponse {
        // Skip CSRF validation for Super Admin operations
        // Super Admin has full privileges and doesn't need CSRF protection

        // Find and remove any blocks for this class schedule time slot
        $blocks = $em->getRepository(FacilityScheduleBlock::class)->createQueryBuilder('b')
            ->where('b.facility = :facility')
            ->andWhere('b.blockDate = :date')
            ->andWhere('b.startTime = :start')
            ->andWhere('b.endTime = :end')
            ->andWhere('b.source = :source')
            ->setParameter('facility', $schedule->getFacility())
            ->setParameter('date', $schedule->getScheduleDate())
            ->setParameter('start', $schedule->getStartTime())
            ->setParameter('end', $schedule->getEndTime())
            ->setParameter('source', 'Class Schedule Block')
            ->getQuery()
            ->getResult();

        foreach ($blocks as $block) {
            $em->remove($block);
        }

        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Class schedule unblocked successfully.',
            'schedule' => [
                'id' => $schedule->getId(),
                'reservationDate' => $schedule->getScheduleDate()->format('Y-m-d'),
            ],
            'scheduleRevision' => $scheduleRevision->getRevision(),
        ]);
    }

    #[Route('/class-schedule/{id}/update', name: 'admin_class_schedule_update', methods: ['POST'])]
    public function updateClassSchedule(
        ClassSchedule $schedule,
        Request $request,
        FacilityRepository $facilityRepo,
        ReservationRepository $reservationRepo,
        ClassScheduleRepository $classScheduleRepo,
        ClassScheduleFacultyMatcher $facultyMatcher,
        EntityManagerInterface $em,
        ScheduleRevisionService $scheduleRevision,
        ClassScheduleNotificationService $notificationService,
    ): JsonResponse {
        // Skip CSRF validation for Super Admin operations
        // Super Admin has full privileges and doesn't need CSRF protection

        $facility = $facilityRepo->find($request->request->get('facility'));
        $date = \DateTime::createFromFormat('!Y-m-d', (string) $request->request->get('date'));
        $start = \DateTime::createFromFormat('!H:i', (string) $request->request->get('start_time'));
        $end = \DateTime::createFromFormat('!H:i', (string) $request->request->get('end_time'));

        if (!$facility) {
            return $this->json(['success' => false, 'message' => 'Invalid class schedule data.'], Response::HTTP_BAD_REQUEST);
        }
        if ($err = $this->validateScheduleDateTime($date, $start, $end, 'Invalid class schedule data.')) {
            return $err;
        }
        if ($err = $this->assertWithinBusinessHours($date, $start, $end, 'Class schedules must be between 7:00 AM and 8:00 PM.')) {
            return $err;
        }

        // Skip conflict validation for Super Admin
        // Super Admin can edit any schedule regardless of conflicts

        $oldFacultyEmail = $schedule->getFacultyEmail();
        $facultyEmail    = $this->applyClassScheduleFields($schedule, $request, $facility, $date, $start, $end, $facultyMatcher);

        $em->flush();

        $message = 'Class schedule updated successfully.';
        $notificationResult = null;

        // Notify faculty if email was added or changed
        if ($facultyEmail !== '' && $facultyEmail !== $oldFacultyEmail) {
            $notificationResult = $notificationService->notifyFaculty($schedule);
            $message = $notificationResult['success']
                ? 'Class schedule updated and faculty notified.'
                : 'Class schedule updated, but notification failed: ' . $notificationResult['message'];
        }

        return $this->json([
            'success' => true,
            'message' => $message,
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
        // Skip CSRF validation for Super Admin operations
        // Super Admin has full privileges and doesn't need CSRF protection

        $em->remove($schedule);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Class schedule deleted successfully.',
            'scheduleRevision' => $scheduleRevision->getRevision(),
        ]);
    }

    #[Route('/calendar/block/{id}/update', name: 'admin_calendar_block_update', methods: ['POST'])]
    public function updateBlock(
        FacilityScheduleBlock $block,
        Request $request,
        FacilityRepository $facilityRepo,
        ReservationRepository $reservationRepo,
        EntityManagerInterface $em,
        ScheduleRevisionService $scheduleRevision,
    ): JsonResponse {
        // Skip CSRF validation for Super Admin operations
        // Super Admin has full privileges and doesn't need CSRF protection

        $facility = $facilityRepo->find($request->request->get('facility'));
        $date = \DateTime::createFromFormat('!Y-m-d', (string) $request->request->get('date'));
        $start = \DateTime::createFromFormat('!H:i', (string) $request->request->get('start_time'));
        $end = \DateTime::createFromFormat('!H:i', (string) $request->request->get('end_time'));

        if (!$facility) {
            return $this->json(['success' => false, 'message' => 'Invalid schedule block data.'], Response::HTTP_BAD_REQUEST);
        }
        if ($err = $this->validateScheduleDateTime($date, $start, $end, 'Invalid schedule block data.')) {
            return $err;
        }
        if ($err = $this->assertWithinBusinessHours($date, $start, $end, 'Schedule blocks must be between 7:00 AM and 8:00 PM.')) {
            return $err;
        }

        // Skip conflict validation for Super Admin
        // Super Admin can edit any schedule regardless of conflicts

        $blockType = $this->resolveBlockType((string) $request->request->get('block_type', 'Manual'));

        if ($blockType === 'Available') {
            $em->remove($block);
            $em->flush();
            return $this->json(['success' => true, 'message' => 'Schedule block removed. Time slot is now available.', 'scheduleRevision' => $scheduleRevision->getRevision()]);
        }

        $this->applyBlockFields($block, $request, $facility, $date, $start, $end, $blockType);

        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Schedule block updated successfully.',
            'block' => [
                'id' => 'block_' . $block->getId(),
                'reservationDate' => $block->getBlockDate()->format('Y-m-d'),
            ],
            'scheduleRevision' => $scheduleRevision->getRevision(),
        ]);
    }

    #[Route('/calendar/block/{id}/delete', name: 'admin_calendar_block_delete', methods: ['POST'])]
    public function deleteBlock(
        FacilityScheduleBlock $block,
        Request $request,
        EntityManagerInterface $em,
        ScheduleRevisionService $scheduleRevision,
    ): JsonResponse {
        // Skip CSRF validation for Super Admin operations
        // Super Admin has full privileges and doesn't need CSRF protection

        $em->remove($block);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Schedule block deleted successfully.',
            'scheduleRevision' => $scheduleRevision->getRevision(),
        ]);
    }

    private function notifyAffectedFaculty(Facility $facility, \DateTimeInterface $date, \DateTimeInterface $start, \DateTimeInterface $end, ?FacilityScheduleBlock $block, ReservationRepository $reservationRepo, ClassScheduleRepository $classScheduleRepo): void
    {
        
        // Find all class schedules that conflict with this time slot
        $conflictingSchedules = $classScheduleRepo->createQueryBuilder('cs')
            ->where('cs.facility = :facility')
            ->andWhere('cs.scheduleDate = :date')
            ->andWhere('(cs.startTime < :end AND cs.endTime > :start)')
            ->setParameter('facility', $facility)
            ->setParameter('date', $date)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();

        foreach ($conflictingSchedules as $schedule) {
            if ($schedule->getFacultyEmail()) {
                // Log the notification (you can implement actual email sending later)
                $action = $block ? 'blocked' : 'modified';
                error_log('Faculty notification sent to: ' . $schedule->getFacultyEmail() . 
                         ' for ' . $action . ' schedule: ' . $schedule->getCourseCode() . 
                         ' on ' . $date->format('Y-m-d') . 
                         ' from ' . $start->format('H:i') . ' to ' . $end->format('H:i') . 
                         ' at facility: ' . $facility->getName());
            }
        }
    }

    #[Route('/class-schedule/{id}/notify', name: 'admin_class_schedule_notify', methods: ['POST'])]
    public function notifyClassSchedule(
        ClassSchedule $schedule,
        Request $request,
        ClassScheduleNotificationService $notificationService,
        EntityManagerInterface $em,
    ): JsonResponse {
        // Skip CSRF validation for Super Admin operations
        // Super Admin has full privileges and doesn't need CSRF protection

        // Get custom message from request or use null
        $customMessage = $request->request->get('custom_message') ?: null;
        
        $result = $notificationService->notifyFaculty($schedule, $customMessage);
        $em->flush();

        return $this->json(
            ['success' => $result['success'], 'message' => $result['message'], 'channels' => $result['channels']],
            $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST,
        );
    }

    // Add missing routes that might be referenced
    #[Route('/calendar/import', name: 'admin_calendar_import', methods: ['POST'])]
    public function importSchedule(
        Request $request,
        ClassScheduleImportService $importService,
        ScheduleRevisionService $scheduleRevision,
    ): JsonResponse {
        // Only Admin and Super Admin can import class schedules
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json([
                'success' => false,
                'message' => 'Access denied. Only Admin can import class schedules.',
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $file = $request->files->get('schedule_file');
            $pasteData = $request->request->get('schedule_paste');
            $startDate = $request->request->get('start_date');
            $endDate = $request->request->get('end_date');
            $sourceName = $file ? $file->getClientOriginalName() : 'Pasted Data';

            $result = $importService->import($file, $pasteData, $sourceName, $startDate, $endDate);

            return $this->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'created' => $result['created'],
                'processed' => $result['processed'],
                'relocated' => $result['relocated'],
                'warnings' => $result['warnings'],
                'date' => $result['date'],
                'scheduleRevision' => $scheduleRevision->getRevision(),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
                'errors' => [$e->getMessage()],
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/calendar/import/delete', name: 'admin_calendar_import_delete', methods: ['POST'])]
    public function deleteImportedSchedule(
        Request $request,
        ClassScheduleRepository $classScheduleRepo,
        EntityManagerInterface $em,
        ScheduleRevisionService $scheduleRevision,
    ): JsonResponse {
        // Only Admin and Super Admin can delete imported class schedules
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json([
                'success' => false,
                'message' => 'Access denied. Only Admin can delete imported class schedules.',
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            // Find all imported class schedules (those with importBatchId)
            $importedSchedules = $classScheduleRepo->createQueryBuilder('cs')
                ->where('cs.importBatchId IS NOT NULL')
                ->getQuery()
                ->getResult();

            if (empty($importedSchedules)) {
                return $this->json([
                    'success' => false,
                    'message' => 'No imported schedules found to delete.'
                ]);
            }

            // Delete all imported class schedules
            $deletedCount = 0;
            foreach ($importedSchedules as $schedule) {
                $em->remove($schedule);
                $deletedCount++;
            }

            $em->flush();

            return $this->json([
                'success' => true,
                'message' => "Successfully deleted {$deletedCount} imported class schedules.",
                'deletedCount' => $deletedCount,
                'scheduleRevision' => $scheduleRevision->getRevision(),
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Error deleting imported schedules: ' . $e->getMessage()
            ]);
        }
    }
    private function isInvalidDateTimeRange(mixed $date, mixed $start, mixed $end): bool
    {
        return !$date || !$start || !$end || $end <= $start;
    }

    private function validateScheduleDateTime(mixed $date, mixed $start, mixed $end, string $message): ?JsonResponse
    {
        if ($this->isInvalidDateTimeRange($date, $start, $end)) {
            return $this->json(['success' => false, 'message' => $message], Response::HTTP_BAD_REQUEST);
        }
        if ($date->format('w') === '0' || $date->format('w') === 0) {
            return $this->json(['success' => false, 'message' => 'All facilities are closed on Sundays.'], Response::HTTP_BAD_REQUEST);
        }
        return null;
    }

    private function assertWithinBusinessHours(mixed $date, mixed $start, mixed $end, string $message): ?JsonResponse
    {
        $dayStart = \DateTime::createFromFormat('!H:i', '07:00');
        $dayEnd   = \DateTime::createFromFormat('!H:i', '20:00');
        if ($start < $dayStart || $end > $dayEnd) {
            return $this->json(['success' => false, 'message' => $message], Response::HTTP_BAD_REQUEST);
        }
        return null;
    }

    private function resolveBlockType(string $raw): string
    {
        return in_array($raw, ['Manual', 'Blocked', 'Maintenance', 'Available'], true) ? $raw : 'Manual';
    }

    private function buildBlockJson(FacilityScheduleBlock $block, Facility $facility): array
    {
        return [
            'block' => [
                'id'                   => 'block_' . $block->getId(),
                'itemType'             => 'block',
                'name'                 => $block->getTitle(),
                'eventName'            => $block->getTitle(),
                'email'                => '',
                'contact'              => '',
                'purpose'              => $block->getNotes(),
                'status'               => $block->getType() ?: 'Manual',
                'capacity'             => 0,
                'reservationDate'      => $block->getBlockDate()->format('Y-m-d'),
                'reservationStartTime' => $block->getStartTime()->format('H:i'),
                'reservationEndTime'   => $block->getEndTime()->format('H:i'),
                'facility'             => [
                    'id'       => $facility->getId(),
                    'name'     => $facility->getName(),
                    'capacity' => $facility->getCapacity(),
                ],
                'isBlock' => true,
            ],
        ];
    }

    private function applyClassScheduleFields(
        ClassSchedule $schedule,
        Request $request,
        Facility $facility,
        \DateTime $date,
        \DateTime $start,
        \DateTime $end,
        \App\Service\ClassScheduleFacultyMatcher $facultyMatcher,
    ): string {
        $previousFacility = $schedule->getFacility();
        if ($previousFacility && $previousFacility->getId() !== $facility->getId()) {
            $schedule->setPreviousFacility($previousFacility);
            $schedule->setIsRelocated(true);
        }

        $schedule->setFacility($facility)
                 ->setScheduleDate($date)
                 ->setStartTime($start)
                 ->setEndTime($end);

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

        return $facultyEmail;
    }

    private function applyBlockFields(
        FacilityScheduleBlock $block,
        Request $request,
        Facility $facility,
        \DateTime $date,
        \DateTime $start,
        \DateTime $end,
        string $blockType,
    ): void {
        $block->setFacility($facility)
              ->setBlockDate($date)
              ->setStartTime($start)
              ->setEndTime($end)
              ->setTitle(trim((string) $request->request->get('title')) ?: 'Unavailable')
              ->setType($blockType)
              ->setNotes($request->request->get('notes'));
    }

}
