<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MentorApplication;
use App\Entity\MentorCustomRequest;
use App\Entity\MentorProfile;
use App\Entity\MentoringAppointment;
use App\Entity\Notification;
use App\Entity\Reservation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/user')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class UserDashboardApiController extends AbstractController
{
    #[Route('/dashboard', name: 'api_user_dashboard', methods: ['GET'])]
    public function getDashboardData(EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $mentorProfile = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $user]);

        // Get reservations
        $reservations = $em->getRepository(Reservation::class)->findBy(
            ['user' => $user],
            ['reservationDate' => 'DESC'],
            10
        );

        // Get mentoring as student
        $mentoringAsStudent = $em->getRepository(MentoringAppointment::class)->findBy(
            ['student' => $user],
            ['scheduledAt' => 'DESC'],
            10
        );

        // Get mentoring as mentor (for faculty)
        $mentoringAsMentor = [];
        if ($mentorProfile) {
            $mentoringAsMentor = $em->getRepository(MentoringAppointment::class)->findBy(
                ['mentor' => $mentorProfile],
                ['scheduledAt' => 'DESC'],
                10
            );
        }

        // Get unread notification count
        $unreadCount = $em->getRepository(Notification::class)->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.isRead = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return $this->json([
            'reservations' => array_map(fn($r) => [
                'id' => $r->getId(),
                'facility' => $r->getFacility()->getName(),
                'date' => $r->getReservationDate()->format('Y-m-d'),
                'startTime' => $r->getReservationStartTime()->format('H:i'),
                'endTime' => $r->getReservationEndTime()->format('H:i'),
                'status' => $r->getStatus(),
                'purpose' => $r->getPurpose(),
            ], $reservations),
            'mentoringAsStudent' => array_map(fn($m) => [
                'id' => $m->getId(),
                'mentorName' => $m->getMentor()->getDisplayName(),
                'date' => $m->getScheduledAt()->format('Y-m-d H:i'),
                'status' => $m->getStatus(),
                'topic' => $m->getTopic(),
            ], $mentoringAsStudent),
            'mentoringAsMentor' => array_map(fn($m) => [
                'id' => $m->getId(),
                'studentName' => $m->getStudent()->getEmail(),
                'date' => $m->getScheduledAt()->format('Y-m-d H:i'),
                'status' => $m->getStatus(),
                'topic' => $m->getTopic(),
            ], $mentoringAsMentor),
            'unreadCount' => (int) $unreadCount,
        ]);
    }

    /**
     * Sidebar data for the end-user dashboard right panel.
     * Returns:
     *   recentReservations  — the user's own 8 most-recent reservation requests
     *   mentorships         — all mentoring appointments where user is student or mentor
     *   mentorRequests      — mentor custom requests submitted by this user
     *   mentorApplications  — mentor applications submitted by this user
     */
    #[Route('/sidebar', name: 'api_user_sidebar', methods: ['GET'])]
    public function getSidebarData(EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $mentorProfile = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $user]);

        // ── Recent reservation requests (this user only) ──
        $reservations = $em->getRepository(Reservation::class)->findBy(
            ['user' => $user],
            ['updatedAt' => 'DESC'],
            8
        );
        $recentReservations = array_map(static fn($r) => [
            'facilityName' => $r->getFacility()->getName(),
            'date'         => $r->getReservationDate()->format('M j, Y'),
            'time'         => $r->getReservationStartTime()->format('H:i'),
            'status'       => $r->getStatus(),
        ], $reservations);

        // ── Mentoring appointments (as student) ──
        $asStudent = $em->getRepository(MentoringAppointment::class)->findBy(
            ['student' => $user],
            ['scheduledAt' => 'DESC'],
            8
        );

        // ── Mentoring appointments (as mentor, if applicable) ──
        $asMentor = [];
        if ($mentorProfile) {
            $asMentor = $em->getRepository(MentoringAppointment::class)->findBy(
                ['mentor' => $mentorProfile],
                ['scheduledAt' => 'DESC'],
                8
            );
        }

        // Merge and de-duplicate by id, keep most recent 8
        $allAppointments = [];
        foreach (array_merge($asStudent, $asMentor) as $apt) {
            $allAppointments[$apt->getId()] = $apt;
        }
        usort($allAppointments, static fn($a, $b) => $b->getScheduledAt() <=> $a->getScheduledAt());
        $allAppointments = array_slice($allAppointments, 0, 8);

        $mentorships = array_map(static fn($m) => [
            'title'  => $m->getTopic() ?: 'Mentoring session',
            'mentor' => $m->getMentor()->getDisplayName(),
            'date'   => $m->getScheduledAt()->format('M j, Y'),
            'time'   => $m->getScheduledAt()->format('h:i A'),
            'status' => $m->getStatus(),
        ], $allAppointments);

        // ── Mentor custom requests submitted by this user ──
        $customRequests = $em->getRepository(MentorCustomRequest::class)->findBy(
            ['student' => $user],
            ['updatedAt' => 'DESC', 'createdAt' => 'DESC'],
            8
        );
        $mentorRequests = array_map(static fn($r) => [
            'topic'     => $r->getPreferredExpertise() ?: ($r->getMessage() ? mb_substr($r->getMessage(), 0, 60) : 'Request'),
            'status'    => ucfirst($r->getStatus()),
            'updatedAt' => ($r->getUpdatedAt() ?: $r->getCreatedAt())->format('M j, Y'),
        ], $customRequests);

        // ── Mentor applications submitted by this user ──
        $applications = $em->getRepository(MentorApplication::class)->findBy(
            ['student' => $user],
            ['createdAt' => 'DESC'],
            4
        );
        $mentorApplications = array_map(static fn($a) => [
            'specialization' => $a->getSpecialization() ?: 'General',
            'status'         => ucfirst($a->getStatus()),
            'createdAt'      => $a->getCreatedAt()->format('M j, Y'),
        ], $applications);

        $response = $this->json([
            'recentReservations' => $recentReservations,
            'mentorships'        => $mentorships,
            'mentorRequests'     => $mentorRequests,
            'mentorApplications' => $mentorApplications,
            'isMentor'           => $mentorProfile !== null,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
