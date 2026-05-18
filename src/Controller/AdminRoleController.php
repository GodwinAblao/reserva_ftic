<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MentorProfile;
use App\Entity\MentoringAppointment;
use App\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
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

    #[Route('/api/recent-reservations', name: 'admin_role_api_recent_reservations', methods: ['GET'])]
    public function apiRecentReservations(EntityManagerInterface $em): JsonResponse
    {
        $conn = $em->getConnection();
        $rows = $conn->executeQuery(
            'SELECT r.name AS userName, f.name AS facilityName,
                    r.reservation_date AS date, r.reservation_start_time AS time, r.status
             FROM reservation r
             LEFT JOIN facility f ON r.facility_id = f.id
             ORDER BY r.created_at DESC LIMIT 8'
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
        $response->headers->set('Cache-Control', 'private, max-age=10');
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
             LEFT JOIN user u ON mcr.student_id = u.id
             ORDER BY mcr.created_at DESC LIMIT 5'
        )->fetchAllAssociative();

        $lbRows = $conn->executeQuery(
            'SELECT mp.display_name, mp.specialization, u.degree
             FROM mentor_profile mp
             LEFT JOIN user u ON mp.user_id = u.id
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
                    'degree'         => $m['degree'] ?? '',
                    'specialization' => $m['specialization'] ? 'Specialization in ' . $m['specialization'] : '',
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
    public function reservationMonitoring(EntityManagerInterface $em): Response
    {
        $today = new \DateTime('today');
        $tomorrow = new \DateTime('tomorrow');
        $todayReservations = $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->where('r.reservationDate >= :today AND r.reservationDate < :tomorrow')
            ->setParameter('today', $today)
            ->setParameter('tomorrow', $tomorrow)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()->getResult();
        return $this->render('admin/reservation_monitoring.html.twig', [
            'reservations' => $todayReservations,
            'statusCounts' => $this->reservationStatusCountsToday($em),
            'facilityCounts' => $this->facilityReservationCountsToday($em),
        ]);
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
             LEFT JOIN user u     ON u.id = r.user_id
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
        $statusCounts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Cancelled' => 0,
                          'AwaitingFacilitySelection' => 0, 'Suggested' => 0];
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
    public function mentorshipAssign(int $id, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('mentorship_assign_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
        $req = $em->getRepository(\App\Entity\MentorCustomRequest::class)->find($id);
        if ($req) {
            $mentorId = $request->request->get('mentor_id');
            if ($mentorId) {
                $mentor = $em->getRepository(MentorProfile::class)->find((int)$mentorId);
                if ($mentor) { $req->setAssignedMentorName($mentor->getDisplayName()); $req->setAssignedMentorExpertise($mentor->getSpecialization()); }
            }
            $req->setMeetingMethod($request->request->get('meeting_method') ?: null);
            $req->setAvailableDates($request->request->get('available_dates') ?: null);
            $req->setAvailableTime($request->request->get('available_time') ?: null);
            $req->setAdminInstructions($request->request->get('admin_instructions') ?: null);
            $req->setStatus('approved');
            $em->flush();
            if ($isAjax) return $this->json(['success' => true, 'message' => 'Mentor assigned successfully.']);
            $this->addFlash('success', 'Mentor assigned successfully.');
        } else {
            if ($isAjax) return $this->json(['success' => false, 'message' => 'Request not found.']);
        }
        return $this->redirectToRoute('admin_role_mentorship_coordination');
    }

    #[Route('/mentorship-coordination', name: 'admin_role_mentorship_coordination', methods: ['GET'])]
    public function mentorshipCoordination(EntityManagerInterface $em): Response
    {
        return $this->render('admin/mentorship_coordination.html.twig', [
            'applications' => $em->getRepository(\App\Entity\MentorApplication::class)->findBy([], ['createdAt' => 'DESC'], 20),
            'requests' => $em->getRepository(\App\Entity\MentorCustomRequest::class)->findBy([], ['createdAt' => 'DESC'], 20),
            'appointments' => $em->getRepository(MentoringAppointment::class)->findBy([], ['scheduledAt' => 'DESC'], 20),
            'mentors' => $em->getRepository(MentorProfile::class)->findBy([], ['displayName' => 'ASC']),
            'statusCounts' => $this->mentoringStatusCounts($em),
            'topExpertise' => $this->topExpertise($em),
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
            ->leftJoin('r.facility', 'f')
            ->addSelect('f')
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

        return [
            'reservations' => [
                'total' => $reservationRepo->count([]),
                'approved' => $reservationRepo->count(['status' => 'Approved']),
                'pending' => $reservationRepo->count(['status' => 'Pending']),
                'rejected' => $reservationRepo->count(['status' => 'Rejected']),
                'today' => $reservationRepo->createQueryBuilder('r')
                    ->select('COUNT(r.id)')
                    ->where('r.reservationDate >= :today')
                    ->setParameter('today', new \DateTime('today'))
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
}
