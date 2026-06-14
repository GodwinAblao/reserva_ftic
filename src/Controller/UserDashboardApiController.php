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
use Symfony\Contracts\Cache\CacheInterface;

#[Route('/api/user')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class UserDashboardApiController extends AbstractController
{
    #[Route('/dashboard', name: 'api_user_dashboard', methods: ['GET'])]
    public function getDashboardData(EntityManagerInterface $em, CacheInterface $cache): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }
        
        $userId = $user->getId();
        $cacheKey = 'api_dashboard_' . $userId;
        
        $data = $cache->get($cacheKey, function() use ($em, $user) {
            $mentorProfile = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $user]);

            // Get reservations with eager loading of facility
            $reservations = $em->getRepository(Reservation::class)->createQueryBuilder('r')
                ->select('r', 'f')
                ->leftJoin('r.facility', 'f')
                ->where('r.user = :user')
                ->andWhere('r.status != :suggested')
                ->setParameter('user', $user)
                ->setParameter('suggested', 'Suggested')
                ->orderBy('r.reservationDate', 'DESC')
                ->setMaxResults(10)
                ->getQuery()
                ->getResult();

            // Get mentoring as student with eager loading
            $mentoringAsStudent = $em->getRepository(MentoringAppointment::class)->createQueryBuilder('ma')
                ->select('ma', 'm')
                ->leftJoin('ma.mentor', 'm')
                ->where('ma.student = :user')
                ->setParameter('user', $user)
                ->orderBy('ma.scheduledAt', 'DESC')
                ->setMaxResults(10)
                ->getQuery()
                ->getResult();

            // Get mentoring as mentor (for faculty) with eager loading
            $mentoringAsMentor = [];
            if ($mentorProfile) {
                $mentoringAsMentor = $em->getRepository(MentoringAppointment::class)->createQueryBuilder('ma')
                    ->select('ma', 's')
                    ->leftJoin('ma.student', 's')
                    ->where('ma.mentor = :mentor')
                    ->setParameter('mentor', $mentorProfile)
                    ->orderBy('ma.scheduledAt', 'DESC')
                    ->setMaxResults(10)
                    ->getQuery()
                    ->getResult();
            }

            // Get unread notification count
            $unreadCount = $em->getRepository(Notification::class)->count(['user' => $user, 'isRead' => false]);

            return [
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
            ];
        }, 60); // Cache for 1 minute

        $response = $this->json($data);
        $response->headers->set('Cache-Control', 'private, max-age=60');
        return $response;
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
    public function getSidebarData(EntityManagerInterface $em, CacheInterface $cache): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $userId = $user->getId();
        $cacheKey = 'api_sidebar_' . $userId;
        
        $data = $cache->get($cacheKey, function() use ($em, $user) {
            $mentorProfile = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $user]);

            // ── Recent reservation requests with eager loading ──
            $reservations = $em->getRepository(Reservation::class)->createQueryBuilder('r')
                ->select('r', 'f')
                ->leftJoin('r.facility', 'f')
                ->where('r.user = :user')
                ->andWhere('r.status != :suggested')
                ->setParameter('user', $user)
                ->setParameter('suggested', 'Suggested')
                ->orderBy('r.updatedAt', 'DESC')
                ->setMaxResults(8)
                ->getQuery()
                ->getResult();
            
            $recentReservations = array_map(static fn($r) => [
                'id'           => $r->getId(),
                'facilityName' => $r->getFacility()->getName(),
                'date'         => $r->getReservationDate()->format('M j, Y'),
                'time'         => $r->getReservationStartTime()->format('g:i A'),
                'endTime'      => $r->getReservationEndTime()->format('g:i A'),
                'eventName'    => $r->getEventName(),
                'capacity'     => $r->getCapacity(),
                'purpose'      => $r->getPurpose(),
                'status'       => $r->getStatus(),
            ], $reservations);

            // ── Mentoring appointments (as student) with eager loading ──
            $asStudent = $em->getRepository(MentoringAppointment::class)->createQueryBuilder('ma')
                ->select('ma', 'm')
                ->leftJoin('ma.mentor', 'm')
                ->where('ma.student = :user')
                ->setParameter('user', $user)
                ->orderBy('ma.scheduledAt', 'DESC')
                ->setMaxResults(8)
                ->getQuery()
                ->getResult();

            // ── Mentoring appointments (as mentor, if applicable) with eager loading ──
            $asMentor = [];
            if ($mentorProfile) {
                $asMentor = $em->getRepository(MentoringAppointment::class)->createQueryBuilder('ma')
                    ->select('ma', 's')
                    ->leftJoin('ma.student', 's')
                    ->where('ma.mentor = :mentor')
                    ->setParameter('mentor', $mentorProfile)
                    ->orderBy('ma.scheduledAt', 'DESC')
                    ->setMaxResults(8)
                    ->getQuery()
                    ->getResult();
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

            // ── Mentor custom requests ──
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

            // ── Mentor applications ──
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

            return [
                'recentReservations' => $recentReservations,
                'mentorships'        => $mentorships,
                'mentorRequests'     => $mentorRequests,
                'mentorApplications' => $mentorApplications,
                'isMentor'           => $mentorProfile !== null,
            ];
        }, 60); // Cache for 1 minute

        $response = $this->json($data);
        $response->headers->set('Cache-Control', 'private, max-age=60');
        return $response;
    }
}
