<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Facility;
use App\Entity\MentorApplication;
use App\Entity\MentorCustomRequest;
use App\Entity\MentorProfile;
use App\Entity\MentoringAppointment;
use App\Entity\MentoringAuditLog;
use App\Repository\MentoringAuditLogRepository;
use App\Entity\Reservation;
use App\Entity\User;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/superadmin')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class AdminController extends AbstractController
{
    private array $connectedClients = [];

    #[Route('', name: 'admin_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('admin_home');
    }

    #[Route('/dashboard', name: 'admin_home', methods: ['GET'])]
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

    #[Route('/api/stats', name: 'admin_api_stats', methods: ['GET'])]
    public function apiStats(EntityManagerInterface $em, CacheInterface $cache): JsonResponse
    {
        return $this->cachedJsonResponse(
            $cache,
            'admin.dashboard.stats.superadmin.v2',
            120,
            fn() => $this->getDashboardData($em),
            'private, max-age=120',
        );
    }

    #[Route('/api/recent-reservations', name: 'admin_api_recent_reservations', methods: ['GET'])]
    public function apiRecentReservations(EntityManagerInterface $em, CacheInterface $cache): JsonResponse
    {
        return $this->cachedJsonResponse(
            $cache,
            'admin.dashboard.recent_reservations.v2',
            120,
            fn() => $this->getRecentReservationsData($em),
            'private, max-age=120',
        );
    }

    #[Route('/api/reservations', name: 'admin_api_reservations', methods: ['GET'])]
    public function apiReservations(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(100, (int) $request->query->get('limit', 20)));
        $status = $request->query->get('status');
        $search = $request->query->get('search');
        $sortBy = $request->query->get('sortBy', 'createdAt');
        $sortOrder = strtoupper($request->query->get('sortOrder', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $qb = $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->leftJoin('r.facility', 'f')
            ->addSelect('f');

        if ($status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        if ($search) {
            $qb->andWhere('r.name LIKE :search OR r.email LIKE :search OR f.name LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $qb->orderBy('r.' . $sortBy, $sortOrder);

        $total = (int) (clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        $reservations = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return $this->json([
            'data' => $reservations,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    #[Route('/api/mentoring', name: 'admin_api_mentoring', methods: ['GET'])]
    public function apiMentoring(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(100, (int) $request->query->get('limit', 20)));
        $type = $request->query->get('type', 'appointments');
        $status = $request->query->get('status');

        $result = match ($type) {
            'appointments' => $this->getMentoringAppointments($em, $page, $limit, $status),
            'requests' => $this->getMentoringRequests($em, $page, $limit, $status),
            'applications' => $this->getMentorApplications($em, $page, $limit, $status),
            default => ['data' => [], 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => 0, 'pages' => 0]],
        };

        return $this->json($result);
    }

    #[Route('/api/facilities', name: 'admin_api_facilities', methods: ['GET'])]
    public function apiFacilities(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(100, (int) $request->query->get('limit', 20)));
        $search = $request->query->get('search');

        $qb = $em->getRepository(Facility::class)->createQueryBuilder('f')
            ->leftJoin('f.images', 'fi')
            ->addSelect('fi');

        if ($search) {
            $qb->andWhere('f.name LIKE :search OR f.description LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $total = (int) (clone $qb)->select('COUNT(f.id)')->getQuery()->getSingleScalarResult();

        $facilities = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $todayDate = (new \DateTime())->format('Y-m-d');
        $facIds    = array_column($facilities, 'id');
        $todayCounts = [];
        if (!empty($facIds)) {
            $rows = $em->getConnection()->fetchAllAssociative(
                'SELECT facility_id, COUNT(*) AS cnt FROM reservation
                 WHERE facility_id IN (' . implode(',', array_map('intval', $facIds)) . ')
                   AND CAST(reservation_date AS DATE) = ?
                   AND status IN (\'Approved\',\'Pending\')
                 GROUP BY facility_id',
                [$todayDate]
            );
            foreach ($rows as $row) { $todayCounts[(int)$row['facility_id']] = (int)$row['cnt']; }
        }
        foreach ($facilities as &$facility) {
            $facility['todayReservations'] = $todayCounts[$facility['id']] ?? 0;
        }

        return $this->json([
            'data' => $facilities,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    #[Route('/api/analytics', name: 'admin_api_analytics', methods: ['GET'])]
    public function apiAnalytics(EntityManagerInterface $em, CacheInterface $cache): JsonResponse
    {
        $data = $cache->get('admin.dashboard.analytics.superadmin.v2', function (ItemInterface $item) use ($em): array {
            $item->expiresAfter(60);
            $conn = $em->getConnection();
            $today = new \DateTime();
            $dates = [];
            for ($i = 29; $i >= 0; $i--) {
                $dates[] = (clone $today)->modify("-$i days")->format('Y-m-d');
            }

            $rangeEnd = (new \DateTimeImmutable($dates[count($dates)-1]))->modify('+1 day')->format('Y-m-d 00:00:00');

            $resByDate = $conn->executeQuery(
                "SELECT CAST(reservation_date AS DATE) as dt, COUNT(*) as cnt 
                 FROM reservation 
                 WHERE reservation_date >= ? AND reservation_date < ?
                 GROUP BY CAST(reservation_date AS DATE)",
                [$dates[0] . ' 00:00:00', $rangeEnd]
            )->fetchAllKeyValue();

            $mentByDate = $conn->executeQuery(
                "SELECT CAST(scheduled_at AS DATE) as dt, COUNT(*) as cnt 
                 FROM mentoring_appointment 
                 WHERE scheduled_at >= ? AND scheduled_at < ?
                 GROUP BY CAST(scheduled_at AS DATE)",
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

            return [
                'dailyStats' => $dailyStats,
                'reservationTrends' => $this->getReservationTrends($em),
                'mentoringTrends' => $this->getMentoringTrends($em),
            ];
        });

        $response = $this->json($data);
        $response->headers->set('Cache-Control', 'private, max-age=60');
        return $response;
    }

    #[Route('/sse/events', name: 'admin_sse_events', methods: ['GET'])]
    public function sseEvents(EntityManagerInterface $em): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($em) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');

            $lastCheck = new \DateTime();
            $clientId = uniqid('dashboard_', true);
            $this->connectedClients[$clientId] = time();

            while (true) {
                if (connection_aborted()) {
                    unset($this->connectedClients[$clientId]);
                    break;
                }

                $changes = $this->checkForChanges($em, $lastCheck);
                
                if (!empty($changes)) {
                    echo "event: update\n";
                    echo "data: " . json_encode($changes) . "\n\n";
                    ob_flush();
                    flush();
                }

                if ((time() - $this->connectedClients[$clientId]) % 30 === 0) {
                    echo "event: heartbeat\n";
                    echo "data: " . json_encode(['timestamp' => time()]) . "\n\n";
                    ob_flush();
                    flush();
                }

                $lastCheck = new \DateTime();
                sleep(2);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    #[Route('/reservation-monitoring', name: 'admin_reservation_monitoring', methods: ['GET'])]
    public function reservationMonitoring(
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('admin_role_reservation_monitoring');
        }
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
        $todayStr    = $today->format('Y-m-d H:i:s');
        $tomorrowStr = $tomorrow->format('Y-m-d H:i:s');
        $conn        = $em->getConnection();

        $statusRows  = $conn->fetchAllAssociative(
            'SELECT status, COUNT(*) AS cnt FROM reservation
             WHERE reservation_date >= ? AND reservation_date < ?
               AND status IN (\'Pending\',\'Approved\',\'Rejected\',\'Cancelled\')
             GROUP BY status',
            [$todayStr, $tomorrowStr]
        );
        $statusCounts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Cancelled' => 0];
        foreach ($statusRows as $row) { $statusCounts[$row['status']] = (int) $row['cnt']; }

        $facRows = $conn->fetchAllAssociative(
            'SELECT f.name AS facility_name, COUNT(r.id) AS cnt
             FROM facility f
             LEFT JOIN reservation r ON r.facility_id = f.id
               AND r.reservation_date >= ? AND r.reservation_date < ?
             GROUP BY f.id, f.name ORDER BY f.name',
            [$todayStr, $tomorrowStr]
        );
        $facilityCounts = [];
        foreach ($facRows as $row) { $facilityCounts[$row['facility_name']] = (int) $row['cnt']; }
        return $this->render('admin/reservation_monitoring.html.twig', [
            'reservations' => $todayReservations,
            'statusCounts' => $statusCounts,
            'facilityCounts' => $facilityCounts,
        ]);
    }

    #[Route('/api/reservation-monitoring', name: 'admin_api_reservation_monitoring', methods: ['GET'])]
    public function apiReservationMonitoring(EntityManagerInterface $em): JsonResponse
    {
        $conn  = $em->getConnection();
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d H:i:s');

        // All today's reservations with facility name + user roles in one query
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
            $roles    = json_decode($r['user_roles'] ?? '[]', true) ?? [];
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

        // Status counts — one query
        $statusRows = $conn->fetchAllAssociative(
            'SELECT status, COUNT(*) AS cnt FROM reservation
             WHERE reservation_date >= :today AND reservation_date < :tomorrow GROUP BY status',
            ['today' => $today, 'tomorrow' => $tomorrow]
        );
        $statusCounts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Cancelled' => 0];
        foreach ($statusRows as $sr) {
            $statusCounts[$sr['status']] = (int) $sr['cnt'];
        }

        // Facility counts — one query
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
            'reservations'  => $reservations,
            'statusCounts'  => $statusCounts,
            'facilityCounts'=> $facilityCounts,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[Route('/mentorship-coordination/{id}/assign', name: 'admin_mentorship_assign', methods: ['POST'])]
    public function mentorshipAssign(int $id, Request $request, EntityManagerInterface $em, NotificationService $notifService): Response
    {
        if (!$this->isCsrfTokenValid('mentorship_assign_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
        $req    = $em->getRepository(MentorCustomRequest::class)->find($id);

        if (!$req) {
            if ($isAjax) return $this->json(['success' => false, 'message' => 'Request not found.']);
            return $this->redirectToRoute('admin_mentorship_coordination');
        }

        $submittedStatus = $this->resolveAssignStatus($request);
        if ($this->externalPanelNeedsEmail($request, $submittedStatus)) {
            $message = 'An email address is required for External Panel Mentors.';
            if ($isAjax) return $this->json(['success' => false, 'message' => $message]);
            $this->addFlash('error', $message);
            return $this->redirectToRoute('admin_mentorship_coordination');
        }

        $this->applyMentorAssignment($req, $request, $em);
        $prevStatus      = $req->getStatus();
        $req->setStatus($submittedStatus);

        $actor         = $this->getUser();
        $actorName     = $actor instanceof User
            ? (trim(($actor->getFirstName() ?? '') . ' ' . ($actor->getLastName() ?? '')) ?: $actor->getEmail())
            : 'Super Admin';
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
            'isSuperAdmin'  => $this->isGranted('ROLE_SUPER_ADMIN'),
        ]);
        $em->flush();

        foreach ($em->getRepository(User::class)->findAdmins() as $u) {
            if ($u === $actor) continue;
            $notifService->notifyAdminMentorRequestUpdated($u, $id, $actorName, ucfirst($submittedStatus), $requesterName);
        }

        if ($isAjax) return $this->json(['success' => true, 'message' => 'Mentor assigned successfully.']);
        $this->addFlash('success', 'Mentor assigned successfully.');
        return $this->redirectToRoute('admin_mentorship_coordination');
    }

    private function applyMentorAssignment(MentorCustomRequest $req, Request $request, EntityManagerInterface $em): void
    {
        $mentorId         = (int) $request->request->get('mentor_id', 0);
        $mentorNameManual = trim((string) ($request->request->get('mentor_name_manual') ?: $request->request->get('mentor_name', '')));
        $specialization   = trim((string) $request->request->get('specialization', ''));

        $this->resolveMentorIdentity($req, $em, $mentorId, $mentorNameManual, $specialization);

        $timeStart     = trim((string) $request->request->get('available_time_start', ''));
        $timeEnd       = trim((string) $request->request->get('available_time_end', ''));
        $fmtTime       = static function (string $t): string {
            $dt = \DateTime::createFromFormat('H:i', $t);
            return $dt ? $dt->format('g:i A') : $t;
        };
        $availTime     = ($timeStart !== '' && $timeEnd !== '')
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
            ->setPerformedByRole(($ctx['isSuperAdmin'] ?? false) ? 'Super Admin' : 'Admin')
            ->setNote($ctx['note']));
    }

    #[Route('/mentorship-coordination', name: 'admin_mentorship_coordination', methods: ['GET'])]
    public function mentorshipCoordination(EntityManagerInterface $em): Response
    {
        // Redirect Super Admin to the full-featured mentoring interface
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('mentoring_superadmin_requests');
        }
        // Regular Admins are redirected to the Admin role controller
        return $this->redirectToRoute('admin_role_mentorship_coordination');
    }

    #[Route('/reports', name: 'admin_reports', methods: ['GET'])]
    public function reports(EntityManagerInterface $em): Response
    {
        if (!$this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('admin_role_reports');
        }
        return $this->render('admin/reports.html.twig', [
            'reservationStatusCounts' => $this->reservationStatusCounts($em),
            'facilityCounts' => $this->facilityReservationCounts($em),
            'mentoringStatusCounts' => $this->mentoringStatusCounts($em),
            'topExpertise' => $this->topExpertise($em),
            'modules' => $this->adminModules(),
        ]);
    }

    #[Route('/reports/download/{type}', name: 'admin_reports_download', methods: ['GET'])]
    public function downloadReport(string $type, EntityManagerInterface $em): Response
    {
        $rows = match ($type) {
            'mentoring' => $this->mentoringReportRows($em),
            default => $this->reservationReportRows($em),
        };

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to create report file.');
        }

        if ($rows !== []) {
            fputcsv($handle, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        $response = new Response($content ?: '');
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('admin-%s-report.csv', $type === 'mentoring' ? 'mentoring' : 'reservation')
        );
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    private function reservationStatusCounts(EntityManagerInterface $em): array
    {
        return $this->keyValueCounts(
            $em->createQueryBuilder()
                ->select('r.status AS label, COUNT(r.id) AS total')
                ->from(Reservation::class, 'r')
                ->groupBy('r.status')
                ->orderBy('total', 'DESC')
                ->getQuery()
                ->getArrayResult()
        );
    }

    private function facilityReservationCounts(EntityManagerInterface $em): array
    {
        return $this->keyValueCounts(
            $em->createQueryBuilder()
                ->select('f.name AS label, COUNT(r.id) AS total')
                ->from(Facility::class, 'f')
                ->leftJoin(Reservation::class, 'r', 'WITH', 'r.facility = f')
                ->groupBy('f.id')
                ->orderBy('total', 'DESC')
                ->getQuery()
                ->getArrayResult()
        );
    }

    private function mentoringStatusCounts(EntityManagerInterface $em): array
    {
        $appointmentCounts = $this->keyValueCounts(
            $em->createQueryBuilder()
                ->select('a.status AS label, COUNT(a.id) AS total')
                ->from(MentoringAppointment::class, 'a')
                ->groupBy('a.status')
                ->getQuery()
                ->getArrayResult()
        );

        $requestCounts = $this->keyValueCounts(
            $em->createQueryBuilder()
                ->select('r.status AS label, COUNT(r.id) AS total')
                ->from(MentorCustomRequest::class, 'r')
                ->groupBy('r.status')
                ->getQuery()
                ->getArrayResult()
        );

        return [
            'appointments' => $appointmentCounts,
            'requests' => $requestCounts,
        ];
    }

    private function topExpertise(EntityManagerInterface $em): array
    {
        $requestExpertise = $this->keyValueCounts(
            $em->createQueryBuilder()
                ->select('r.preferredExpertise AS label, COUNT(r.id) AS total')
                ->from(MentorCustomRequest::class, 'r')
                ->where('r.preferredExpertise IS NOT NULL')
                ->groupBy('r.preferredExpertise')
                ->orderBy('total', 'DESC')
                ->getQuery()
                ->getArrayResult()
        );

        $mentorExpertise = $this->keyValueCounts(
            $em->createQueryBuilder()
                ->select('m.specialization AS label, COUNT(m.id) AS total')
                ->from(MentorProfile::class, 'm')
                ->groupBy('m.specialization')
                ->orderBy('total', 'DESC')
                ->getQuery()
                ->getArrayResult()
        );

        foreach ($mentorExpertise as $label => $total) {
            $requestExpertise[$label] = ($requestExpertise[$label] ?? 0) + $total;
        }

        arsort($requestExpertise);

        return array_slice($requestExpertise, 0, 10, true);
    }

    private function reservationReportRows(EntityManagerInterface $em): array
    {
        $rows = [];
        foreach ($em->getRepository(Reservation::class)->findBy([], ['reservationDate' => 'DESC']) as $reservation) {
            $rows[] = [
                'id' => $reservation->getId(),
                'facility' => $reservation->getFacility()?->getName(),
                'requester' => $reservation->getName(),
                'email' => $reservation->getEmail(),
                'date' => $reservation->getReservationDate()?->format('Y-m-d'),
                'start_time' => $reservation->getReservationStartTime()?->format('H:i'),
                'end_time' => $reservation->getReservationEndTime()?->format('H:i'),
                'capacity' => $reservation->getCapacity(),
                'status' => $reservation->getStatus(),
                'purpose' => $reservation->getPurpose(),
            ];
        }

        return $rows;
    }

    private function mentoringReportRows(EntityManagerInterface $em): array
    {
        $rows = [];
        foreach ($em->getRepository(MentoringAppointment::class)->findBy([], ['scheduledAt' => 'DESC']) as $appointment) {
            $rows[] = [
                'id' => $appointment->getId(),
                'student' => $appointment->getStudent()?->getEmail(),
                'mentor' => $appointment->getMentor()?->getDisplayName(),
                'specialization' => $appointment->getMentor()?->getSpecialization(),
                'scheduled_at' => $appointment->getScheduledAt()?->format('Y-m-d H:i'),
                'status' => $appointment->getStatus(),
                'topic' => $appointment->getTopic(),
            ];
        }

        return $rows;
    }

    private function keyValueCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                $label = 'Unspecified';
            }
            $counts[$label] = (int) $row['total'];
        }

        return $counts;
    }

    private function getRecentReservationsData(EntityManagerInterface $em): array
    {
        $conn = $em->getConnection();
        $rows = $conn->executeQuery(
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
                    'date'         => $r['date']  ? (new \DateTime($r['date']))->format('M j, Y') : '',
                    'time'         => $r['time']  ? date('g:i A', strtotime($r['time'])) : '',
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

    private function getDashboardData(EntityManagerInterface $em): array
    {
        $conn = $em->getConnection();
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d H:i:s');

        // Single batch query: counts across all four tables in one round-trip
        $batch = $conn->executeQuery(
            "SELECT 'res_status'  AS grp, status AS lbl, COUNT(*) AS cnt FROM reservation WHERE status != 'Suggested' GROUP BY status
             UNION ALL
             SELECT 'apt_status', status, COUNT(*) FROM mentoring_appointment GROUP BY status
             UNION ALL
             SELECT 'req_status', status, COUNT(*) FROM mentor_custom_request GROUP BY status
             UNION ALL
             SELECT 'meta', 'mentors',    COUNT(*) FROM mentor_profile
             UNION ALL
             SELECT 'meta', 'facilities', COUNT(*) FROM facility
             UNION ALL
             SELECT 'meta', 'users',      COUNT(*) FROM \"user\"
             UNION ALL
             SELECT 'meta', 'today_res',  COUNT(*) FROM reservation WHERE reservation_date >= ? AND reservation_date < ? AND status != 'Suggested'
             UNION ALL
             SELECT 'meta', 'active_res', COUNT(*) FROM reservation WHERE reservation_date >= ? AND status = 'Approved'",
            [$today, $tomorrow, $today]
        )->fetchAllAssociative();

        return $this->parseDashboardBatch($batch);
    }

    private function getMentoringAppointments(EntityManagerInterface $em, int $page, int $limit, ?string $status): array
    {
        $qb = $em->getRepository(MentoringAppointment::class)->createQueryBuilder('a')
            ->leftJoin('a.mentor', 'm')->leftJoin('a.student', 's')->addSelect('m', 's');
        if ($status) $qb->andWhere('a.status = :status')->setParameter('status', $status);
        return $this->paginatedQuery($qb->orderBy('a.scheduledAt', 'DESC'), 'a.id', $page, $limit);
    }

    private function getMentoringRequests(EntityManagerInterface $em, int $page, int $limit, ?string $status): array
    {
        $qb = $em->getRepository(MentorCustomRequest::class)->createQueryBuilder('r')->leftJoin('r.student', 's')->addSelect('s');
        if ($status) $qb->andWhere('r.status = :status')->setParameter('status', $status);
        return $this->paginatedQuery($qb->orderBy('r.createdAt', 'DESC'), 'r.id', $page, $limit);
    }

    private function getMentorApplications(EntityManagerInterface $em, int $page, int $limit, ?string $status): array
    {
        $qb = $em->getRepository(MentorApplication::class)->createQueryBuilder('a')->leftJoin('a.user', 'u')->addSelect('u');
        if ($status) $qb->andWhere('a.status = :status')->setParameter('status', $status);
        return $this->paginatedQuery($qb->orderBy('a.createdAt', 'DESC'), 'a.id', $page, $limit);
    }

    private function checkForChanges(EntityManagerInterface $em, \DateTime $lastCheck): array
    {
        $changes = [];
        $newReservations = $em->getRepository(Reservation::class)->createQueryBuilder('r')->where('r.createdAt > :lastCheck')
            ->setParameter('lastCheck', $lastCheck)->getQuery()->getResult();
        if (!empty($newReservations)) $changes['reservations'] = ['new' => count($newReservations)];
        
        $newAppointments = $em->getRepository(MentoringAppointment::class)->createQueryBuilder('a')->where('a.createdAt > :lastCheck')
            ->setParameter('lastCheck', $lastCheck)->getQuery()->getResult();
        if (!empty($newAppointments)) $changes['mentoring'] = ['new' => count($newAppointments)];
        
        $updatedReservations = $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->where('r.updatedAt > :lastCheck')->andWhere('r.createdAt < :lastCheck')
            ->setParameter('lastCheck', $lastCheck)->getQuery()->getResult();
        if (!empty($updatedReservations)) $changes['reservations']['updated'] = count($updatedReservations);
        
        return $changes;
    }

    private function getReservationTrends(EntityManagerInterface $em): array
    {
        $conn = $em->getConnection();
        $today = new \DateTime();
        
        [$dates, $dateKeys, $rangeEnd] = $this->buildDateRange($today, 7);

        // Single query for all reservation trends
        $rows = $conn->executeQuery(
            "SELECT CAST(reservation_date AS DATE) as dt, status, COUNT(*) as cnt 
             FROM reservation 
             WHERE reservation_date >= ? AND reservation_date < ?
             AND status IN ('Approved', 'Pending')
             GROUP BY CAST(reservation_date AS DATE), status",
            [$dateKeys[0] . ' 00:00:00', $rangeEnd]
        )->fetchAllAssociative();
        
        // Organize by date
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row['dt']][$row['status']] = (int) $row['cnt'];
        }
        
        $trends = [];
        foreach ($dates as $date => $day) {
            $trends[] = [
                'day' => $day,
                'approved' => $byDate[$date]['Approved'] ?? 0,
                'pending' => $byDate[$date]['Pending'] ?? 0,
            ];
        }
        return $trends;
    }

    private function getMentoringTrends(EntityManagerInterface $em): array
    {
        $conn = $em->getConnection();
        $today = new \DateTime();
        
        [$dates, $dateKeys, $rangeEnd] = $this->buildDateRange($today, 7);

        // Single query for appointment trends
        $aptRows = $conn->executeQuery(
            "SELECT CAST(scheduled_at AS DATE) as dt, COUNT(*) as cnt 
             FROM mentoring_appointment 
             WHERE scheduled_at >= ? AND scheduled_at < ?
             GROUP BY CAST(scheduled_at AS DATE)",
            [$dateKeys[0] . ' 00:00:00', $rangeEnd]
        )->fetchAllKeyValue();
        
        // Single query for request trends
        $reqRows = $conn->executeQuery(
            "SELECT CAST(created_at AS DATE) as dt, COUNT(*) as cnt 
             FROM mentor_custom_request 
             WHERE created_at >= ? AND created_at < ?
             GROUP BY CAST(created_at AS DATE)",
            [$dateKeys[0] . ' 00:00:00', $rangeEnd]
        )->fetchAllKeyValue();
        
        $trends = [];
        foreach ($dates as $date => $day) {
            $trends[] = [
                'day' => $day,
                'appointments' => (int) ($aptRows[$date] ?? 0),
                'requests' => (int) ($reqRows[$date] ?? 0),
            ];
        }
        return $trends;
    }

    #[Route('/api/mentoring-panel', name: 'admin_api_mentoring_panel', methods: ['GET'])]
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

    private function adminModules(): array
    {
        return [
            ['area' => 'User Profile', 'priority' => 'Low', 'task' => 'View profile information', 'weight' => '1%'],
            ['area' => 'User Profile', 'priority' => 'Mid', 'task' => 'Update profile information', 'weight' => '2%'],
            ['area' => 'Dashboard', 'priority' => 'Low', 'task' => 'View reserved facility', 'weight' => '1%'],
            ['area' => 'Dashboard', 'priority' => 'Low', 'task' => 'View mentoring session', 'weight' => '1%'],
            ['area' => 'Facility Reservation Monitoring', 'priority' => 'Low', 'task' => 'View reservation request', 'weight' => '1%'],
            ['area' => 'Facility Reservation Monitoring', 'priority' => 'Low', 'task' => 'View status of the reservation request', 'weight' => '1%'],
            ['area' => 'Facility Reservation Monitoring', 'priority' => 'Mid', 'task' => 'Modify facility schedule', 'weight' => '2%'],
            ['area' => 'Mentorship Coordination', 'priority' => 'High', 'task' => 'Review mentoring request', 'weight' => '2%'],
            ['area' => 'Mentorship Coordination', 'priority' => 'High', 'task' => 'Assign mentor to mentee', 'weight' => '2%'],
            ['area' => 'Mentorship Coordination', 'priority' => 'High', 'task' => 'Monitor mentoring session', 'weight' => '2%'],
            ['area' => 'Mentorship Coordination', 'priority' => 'Low', 'task' => 'View mentoring records', 'weight' => '1%'],
            ['area' => 'Reports', 'priority' => 'High', 'task' => 'Generate operation reports for reservation summaries', 'weight' => '2%'],
            ['area' => 'Reports', 'priority' => 'High', 'task' => 'Generate operation reports for mentoring', 'weight' => '2%'],
            ['area' => 'Reports', 'priority' => 'High', 'task' => 'Generate descriptive analytics for most request experties', 'weight' => '2%'],
            ['area' => 'Reports', 'priority' => 'High', 'task' => 'Download operation reports for reservation summaries', 'weight' => '2%'],
            ['area' => 'Reports', 'priority' => 'High', 'task' => 'Download operation reports for mentoring', 'weight' => '2%'],
        ];
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

    private function resolveMentorIdentity(
        MentorCustomRequest $req,
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

    private function paginatedQuery(
        \Doctrine\ORM\QueryBuilder $qb,
        string $countAlias,
        int $page,
        int $limit,
    ): array {
        $total = (int) (clone $qb)->select('COUNT(' . $countAlias . ')')->getQuery()->getSingleScalarResult();
        $data  = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getArrayResult();
        return ['data' => $data, 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)]];
    }

    private function parseDashboardBatch(array $batch): array
    {
        $resCounts = []; $aptCounts = []; $reqCounts = [];
        $mentors = 0; $facilities = 0; $users = 0; $resToday = 0; $activeRes = 0;

        foreach ($batch as $row) {
            $cnt = (int) $row['cnt'];
            match ($row['grp']) {
                'res_status' => $resCounts[$row['lbl']] = $cnt,
                'apt_status' => $aptCounts[$row['lbl']] = $cnt,
                'req_status' => $reqCounts[$row['lbl']] = $cnt,
                'meta'       => match ($row['lbl']) {
                    'mentors'    => $mentors    = $cnt,
                    'facilities' => $facilities = $cnt,
                    'users'      => $users      = $cnt,
                    'today_res'  => $resToday   = $cnt,
                    'active_res' => $activeRes  = $cnt,
                    default      => null,
                },
                default => null,
            };
        }

        return [
            'reservations' => [
                'total'    => (int) array_sum($resCounts),
                'approved' => (int) ($resCounts['Approved'] ?? 0),
                'pending'  => (int) ($resCounts['Pending']  ?? 0),
                'rejected' => (int) ($resCounts['Rejected'] ?? 0),
                'today'    => $resToday,
            ],
            'mentoring' => [
                'totalMentors'  => $mentors,
                'appointments'  => [
                    'total'     => (int) array_sum($aptCounts),
                    'pending'   => (int) ($aptCounts['Pending']   ?? 0),
                    'completed' => (int) ($aptCounts['Completed'] ?? 0),
                ],
                'requests' => [
                    'total'   => (int) array_sum($reqCounts),
                    'pending' => (int) ($reqCounts['Pending'] ?? 0),
                ],
            ],
            'facilities' => ['total' => $facilities, 'activeReservations' => $activeRes],
            'users'      => ['total' => $users],
            'timestamp'  => (new \DateTime())->format('c'),
        ];
    }

    #[Route('/analytics/ledger', name: 'admin_ledger', methods: ['GET'])]
    public function transactionLedger(EntityManagerInterface $em): Response
    {
        $conn = $em->getConnection();
        $kpi = $conn->fetchAssociative(
            "SELECT
                (SELECT COUNT(*) FROM reservation WHERE status != 'Suggested') AS total_reservations,
                (SELECT COUNT(*) FROM reservation WHERE status != 'Suggested' AND created_at::date = CURRENT_DATE) AS today_reservations,
                (SELECT COUNT(*) FROM mentoring_audit_log WHERE logged_at::date = CURRENT_DATE) AS today_mentoring_actions,
                (SELECT COUNT(*) FROM mentor_application WHERE created_at::date = CURRENT_DATE) AS today_applications,
                (SELECT COUNT(*) FROM \"user\") AS total_users,
                (SELECT COUNT(*) FROM reservation WHERE status != 'Suggested' AND created_at >= NOW() - INTERVAL '7 days') AS week_reservations,
                (SELECT COUNT(*) FROM mentoring_audit_log WHERE logged_at >= NOW() - INTERVAL '7 days') AS week_mentoring,
                (SELECT COUNT(*) FROM mentor_custom_request WHERE created_at >= NOW() - INTERVAL '7 days') AS week_requests"
        );
        return $this->render('analytics/transaction_ledger.html.twig', ['kpi' => $kpi]);
    }

    #[Route('/analytics/ledger/api', name: 'admin_ledger_api', methods: ['GET'])]
    public function transactionLedgerApi(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $module = $request->query->get('module', 'all');
        $search = trim((string) $request->query->get('q', ''));
        $from   = $request->query->get('from', '');
        $to     = $request->query->get('to', '');
        $page   = max(1, (int) $request->query->get('page', 1));
        $limit  = 60;
        $offset = ($page - 1) * $limit;

        $rows  = $this->buildLedgerRows($em->getConnection(), $module, $search, $from, $to, $limit, $offset);
        $total = $this->countLedgerRows($em->getConnection(), $module, $search, $from, $to);

        return $this->json(['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / $limit)]);
    }

    #[Route('/analytics/ledger/export', name: 'admin_ledger_export', methods: ['GET'])]
    public function transactionLedgerExport(Request $request, EntityManagerInterface $em): Response
    {
        $module = $request->query->get('module', 'all');
        $from   = $request->query->get('from', '');
        $to     = $request->query->get('to', '');

        $rows = $this->buildLedgerRows($em->getConnection(), $module, '', $from, $to, 10000, 0);
        $moduleLabel = match ($module) {
            'reservations' => 'Reservation_Transactions',
            'mentoring'    => 'Mentoring_Audit',
            'applications' => 'Mentor_Applications',
            'sessions'     => 'Mentoring_Requests',
            'users'        => 'User_Registrations',
            default        => 'All_Transactions',
        };

        $response = new StreamedResponse();
        $response->setCallback(function () use ($rows) {
            $fp = fopen('php://output', 'w');
            fputcsv($fp, ['Timestamp', 'Module', 'Action', 'Actor', 'Actor Role', 'Subject', 'Detail', 'Status Before', 'Status After']);
            foreach ($rows as $r) {
                fputcsv($fp, [$r['ts'], $r['module'], $r['action'], $r['actor'], $r['actor_role'], $r['subject'], $r['detail'], $r['status_before'], $r['status_after']]);
            }
            fclose($fp);
        });
        $filename = 'ftic_ledger_' . $moduleLabel . '_' . date('Ymd_His') . '.csv';
        $disposition = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        return $response;
    }

    private function buildLedgerRows(\Doctrine\DBAL\Connection $conn, string $module, string $search, string $from, string $to, int $limit, int $offset): array
    {
        $streams = [];
        $like = 'ILIKE';

        if ($module === 'all' || $module === 'reservations') {
            $sql = "SELECT
                        r.created_at AS ts,
                        'Reservation' AS module,
                        'Reservation Created' AS action,
                        r.name AS actor,
                        'Requester' AS actor_role,
                        COALESCE(f.name, '') || ' — ' || COALESCE(r.event_name, '') AS subject,
                        'Capacity: ' || r.capacity || ' · Purpose: ' || COALESCE(r.purpose, '—') AS detail,
                        '' AS status_before,
                        r.status AS status_after
                    FROM reservation r
                    INNER JOIN facility f ON f.id = r.facility_id
                    WHERE r.status != 'Suggested'";
            if ($search) $sql .= " AND (r.name $like :q OR r.email $like :q OR f.name $like :q OR r.event_name $like :q)";
            if ($from)   $sql .= " AND r.created_at::date >= CAST(:from AS date)";
            if ($to)     $sql .= " AND r.created_at::date <= CAST(:to AS date)";
            $streams[] = $sql;
        }

        if ($module === 'all' || $module === 'mentoring') {
            $sql = "SELECT
                        mal.logged_at AS ts,
                        'Mentoring Audit' AS module,
                        mal.action AS action,
                        COALESCE(mal.performed_by_name, 'System') AS actor,
                        COALESCE(mal.performed_by_role, '—') AS actor_role,
                        mal.subject_label AS subject,
                        COALESCE(mal.note, '') AS detail,
                        COALESCE(mal.previous_status, '') AS status_before,
                        COALESCE(mal.new_status, '') AS status_after
                    FROM mentoring_audit_log mal
                    WHERE 1=1";
            if ($search) $sql .= " AND (mal.performed_by_name $like :q OR mal.subject_label $like :q OR mal.action $like :q)";
            if ($from)   $sql .= " AND mal.logged_at::date >= CAST(:from AS date)";
            if ($to)     $sql .= " AND mal.logged_at::date <= CAST(:to AS date)";
            $streams[] = $sql;
        }

        if ($module === 'all' || $module === 'applications') {
            $sql = "SELECT
                        ma.created_at AS ts,
                        'Mentor Application' AS module,
                        'Application ' || ma.status AS action,
                        COALESCE(ma.first_name, '') || ' ' || COALESCE(ma.last_name, '') AS actor,
                        'Applicant' AS actor_role,
                        ma.specialization AS subject,
                        COALESCE(ma.admin_note, '') AS detail,
                        'Pending' AS status_before,
                        ma.status AS status_after
                    FROM mentor_application ma
                    WHERE 1=1";
            if ($search) $sql .= " AND (ma.first_name $like :q OR ma.last_name $like :q OR ma.email $like :q OR ma.specialization $like :q)";
            if ($from)   $sql .= " AND ma.created_at::date >= CAST(:from AS date)";
            if ($to)     $sql .= " AND ma.created_at::date <= CAST(:to AS date)";
            $streams[] = $sql;
        }

        if ($module === 'all' || $module === 'sessions') {
            $sql = "SELECT
                        mcr.created_at AS ts,
                        'Mentoring Request' AS module,
                        'Request ' || mcr.status AS action,
                        COALESCE(NULLIF(mcr.full_name, ''), NULLIF(TRIM(COALESCE(su.first_name, '') || ' ' || COALESCE(su.last_name, '')), ''), su.email) AS actor,
                        'Student' AS actor_role,
                        CASE
                            WHEN mp.id IS NOT NULL THEN mp.display_name || ' (' || mp.specialization || ')'
                            WHEN NULLIF(mcr.assigned_mentor_name, '') IS NOT NULL THEN mcr.assigned_mentor_name || COALESCE(' (' || NULLIF(mcr.assigned_mentor_expertise, '') || ')', '')
                            ELSE COALESCE(NULLIF(mcr.preferred_expertise, ''), 'Mentor assistance request')
                        END AS subject,
                        TRIM(BOTH ' ' FROM CONCAT_WS(' | ',
                            NULLIF(mcr.message, ''),
                            NULLIF(mcr.preferred_schedule, ''),
                            NULLIF(mcr.available_dates, ''),
                            NULLIF(mcr.scheduled_date::text, ''),
                            NULLIF(mcr.scheduled_time, '')
                        )) AS detail,
                        '' AS status_before,
                        mcr.status AS status_after
                    FROM mentor_custom_request mcr
                    INNER JOIN \"user\" su ON su.id = mcr.student_id
                    LEFT JOIN mentor_profile mp ON mp.id = mcr.mentor_profile_id
                    WHERE 1=1";
            if ($search) $sql .= " AND (su.first_name $like :q OR su.last_name $like :q OR su.email $like :q OR mcr.full_name $like :q OR mp.display_name $like :q OR mcr.preferred_expertise $like :q OR mcr.assigned_mentor_name $like :q OR mcr.message $like :q OR mcr.status $like :q)";
            if ($from)   $sql .= " AND mcr.created_at::date >= CAST(:from AS date)";
            if ($to)     $sql .= " AND mcr.created_at::date <= CAST(:to AS date)";
            $streams[] = $sql;

            $sql = "SELECT
                        apt.created_at AS ts,
                        'Mentoring Request' AS module,
                        'Request ' || apt.status AS action,
                        COALESCE(su.first_name, '') || ' ' || COALESCE(su.last_name, '') AS actor,
                        'Student' AS actor_role,
                        mp.display_name || ' (' || mp.specialization || ')' AS subject,
                        COALESCE(apt.topic, '') AS detail,
                        '' AS status_before,
                        apt.status AS status_after
                    FROM mentoring_appointment apt
                    INNER JOIN \"user\" su ON su.id = apt.student_id
                    INNER JOIN mentor_profile mp ON mp.id = apt.mentor_id
                    WHERE 1=1";
            if ($search) $sql .= " AND (su.first_name $like :q OR su.last_name $like :q OR mp.display_name $like :q OR apt.topic $like :q OR apt.status $like :q)";
            if ($from)   $sql .= " AND apt.created_at::date >= CAST(:from AS date)";
            if ($to)     $sql .= " AND apt.created_at::date <= CAST(:to AS date)";
            $streams[] = $sql;
        }

        if ($module === 'all' || $module === 'users') {
            $sql = "SELECT
                        NOW() AS ts,
                        'User' AS module,
                        'Account Registered' AS action,
                        COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, '') AS actor,
                        CASE
                            WHEN u.roles::jsonb @> '[\"ROLE_SUPER_ADMIN\"]' THEN 'Super Admin'
                            WHEN u.roles::jsonb @> '[\"ROLE_ADMIN\"]' THEN 'Admin'
                            WHEN u.roles::jsonb @> '[\"ROLE_MENTOR\"]' THEN 'Mentor'
                            ELSE 'User'
                        END AS actor_role,
                        u.email AS subject,
                        COALESCE(u.degree, '') AS detail,
                        '' AS status_before,
                        CASE WHEN u.is_verified THEN 'Verified' ELSE 'Unverified' END AS status_after
                    FROM \"user\" u
                    WHERE 1=1";
            if ($search) $sql .= " AND (u.first_name $like :q OR u.last_name $like :q OR u.email $like :q)";
            $streams[] = $sql;
        }

        if (empty($streams)) return [];

        $union = implode("\n UNION ALL \n", array_map(fn($s) => "($s)", $streams));
        $final = "SELECT * FROM ($union) AS ledger ORDER BY ts DESC LIMIT :lim OFFSET :off";

        $params = ['lim' => $limit, 'off' => $offset];
        $types  = ['lim' => \Doctrine\DBAL\ParameterType::INTEGER, 'off' => \Doctrine\DBAL\ParameterType::INTEGER];
        if ($search) { $params['q'] = '%' . $search . '%'; }
        if ($from)   { $params['from'] = $from; }
        if ($to)     { $params['to']   = $to; }

        $data = $conn->fetchAllAssociative($final, $params, $types);

        return array_map(fn($r) => [
            'ts'           => $r['ts'] ?? '',
            'module'       => $r['module'],
            'action'       => $r['action'],
            'actor'        => trim((string) ($r['actor'] ?? '')),
            'actor_role'   => $r['actor_role'] ?? '',
            'subject'      => $r['subject'] ?? '',
            'detail'       => $r['detail'] ?? '',
            'status_before'=> $r['status_before'] ?? '',
            'status_after' => $r['status_after'] ?? '',
        ], $data);
    }

    private function countLedgerRows(\Doctrine\DBAL\Connection $conn, string $module, string $search, string $from, string $to): int
    {
        return count($this->buildLedgerRows($conn, $module, $search, $from, $to, 100000, 0));
    }

    private function buildDateRange(\DateTime $today, int $days): array
    {
        $dates = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = (clone $today)->modify("-$i days");
            $dates[$d->format('Y-m-d')] = $d->format('D');
        }
        $keys     = array_keys($dates);
        $rangeEnd = (new \DateTimeImmutable($keys[count($keys) - 1]))->modify('+1 day')->format('Y-m-d 00:00:00');
        return [$dates, $keys, $rangeEnd];
    }

}
