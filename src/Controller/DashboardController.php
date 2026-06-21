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
use Symfony\Contracts\Cache\CacheInterface;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(EntityManagerInterface $em, CacheInterface $cache): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $userId = $user->getId();
        
        // Super Admin → Superadmin Dashboard
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('admin_home');
        }
        
        // Regular Admin → Admin Dashboard
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_role_home');
        }

        // Check if user is mentor - single query
        $mentorProfile = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $user]);

        // Get all stats in ONE query using CASE statements
        $statsData = $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->select(
                'COUNT(r.id) as total',
                'SUM(CASE WHEN r.status = :pending THEN 1 ELSE 0 END) as pending',
                'SUM(CASE WHEN r.status = :approved THEN 1 ELSE 0 END) as approved',
                'SUM(CASE WHEN r.status = :rejected THEN 1 ELSE 0 END) as rejected',
                'SUM(CASE WHEN r.status = :cancelled THEN 1 ELSE 0 END) as cancelled'
            )
            ->where('r.user = :user')
            ->andWhere('r.status != :suggested')
            ->setParameter('user', $user)
            ->setParameter('suggested', 'Suggested')
            ->setParameter('pending', 'Pending')
            ->setParameter('approved', 'Approved')
            ->setParameter('rejected', 'Rejected')
            ->setParameter('cancelled', 'Cancelled')
            ->getQuery()
            ->getSingleResult();

        // Get mentoring counts in parallel queries with eager loading
        $mentoringAsStudent = $em->getRepository(MentoringAppointment::class)->createQueryBuilder('ma')
            ->select('ma', 'm')
            ->leftJoin('ma.mentor', 'm')
            ->where('ma.student = :user')
            ->setParameter('user', $user)
            ->orderBy('ma.scheduledAt', 'DESC')
            ->setMaxResults(6)
            ->getQuery()
            ->getResult();

        $mentoringAsMentor = [];
        if ($mentorProfile) {
            $mentoringAsMentor = $em->getRepository(MentoringAppointment::class)->createQueryBuilder('ma')
                ->select('ma', 's')
                ->leftJoin('ma.student', 's')
                ->where('ma.mentor = :mentor')
                ->setParameter('mentor', $mentorProfile)
                ->orderBy('ma.scheduledAt', 'DESC')
                ->setMaxResults(6)
                ->getQuery()
                ->getResult();
        }

        $stats = [
            'totalReservations' => (int) $statsData['total'],
            'pendingReservations' => (int) $statsData['pending'],
            'approvedReservations' => (int) $statsData['approved'],
            'rejectedReservations' => (int) $statsData['rejected'],
            'cancelledReservations' => (int) $statsData['cancelled'],
            'mentoringSessions' => count($mentoringAsStudent) + count($mentoringAsMentor),
            'unreadNotifications' => (int) $em->getRepository(Notification::class)->count(['user' => $user, 'isRead' => false]),
        ];

        // Use cached data for facilities (they don't change often)
        $facilities = $cache->get('dashboard_facilities_' . $userId, function() use ($em) {
            return $em->getRepository(Facility::class)->createQueryBuilder('f')
                ->select('f', 'fi')
                ->leftJoin('f.images', 'fi')
                ->where('f.availableForReservation = true')
                ->orderBy('f.id', 'ASC')
                ->getQuery()
                ->getResult();
        });

        // Cache research content
        $recentResearch = $cache->get('dashboard_research_' . $userId, function() use ($em) {
            return $em->getRepository(ResearchContent::class)->findBy(
                ['visibility' => 'Public'],
                ['createdAt' => 'DESC'],
                4
            );
        }, 300); // Cache for 5 minutes

        return $this->render('dashboard/index.html.twig', [
            'reservations' => [], // Loaded via AJAX API call
            'mentoringAsStudent' => $mentoringAsStudent,
            'mentoringAsMentor' => $mentoringAsMentor,
            'latestMentoringAppointment' => $mentoringAsStudent[0] ?? $mentoringAsMentor[0] ?? null,
            'latestMentorRequest' => null, // Loaded via AJAX
            'notifications' => [], // Loaded via AJAX
            'unreadCount' => $stats['unreadNotifications'],
            'stats' => $stats,
            'facilities' => $facilities,
            'recentResearch' => $recentResearch,
        ]);
    }
}
