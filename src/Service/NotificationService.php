<?php

namespace App\Service;

use App\Entity\MentorApplication;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class NotificationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationRepository $notificationRepo,
        private MailerInterface $mailer,
        private Environment $twig,
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
        ?int $referenceId = null,
        bool $flush = true
    ): Notification {
        error_log('DEBUG: Creating notification for user ' . $user->getId() . ' (' . $user->getEmail() . ') - Type: ' . $type . ', Title: ' . $title);
        
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
        if ($flush) {
            try {
                $this->em->flush();
                error_log('DEBUG: Notification flushed to database with ID: ' . $notification->getId());
            } catch (\Exception $e) {
                error_log('DEBUG: Failed to flush notification: ' . $e->getMessage());
                throw $e;
            }
        }

        error_log('DEBUG: Notification created successfully with ID: ' . $notification->getId());
        return $notification;
    }

    /**
     * Notify user about mentor application submission
     */
    public function notifyMentorApplicationSubmitted(User $user, int $applicationId, bool $flush = true): void
    {
        // Create in-app notification
        $this->create(
            $user,
            'mentor',
            'Mentor Application Submitted',
            'Your mentor application has been submitted and is awaiting Super Admin review.',
            'Pending',
            $applicationId,
            $flush
        );

        // Send email notification asynchronously (non-blocking)
        // Email sending is deferred to prevent blocking the response
        register_shutdown_function(function() use ($user, $applicationId) {
            try {
                $this->sendMentorApplicationEmail($user, $applicationId, 'submitted');
            } catch (\Exception $e) {
                error_log('Failed to send mentor application email (async): ' . $e->getMessage());
            }
        });
    }

    /**
     * Notify user about mentor application approval
     */
    public function notifyMentorApplicationApproved(User $user, int $applicationId): void
    {
        // Create in-app notification
        $this->create(
            $user,
            'mentor',
            'Mentor Application Approved',
            'Congratulations! Your mentor application has been approved. You can now start mentoring students.',
            'Approved',
            $applicationId
        );

        // Send email notification
        $this->sendMentorApplicationEmail($user, $applicationId, 'approved');
    }

    /**
     * Notify user about mentor application rejection
     */
    public function notifyMentorApplicationRejected(User $user, int $applicationId, ?string $reason = null): void
    {
        // Create in-app notification
        $this->create(
            $user,
            'mentor',
            'Mentor Application Rejected',
            $reason ? 'Your mentor application was not approved. Reason: ' . $reason : 'Your mentor application was not approved.',
            'Rejected',
            $applicationId
        );

        // Send email notification
        $this->sendMentorApplicationEmail($user, $applicationId, 'rejected', $reason);
    }

    /**
     * Send mentor application status email to user
     */
    private function sendMentorApplicationEmail(User $user, int $applicationId, string $status, ?string $reason = null): void
    {
        $email = $user->getEmail();
        if (!$email) {
            return;
        }

        $subject = match ($status) {
            'submitted' => 'Mentor Application Submitted - Reserva FTIC',
            'approved' => 'Mentor Application Approved - Reserva FTIC',
            'rejected' => 'Mentor Application Update - Reserva FTIC',
            'admin_notification' => 'New Mentor Application - Reserva FTIC',
            default => 'Mentor Application Update - Reserva FTIC',
        };

        $emailBody = $this->twig->render('emails/mentor_application_status.html.twig', [
            'user' => $user,
            'applicationId' => $applicationId,
            'status' => $status,
            'reason' => $reason,
        ]);

        $email = (new Email())
            ->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))
            ->to(new Address($email, $user->getFirstName() . ' ' . $user->getLastName()))
            ->subject($subject)
            ->html($emailBody);

        try {
            $this->mailer->send($email);
        } catch (\Exception $e) {
            // Log error but don't throw to prevent breaking the flow
            error_log('Failed to send mentor application email: ' . $e->getMessage());
        }
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
    public function notifyAdminNewMentorApplication(User $admin, int $applicationId, string $applicantName): Notification
    {
        // Create in-app notification
        $notification = $this->create(
            $admin,
            'mentor',
            'New Mentor Application',
            'A new mentor application has been submitted by ' . $applicantName . '.',
            'Pending',
            $applicationId
        );

        // Send email notification to admin immediately
        try {
            $this->sendMentorApplicationEmail($admin, $applicationId, 'admin_notification', $applicantName);
            error_log('EMAIL: Successfully sent mentor application email to admin: ' . $admin->getEmail());
        } catch (\Exception $e) {
            error_log('EMAIL: FAILED to send mentor application email to admin ' . $admin->getEmail() . ': ' . $e->getMessage());
        }

        return $notification;
    }

    public function notifyAdminNewMentorAssistanceRequest(User $admin, int $requestId, string $requesterName): Notification
    {
        return $this->create(
            $admin,
            'mentor_assistance',
            'New Mentor Assistance Request',
            $requesterName . ' requested help finding a mentor.',
            'Pending',
            $requestId
        );
    }

    public function notifyAdminWithEmail(
        User $admin,
        string $type,
        string $title,
        string $message,
        string $status,
        ?int $referenceId = null
    ): Notification {
        $notification = $this->create($admin, $type, $title, $message, $status, $referenceId);
        $this->sendAdminNotificationEmail($admin, $title . ' - Reserva FTIC', $title, $message);

        return $notification;
    }

    public function notifyAdminMentorRequestUpdated(User $adminUser, int $requestId, string $updatedByName, string $newStatus, string $requesterName): void
    {
        $message = $updatedByName . ' updated the request from ' . $requesterName . ' -> Status: ' . $newStatus;

        $this->create(
            $adminUser,
            'mentor_request_updated',
            'Mentor Request Updated',
            $message,
            $newStatus,
            $requestId
        );

        $this->sendAdminNotificationEmail(
            $adminUser,
            'Mentor Request Updated - Reserva FTIC',
            'Mentor Request Updated',
            $message
        );
    }

    public function notifyMentorAssistanceStatus(User $user, int $requestId, string $status, string $message): Notification
    {
        return $this->create(
            $user,
            'mentor_assistance',
            'Mentor Request ' . $status,
            $message,
            $status,
            $requestId
        );
    }

    /**
     * Notify super admin about new reservation
     */
    public function notifyAdminNewReservation(User $admin, int $reservationId, string $requesterName): void
    {
        $message = 'A new facility reservation request has been submitted by ' . $requesterName . '.';

        $this->create(
            $admin,
            'reservation',
            'New Reservation Request',
            $message,
            'Pending',
            $reservationId
        );

        $this->sendAdminNotificationEmail(
            $admin,
            'New Facility Reservation Request - Reserva FTIC',
            'New Reservation Request',
            $message
        );
    }

    private function sendAdminNotificationEmail(User $admin, string $subject, string $title, string $message): void
    {
        $to = trim($admin->getEmail());
        if ($to === '') {
            return;
        }

        $displayName = trim(($admin->getFirstName() ?? '') . ' ' . ($admin->getLastName() ?? '')) ?: $to;
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $reason = null;
        $mainMessage = $message;
        if (str_contains($message, ' Reason: ')) {
            [$mainMessage, $reason] = explode(' Reason: ', $message, 2);
        }

        $safeMessage = nl2br(htmlspecialchars($mainMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $reasonHtml = '';
        if ($reason !== null && trim($reason) !== '') {
            $safeReason = nl2br(htmlspecialchars(trim($reason), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            $reasonHtml = '<div style="margin:0 0 18px;padding:14px 16px;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:8px;background:#fef2f2;">'
                . '<div style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#991b1b;margin-bottom:6px;">Cancellation Reason</div>'
                . '<div style="font-size:14px;line-height:1.5;color:#b91c1c;font-weight:700;">' . $safeReason . '</div>'
                . '</div>';
        }

        try {
            $email = (new Email())
                ->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))
                ->to(new Address($to, $displayName))
                ->subject($subject)
                ->html(
                    '<div style="font-family:Arial,sans-serif;max-width:620px;margin:0 auto;color:#1f2937;">'
                    . '<div style="background:#006633;color:#fff;padding:18px 22px;border-radius:8px 8px 0 0;">'
                    . '<h1 style="font-size:20px;margin:0;">' . $safeTitle . '</h1>'
                    . '</div>'
                    . '<div style="border:1px solid #d1d5db;border-top:0;padding:22px;border-radius:0 0 8px 8px;background:#fff;">'
                    . '<p style="font-size:15px;line-height:1.5;margin:0 0 18px;">' . $safeMessage . '</p>'
                    . $reasonHtml
                    . '<p style="font-size:13px;color:#6b7280;margin:0;">Please sign in to Reserva FTIC to review the full details.</p>'
                    . '</div>'
                    . '</div>'
                );

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            error_log('Failed to send admin notification email to ' . $to . ': ' . $e->getMessage());
        }
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
