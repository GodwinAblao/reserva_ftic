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
#[IsGranted('ROLE_ADMIN')]
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

    #[Route('/api/stats', name: 'admin_api_stats', methods: ['GET'])]
    public function apiStats(EntityManagerInterface $em, CacheInterface $cache): JsonResponse
    {
        $data = $cache->get('admin.dashboard.stats.superadmin.v2', function (ItemInterface $item) use ($em): array {
            $item->expiresAfter(10);
            return $this->getDashboardData($em);
        });

        $response = $this->json($data);
        $response->headers->set('Cache-Control', 'private, max-age=10');
        return $response;
    }

    #[Route('/api/recent-reservations', name: 'admin_api_recent_reservations', methods: ['GET'])]
    public function apiRecentReservations(EntityManagerInterface $em, CacheInterface $cache): JsonResponse
    {
        $data = $cache->get('admin.dashboard.recent_reservations.v2', function (ItemInterface $item) use ($em): array {
            $item->expiresAfter(10);
            return $this->getRecentReservationsData($em);
        });

        $response = $this->json($data);
        $response->headers->set('Cache-Control', 'private, max-age=10');
        return $response;
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

        $today = new \DateTime();
        foreach ($facilities as &$facility) {
            $reservationCount = $em->getRepository(Reservation::class)->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->where('r.facility = :facility')
                ->andWhere('r.reservationDate = :today')
                ->andWhere('r.status IN (:statuses)')
                ->setParameter('facility', $facility['id'])
                ->setParameter('today', $today->format('Y-m-d'))
                ->setParameter('statuses', ['Approved', 'Pending'])
                ->getQuery()
                ->getSingleScalarResult();
            
            $facility['todayReservations'] = (int) $reservationCount;
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
        $statuses = ['Pending', 'Approved', 'Rejected', 'Cancelled'];
        $statusCounts = [];
        foreach ($statuses as $s) {
            $statusCounts[$s] = (int) $em->getRepository(Reservation::class)->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->where('r.status = :status AND r.reservationDate >= :today AND r.reservationDate < :tomorrow')
                ->setParameter('status', $s)->setParameter('today', $today)->setParameter('tomorrow', $tomorrow)
                ->getQuery()->getSingleScalarResult();
        }
        $facilityCounts = [];
        foreach ($em->getRepository(Facility::class)->findAll() as $facility) {
            $cnt = (int) $em->getRepository(Reservation::class)->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->where('r.facility = :facility AND r.reservationDate >= :today AND r.reservationDate < :tomorrow')
                ->setParameter('facility', $facility)->setParameter('today', $today)->setParameter('tomorrow', $tomorrow)
                ->getQuery()->getSingleScalarResult();
            $facilityCounts[$facility->getName()] = $cnt;
        }
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
        $req = $em->getRepository(MentorCustomRequest::class)->find($id);
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
            $actor = $this->getUser();
            $actorName = $actor instanceof User ? trim(($actor->getFirstName() ?? '') . ' ' . ($actor->getLastName() ?? '')) : 'Super Admin';
            if ($actorName === '') { $actorName = $actor instanceof User ? $actor->getEmail() : 'Super Admin'; }
            $student = $req->getStudent();
            $requesterName = $req->getFullName() ?: ($student ? trim(($student->getFirstName() ?? '') . ' ' . ($student->getLastName() ?? '')) : 'Unknown');
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
                    ->setPerformedByRole($this->isGranted('ROLE_SUPER_ADMIN') ? 'Super Admin' : 'Admin')
                    ->setNote($noteText);
                $em->persist($auditLog);
            }
            $em->flush();
            foreach ($em->getRepository(User::class)->findAdmins() as $u) {
                if ($u === $actor) continue;
                $notifService->notifyAdminMentorRequestUpdated($u, $id, $actorName, ucfirst($submittedStatus), $requesterName);
            }
            if ($isAjax) return $this->json(['success' => true, 'message' => 'Mentor assigned successfully.']);
            $this->addFlash('success', 'Mentor assigned successfully.');
        } else {
            if ($isAjax) return $this->json(['success' => false, 'message' => 'Request not found.']);
        }
        return $this->redirectToRoute('admin_mentorship_coordination');
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
             WHERE r.status != 'Suggested'
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

        $resCounts = []; $aptCounts = []; $reqCounts = [];
        $mentors = 0; $facilities = 0; $users = 0; $resToday = 0; $activeRes = 0;

        foreach ($batch as $row) {
            $cnt = (int) $row['cnt'];
            switch ($row['grp']) {
                case 'res_status': $resCounts[$row['lbl']] = $cnt; break;
                case 'apt_status': $aptCounts[$row['lbl']] = $cnt; break;
                case 'req_status': $reqCounts[$row['lbl']] = $cnt; break;
                case 'meta':
                    match ($row['lbl']) {
                        'mentors'    => $mentors    = $cnt,
                        'facilities' => $facilities = $cnt,
                        'users'      => $users      = $cnt,
                        'today_res'  => $resToday   = $cnt,
                        'active_res' => $activeRes  = $cnt,
                        default      => null,
                    };
                    break;
            }
        }

        $resTotal = (int) array_sum($resCounts);

        return [
            'reservations' => [
                'total' => $resTotal,
                'approved' => (int) ($resCounts['Approved'] ?? 0),
                'pending' => (int) ($resCounts['Pending'] ?? 0),
                'rejected' => (int) ($resCounts['Rejected'] ?? 0),
                'today' => $resToday,
            ],
            'mentoring' => [
                'totalMentors' => $mentors,
                'appointments' => [
                    'total' => (int) array_sum($aptCounts),
                    'pending' => (int) ($aptCounts['Pending'] ?? 0),
                    'completed' => (int) ($aptCounts['Completed'] ?? 0),
                ],
                'requests' => [
                    'total' => (int) array_sum($reqCounts),
                    'pending' => (int) ($reqCounts['Pending'] ?? 0),
                ],
            ],
            'facilities' => [
                'total' => $facilities,
                'activeReservations' => $activeRes,
            ],
            'users' => [
                'total' => $users,
            ],
            'timestamp' => (new \DateTime())->format('c'),
        ];
    }

    private function getMentoringAppointments(EntityManagerInterface $em, int $page, int $limit, ?string $status): array
    {
        $qb = $em->getRepository(MentoringAppointment::class)->createQueryBuilder('a')
            ->leftJoin('a.mentor', 'm')->leftJoin('a.student', 's')->addSelect('m', 's');
        if ($status) $qb->andWhere('a.status = :status')->setParameter('status', $status);
        $qb->orderBy('a.scheduledAt', 'DESC');
        $total = (int) (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
        $appointments = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getArrayResult();
        return ['data' => $appointments, 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)]];
    }

    private function getMentoringRequests(EntityManagerInterface $em, int $page, int $limit, ?string $status): array
    {
        $qb = $em->getRepository(MentorCustomRequest::class)->createQueryBuilder('r')->leftJoin('r.student', 's')->addSelect('s');
        if ($status) $qb->andWhere('r.status = :status')->setParameter('status', $status);
        $qb->orderBy('r.createdAt', 'DESC');
        $total = (int) (clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
        $requests = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getArrayResult();
        return ['data' => $requests, 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)]];
    }

    private function getMentorApplications(EntityManagerInterface $em, int $page, int $limit, ?string $status): array
    {
        $qb = $em->getRepository(MentorApplication::class)->createQueryBuilder('a')->leftJoin('a.user', 'u')->addSelect('u');
        if ($status) $qb->andWhere('a.status = :status')->setParameter('status', $status);
        $qb->orderBy('a.createdAt', 'DESC');
        $total = (int) (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
        $applications = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getArrayResult();
        return ['data' => $applications, 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)]];
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
        
        // Build date range for last 7 days
        $dates = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = (clone $today)->modify("-$i days");
            $dates[$date->format('Y-m-d')] = $date->format('D');
        }
        
        $dateKeys = array_keys($dates);
        
        $rangeEnd = (new \DateTimeImmutable($dateKeys[count($dateKeys)-1]))->modify('+1 day')->format('Y-m-d 00:00:00');

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
        
        // Build date range for last 7 days
        $dates = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = (clone $today)->modify("-$i days");
            $dates[$date->format('Y-m-d')] = $date->format('D');
        }
        
        $dateKeys = array_keys($dates);
        
        // Single query for appointment trends
        $aptRows = $conn->executeQuery(
            "SELECT CAST(scheduled_at AS DATE) as dt, COUNT(*) as cnt 
             FROM mentoring_appointment 
             WHERE scheduled_at >= ? AND scheduled_at < ?
             GROUP BY CAST(scheduled_at AS DATE)",
            [$dateKeys[0] . ' 00:00:00', (new \DateTimeImmutable($dateKeys[count($dateKeys)-1]))->modify('+1 day')->format('Y-m-d 00:00:00')]
        )->fetchAllKeyValue();
        
        // Single query for request trends
        $reqRows = $conn->executeQuery(
            "SELECT CAST(created_at AS DATE) as dt, COUNT(*) as cnt 
             FROM mentor_custom_request 
             WHERE created_at >= ? AND created_at < ?
             GROUP BY CAST(created_at AS DATE)",
            [$dateKeys[0] . ' 00:00:00', (new \DateTimeImmutable($dateKeys[count($dateKeys)-1]))->modify('+1 day')->format('Y-m-d 00:00:00')]
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

    #[Route('/api/mentoring', name: 'admin_api_mentoring', methods: ['GET'])]
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
}
