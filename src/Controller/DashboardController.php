<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Facility;
use App\Entity\MentorCustomRequest;
use App\Entity\MentorProfile;
use App\Entity\MentoringAppointment;
use App\Entity\Notification;
use App\Entity\Reservation;
use App\Entity\ResearchContent;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        
        // Super Admin → Superadmin Dashboard
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('admin_home');
        }
        
        // Regular Admin → Admin Dashboard
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_role_home');
        }

        $mentorProfile = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $user]);

        // Load user dashboard data
        $reservations = $em->getRepository(Reservation::class)->findBy(
            ['user' => $user],
            ['updatedAt' => 'DESC'],
            6
        );
        
        $mentoringAsStudent = $em->getRepository(MentoringAppointment::class)->findBy(
            ['student' => $user],
            ['scheduledAt' => 'DESC'],
            6
        );
        
        $mentoringAsMentor = [];
        if ($mentorProfile) {
            $mentoringAsMentor = $em->getRepository(MentoringAppointment::class)->findBy(
                ['mentor' => $mentorProfile],
                ['scheduledAt' => 'DESC'],
                6
            );
        }

        $latestMentoringAppointment = null;
        foreach (array_merge($mentoringAsStudent, $mentoringAsMentor) as $appointment) {
            if ($latestMentoringAppointment === null || $appointment->getScheduledAt() > $latestMentoringAppointment->getScheduledAt()) {
                $latestMentoringAppointment = $appointment;
            }
        }

        $latestMentorRequest = $em->getRepository(MentorCustomRequest::class)->findOneBy(
            ['student' => $user],
            ['updatedAt' => 'DESC', 'createdAt' => 'DESC']
        );
        
        $notifications = $em->getRepository(Notification::class)->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC'],
            8
        );

        $facilities = $em->getRepository(Facility::class)->findBy(
            ['availableForReservation' => true],
            ['id' => 'ASC'],
            3
        );

        $latestResearchContent = $em->getRepository(ResearchContent::class)->findOneBy(
            ['visibility' => 'Public'],
            ['createdAt' => 'DESC']
        );
        
        $unreadCount = $em->getRepository(Notification::class)->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.isRead = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        // Stats
        $stats = [
            'totalReservations' => $em->getRepository(Reservation::class)->count(['user' => $user]),
            'pendingReservations' => $em->getRepository(Reservation::class)->count(['user' => $user, 'status' => 'Pending']),
            'approvedReservations' => $em->getRepository(Reservation::class)->count(['user' => $user, 'status' => 'Approved']),
            'mentoringSessions' => count($mentoringAsStudent) + count($mentoringAsMentor),
            'unreadNotifications' => (int) $unreadCount,
        ];

        return $this->render('dashboard/index.html.twig', [
            'reservations' => $reservations,
            'mentoringAsStudent' => $mentoringAsStudent,
            'mentoringAsMentor' => $mentoringAsMentor,
            'latestMentoringAppointment' => $latestMentoringAppointment,
            'latestMentorRequest' => $latestMentorRequest,
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
            'stats' => $stats,
            'facilities' => $facilities,
            'latestResearchContent' => $latestResearchContent,
        ]);
    }
}
