<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ClassSchedule;
use App\Entity\Facility;
use App\Entity\MentorProfile;
use App\Entity\MentoringAppointment;
use App\Entity\Reservation;
use App\Repository\FacilityRepository;
use App\Repository\ReservationRepository;
use App\Repository\ClassScheduleNotificationLogRepository;
use App\Repository\ReservationStatusLogRepository;
use App\Repository\SpecializationRepository;
use App\Service\CalendarDataService;
use App\Service\ClassScheduleNotificationService;
use App\Service\ReservationAuditLogger;
use App\Service\ReservationStatusManager;
use App\Service\NotificationService;
use App\Entity\User;
use App\Entity\MentorCustomRequest;
use App\Entity\MentorApplication;
use App\Entity\MentoringAuditLog;
use App\Repository\MentoringAuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminRoleController extends AbstractController
{
    #[Route('', name: 'admin_role_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('admin_role_home');
    }

    #[Route('/dashboard', name: 'admin_role_home', methods: ['GET'])]
    public function home(EntityManagerInterface $em): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'initialData' => $this->getDashboardData($em),
            'reservationStatusCounts' => $this->reservationStatusCounts($em),
            'mentoringStatusCounts' => $this->mentoringStatusCounts($em),
            'upcomingReservations' => $em->getRepository(Reservation::class)->findBy(
                ['status' => 'Approved'],
                ['reservationDate' => 'ASC'],
                8
            ),
            'upcomingMentoringSessions' => $em->getRepository(MentoringAppointment::class)->findBy(
                [],
                ['scheduledAt' => 'ASC'],
                8
            ),
            'modules' => $this->adminModules(),
        ]);
    }

    #[Route('/api/stats', name: 'admin_role_api_stats', methods: ['GET'])]
    public function apiStats(EntityManagerInterface $em): JsonResponse
    {
        $response = $this->json($this->getDashboardData($em));
        $response->headers->set('Cache-Control', 'private, max-age=15');
        return $response;
    }

    #[Route('/api/analytics', name: 'admin_role_api_analytics', methods: ['GET'])]
    public function apiAnalytics(EntityManagerInterface $em): JsonResponse
    {
        $conn = $em->getConnection();
        $dates = [];
        for ($i = 29; $i >= 0; $i--) {
            $dates[] = (new \DateTime())->modify("-$i days")->format('Y-m-d');
        }

        $resByDate = $conn->executeQuery(
            'SELECT DATE(reservation_date) AS dt, COUNT(*) AS cnt
             FROM reservation
             WHERE status IN (\'Approved\', \'Pending\')
               AND DATE(reservation_date) >= ? AND DATE(reservation_date) <= ?
             GROUP BY DATE(reservation_date)',
            [$dates[0], $dates[\count($dates) - 1]]
        )->fetchAllKeyValue();

        $mentByDate = $conn->executeQuery(
            'SELECT DATE(scheduled_at) AS dt, COUNT(*) AS cnt
             FROM mentoring_appointment
             WHERE DATE(scheduled_at) >= ? AND DATE(scheduled_at) <= ?
             GROUP BY DATE(scheduled_at)',
            [$dates[0], $dates[\count($dates) - 1]]
        )->fetchAllKeyValue();

        $dailyStats = [];
        foreach ($dates as $date) {
            $dailyStats[] = [
                'date' => $date,
                'reservations' => (int) ($resByDate[$date] ?? 0),
                'mentoring' => (int) ($mentByDate[$date] ?? 0),
            ];
        }

        $liveTotal = array_sum(array_column($dailyStats, 'reservations'));

        return $this->json([
            'source' => $liveTotal > 0 ? 'live' : 'demo',
            'dataSourceLabel' => $liveTotal > 0 ? 'Live reservation data' : 'No live data — start analytics service for demo charts',
            'dailyStats' => $dailyStats,
        ]);
    }

    #[Route('/api/recent-reservations', name: 'admin_role_api_recent_reservations', methods: ['GET'])]
    public function apiRecentReservations(EntityManagerInterface $em): JsonResponse
    {
        $conn = $em->getConnection();
        $rows = $conn->executeQuery(
            'SELECT r.name AS userName, f.name AS facilityName,
                    r.reservation_date AS date, r.reservation_start_time AS time, r.status
             FROM reservation r
             LEFT JOIN facility f ON r.facility_id = f.id
             WHERE r.status NOT IN (:statuses)
             ORDER BY r.created_at DESC LIMIT 8',
            ['statuses' => ['Suggested']],
            ['statuses' => \Doctrine\DBAL\ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $response = $this->json([
            'recentReservations' => array_map(static function ($r) {
                return [
                    'facilityName' => $r['facilityName'] ?? 'Unknown',
                    'userName'     => $r['userName'] ?? '',
                    'date'         => $r['date'] ? date('M j, Y', strtotime($r['date'])) : '',
                    'time'         => $r['time'] ? substr($r['time'], 0, 5) : '',
                    'status'       => $r['status'] ?? '',
                ];
            }, $rows),
            'ts' => time(),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[Route('/api/mentoring-panel', name: 'admin_role_api_mentoring_panel', methods: ['GET'])]
    public function apiMentoringPanel(EntityManagerInterface $em): JsonResponse
    {
        $conn = $em->getConnection();

        $reqRows = $conn->executeQuery(
            'SELECT mcr.preferred_expertise, mcr.created_at,
                    u.first_name, u.last_name, u.email
             FROM mentor_custom_request mcr
             LEFT JOIN "user" u ON mcr.student_id = u.id
             ORDER BY mcr.created_at DESC LIMIT 5'
        )->fetchAllAssociative();

        $lbRows = $conn->executeQuery(
            'SELECT mp.display_name, mp.specialization, mp.engagement_points, u.degree
             FROM mentor_profile mp
             LEFT JOIN "user" u ON mp.user_id = u.id
             ORDER BY mp.engagement_points DESC LIMIT 5'
        )->fetchAllAssociative();

        $response = $this->json([
            'requests'    => array_map(static function ($r) {
                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ($r['email'] ?? '');
                return [
                    'title'   => $r['preferred_expertise'] ? $r['preferred_expertise'] . ' Mentor Request' : 'Mentoring Request',
                    'student' => $name,
                    'date'    => $r['created_at'] ? date('M j, Y', strtotime($r['created_at'])) : '',
                    'time'    => $r['created_at'] ? substr($r['created_at'], 11, 5) : '',
                ];
            }, $reqRows),
            'leaderboard' => array_map(static function ($m) {
                return [
                    'name'           => $m['display_name'] ?? '',
                    'points'         => $m['engagement_points'] ?? 0,
                    'specialization' => $m['specialization'] ?? '',
                    'degree'         => $m['degree'] ?? '',
                ];
            }, $lbRows),
        ]);
        $response->headers->set('Cache-Control', 'private, max-age=15');
        return $response;
    }

    #[Route('/reports', name: 'admin_role_reports', methods: ['GET'])]
    public function reports(EntityManagerInterface $em): Response
    {
        return $this->render('admin/reports.html.twig', [
            'reservationStatusCounts' => $this->reservationStatusCounts($em),
            'facilityCounts' => $this->facilityReservationCounts($em),
            'mentoringStatusCounts' => $this->mentoringStatusCounts($em),
            'topExpertise' => $this->topExpertise($em),
            'modules' => $this->adminModules(),
        ]);
    }

    #[Route('/reservation-monitoring', name: 'admin_role_reservation_monitoring', methods: ['GET'])]
    public function reservationMonitoring(
        EntityManagerInterface $em,
        ReservationStatusLogRepository $auditRepo,
        ClassScheduleNotificationLogRepository $classNotifyAuditRepo,
    ): Response {
        $today = new \DateTime('today');
        $tomorrow = new \DateTime('tomorrow');
        $todayReservations = $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->where('r.reservationDate >= :today AND r.reservationDate < :tomorrow')
            ->andWhere('r.status != :suggestedStatus')
            ->setParameter('today', $today)
            ->setParameter('tomorrow', $tomorrow)
            ->setParameter('suggestedStatus', 'Suggested')
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()->getResult();

        return $this->render('admin/reservation_monitoring.html.twig', [
            'reservations' => $todayReservations,
            'statusCounts' => $this->reservationStatusCountsToday($em),
            'facilityCounts' => $this->facilityReservationCountsToday($em),
            'statusAuditLogs' => $auditRepo->findRecent(30),
            'classScheduleNotifyLogs' => $classNotifyAuditRepo->findRecent(30),
        ]);
    }

    #[Route('/calendar', name: 'admin_role_calendar', methods: ['GET'])]
    public function calendar(Request $request, FacilityRepository $facilityRepo): Response
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('admin_calendar');
        }

        $initialDate = $request->query->get('date');
        $parsedInitialDate = $initialDate ? \DateTime::createFromFormat('!Y-m-d', $initialDate) : null;

        return $this->render('super_admin/calendar.html.twig', [
            'facilities' => $facilityRepo->findAll(),
            'initialDate' => $parsedInitialDate ? $parsedInitialDate->format('Y-m-d') : null,
            'calendar_full_mode' => false,
            'calendar_can_import' => true,
            'calendar_back_url' => $this->generateUrl('admin_role_reservation_monitoring'),
            'calendar_data_url' => $this->generateUrl('admin_role_calendar_data'),
            'calendar_edit_url_pattern' => '/admin/reservations/{id}/edit',
            'calendar_status_url_pattern' => '/admin/reservations/{id}/status',
            'calendar_block_create_url' => '',
            'calendar_import_url' => $this->generateUrl('admin_calendar_import'),
            'calendar_import_delete_url' => $this->generateUrl('admin_calendar_import_delete'),
            'calendar_block_update_pattern' => '',
            'calendar_block_delete_pattern' => '',
            'calendar_notify_url_pattern' => '/admin/class-schedule/{id}/notify',
        ]);
    }

    #[Route('/class-schedule/{id}/notify', name: 'admin_role_class_schedule_notify', methods: ['POST'])]
    public function notifyClassScheduleFaculty(
        ClassSchedule $schedule,
        Request $request,
        ClassScheduleNotificationService $notificationService,
    ): JsonResponse {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->json(['success' => false, 'message' => 'Use super-admin tools for this account.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('class_schedule_notify_' . $schedule->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], Response::HTTP_FORBIDDEN);
        }

        $result = $notificationService->notifyFaculty($schedule);

        return $this->json(
            ['success' => $result['success'], 'message' => $result['message'], 'channels' => $result['channels']],
            $result['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST,
        );
    }

    #[Route('/calendar/data', name: 'admin_role_calendar_data', methods: ['GET'])]
    public function calendarData(Request $request, CalendarDataService $calendarData, CsrfTokenManagerInterface $csrfTokenManager): JsonResponse
    {
        $start = $request->query->get('start');
        $end = $request->query->get('end');

        if (!$start || !$end) {
            return $this->json(['reservations' => []]);
        }

        $payload = $calendarData->buildCalendarPayload(
            $start,
            $end,
            $request->query->get('facility'),
            $request->query->get('status'),
            true,
            true,
        );

        foreach ($payload['reservations'] as &$item) {
            if (($item['itemType'] ?? '') === 'reservation' && is_numeric($item['id'])) {
                $item['statusCsrfToken'] = $csrfTokenManager
                    ->getToken('update_reservation_status_' . $item['id'])
                    ->getValue();
            }
        }
        unset($item);

        return $this->json($payload);
    }

    #[Route('/reservations/{id}/edit', name: 'admin_role_edit_reservation', methods: ['GET'])]
    public function editReservation(Reservation $reservation, FacilityRepository $facilityRepo): Response
    {
        return $this->render('super_admin/edit_reservation.html.twig', [
            'reservation' => $reservation,
            'facilities' => $facilityRepo->findAll(),
            'calendar_back_route' => 'admin_role_calendar',
            'update_route' => 'admin_role_update_reservation',
        ]);
    }

    #[Route('/reservations/{id}/update', name: 'admin_role_update_reservation', methods: ['POST'])]
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

        if (!ReservationAuditLogger::isManageableStatus($newStatus)) {
            $this->addFlash('error', 'Invalid status. Allowed: Pending, Approved, Rejected, Cancelled.');
            return $this->redirectToRoute('admin_role_edit_reservation', ['id' => $reservation->getId()]);
        }

        $reservation->setName((string) $request->request->get('name'));
        $reservation->setEventName(trim((string) $request->request->get('event_name')) ?: null);
        $reservation->setEmail((string) $request->request->get('email'));
        $reservation->setContact((string) $request->request->get('contact'));
        $reservation->setCapacity((int) $request->request->get('capacity'));
        $reservation->setPurpose($request->request->get('purpose'));

        $reservationDate = new \DateTime((string) $request->request->get('reservationDate'));
        $startTime = \DateTime::createFromFormat('H:i', (string) $request->request->get('reservationStartTime'));
        $endTime = \DateTime::createFromFormat('H:i', (string) $request->request->get('reservationEndTime'));

        $reservation->setReservationDate($reservationDate);
        $reservation->setReservationStartTime($startTime);
        $reservation->setReservationEndTime($endTime);

        $facilityId = $request->request->get('facility');
        if ($facilityId) {
            $facility = $em->getRepository(Facility::class)->find($facilityId);
            if ($facility) {
                $reservation->setFacility($facility);
            }
        }

        if ($newStatus === 'Approved' && $reservationRepo->isTimeRangeBooked(
            $reservation->getFacility(),
            $reservationDate,
            $startTime,
            $endTime,
            $reservation->getId(),
        )) {
            $this->addFlash('error', 'Cannot update: this time range is already booked for this facility.');
            return $this->redirectToRoute('admin_role_edit_reservation', ['id' => $reservation->getId()]);
        }

        $reservation->setStatus($newStatus);
        $reservation->setUpdatedAt(new \DateTime());
        $auditLogger->logStatusChange($reservation, $previousStatus, $newStatus, 'update');
        $em->flush();

        $this->addFlash('success', 'Reservation updated successfully.');

        return $this->redirectToRoute('admin_role_calendar');
    }

    #[Route('/reservations/{id}/approve', name: 'admin_role_approve_reservation', methods: ['POST'])]
    public function approveReservation(
        Reservation $reservation,
        Request $request,
        ReservationStatusManager $statusManager,
    ): Response {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('admin_reservations');
        }

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
        } else {
            $this->addFlash('success', $result['message']);
        }

        return $this->redirectToRoute('admin_role_reservation_monitoring');
    }

    #[Route('/reservations/{id}/reject', name: 'admin_role_reject_reservation', methods: ['POST'])]
    public function rejectReservation(
        Reservation $reservation,
        Request $request,
        ReservationStatusManager $statusManager,
    ): Response {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('admin_reservations');
        }

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
        } else {
            $this->addFlash('success', $result['message']);
        }

        return $this->redirectToRoute('admin_role_reservation_monitoring');
    }

    #[Route('/reservations/{id}/status', name: 'admin_role_update_reservation_status', methods: ['POST'])]
    public function updateReservationStatus(
        Reservation $reservation,
        Request $request,
        ReservationStatusManager $statusManager,
    ): JsonResponse {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->json(['success' => false, 'message' => 'Use super-admin tools for this account.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('update_reservation_status_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], Response::HTTP_FORBIDDEN);
        }

        $newStatus = (string) $request->request->get('status');
        $note = $request->request->get('note');
        $result = $statusManager->applyManageableStatus($reservation, $newStatus, 'calendar', is_string($note) ? $note : null);

        if (!$result['ok']) {
            return $this->json(['success' => false, 'message' => $result['message'] ?? 'Update failed.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true, 'message' => 'Status updated to ' . $newStatus . '.']);
    }

    #[Route('/api/reservation-audit', name: 'admin_role_api_reservation_audit', methods: ['GET'])]
    public function apiReservationAudit(ReservationStatusLogRepository $auditRepo): JsonResponse
    {
        $logs = array_map(static function ($log) {
            $reservation = $log->getReservation();
            $facility = $reservation?->getFacility();
            $user = $log->getChangedBy();

            return [
                'changedAt' => $log->getChangedAt()->format('M d, Y H:i'),
                'actorRole' => $log->getActorRoleLabel(),
                'actorEmail' => $user?->getEmail() ?? '',
                'facility' => $facility?->getName() ?? '',
                'requester' => $reservation?->getName() ?? '',
                'previousStatus' => $log->getPreviousStatus(),
                'newStatus' => $log->getNewStatus(),
                'action' => $log->getAction(),
                'note' => $log->getNote(),
            ];
        }, $auditRepo->findRecent(50));

        $response = $this->json(['logs' => $logs]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/api/reservation-monitoring', name: 'admin_role_api_reservation_monitoring', methods: ['GET'])]
    public function apiReservationMonitoring(EntityManagerInterface $em): JsonResponse
    {
        $conn  = $em->getConnection();
        $today = (new \DateTime('today'))->format('Y-m-d');

        $rows = $conn->fetchAllAssociative(
            'SELECT r.id, r.name, r.email, r.status,
                    r.reservation_start_time, r.reservation_end_time,
                    f.name AS facility_name,
                    u.roles AS user_roles
             FROM reservation r
             LEFT JOIN facility f ON f.id = r.facility_id
             LEFT JOIN "user" u   ON u.id = r.user_id
             WHERE DATE(r.reservation_date) = :today
             ORDER BY r.created_at DESC',
            ['today' => $today]
        );

        $reservations = array_map(static function (array $r): array {
            $roles     = json_decode($r['user_roles'] ?? '[]', true) ?? [];
            $roleLabel = in_array('ROLE_FACULTY', $roles) ? 'Faculty'
                       : (in_array('ROLE_MENTOR', $roles)  ? 'Mentor' : 'Student');
            return [
                'name'     => $r['name'],
                'email'    => $r['email'],
                'role'     => $roleLabel,
                'facility' => $r['facility_name'] ?? '',
                'time'     => substr($r['reservation_start_time'], 0, 5)
                              . ' – ' . substr($r['reservation_end_time'], 0, 5),
                'status'   => $r['status'],
            ];
        }, $rows);

        $statusRows = $conn->fetchAllAssociative(
            'SELECT status, COUNT(*) AS cnt FROM reservation
             WHERE DATE(reservation_date) = :today GROUP BY status',
            ['today' => $today]
        );
        $statusCounts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Cancelled' => 0];
        foreach ($statusRows as $sr) {
            $statusCounts[$sr['status']] = (int) $sr['cnt'];
        }

        $facRows = $conn->fetchAllAssociative(
            'SELECT f.name AS facility_name, COUNT(r.id) AS cnt
             FROM facility f
             LEFT JOIN reservation r ON r.facility_id = f.id AND DATE(r.reservation_date) = :today
             GROUP BY f.id, f.name',
            ['today' => $today]
        );
        $facilityCounts = [];
        foreach ($facRows as $fr) {
            $facilityCounts[$fr['facility_name']] = (int) $fr['cnt'];
        }

        $response = $this->json([
            'reservations'   => $reservations,
            'statusCounts'   => $statusCounts,
            'facilityCounts' => $facilityCounts,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[Route('/mentorship-coordination/{id}/assign', name: 'admin_role_mentorship_assign', methods: ['POST'])]
    public function mentorshipAssign(int $id, Request $request, EntityManagerInterface $em, NotificationService $notifService): Response
    {
        if (!$this->isCsrfTokenValid('mentorship_assign_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
        $req = $em->getRepository(\App\Entity\MentorCustomRequest::class)->find($id);
        if ($req) {
            $mentorId = (int) $request->request->get('mentor_id', 0);
            $mentorNameManual = trim((string) $request->request->get('mentor_name_manual', ''));
            $specialization   = trim((string) $request->request->get('specialization', ''));
            if ($mentorId > 0) {
                $mentor = $em->getRepository(MentorProfile::class)->find($mentorId);
                if ($mentor) {
                    $req->setAssignedMentorName($mentorNameManual ?: $mentor->getDisplayName());
                    $req->setAssignedMentorExpertise($specialization ?: $mentor->getSpecialization());
                }
            } else {
                if ($mentorNameManual !== '') $req->setAssignedMentorName($mentorNameManual);
                if ($specialization !== '') $req->setAssignedMentorExpertise($specialization);
            }
            $timeStart = trim((string) $request->request->get('available_time_start', ''));
            $timeEnd   = trim((string) $request->request->get('available_time_end', ''));
            $fmtTime = static function (string $t): string {
                $dt = \DateTime::createFromFormat('H:i', $t);
                return $dt ? $dt->format('g:i A') : $t;
            };
            $availableTime = ($timeStart !== '' && $timeEnd !== '')
                ? $fmtTime($timeStart) . ' – ' . $fmtTime($timeEnd)
                : trim((string) $request->request->get('available_time', ''));
            $meetingMethod = trim((string) $request->request->get('meeting_method_override', '')) ?: trim((string) $request->request->get('meeting_method', ''));
            $req->setMeetingMethod($meetingMethod ?: null);
            $req->setAvailableDates($request->request->get('available_dates') ?: null);
            $req->setAvailableTime($availableTime ?: null);
            $req->setAdminInstructions($request->request->get('admin_instructions') ?: null);
            $submittedStatus = trim((string) $request->request->get('status', 'approved'));
            $prevStatus = $req->getStatus();
            $req->setStatus(in_array($submittedStatus, ['pending','reviewing','assigned','completed','cancelled','approved'], true) ? $submittedStatus : 'approved');
            $student = $req->getStudent();
            $requesterName = $req->getFullName() ?: ($student ? trim(($student->getFirstName() ?? '') . ' ' . ($student->getLastName() ?? '')) : 'Unknown');
            $actor = $this->getUser();
            $actorName = $actor instanceof User ? trim(($actor->getFirstName() ?? '') . ' ' . ($actor->getLastName() ?? '')) : 'Admin';
            if ($actorName === '') { $actorName = $actor instanceof User ? $actor->getEmail() : 'Admin'; }
            $instructions = trim((string) $request->request->get('admin_instructions', ''));
            $noteText = $instructions !== '' ? $instructions : ($req->getAssignedMentorName() ? 'Assigned to: ' . $req->getAssignedMentorName() : null);
            /** @var MentoringAuditLogRepository $auditRepo */
            $auditRepo = $em->getRepository(MentoringAuditLog::class);
            if (!$auditRepo->existsRecent('custom_request', $id, 'update_status', $submittedStatus, $actor instanceof User ? $actor->getId() : null)) {
                $auditLog = (new MentoringAuditLog())
                    ->setSubjectType('custom_request')
                    ->setSubjectId($id)
                    ->setSubjectLabel($requesterName)
                    ->setAction('update_status')
                    ->setPreviousStatus($prevStatus)
                    ->setNewStatus($submittedStatus)
                    ->setPerformedBy($actor instanceof User ? $actor : null)
                    ->setPerformedByName($actorName)
                    ->setPerformedByRole('Admin')
                    ->setNote($noteText);
                $em->persist($auditLog);
            }
            $em->flush();
            $actorName2 = $actor instanceof User ? trim($actor->getFirstName() . ' ' . $actor->getLastName()) : 'Admin';
            foreach ($em->getRepository(User::class)->findAdmins() as $u) {
                if ($u === $actor) continue;
                $notifService->notifyAdminMentorRequestUpdated($u, $id, $actorName2, ucfirst($submittedStatus), $requesterName);
            }
            if ($isAjax) return $this->json(['success' => true, 'message' => 'Mentor assigned successfully.']);
            $this->addFlash('success', 'Mentor assigned successfully.');
        } else {
            if ($isAjax) return $this->json(['success' => false, 'message' => 'Request not found.']);
        }
        return $this->redirectToRoute('admin_role_mentorship_coordination');
    }

    #[Route('/mentorship-coordination', name: 'admin_role_mentorship_coordination', methods: ['GET'])]
    public function mentorshipCoordination(EntityManagerInterface $em, SpecializationRepository $specializationRepository): Response
    {
        // Redirect Super Admin to the full-featured Super Admin interface
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('mentoring_superadmin_requests');
        }

        $specializations = $specializationRepository->findAllOrderedByName();

        return $this->render('admin/admin_mentoring.html.twig', [
            'applications' => $em->getRepository(\App\Entity\MentorApplication::class)->findBy([], ['createdAt' => 'DESC'], 20),
            'requests' => $em->getRepository(MentorCustomRequest::class)->findBy([], ['createdAt' => 'DESC'], 50),
            'appointments' => $em->getRepository(MentoringAppointment::class)->findBy([], ['scheduledAt' => 'DESC'], 20),
            'mentors' => $em->getRepository(MentorProfile::class)->findBy([], ['displayName' => 'ASC']),
            'leaderboard' => $em->getRepository(MentorProfile::class)->findBy([], ['engagementPoints' => 'DESC'], 10),
            'users' => $em->getRepository(User::class)->findAll(),
            'statusCounts' => $this->mentoringStatusCounts($em),
            'topExpertise' => $this->topExpertise($em),
            'is_super_admin' => false,
            'auditLogs' => $em->getRepository(MentoringAuditLog::class)->findRecent(60),
            'allSpecializations' => $specializations,
        ]);
    }

    #[Route('/mentors', name: 'admin_role_mentors_list', methods: ['GET'])]
    public function adminMentorsList(EntityManagerInterface $em): Response
    {
        // Redirect Super Admin to the full-featured Super Admin mentors list
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('mentoring_mentors_list');
        }

        return $this->render('admin/admin_mentors_list.html.twig', [
            'mentors' => $em->getRepository(MentorProfile::class)->findBy([], ['displayName' => 'ASC']),
        ]);
    }

    #[Route('/reports/download/{type}', name: 'admin_role_reports_download', methods: ['GET'])]
    public function downloadReport(string $type, EntityManagerInterface $em): Response
    {
        $rows = match ($type) {
            'reservations' => $this->reservationReportRows($em),
            'mentoring' => $this->mentoringReportRows($em),
            default => throw $this->createNotFoundException('Invalid report type'),
        };

        $filename = match ($type) {
            'reservations' => 'reservations_report.csv',
            'mentoring' => 'mentoring_report.csv',
            default => 'report.csv',
        };

        $response = new StreamedResponse();
        $response->setCallback(function () use ($rows) {
            $fp = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($fp, $row);
            }
            fclose($fp);
        });

        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );

        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Type', 'text/csv');

        return $response;
    }

    private function getDashboardData(EntityManagerInterface $em): array
    {
        $reservationRepo = $em->getRepository(Reservation::class);
        $mentorProfileRepo = $em->getRepository(MentorProfile::class);
        $appointmentRepo = $em->getRepository(MentoringAppointment::class);

        $recentRaw = $reservationRepo->createQueryBuilder('r')
            ->innerJoin('r.facility', 'f')
            ->addSelect('f')
            ->where('r.status != :suggestedStatus')
            ->setParameter('suggestedStatus', 'Suggested')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(8)
            ->getQuery()
            ->getResult();

        $recentReservations = array_map(function ($r) {
            return [
                'facilityName' => $r->getFacility() ? $r->getFacility()->getName() : 'Unknown',
                'userName' => $r->getName(),
                'date' => $r->getReservationDate() ? $r->getReservationDate()->format('M j, Y') : '',
                'time' => $r->getReservationStartTime() ? $r->getReservationStartTime()->format('H:i') : '',
                'status' => $r->getStatus(),
            ];
        }, $recentRaw);

        $excludeSuggested = fn($qb) => $qb->where('r.status != :s')->setParameter('s', 'Suggested')->getQuery()->getSingleScalarResult();

        return [
            'reservations' => [
                'total' => (int) $excludeSuggested($reservationRepo->createQueryBuilder('r')->select('COUNT(r.id)')),
                'approved' => $reservationRepo->count(['status' => 'Approved']),
                'pending' => $reservationRepo->count(['status' => 'Pending']),
                'rejected' => $reservationRepo->count(['status' => 'Rejected']),
                'today' => $reservationRepo->createQueryBuilder('r')
                    ->select('COUNT(r.id)')
                    ->where('r.reservationDate >= :today')
                    ->andWhere('r.status != :sug')
                    ->setParameter('today', new \DateTime('today'))
                    ->setParameter('sug', 'Suggested')
                    ->getQuery()
                    ->getSingleScalarResult(),
            ],
            'mentoring' => [
                'totalMentors' => $mentorProfileRepo->count([]),
                'appointments' => [
                    'total' => $appointmentRepo->count([]),
                    'upcoming' => $appointmentRepo->createQueryBuilder('a')
                        ->select('COUNT(a.id)')
                        ->where('a.scheduledAt >= :now')
                        ->setParameter('now', new \DateTime())
                        ->getQuery()
                        ->getSingleScalarResult(),
                ],
            ],
            'recentReservations' => $recentReservations,
        ];
    }

    private function reservationStatusCounts(EntityManagerInterface $em): array
    {
        $repo = $em->getRepository(Reservation::class);
        return [
            'Pending' => $repo->count(['status' => 'Pending']),
            'Approved' => $repo->count(['status' => 'Approved']),
            'Rejected' => $repo->count(['status' => 'Rejected']),
            'Cancelled' => $repo->count(['status' => 'Cancelled']),
        ];
    }

    private function reservationStatusCountsToday(EntityManagerInterface $em): array
    {
        $today = new \DateTime('today');
        $tomorrow = new \DateTime('tomorrow');
        $statuses = ['Pending', 'Approved', 'Rejected', 'Cancelled'];
        $counts = [];
        foreach ($statuses as $status) {
            $counts[$status] = (int) $em->getRepository(Reservation::class)->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->where('r.status = :status AND r.reservationDate >= :today AND r.reservationDate < :tomorrow')
                ->setParameter('status', $status)
                ->setParameter('today', $today)
                ->setParameter('tomorrow', $tomorrow)
                ->getQuery()->getSingleScalarResult();
        }
        return $counts;
    }

    private function facilityReservationCountsToday(EntityManagerInterface $em): array
    {
        $today = new \DateTime('today');
        $tomorrow = new \DateTime('tomorrow');
        $facilities = $em->getRepository(\App\Entity\Facility::class)->findAll();
        $counts = [];
        foreach ($facilities as $facility) {
            $cnt = (int) $em->getRepository(Reservation::class)->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->where('r.facility = :facility AND r.reservationDate >= :today AND r.reservationDate < :tomorrow')
                ->setParameter('facility', $facility)
                ->setParameter('today', $today)
                ->setParameter('tomorrow', $tomorrow)
                ->getQuery()->getSingleScalarResult();
            $counts[$facility->getName()] = $cnt;
        }
        return $counts;
    }

    private function mentoringStatusCounts(EntityManagerInterface $em): array
    {
        $appointmentRepo = $em->getRepository(MentoringAppointment::class);
        $applicationRepo = $em->getRepository(\App\Entity\MentorApplication::class);

        return [
            'appointments' => [
                'Scheduled' => $appointmentRepo->count(['status' => 'Scheduled']),
                'Completed' => $appointmentRepo->count(['status' => 'Completed']),
                'Cancelled' => $appointmentRepo->count(['status' => 'Cancelled']),
            ],
            'requests' => [
                'Pending' => $applicationRepo->count(['status' => 'Pending']),
                'Approved' => $applicationRepo->count(['status' => 'Approved']),
                'Rejected' => $applicationRepo->count(['status' => 'Rejected']),
            ],
        ];
    }

    private function facilityReservationCounts(EntityManagerInterface $em): array
    {
        $repo = $em->getRepository(Reservation::class);
        $facilities = $em->getRepository(\App\Entity\Facility::class)->findAll();
        
        $counts = [];
        foreach ($facilities as $facility) {
            $counts[$facility->getName()] = $repo->count(['facility' => $facility]);
        }
        
        return $counts;
    }

    private function topExpertise(EntityManagerInterface $em): array
    {
        $mentorProfiles = $em->getRepository(MentorProfile::class)->findAll();
        $expertiseCounts = [];

        foreach ($mentorProfiles as $profile) {
            $specialization = $profile->getSpecialization();
            if (!empty($specialization)) {
                $expertiseCounts[$specialization] = ($expertiseCounts[$specialization] ?? 0) + 1;
            }
        }

        arsort($expertiseCounts);
        return array_slice($expertiseCounts, 0, 5, true);
    }

    private function adminModules(): array
    {
        return [
            ['area' => 'Dashboard', 'priority' => 'High', 'task' => 'View real-time dashboard with analytics', 'weight' => '5%'],
            ['area' => 'Reservations', 'priority' => 'High', 'task' => 'Monitor and manage reservation requests', 'weight' => '25%'],
            ['area' => 'Mentoring', 'priority' => 'High', 'task' => 'Coordinate mentorship programs', 'weight' => '20%'],
            ['area' => 'Reports', 'priority' => 'High', 'task' => 'Generate operation reports for reservation summaries', 'weight' => '2%'],
            ['area' => 'Reports', 'priority' => 'High', 'task' => 'Generate operation reports for mentoring', 'weight' => '2%'],
        ];
    }

    private function reservationReportRows(EntityManagerInterface $em): array
    {
        $reservations = $em->getRepository(Reservation::class)->findBy([], ['createdAt' => 'DESC']);
        $rows = [['ID', 'Name', 'Email', 'Facility', 'Date', 'Time', 'Status', 'Created At']];
        
        foreach ($reservations as $r) {
            $rows[] = [
                $r->getId(),
                $r->getName(),
                $r->getEmail(),
                $r->getFacility()->getName(),
                $r->getReservationDate()->format('Y-m-d'),
                $r->getReservationStartTime()->format('H:i') . ' - ' . $r->getReservationEndTime()->format('H:i'),
                $r->getStatus(),
                $r->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        }
        
        return $rows;
    }

    private function mentoringReportRows(EntityManagerInterface $em): array
    {
        $appointments = $em->getRepository(MentoringAppointment::class)->findBy([], ['scheduledAt' => 'DESC']);
        $rows = [['ID', 'Mentor', 'Student Email', 'Scheduled At', 'Status']];
        
        foreach ($appointments as $a) {
            $rows[] = [
                $a->getId(),
                $a->getMentor()->getDisplayName(),
                $a->getStudent()->getEmail(),
                $a->getScheduledAt()->format('Y-m-d H:i:s'),
                $a->getStatus(),
            ];
        }
        
        return $rows;
    }

    #[Route('/api/mentoring-panel', name: 'admin_role_api_mentoring_panel', methods: ['GET'])]
    public function mentoringPanelApi(EntityManagerInterface $em): JsonResponse
    {
        $requests = $em->getRepository(MentorCustomRequest::class)->findBy([], ['createdAt' => 'DESC'], 10);
        $leaderboard = $em->getRepository(MentorProfile::class)->findBy([], ['engagementPoints' => 'DESC'], 5);

        $reqData = array_map(fn($r) => [
            'id'     => $r->getId(),
            'name'   => $r->getFullName() ?: ($r->getStudent() ? trim($r->getStudent()->getFirstName() . ' ' . $r->getStudent()->getLastName()) : 'Unknown'),
            'status' => $r->getStatus(),
            'topic'  => $r->getPreferredExpertise() ?: 'General',
        ], $requests);

        $lbData = array_map(fn($m) => [
            'name'   => $m->getDisplayName(),
            'points' => $m->getEngagementPoints(),
        ], $leaderboard);

        return $this->json(['requests' => $reqData, 'leaderboard' => $lbData]);
    }

    #[Route('/api/mentoring-requests-poll', name: 'admin_role_api_mentoring_poll', methods: ['GET'])]
    public function mentoringRequestsPoll(EntityManagerInterface $em): JsonResponse
    {
        $allReqs = $em->getRepository(MentorCustomRequest::class)->findBy([], ['createdAt' => 'DESC'], 50);
        $applications = $em->getRepository(\App\Entity\MentorApplication::class)->findBy([], ['createdAt' => 'DESC']);

        $mapReq = static function (MentorCustomRequest $r): array {
            $s = $r->getStudent();
            return [
                'id'                      => $r->getId(),
                'fullName'                => $r->getFullName() ?: trim(($s?->getFirstName() ?? '') . ' ' . ($s?->getLastName() ?? '')),
                'email'                   => $s?->getEmail() ?? '',
                'departmentCourse'        => $r->getDepartmentCourse() ?? '',
                'preferredExpertise'      => $r->getPreferredExpertise() ?? '',
                'availableDates'          => $r->getAvailableDates() ?? '',
                'preferredSchedule'       => $r->getPreferredSchedule() ?? '',
                'availableTime'           => $r->getAvailableTime() ?? '',
                'assignedMentorName'      => $r->getAssignedMentorName() ?? '',
                'assignedMentorExpertise' => $r->getAssignedMentorExpertise() ?? '',
                'meetingMethod'           => $r->getMeetingMethod() ?? '',
                'adminInstructions'       => $r->getAdminInstructions() ?? '',
                'message'                 => $r->getMessage() ?? '',
                'status'                  => $r->getStatus(),
                'createdAt'               => $r->getCreatedAt()->format('Y-m-d H:i:s'),
                'isAssistance'            => $r->isAssistanceRequest(),
            ];
        };

        $mapApp = static function (\App\Entity\MentorApplication $a): array {
            return [
                'id'             => $a->getId(),
                'firstName'      => $a->getFirstName() ?? '',
                'lastName'       => $a->getLastName() ?? '',
                'email'          => $a->getEmail(),
                'specialization' => $a->getSpecialization(),
                'status'         => $a->getStatus(),
                'createdAt'      => $a->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        };

        $response = $this->json([
            'requests'     => array_map($mapReq, $allReqs),
            'applications' => array_map($mapApp, $applications),
            'ts'           => time(),
        ]);
        $response->setMaxAge(0)->headers->addCacheControlDirective('no-store');
        return $response;
    }
}
