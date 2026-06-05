<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/notifications')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class NotificationController extends AbstractController
{
    /**
     * Fetch notifications for the current user
     */
    #[Route('', name: 'api_notifications_list', methods: ['GET'])]
    public function list(NotificationRepository $notificationRepo): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            error_log('DEBUG: Notification API called by unauthenticated user');
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $userId = $user instanceof \App\Entity\User ? $user->getId() : 'unknown';
        $userEmail = $user instanceof \App\Entity\User ? $user->getEmail() : 'unknown';
        $userRoles = $user instanceof \App\Entity\User ? implode(',', $user->getRoles()) : 'unknown';
        
        error_log('DEBUG: Notification API called by user ' . $userId . ' (' . $userEmail . ') with roles: ' . $userRoles);

        $notifications = $notificationRepo->findLatest($user, 20);
        error_log('DEBUG: Found ' . count($notifications) . ' notifications for user ' . $userId);

        $data = [];
        foreach ($notifications as $n) {
            $data[] = [
                'id' => $n->getId(),
                'type' => $n->getType(),
                'title' => $n->getTitle(),
                'message' => $n->getMessage(),
                'status' => $n->getStatus(),
                'isRead' => $n->isIsRead(),
                'referenceId' => $n->getReferenceId(),
                'createdAt' => $n->getCreatedAt()->format('c'),
                'link' => $this->resolveNotificationLink($n),
            ];
        }

        $unreadCount = $notificationRepo->getUnreadCount($user);
        error_log('DEBUG: Unread count for user ' . $userId . ': ' . $unreadCount);

        $response = $this->json([
            'notifications' => $data,
            'unreadCount' => $unreadCount,
        ]);
        $response->headers->set('Cache-Control', 'private, max-age=60');
        return $response;
    }

    /**
     * Lightweight poll endpoint — returns only unreadCount + newestId.
     * Used for background polling; costs a single indexed COUNT query.
     */
    #[Route('/poll', name: 'api_notifications_poll', methods: ['GET'])]
    public function poll(NotificationRepository $notificationRepo): JsonResponse
    {
        try {
            $user = $this->getUser();
            if (!$user) {
                error_log('DEBUG: Notification poll API called by unauthenticated user');
                return $this->json(['error' => 'Unauthorized', 'unreadCount' => 0, 'newestId' => 0], 401);
            }

            $userId = $user instanceof \App\Entity\User ? $user->getId() : 'unknown';
            $userEmail = $user instanceof \App\Entity\User ? $user->getEmail() : 'unknown';
            
            // Ensure user has an ID before querying
            if ($userId === 'unknown' || $userId === null) {
                error_log('DEBUG: Notification poll - user has no ID');
                return $this->json(['error' => 'Invalid user', 'unreadCount' => 0, 'newestId' => 0], 400);
            }

            $pollData = $notificationRepo->getPollData($user);

            $response = $this->json($pollData);
            $response->headers->set('Cache-Control', 'private, no-store');
            return $response;
        } catch (\Exception $e) {
            error_log('ERROR in NotificationController::poll: ' . $e->getMessage());
            return $this->json(['error' => 'Server error', 'unreadCount' => 0, 'newestId' => 0], 500);
        }
    }

    /**
     * Get unread notification count
     */
    #[Route('/unread-count', name: 'api_notifications_unread_count', methods: ['GET'])]
    public function unreadCount(NotificationRepository $notificationRepo): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        return $this->json([
            'unreadCount' => $notificationRepo->getUnreadCount($user),
        ]);
    }

    /**
     * Mark notification as read
     */
    #[Route('/{id}/read', name: 'api_notifications_mark_read', methods: ['POST'])]
    public function markAsRead(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        NotificationRepository $notificationRepo
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $notification = $notificationRepo->find($id);
        if (!$notification) {
            return $this->json(['error' => 'Notification not found'], 404);
        }

        // Verify ownership
        if ($notification->getUser() !== $user) {
            return $this->json(['error' => 'Access denied'], 403);
        }

        $notification->setIsRead(true);
        $em->flush();

        return $this->json([
            'success' => true,
            'unreadCount' => $notificationRepo->getUnreadCount($user),
        ]);
    }

    /**
     * Mark all notifications as read
     */
    #[Route('/read-all', name: 'api_notifications_mark_all_read', methods: ['POST'])]
    public function markAllAsRead(
        NotificationRepository $notificationRepo
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $notificationRepo->markAllReadForUser($user);

        return $this->json([
            'success' => true,
            'unreadCount' => 0,
        ]);
    }

    /**
     * Create notification (helper method - used internally)
     */
    public static function createNotification(
        EntityManagerInterface $em,
        $user,
        string $type,
        string $title,
        string $message,
        string $status,
        ?int $referenceId = null
    ): Notification {
        $notification = new Notification();
        $notification->setUser($user);
        $notification->setType($type);
        $notification->setTitle($title);
        $notification->setMessage($message);
        $notification->setStatus($status);
        $notification->setReferenceId($referenceId);
        $notification->setIsRead(false);
        $notification->setCreatedAt(new \DateTime());

        $em->persist($notification);
        $em->flush();

        return $notification;
    }

    /**
     * Route each notification to the correct area for the current access level.
     * Super Admin and Admin both have ROLE_ADMIN — check ROLE_SUPER_ADMIN first.
     */
    private function resolveNotificationLink(Notification $n): string
    {
        if (in_array($n->getType(), ['mentor_assistance', 'mentor_request_updated'], true) && $this->isGranted('ROLE_ADMIN')) {
            if ($this->isGranted('ROLE_SUPER_ADMIN')) {
                return $this->generateUrl('mentoring_superadmin_requests');
            }

            return $this->generateUrl('admin_role_mentorship_coordination');
        }

        if (str_starts_with($n->getType(), 'mentor')) {
            if ($this->isGranted('ROLE_SUPER_ADMIN')) {
                return $this->generateUrl('mentoring_super-admin');
            }
            if ($this->isGranted('ROLE_ADMIN')) {
                return $this->generateUrl('admin_role_mentorship_coordination');
            }

            return $this->generateUrl('mentoring_index');
        }

        if ($n->getType() === 'reservation') {
            if ($this->isGranted('ROLE_SUPER_ADMIN')) {
                return $this->generateUrl('admin_reservations');
            }
            if ($this->isGranted('ROLE_ADMIN')) {
                return $this->generateUrl('admin_role_reservation_monitoring');
            }

            return $this->generateUrl('user_reservations');
        }

        if ($n->getType() === 'class_schedule') {
            if ($this->isGranted('ROLE_SUPER_ADMIN')) {
                return $this->generateUrl('admin_calendar');
            }
            if ($this->isGranted('ROLE_ADMIN')) {
                return $this->generateUrl('admin_role_calendar');
            }

            return $this->generateUrl('app_dashboard');
        }

        return '#';
    }
}
