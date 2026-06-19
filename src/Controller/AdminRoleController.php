<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ClassSchedule;
use App\Entity\Facility;
use App\Entity\MentorProfile;
use App\Entity\MentoringAppointment;
use App\Entity\Reservation;
use App\Repository\FacilityRepository;
use App\Repository\MentorProfileRepository;
use App\Repository\ReservationRepository;
use App\Repository\SpecializationRepository;
use App\Repository\UserRepository;
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
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

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
        $today = new \DateTime('today');
        $nextWeek = (clone $today)->modify('+7 days');

        $upcomingReservations = $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->where('r.status = :status')
            ->andWhere('r.reservationDate >= :today')
            ->andWhere('r.reservationDate <= :nextWeek')
            ->setParameter('status', 'Approved')
            ->setParameter('today', $today)
            ->setParameter('nextWeek', $nextWeek)
            ->orderBy('r.reservationDate', 'ASC')
            ->setMaxResults(8)
            ->getQuery()
            ->getResult();

        return $this->render('admin/dashboard.html.twig', [
            'initialData' => $this->getDashboardData($em),
            'reservationStatusCounts' => $this->reservationStatusCounts($em),
            'mentoringStatusCounts' => $this->mentoringStatusCounts($em),
            'upcomingReservations' => $upcomingReservations,
            'upcomingMentoringSessions' => $em->getRepository(MentoringAppointment::class)->findBy(
                [],
                ['scheduledAt' => 'ASC'],
                8
            ),
            'modules' => $this->adminModules(),
        ]);
    }

    #[Route('/api/stats', name: 'admin_role_api_stats', methods: ['GET'])]
    public function apiStats(EntityManagerInterface $em, CacheInterface $cache): JsonResponse
    {
        return $this->cachedJsonResponse(
            $cache,
            'admin.dashboard.stats.admin.v2',
            10,
            fn() => $this->getDashboardData($em),
            'private, max-age=120',
        );
    }

    #[Route('/api/analytics', name: 'admin_role_api_analytics', methods: ['GET'])]
    public function apiAnalytics(EntityManagerInterface $em, CacheInterface $cache): JsonResponse
    {
        $data = $cache->get('admin.dashboard.analytics.admin.v2', function (ItemInterface $item) use ($em): array {
            $item->expiresAfter(60);
            $conn = $em->getConnection();
            $dates = [];
            for ($i = 29; $i >= 0; $i--) {
                $dates[] = (new \DateTime())->modify("-$i days")->format('Y-m-d');
            }
            $rangeEnd = (new \DateTimeImmutable($dates[\count($dates) - 1]))->modify('+1 day')->format('Y-m-d 00:00:00');

            $resByDate = $conn->executeQuery(
                'SELECT CAST(reservation_date AS DATE) AS dt, COUNT(*) AS cnt
                 FROM reservation
                 WHERE status IN (\'Approved\', \'Pending\')
                   AND reservation_date >= ? AND reservation_date < ?
                 GROUP BY CAST(reservation_date AS DATE)',
                [$dates[0] . ' 00:00:00', $rangeEnd]
            )->fetchAllKeyValue();

            $mentByDate = $conn->executeQuery(
                'SELECT CAST(scheduled_at AS DATE) AS dt, COUNT(*) AS cnt
                 FROM mentoring_appointment
                 WHERE scheduled_at >= ? AND scheduled_at < ?
                 GROUP BY CAST(scheduled_at AS DATE)',
                [$dates[0] . ' 00:00:00', $rangeEnd]
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

            return [
                'source' => $liveTotal > 0 ? 'live' : 'demo',
                'dataSourceLabel' => $liveTotal > 0 ? 'Live reservation data' : 'No live data - start analytics service for demo charts',
                'dailyStats' => $dailyStats,
            ];
        });

        $response = $this->json($data);
        $response->headers->set('Cache-Control', 'private, max-age=60');
        return $response;
    }

    #[Route('/api/recent-reservations', name: 'admin_role_api_recent_reservations', methods: ['GET'])]
    public function apiRecentReservations(EntityManagerInterface $em, CacheInterface $cache): JsonResponse
    {
        return $this->cachedJsonResponse(
            $cache,
            'admin.dashboard.recent_reservations.v2',
            10,
            fn() => $this->getRecentReservationsData($em),
            'private, max-age=120',
        );
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

        return $this->handleStatusResult($result, 'admin_role_reservation_monitoring');
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

        return $this->handleStatusResult($result, 'admin_role_reservation_monitoring');
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


    #[Route('/api/reservation-monitoring', name: 'admin_role_api_reservation_monitoring', methods: ['GET'])]
    public function apiReservationMonitoring(EntityManagerInterface $em): JsonResponse
    {
        $conn  = $em->getConnection();
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d H:i:s');

        $rows = $conn->fetchAllAssociative(
            'SELECT r.id, r.name, r.email, r.status,
                    r.reservation_start_time, r.reservation_end_time,
                    f.name AS facility_name,
                    u.roles AS user_roles
             FROM reservation r
             LEFT JOIN facility f ON f.id = r.facility_id
             LEFT JOIN "user" u   ON u.id = r.user_id
             WHERE r.reservation_date >= :today AND r.reservation_date < :tomorrow
             ORDER BY r.created_at DESC',
            ['today' => $today, 'tomorrow' => $tomorrow]
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
             WHERE reservation_date >= :today AND reservation_date < :tomorrow GROUP BY status',
            ['today' => $today, 'tomorrow' => $tomorrow]
        );
        $statusCounts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Cancelled' => 0];
        foreach ($statusRows as $sr) {
            $statusCounts[$sr['status']] = (int) $sr['cnt'];
        }

        $facRows = $conn->fetchAllAssociative(
            'SELECT f.name AS facility_name, COUNT(r.id) AS cnt
             FROM facility f
             LEFT JOIN reservation r ON r.facility_id = f.id AND r.reservation_date >= :today AND r.reservation_date < :tomorrow
             GROUP BY f.id, f.name',
            ['today' => $today, 'tomorrow' => $tomorrow]
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
        $req    = $em->getRepository(\App\Entity\MentorCustomRequest::class)->find($id);

        if (!$req) {
            if ($isAjax) return $this->json(['success' => false, 'message' => 'Request not found.']);
            return $this->redirectToRoute('admin_role_mentorship_coordination');
        }

        $submittedStatus = $this->resolveAssignStatus($request);
        if ($this->externalPanelNeedsEmail($request, $submittedStatus)) {
            $message = 'An email address is required for External Panel Mentors.';
            if ($isAjax) return $this->json(['success' => false, 'message' => $message]);
            $this->addFlash('error', $message);
            return $this->redirectToRoute('admin_role_mentorship_coordination');
        }

        $this->applyMentorAssignment($req, $request, $em);
        $prevStatus      = $req->getStatus();
        $req->setStatus($submittedStatus);

        $actor         = $this->getUser();
        $actorName     = $actor instanceof User
            ? (trim(($actor->getFirstName() ?? '') . ' ' . ($actor->getLastName() ?? '')) ?: $actor->getEmail())
            : 'Admin';
        $student       = $req->getStudent();
        $requesterName = $req->getFullName()
            ?: ($student ? trim(($student->getFirstName() ?? '') . ' ' . ($student->getLastName() ?? '')) : 'Unknown');
        $instructions  = trim((string) $request->request->get('admin_instructions', ''));
        $noteText      = $instructions !== '' ? $instructions : ($req->getAssignedMentorName() ? 'Assigned to: ' . $req->getAssignedMentorName() : null);

        $this->persistAssignAuditLog($em, $id, [
            'requesterName' => $requesterName,
            'prevStatus'    => $prevStatus,
            'newStatus'     => $submittedStatus,
            'actor'         => $actor,
            'actorName'     => $actorName,
            'note'          => $noteText,
        ]);
        $em->flush();

        foreach ($em->getRepository(User::class)->findAdmins() as $u) {
            if ($u === $actor) continue;
            $notifService->notifyAdminMentorRequestUpdated($u, $id, $actorName, ucfirst($submittedStatus), $requesterName);
        }

        if ($isAjax) return $this->json(['success' => true, 'message' => 'Mentor assigned successfully.']);
        $this->addFlash('success', 'Mentor assigned successfully.');
        return $this->redirectToRoute('admin_role_mentorship_coordination');
    }

    private function applyMentorAssignment(\App\Entity\MentorCustomRequest $req, Request $request, EntityManagerInterface $em): void
    {
        $mentorId         = (int) $request->request->get('mentor_id', 0);
        $mentorNameManual = trim((string) ($request->request->get('mentor_name_manual') ?: $request->request->get('mentor_name', '')));
        $specialization   = trim((string) $request->request->get('specialization', ''));

        $this->resolveMentorIdentity($req, $em, $mentorId, $mentorNameManual, $specialization);

        $timeStart   = trim((string) $request->request->get('available_time_start', ''));
        $timeEnd     = trim((string) $request->request->get('available_time_end', ''));
        $fmtTime     = static function (string $t): string {
            $dt = \DateTime::createFromFormat('H:i', $t);
            return $dt ? $dt->format('g:i A') : $t;
        };
        $availTime   = ($timeStart !== '' && $timeEnd !== '')
            ? $fmtTime($timeStart) . ' – ' . $fmtTime($timeEnd)
            : trim((string) $request->request->get('available_time', ''));
        $meetingMethod = trim((string) $request->request->get('meeting_method_override', ''))
            ?: trim((string) $request->request->get('meeting_method', ''));
        $instructions = $request->request->get('admin_instructions') ?: $request->request->get('instructions');

        $req->setMeetingMethod($meetingMethod ?: null)
            ->setAvailableDates($request->request->get('available_dates') ?: null)
            ->setAvailableTime($availTime ?: null)
            ->setAdminInstructions($instructions ?: null);
    }

    private function resolveAssignStatus(Request $request): string
    {
        $s = strtolower(trim((string) $request->request->get('status', 'approved')));
        return [
            'pending' => 'Pending',
            'reviewing' => 'Reviewing',
            'assigned' => 'Assigned',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'approved' => 'Approved',
        ][$s] ?? 'Approved';
    }

    private function externalPanelNeedsEmail(Request $request, string $submittedStatus): bool
    {
        if (!in_array(strtolower($submittedStatus), ['assigned', 'completed'], true)) {
            return false;
        }

        $mentorId = (int) $request->request->get('mentor_id', 0);
        $mentorNameManual = trim((string) ($request->request->get('mentor_name_manual') ?: $request->request->get('mentor_name', '')));
        if ($mentorId > 0 || $mentorNameManual === '') {
            return false;
        }

        $instructions = trim((string) ($request->request->get('admin_instructions') ?: $request->request->get('instructions', '')));
        return preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $instructions) !== 1;
    }

    private function persistAssignAuditLog(
        EntityManagerInterface $em,
        int $id,
        array $ctx,
    ): void {
        /** @var MentoringAuditLogRepository $auditRepo */
        $auditRepo = $em->getRepository(MentoringAuditLog::class);
        $actor = $ctx['actor'];
        if ($auditRepo->existsRecent('custom_request', $id, 'update_status', $ctx['newStatus'], $actor instanceof User ? $actor->getId() : null)) {
            return;
        }
        $em->persist((new MentoringAuditLog())
            ->setSubjectType('custom_request')
            ->setSubjectId($id)
            ->setSubjectLabel($ctx['requesterName'])
            ->setAction('update_status')
            ->setPreviousStatus($ctx['prevStatus'])
            ->setNewStatus($ctx['newStatus'])
            ->setPerformedBy($actor instanceof User ? $actor : null)
            ->setPerformedByName($ctx['actorName'])
            ->setPerformedByRole('Admin')
            ->setNote($ctx['note']));
    }

    #[Route('/mentorship-coordination', name: 'admin_role_mentorship_coordination', methods: ['GET'])]
    public function mentorshipCoordination(EntityManagerInterface $em, SpecializationRepository $specializationRepository, UserRepository $userRepository, MentorProfileRepository $mentorProfileRepository): Response
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
            'mentors' => $mentorProfileRepository->findActiveOrderedByDisplayName(),
            'leaderboard' => $mentorProfileRepository->findActiveLeaderboard(10),
            'users' => $userRepository->findEligibleMentorCreationUsers(),
            'statusCounts' => $this->mentoringStatusCounts($em),
            'topExpertise' => $this->topExpertise($em),
            'is_super_admin' => false,
            'auditLogs' => [],
            'allSpecializations' => $specializations,
        ]);
    }

    #[Route('/mentors', name: 'admin_role_mentors_list', methods: ['GET'])]
    public function adminMentorsList(MentorProfileRepository $mentorProfileRepository): Response
    {
        // Redirect Super Admin to the full-featured Super Admin mentors list
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('mentoring_mentors_list');
        }

        return $this->render('admin/admin_mentors_list.html.twig', [
            'mentors' => $mentorProfileRepository->findActiveOrderedByDisplayName(),
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

    #[Route('/reports/download-template', name: 'admin_role_reports_template', methods: ['GET'])]
    public function downloadTemplate(): Response
    {
        $lines = [
            "# FTIC Reservation Data Import Template",
            "# Fill in your historical reservation data below and upload to seed the analytics system.",
            "# Required fields: name, email, facility_name, reservation_date (YYYY-MM-DD), start_time (HH:MM), end_time (HH:MM), purpose, status",
            "# Valid statuses: Pending, Approved, Rejected, Cancelled",
            "# Valid purposes: Academic, Research, Organization, Meeting, Training, Conference, Workshop, Other",
            "name,email,facility_name,reservation_date,start_time,end_time,event_name,purpose,capacity,status",
            "\"Juan dela Cruz\",\"juan@ftic.edu\",\"Function Hall\",\"2025-01-10\",\"08:00\",\"10:00\",\"Math Review Session\",\"Academic\",30,\"Approved\"",
            "\"Maria Santos\",\"maria@ftic.edu\",\"Computer Lab\",\"2025-01-11\",\"13:00\",\"15:00\",\"Research Workshop\",\"Research\",20,\"Approved\"",
            "\"Pedro Reyes\",\"pedro@ftic.edu\",\"Conference Room\",\"2025-01-12\",\"09:00\",\"11:00\",\"Club Meeting\",\"Organization\",15,\"Approved\"",
        ];
        $content = implode("\n", $lines) . "\n";

        $response = new Response($content);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'ftic_reservation_import_template.csv'
        );
        $response->headers->set('Content-Disposition', $disposition);
        return $response;
    }

    #[Route('/reports/download-extended/{type}', name: 'admin_role_reports_extended', methods: ['GET'])]
    public function downloadExtendedReport(string $type, EntityManagerInterface $em): Response
    {
        [$rows, $filename] = match ($type) {
            'facility-utilization' => [$this->facilityUtilizationRows($em), 'facility_utilization_report.csv'],
            'pending'              => [$this->filteredReservationRows($em, 'Pending'), 'pending_reservations.csv'],
            'rejected'             => [$this->filteredReservationRows($em, 'Rejected'), 'rejected_reservations.csv'],
            'cancellations'        => [$this->filteredReservationRows($em, 'Cancelled'), 'cancelled_reservations.csv'],
            'monthly-summary'      => [$this->monthlySummaryRows($em), 'monthly_reservation_summary.csv'],
            'top-events'           => [$this->topEventTypeRows($em), 'top_event_types.csv'],
            'user-activity'        => [$this->userActivityRows($em), 'user_activity_report.csv'],
            default => throw $this->createNotFoundException('Invalid report type'),
        };

        $response = new StreamedResponse();
        $response->setCallback(function () use ($rows) {
            $fp = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($fp, $row);
            }
            fclose($fp);
        });
        $disposition = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        return $response;
    }

    private function facilityUtilizationRows(EntityManagerInterface $em): array
    {
        $rows = $em->getConnection()->fetchAllAssociative(
            "SELECT f.name AS facility,
                    COUNT(r.id) AS total_reservations,
                    SUM(CASE WHEN r.status='Approved' THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN r.status='Rejected' THEN 1 ELSE 0 END) AS rejected,
                    SUM(CASE WHEN r.status='Cancelled' THEN 1 ELSE 0 END) AS cancelled,
                    SUM(CASE WHEN r.status='Pending' THEN 1 ELSE 0 END) AS pending,
                    ROUND(100.0 * SUM(CASE WHEN r.status='Approved' THEN 1 ELSE 0 END) / NULLIF(COUNT(r.id),0), 1) AS approval_rate_pct
             FROM facility f
             LEFT JOIN reservation r ON r.facility_id = f.id
             GROUP BY f.id, f.name
             ORDER BY total_reservations DESC"
        );
        $result = [['Facility', 'Total Reservations', 'Approved', 'Rejected', 'Cancelled', 'Pending', 'Approval Rate (%)']];
        foreach ($rows as $r) {
            $result[] = [$r['facility'], $r['total_reservations'], $r['approved'], $r['rejected'], $r['cancelled'], $r['pending'], $r['approval_rate_pct'] ?? 0];
        }
        return $result;
    }

    private function filteredReservationRows(EntityManagerInterface $em, string $status): array
    {
        $reservations = $em->getRepository(Reservation::class)->findBy(['status' => $status], ['reservationDate' => 'DESC']);
        $rows = [['ID', 'Name', 'Email', 'Facility', 'Date', 'Start Time', 'End Time', 'Event Name', 'Purpose', 'Capacity', 'Status', 'Created At']];
        foreach ($reservations as $r) {
            $rows[] = [
                $r->getId(),
                $r->getName(),
                $r->getEmail(),
                $r->getFacility()?->getName() ?? '',
                $r->getReservationDate()?->format('Y-m-d') ?? '',
                $r->getReservationStartTime()?->format('H:i') ?? '',
                $r->getReservationEndTime()?->format('H:i') ?? '',
                $r->getEventName() ?? '',
                $r->getPurpose() ?? '',
                $r->getCapacity() ?? '',
                $r->getStatus(),
                $r->getCreatedAt()?->format('Y-m-d H:i:s') ?? '',
            ];
        }
        return $rows;
    }

    private function monthlySummaryRows(EntityManagerInterface $em): array
    {
        $data = $em->getConnection()->fetchAllAssociative(
            "SELECT TO_CHAR(r.reservation_date, 'YYYY-MM') AS month,
                    f.name AS facility,
                    COUNT(r.id) AS total,
                    SUM(CASE WHEN r.status='Approved' THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN r.status='Rejected' THEN 1 ELSE 0 END) AS rejected,
                    SUM(CASE WHEN r.status='Cancelled' THEN 1 ELSE 0 END) AS cancelled
             FROM reservation r
             INNER JOIN facility f ON r.facility_id = f.id
             GROUP BY TO_CHAR(r.reservation_date, 'YYYY-MM'), f.id, f.name
             ORDER BY month DESC, total DESC"
        );
        $rows = [['Month', 'Facility', 'Total', 'Approved', 'Rejected', 'Cancelled']];
        foreach ($data as $d) {
            $rows[] = [$d['month'], $d['facility'], $d['total'], $d['approved'], $d['rejected'], $d['cancelled']];
        }
        return $rows;
    }

    private function topEventTypeRows(EntityManagerInterface $em): array
    {
        $data = $em->getConnection()->fetchAllAssociative(
            "SELECT r.purpose AS event_type,
                    COUNT(*) AS total,
                    SUM(CASE WHEN r.status='Approved' THEN 1 ELSE 0 END) AS approved,
                    ROUND(100.0 * SUM(CASE WHEN r.status='Approved' THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0), 1) AS approval_rate_pct
             FROM reservation r
             WHERE r.purpose IS NOT NULL
             GROUP BY r.purpose
             ORDER BY total DESC"
        );
        $rows = [['Event Type / Purpose', 'Total Reservations', 'Approved', 'Approval Rate (%)']];
        foreach ($data as $d) {
            $rows[] = [$d['event_type'], $d['total'], $d['approved'], $d['approval_rate_pct']];
        }
        return $rows;
    }

    private function userActivityRows(EntityManagerInterface $em): array
    {
        $data = $em->getConnection()->fetchAllAssociative(
            "SELECT r.name AS user_name,
                    r.email,
                    COUNT(*) AS total_reservations,
                    SUM(CASE WHEN r.status='Approved' THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN r.status='Rejected' THEN 1 ELSE 0 END) AS rejected,
                    SUM(CASE WHEN r.status='Cancelled' THEN 1 ELSE 0 END) AS cancelled,
                    MAX(r.reservation_date) AS last_booking_date
             FROM reservation r
             GROUP BY r.name, r.email
             ORDER BY total_reservations DESC
             LIMIT 100"
        );
        $rows = [['Name', 'Email', 'Total Reservations', 'Approved', 'Rejected', 'Cancelled', 'Last Booking Date']];
        foreach ($data as $d) {
            $rows[] = [$d['user_name'], $d['email'], $d['total_reservations'], $d['approved'], $d['rejected'], $d['cancelled'], $d['last_booking_date']];
        }
        return $rows;
    }

    private function getDashboardData(EntityManagerInterface $em): array
    {
        $conn = $em->getConnection();
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d H:i:s');

        $batch = $conn->executeQuery(
            "SELECT 'res_status' AS grp, status AS lbl, COUNT(*) AS cnt FROM reservation WHERE status != 'Suggested' GROUP BY status
             UNION ALL
             SELECT 'apt_status', status, COUNT(*) FROM mentoring_appointment GROUP BY status
             UNION ALL
             SELECT 'req_status', status, COUNT(*) FROM mentor_custom_request GROUP BY status
             UNION ALL
             SELECT 'meta', 'mentors', COUNT(*) FROM mentor_profile
             UNION ALL
             SELECT 'meta', 'today_res', COUNT(*) FROM reservation WHERE reservation_date >= ? AND reservation_date < ? AND status != 'Suggested'
             UNION ALL
             SELECT 'meta', 'upcoming_apt', COUNT(*) FROM mentoring_appointment WHERE scheduled_at >= ?",
            [$today, $tomorrow, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]
        )->fetchAllAssociative();

        $resCounts = [];
        $aptCounts = [];
        $mentors = 0;
        $todayReservations = 0;
        $upcomingAppointments = 0;

        foreach ($batch as $row) {
            $count = (int) $row['cnt'];
            if ($row['grp'] === 'res_status') {
                $resCounts[$row['lbl']] = $count;
                continue;
            }
            if ($row['grp'] === 'apt_status') {
                $aptCounts[$row['lbl']] = $count;
                continue;
            }
            if ($row['grp'] === 'meta') {
                match ($row['lbl']) {
                    'mentors' => $mentors = $count,
                    'today_res' => $todayReservations = $count,
                    'upcoming_apt' => $upcomingAppointments = $count,
                    default => null,
                };
            }
        }

        return [
            'reservations' => [
                'total' => (int) array_sum($resCounts),
                'approved' => (int) ($resCounts['Approved'] ?? 0),
                'pending' => (int) ($resCounts['Pending'] ?? 0),
                'rejected' => (int) ($resCounts['Rejected'] ?? 0),
                'today' => $todayReservations,
            ],
            'mentoring' => [
                'totalMentors' => $mentors,
                'appointments' => [
                    'total' => (int) array_sum($aptCounts),
                    'upcoming' => $upcomingAppointments,
                ],
            ],
        ];
    }

    private function getRecentReservationsData(EntityManagerInterface $em): array
    {
        $rows = $em->getConnection()->executeQuery(
            "SELECT r.name AS \"userName\", f.name AS \"facilityName\",
                    r.reservation_date AS \"date\",
                    r.reservation_start_time AS \"time\",
                    r.reservation_end_time AS \"endTime\",
                    r.event_name AS \"eventName\",
                    r.email, r.contact, r.capacity, r.purpose, r.status
             FROM reservation r
             INNER JOIN facility f ON r.facility_id = f.id
             WHERE r.status = 'Pending'
             ORDER BY r.created_at DESC LIMIT 8"
        )->fetchAllAssociative();

        return [
            'recentReservations' => array_map(static function ($r) {
                return [
                    'facilityName' => $r['facilityName'] ?? ($r['facilityname'] ?? 'Unknown'),
                    'userName'     => $r['userName'] ?? ($r['username'] ?? ''),
                    'date'         => $r['date'] ? date('M j, Y', strtotime($r['date'])) : '',
                    'time'         => $r['time'] ? date('g:i A', strtotime($r['time'])) : '',
                    'endTime'      => !empty($r['endTime']) ? date('g:i A', strtotime($r['endTime'])) : '',
                    'eventName'    => $r['eventName'] ?? ($r['eventname'] ?? ''),
                    'email'        => $r['email'] ?? '',
                    'contact'      => $r['contact'] ?? '',
                    'capacity'     => $r['capacity'] ?? '',
                    'purpose'      => $r['purpose'] ?? '',
                    'status'       => $r['status'] ?? '',
                ];
            }, $rows),
            'ts' => time(),
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
        $today    = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d H:i:s');
        $rows     = $em->getConnection()->fetchAllAssociative(
            'SELECT status, COUNT(*) AS cnt FROM reservation
             WHERE reservation_date >= ? AND reservation_date < ?
               AND status IN (\'Pending\',\'Approved\',\'Rejected\',\'Cancelled\')
             GROUP BY status',
            [$today, $tomorrow]
        );
        $counts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Cancelled' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
        }
        return $counts;
    }

    private function facilityReservationCountsToday(EntityManagerInterface $em): array
    {
        $today    = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d H:i:s');
        $rows     = $em->getConnection()->fetchAllAssociative(
            'SELECT f.name AS facility_name, COUNT(r.id) AS cnt
             FROM facility f
             LEFT JOIN reservation r ON r.facility_id = f.id
               AND r.reservation_date >= ? AND r.reservation_date < ?
             GROUP BY f.id, f.name
             ORDER BY f.name',
            [$today, $tomorrow]
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['facility_name']] = (int) $row['cnt'];
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
        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT f.name AS facility_name, COUNT(r.id) AS cnt
             FROM facility f
             LEFT JOIN reservation r ON r.facility_id = f.id
             GROUP BY f.id, f.name
             ORDER BY f.name'
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['facility_name']] = (int) $row['cnt'];
        }
        return $counts;
    }

    private function topExpertise(EntityManagerInterface $em): array
    {
        $counts = [];
        $mentorRepo = $em->getRepository(MentorProfile::class);
        if ($mentorRepo instanceof MentorProfileRepository) {
            foreach ($mentorRepo->findActiveOrderedByDisplayName() as $mentorProfile) {
                $specialization = $mentorProfile->getSpecialization();
                if ($specialization === '') {
                    continue;
                }
                $counts[$specialization] = ($counts[$specialization] ?? 0) + 1;
            }
        }
        arsort($counts);
        return array_slice($counts, 0, 5, true);
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
                'externalMentorEmail'     => $r->getExternalMentorEmail() ?? '',
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
    private function handleStatusResult(array|JsonResponse $result, string $redirectRoute): Response
    {
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $this->addFlash($result['success'] ? 'success' : 'error', $result['message']);
        return $this->redirectToRoute($redirectRoute);
    }

    private function resolveMentorIdentity(
        \App\Entity\MentorCustomRequest $req,
        EntityManagerInterface $em,
        int $mentorId,
        string $mentorNameManual,
        string $specialization,
    ): void {
        if ($mentorId > 0) {
            $mentor = $em->getRepository(MentorProfile::class)->find($mentorId);
            if ($mentor) {
                $req->setAssignedMentorName($mentorNameManual ?: $mentor->getDisplayName());
                $req->setAssignedMentorExpertise($specialization ?: $mentor->getSpecialization());
            }
        } else {
            if ($mentorNameManual !== '') $req->setAssignedMentorName($mentorNameManual);
            if ($specialization   !== '') $req->setAssignedMentorExpertise($specialization);
        }
    }

    private function cachedJsonResponse(
        \Symfony\Contracts\Cache\CacheInterface $cache,
        string $key,
        int $ttl,
        callable $builder,
        string $cacheControl,
    ): JsonResponse {
        $data = $cache->get($key, function (\Symfony\Contracts\Cache\ItemInterface $item) use ($ttl, $builder): array {
            $item->expiresAfter($ttl);
            return $builder();
        });
        $response = $this->json($data);
        $response->headers->set('Cache-Control', $cacheControl);
        return $response;
    }

}
