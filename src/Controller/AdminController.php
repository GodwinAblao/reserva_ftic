<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Facility;
use App\Entity\MentorApplication;
use App\Entity\MentorCustomRequest;
use App\Entity\MentorProfile;
use App\Entity\MentoringAppointment;
use App\Entity\Reservation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
    public function apiStats(EntityManagerInterface $em): JsonResponse
    {
        return $this->json($this->getDashboardData($em));
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
    public function apiAnalytics(EntityManagerInterface $em): JsonResponse
    {
        $conn = $em->getConnection();
        $today = new \DateTime();
        
        // Build date range for last 30 days
        $dates = [];
        for ($i = 29; $i >= 0; $i--) {
            $dates[] = (clone $today)->modify("-$i days")->format('Y-m-d');
        }
        
        // Single query for all reservation counts by date
        $resByDate = $conn->executeQuery(
            "SELECT reservation_date as dt, COUNT(*) as cnt 
             FROM reservation 
             WHERE reservation_date >= ? AND reservation_date <= ?
             GROUP BY reservation_date",
            [$dates[0], $dates[count($dates)-1]]
        )->fetchAllKeyValue();
        
        // Single query for all mentoring counts by date
        $mentByDate = $conn->executeQuery(
            "SELECT DATE(scheduled_at) as dt, COUNT(*) as cnt 
             FROM mentoring_appointment 
             WHERE DATE(scheduled_at) >= ? AND DATE(scheduled_at) <= ?
             GROUP BY DATE(scheduled_at)",
            [$dates[0], $dates[count($dates)-1]]
        )->fetchAllKeyValue();
        
        $dailyStats = [];
        foreach ($dates as $date) {
            $dailyStats[] = [
                'date' => $date,
                'reservations' => (int) ($resByDate[$date] ?? 0),
                'mentoring' => (int) ($mentByDate[$date] ?? 0),
            ];
        }

        return $this->json([
            'dailyStats' => $dailyStats,
            'reservationTrends' => $this->getReservationTrends($em),
            'mentoringTrends' => $this->getMentoringTrends($em),
        ]);
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
    public function reservationMonitoring(EntityManagerInterface $em): Response
    {
        return $this->render('admin/reservation_monitoring.html.twig', [
            'reservations' => $em->getRepository(Reservation::class)->findBy([], ['createdAt' => 'DESC'], 40),
            'statusCounts' => $this->reservationStatusCounts($em),
            'facilityCounts' => $this->facilityReservationCounts($em),
        ]);
    }

    #[Route('/mentorship-coordination', name: 'admin_mentorship_coordination', methods: ['GET'])]
    public function mentorshipCoordination(EntityManagerInterface $em): Response
    {
        return $this->render('admin/mentorship_coordination.html.twig', [
            'applications' => $em->getRepository(MentorApplication::class)->findBy([], ['createdAt' => 'DESC'], 20),
            'requests' => $em->getRepository(MentorCustomRequest::class)->findBy([], ['createdAt' => 'DESC'], 20),
            'appointments' => $em->getRepository(MentoringAppointment::class)->findBy([], ['scheduledAt' => 'DESC'], 20),
            'mentors' => $em->getRepository(MentorProfile::class)->findBy([], ['displayName' => 'ASC']),
            'statusCounts' => $this->mentoringStatusCounts($em),
            'topExpertise' => $this->topExpertise($em),
        ]);
    }

    #[Route('/reports', name: 'admin_reports', methods: ['GET'])]
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

    private function getDashboardData(EntityManagerInterface $em): array
    {
        $conn = $em->getConnection();
        $today = (new \DateTime())->format('Y-m-d');

        // Use native SQL for much faster counts - single query per table with GROUP BY
        
        // Get all reservation counts in one query
        $resCounts = $conn->executeQuery(
            "SELECT status, COUNT(*) as cnt FROM reservation GROUP BY status"
        )->fetchAllKeyValue();
        
        $resTotal = (int) array_sum($resCounts);
        $resToday = (int) $conn->executeQuery(
            "SELECT COUNT(*) FROM reservation WHERE reservation_date = ?",
            [$today]
        )->fetchOne();

        // Get appointment counts in one query
        $aptCounts = $conn->executeQuery(
            "SELECT status, COUNT(*) as cnt FROM mentoring_appointment GROUP BY status"
        )->fetchAllKeyValue();
        
        // Get mentor request counts in one query
        $reqCounts = $conn->executeQuery(
            "SELECT status, COUNT(*) as cnt FROM mentor_custom_request GROUP BY status"
        )->fetchAllKeyValue();

        // Fast single counts
        $mentors = (int) $conn->executeQuery("SELECT COUNT(*) FROM mentor_profile")->fetchOne();
        $facilities = (int) $conn->executeQuery("SELECT COUNT(*) FROM facility")->fetchOne();
        $users = (int) $conn->executeQuery("SELECT COUNT(*) FROM user")->fetchOne();
        
        $activeRes = (int) $conn->executeQuery(
            "SELECT COUNT(*) FROM reservation WHERE reservation_date >= ? AND status = 'Approved'",
            [$today]
        )->fetchOne();

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
        
        // Single query for all reservation trends
        $rows = $conn->executeQuery(
            "SELECT reservation_date as dt, status, COUNT(*) as cnt 
             FROM reservation 
             WHERE reservation_date >= ? AND reservation_date <= ?
             AND status IN ('Approved', 'Pending')
             GROUP BY reservation_date, status",
            [$dateKeys[0], $dateKeys[count($dateKeys)-1]]
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
            "SELECT DATE(scheduled_at) as dt, COUNT(*) as cnt 
             FROM mentoring_appointment 
             WHERE DATE(scheduled_at) >= ? AND DATE(scheduled_at) <= ?
             GROUP BY DATE(scheduled_at)",
            [$dateKeys[0], $dateKeys[count($dateKeys)-1]]
        )->fetchAllKeyValue();
        
        // Single query for request trends
        $reqRows = $conn->executeQuery(
            "SELECT DATE(created_at) as dt, COUNT(*) as cnt 
             FROM mentor_custom_request 
             WHERE DATE(created_at) >= ? AND DATE(created_at) <= ?
             GROUP BY DATE(created_at)",
            [$dateKeys[0], $dateKeys[count($dateKeys)-1]]
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
