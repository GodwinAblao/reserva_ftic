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
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $notifications = $notificationRepo->findLatest($user, 20);
        
        $data = array_map(function(Notification $n) {
            // compute a link appropriate to the notification and the current user's roles
            $link = '#';
            if ($n->getType() === 'mentor_assistance' && $this->isGranted('ROLE_ADMIN')) {
                $link = $this->generateUrl('mentoring_admin_requests');
            } elseif (str_starts_with($n->getType(), 'mentor')) {
                if ($this->isGranted('ROLE_ADMIN')) {
                    $link = $this->generateUrl('mentoring_super-admin');
                } else {
                    $link = $this->generateUrl('mentoring_index');
                }
            } elseif ($n->getType() === 'reservation') {
                if ($this->isGranted('ROLE_ADMIN')) {
                    $link = $this->generateUrl('admin_reservations');
                } else {
                    $link = $this->generateUrl('user_reservations');
                }
            }

            return [
                'id' => $n->getId(),
                'type' => $n->getType(),
                'title' => $n->getTitle(),
                'message' => $n->getMessage(),
                'status' => $n->getStatus(),
                'isRead' => $n->isIsRead(),
                'referenceId' => $n->getReferenceId(),
                'createdAt' => $n->getCreatedAt()->format('c'),
                'link' => $link,
            ];
        }, $notifications);

        return $this->json([
            'notifications' => $data,
            'unreadCount' => $notificationRepo->getUnreadCount($user),
        ]);
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
        EntityManagerInterface $em,
        NotificationRepository $notificationRepo
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $notifications = $notificationRepo->findUnread($user);
        foreach ($notifications as $notification) {
            $notification->setIsRead(true);
        }

        $em->flush();

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
}
