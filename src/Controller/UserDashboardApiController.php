<?php

declare(strict_types=1);

namespace App\Controller;

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
}
