<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Facility;
use App\Entity\MentorApplication;
use App\Entity\MentorCustomRequest;
use App\Entity\MentorProfile;
use App\Entity\MentoringAppointment;
use App\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    #[Route('', name: 'admin_home', methods: ['GET'])]
    public function home(EntityManagerInterface $em): Response
    {
        return $this->render('admin/dashboard.html.twig', [
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
