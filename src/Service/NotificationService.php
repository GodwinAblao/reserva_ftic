<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;

class NotificationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationRepository $notificationRepo
    ) {}

    /**
     * Create a notification for a user
     */
    public function create(
        User $user,
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

        $this->em->persist($notification);
        $this->em->flush();

        return $notification;
    }

    /**
     * Notify user about mentor application submission
     */
    public function notifyMentorApplicationSubmitted(User $user, int $applicationId): void
    {
        $this->create(
            $user,
            'mentor',
            'Mentor Application Submitted',
            'Your mentor application has been submitted and is awaiting Super Admin review.',
            'Pending',
            $applicationId
        );
    }

    /**
     * Notify user about mentor application approval
     */
    public function notifyMentorApplicationApproved(User $user, int $applicationId, ?\DateTimeInterface $validUntil = null): void
    {
        $message = 'Congratulations! Your mentor application has been approved.';
        if ($validUntil) {
            $message .= ' Your mentorship is valid until ' . $validUntil->format('F j, Y') . '.';
        }

        $this->create(
            $user,
            'mentor',
            'Mentor Application Approved',
            $message,
            'Approved',
            $applicationId
        );
    }

    /**
     * Notify user about mentor application rejection
     */
    public function notifyMentorApplicationRejected(User $user, int $applicationId, ?string $reason = null): void
    {
        $message = 'Your mentor application has been rejected.';
        if ($reason) {
            $message .= ' Reason: ' . $reason;
        }

        $this->create(
            $user,
            'mentor',
            'Mentor Application Rejected',
            $message,
            'Rejected',
            $applicationId
        );
    }

    /**
     * Notify user about mentor profile creation by super admin
     */
    public function notifyMentorProfileCreated(User $user): void
    {
        $this->create(
            $user,
            'mentor',
            'Mentor Profile Created',
            'A Super Admin has created a mentor profile for you. You are now a mentor!',
            'Created'
        );
    }

    /**
     * Notify user about reservation submission
     */
    public function notifyReservationSubmitted(User $user, int $reservationId): void
    {
        $this->create(
            $user,
            'reservation',
            'Reservation Submitted',
            'Your facility reservation request has been submitted and is awaiting approval.',
            'Pending',
            $reservationId
        );
    }

    /**
     * Notify user about reservation approval
     */
    public function notifyReservationApproved(User $user, int $reservationId): void
    {
        $this->create(
            $user,
            'reservation',
            'Reservation Approved',
            'Your facility reservation has been approved!',
            'Approved',
            $reservationId
        );
    }

    /**
     * Notify user about reservation rejection
     */
    public function notifyReservationRejected(User $user, int $reservationId, ?string $reason = null): void
    {
        $message = 'Your facility reservation has been rejected.';
        if ($reason) {
            $message .= ' Reason: ' . $reason;
        }

        $this->create(
            $user,
            'reservation',
            'Reservation Rejected',
            $message,
            'Rejected',
            $reservationId
        );
    }

    /**
     * Notify user about mentor profile deletion
     */
    public function notifyMentorProfileDeleted(User $user): void
    {
        $this->create(
            $user,
            'mentor',
            'Mentor Profile Deleted',
            'Your mentor profile has been deleted by a Super Admin. You are no longer a mentor.',
            'Deleted'
        );
    }

    /**
     * Notify super admin about new mentor application
     */
    public function notifyAdminNewMentorApplication(User $admin, int $applicationId, string $applicantName): void
    {
        $this->create(
            $admin,
            'mentor',
            'New Mentor Application',
            'A new mentor application has been submitted by ' . $applicantName . '.',
            'Pending',
            $applicationId
        );
    }

    /**
     * Notify super admin about new reservation
     */
    public function notifyAdminNewReservation(User $admin, int $reservationId, string $requesterName): void
    {
        $this->create(
            $admin,
            'reservation',
            'New Reservation Request',
            'A new facility reservation request has been submitted by ' . $requesterName . '.',
            'Pending',
            $reservationId
        );
    }

    /**
     * Get unread count for a user
     */
    public function getUnreadCount(User $user): int
    {
        return $this->notificationRepo->getUnreadCount($user);
    }

    /**
     * Get latest notifications for a user
     */
    public function getLatest(User $user, int $limit = 20): array
    {
        return $this->notificationRepo->findLatest($user, $limit);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Notification $notification): void
    {
        $notification->setIsRead(true);
        $this->em->flush();
    }

    /**
     * Mark all notifications as read for a user
     */
    public function markAllAsRead(User $user): void
    {
        $notifications = $this->notificationRepo->findUnread($user);
        foreach ($notifications as $notification) {
            $notification->setIsRead(true);
        }
        $this->em->flush();
    }
}
