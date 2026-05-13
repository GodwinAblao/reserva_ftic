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
        return $this->json($this->getDashboardData($em));
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
        return $this->render('admin/reservation_monitoring.html.twig', [
            'reservations' => $em->getRepository(Reservation::class)->findBy([], ['createdAt' => 'DESC'], 40),
            'statusCounts' => $this->reservationStatusCounts($em),
            'facilityCounts' => $this->facilityReservationCounts($em),
        ]);
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
