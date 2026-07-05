<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Message;
use App\Entity\Notification;
use App\Entity\Reservation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Psr\Log\LoggerInterface;

class ReservationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notifyPending(Reservation $reservation): void
    {
        $user = $reservation->getUser();
        if (!$user) {
            return;
        }

        $this->sendUserEmail($reservation, $user, 'Pending',
            'Your reservation request has been received and is currently under review. We will notify you once it has been processed.',
        );

        $this->createUserNotification($reservation, $user, 'Pending',
            'Reservation Request Received',
            sprintf('Your reservation for %s on %s is pending approval.',
                $reservation->getFacility()?->getName() ?? 'a facility',
                $reservation->getReservationDate()?->format('F j, Y') ?? ''
            ),
        );
    }

    public function notifyApproved(Reservation $reservation, ?string $extraMessage = null): void
    {
        $user = $reservation->getUser();
        if (!$user) {
            return;
        }

        $this->sendUserEmail($reservation, $user, 'Approved',
            'Great news! Your facility reservation has been approved. Please review the details below.',
            $extraMessage,
        );

        $notificationMessage = sprintf('Your reservation for %s on %s has been approved.',
            $reservation->getFacility()?->getName() ?? 'a facility',
            $reservation->getReservationDate()?->format('F j, Y') ?? ''
        );
        if ($extraMessage) {
            $notificationMessage .= ' Note: ' . $extraMessage;
        }

        $this->createUserNotification($reservation, $user, 'Approved',
            'Reservation Approved',
            $notificationMessage,
        );
    }

    public function notifyRejected(Reservation $reservation, ?string $extraMessage = null): void
    {
        $user = $reservation->getUser();
        if (!$user) {
            return;
        }

        $reason = $reservation->getRejectionReason() ?: 'No reason provided.';

        $this->sendUserEmail($reservation, $user, 'Rejected',
            'We regret to inform you that your facility reservation request has been rejected.',
            trim('Reason: ' . $reason . ($extraMessage ? "\n\n" . $extraMessage : '')),
        );

        $notificationMessage = sprintf('Your reservation for %s on %s was rejected. Reason: %s',
            $reservation->getFacility()?->getName() ?? 'a facility',
            $reservation->getReservationDate()?->format('F j, Y') ?? '',
            $reason
        );
        if ($extraMessage) {
            $notificationMessage .= ' Note: ' . $extraMessage;
        }

        $this->createUserNotification($reservation, $user, 'Rejected',
            'Reservation Rejected',
            $notificationMessage,
        );
    }

    public function notifyCancelled(Reservation $reservation, ?string $extraMessage = null): void
    {
        $user = $reservation->getUser();
        if (!$user) {
            return;
        }

        $this->sendUserEmail($reservation, $user, 'Cancelled',
            'Your facility reservation has been cancelled.',
            $extraMessage,
        );

        $notificationMessage = sprintf('Your reservation for %s on %s has been cancelled.',
            $reservation->getFacility()?->getName() ?? 'a facility',
            $reservation->getReservationDate()?->format('F j, Y') ?? ''
        );
        if ($extraMessage) {
            $notificationMessage .= ' Note: ' . $extraMessage;
        }

        $this->createUserNotification($reservation, $user, 'Cancelled',
            'Reservation Cancelled',
            $notificationMessage,
        );
    }

    public function notifyUpdated(Reservation $reservation, ?string $extraMessage = null): void
    {
        $user = $reservation->getUser();
        if (!$user) {
            return;
        }

        $this->sendUserEmail($reservation, $user, 'Updated',
            'Your facility reservation request has been updated by the administrator. Please review the latest details below.',
            $extraMessage,
        );

        $notificationMessage = sprintf('Your reservation for %s on %s has been updated.',
            $reservation->getFacility()?->getName() ?? 'a facility',
            $reservation->getReservationDate()?->format('F j, Y') ?? ''
        );
        if ($extraMessage) {
            $notificationMessage .= ' Note: ' . $extraMessage;
        }

        $this->createUserNotification($reservation, $user, 'Updated',
            'Reservation Updated',
            $notificationMessage,
        );
    }

    public function notifySuggested(Reservation $reservation): void
    {
        $user = $reservation->getUser();
        if (!$user) {
            return;
        }

        $this->sendUserEmail($reservation, $user, 'Suggested',
            'The admin has suggested an alternative facility or schedule for your reservation. Please log in to review and accept or decline the suggestion.',
            'Log in to your account to view and respond to this suggestion.',
        );

        $this->createUserNotification($reservation, $user, 'Suggested',
            'Alternative Facility Suggested',
            sprintf('An alternative has been suggested for your reservation at %s. Please review and respond.',
                $reservation->getFacility()?->getName() ?? 'a facility'
            ),
        );
    }

    public function notifySuggestionAccepted(Reservation $reservation): void
    {
        $user = $reservation->getUser();
        if (!$user) {
            return;
        }

        $this->sendUserEmail($reservation, $user, 'Approved',
            'You have accepted the suggested alternative. Your reservation is now confirmed with the updated details.',
        );

        $this->createUserNotification($reservation, $user, 'Approved',
            'Suggestion Accepted – Reservation Confirmed',
            sprintf('You accepted the suggestion. Your reservation for %s on %s is now confirmed.',
                $reservation->getFacility()?->getName() ?? 'a facility',
                $reservation->getReservationDate()?->format('F j, Y') ?? ''
            ),
        );
    }

    public function notifySuggestionDeclined(Reservation $reservation): void
    {
        $user = $reservation->getUser();
        if (!$user) {
            return;
        }

        $this->sendUserEmail($reservation, $user, 'Approved',
            'You have declined the suggestion. Your original facility reservation is now confirmed.',
        );

        $this->createUserNotification($reservation, $user, 'Approved',
            'Suggestion Declined – Original Reservation Confirmed',
            sprintf('You declined the suggestion. Your original reservation for %s on %s is confirmed.',
                $reservation->getFacility()?->getName() ?? 'a facility',
                $reservation->getReservationDate()?->format('F j, Y') ?? ''
            ),
        );
    }

    public function notifyExpired(Reservation $reservation): void
    {
        $to = $reservation->getEmail();
        if (!$to) {
            return;
        }

        $facilityName = $reservation->getFacility()?->getName() ?? 'the requested facility';
        $date         = $reservation->getReservationDate()?->format('F j, Y') ?? 'the scheduled date';
        $user         = $reservation->getUser();
        $name         = $user?->getFirstName() ?: $reservation->getName();

        $html = '<div style="font-family:sans-serif;max-width:600px;margin:auto;padding:24px;">'
            . '<div style="background:#fff7ed;border-left:5px solid #f59e0b;border-radius:8px;padding:16px 20px;margin-bottom:24px;">'
            . '<p style="margin:0;font-size:15px;font-weight:700;color:#92400e;">Reservation Expired – No Response Received</p>'
            . '</div>'
            . '<p>Dear <strong>' . htmlspecialchars($name) . '</strong>,</p>'
            . '<p>We regret to inform you that your reservation request for <strong>' . htmlspecialchars($facilityName) . '</strong> '
            . 'scheduled on <strong>' . htmlspecialchars($date) . '</strong> has been <strong>automatically cancelled</strong>.</p>'
            . '<p>Your request remained <strong>Pending</strong> and the scheduled date has now passed without an admin response. '
            . 'As a result, the system has automatically closed this reservation.</p>'
            . '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-left:5px solid #0d9b00;border-radius:8px;padding:16px 20px;margin:20px 0;">'
            . '<p style="margin:0;font-size:14px;color:#374151;">If you still need a facility reservation, please submit a new request through the Reserva FTIC system.</p>'
            . '</div>'
            . '<p style="color:#6b7280;font-size:13px;margin-top:24px;">This is an automated message. Please do not reply directly to this email.</p>'
            . '</div>';

        try {
            $email = (new Email())
                ->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))
                ->to($to)
                ->subject('Reservation Expired – No Response Received – Reserva FTIC')
                ->html($html);

            $this->mailer->send($email);
            $this->logger->info('Expired reservation email sent', ['to' => $to, 'reservation_id' => $reservation->getId()]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send expired reservation email', ['to' => $to, 'error' => $e->getMessage()]);
        }

        if ($user) {
            $this->createUserNotification(
                $reservation,
                $user,
                'Cancelled',
                'Reservation Expired – Automatically Cancelled',
                sprintf('Your reservation for %s on %s was automatically cancelled because it passed its scheduled date without a response.', $facilityName, $date),
            );
        }
    }

    private function sendUserEmail(Reservation $reservation, User $user, string $status, string $message, ?string $extraMessage = null): void
    {
        $to = $reservation->getEmail() ?: $user->getEmail();
        if (!$to) {
            return;
        }

        $name = $user->getFirstName() ?: $user->getEmail();

        $subjects = [
            'Pending'   => 'Reservation Request Received – Reserva FTIC',
            'Approved'  => 'Reservation Approved – Reserva FTIC',
            'Rejected'  => 'Reservation Update: Request Rejected – Reserva FTIC',
            'Cancelled' => 'Reservation Cancelled – Reserva FTIC',
            'Suggested' => 'Alternative Suggested for Your Reservation – Reserva FTIC',
            'Updated'   => 'Reservation Updated – Reserva FTIC',
        ];

        try {
            $html = $this->twig->render('email/reservation_status.html.twig', [
                'reservation'   => $reservation,
                'name'          => $name,
                'status'        => $status,
                'message'       => $message,
                'extra_message' => $extraMessage,
            ]);

            $email = (new Email())
                ->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))
                ->replyTo('hurstdale101@gmail.com')
                ->to($to)
                ->subject($subjects[$status] ?? 'Reservation Update – Reserva FTIC')
                ->html($html);

            $this->mailer->send($email);
            $this->logger->info('Reservation status email sent', ['to' => $to, 'status' => $status]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send reservation status email', ['to' => $to, 'status' => $status, 'error' => $e->getMessage()]);
        }
    }

    public function notifyAdminApprovedToSuperadmin(Reservation $reservation, string $adminName, string $adminEmail): void
    {
        $allUsers = $this->em->getRepository(User::class)->findAll();
        $superadminEmails = [];
        foreach ($allUsers as $u) {
            if (in_array('ROLE_SUPER_ADMIN', $u->getRoles(), true)) {
                $email = $u->getEmail();
                if ($email) {
                    $superadminEmails[] = $email;
                }
            }
        }

        if (empty($superadminEmails)) {
            $this->logger->warning('No superadmin email found to notify of admin approval.');
            return;
        }

        $facilityName = $reservation->getFacility()?->getName() ?? 'N/A';
        $date         = $reservation->getReservationDate()?->format('F j, Y') ?? 'N/A';
        $startTime    = $reservation->getReservationStartTime()?->format('h:i A') ?? '';
        $endTime      = $reservation->getReservationEndTime()?->format('h:i A') ?? '';
        $requester    = $reservation->getName();
        $event        = $reservation->getEventName() ?: 'N/A';

        $html = '<div style="font-family:sans-serif;max-width:600px;margin:auto;padding:24px;">'
            . '<h2 style="color:#f59e0b;">&#9888; Admin Approval Notification</h2>'
            . '<p>The admin <strong>' . htmlspecialchars($adminName) . '</strong> ('
            . htmlspecialchars($adminEmail) . ') has <strong style="color:#f59e0b;">approved</strong> '
            . 'a facility reservation and recommends it for your final approval.</p>'
            . '<table style="width:100%;border-collapse:collapse;margin-top:16px;">'
            . '<tr><td style="padding:8px;font-weight:bold;color:#6b7280;width:40%;">Reservation ID</td><td style="padding:8px;">#' . $reservation->getId() . '</td></tr>'
            . '<tr style="background:#f9fafb;"><td style="padding:8px;font-weight:bold;color:#6b7280;">Facility</td><td style="padding:8px;">' . htmlspecialchars($facilityName) . '</td></tr>'
            . '<tr><td style="padding:8px;font-weight:bold;color:#6b7280;">Requester</td><td style="padding:8px;">' . htmlspecialchars($requester) . '</td></tr>'
            . '<tr style="background:#f9fafb;"><td style="padding:8px;font-weight:bold;color:#6b7280;">Event</td><td style="padding:8px;">' . htmlspecialchars($event) . '</td></tr>'
            . '<tr><td style="padding:8px;font-weight:bold;color:#6b7280;">Date</td><td style="padding:8px;">' . htmlspecialchars($date) . '</td></tr>'
            . '<tr style="background:#f9fafb;"><td style="padding:8px;font-weight:bold;color:#6b7280;">Time</td><td style="padding:8px;">' . htmlspecialchars($startTime . ' – ' . $endTime) . '</td></tr>'
            . '</table>'
            . '<p style="margin-top:20px;color:#6b7280;">Please log in to the Reserva FTIC system to take final action on this reservation.</p>'
            . '</div>';

        foreach ($superadminEmails as $to) {
            try {
                $email = (new Email())
                    ->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))
                    ->to($to)
                    ->subject('Admin Approved Reservation #' . $reservation->getId() . ' – Action Required')
                    ->html($html);
                $this->mailer->send($email);
                $this->logger->info('Admin-approved notification sent to superadmin', ['to' => $to]);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send admin-approved notification to superadmin', ['to' => $to, 'error' => $e->getMessage()]);
            }
        }
    }

    public function notifyAdminNotesToUser(Reservation $reservation, string $notes, array $files, string $adminEmail, string $adminName): void
    {
        $user = $reservation->getUser();
        $to   = $reservation->getEmail() ?: ($user?->getEmail() ?? '');
        if (!$to) {
            $this->logger->warning('No recipient email for admin notes notification.', ['reservation_id' => $reservation->getId()]);
            return;
        }

        $facilityName = $reservation->getFacility()?->getName() ?? 'N/A';
        $date         = $reservation->getReservationDate()?->format('F j, Y') ?? 'N/A';
        $userName     = $user?->getFirstName() ?: $reservation->getName();

        $html = '<div style="font-family:sans-serif;max-width:600px;margin:auto;padding:24px;">'
            . '<h2 style="color:#0d9b00;">Message from the Admin – ' . htmlspecialchars($facilityName) . ' Reservation</h2>'
            . '<p>Dear <strong>' . htmlspecialchars($userName) . '</strong>,</p>'
            . '<p>The admin has sent you the following notes regarding your reservation for '
            . '<strong>' . htmlspecialchars($facilityName) . '</strong> on <strong>' . htmlspecialchars($date) . '</strong>:</p>'
            . '<blockquote style="border-left:4px solid #0d9b00;margin:16px 0;padding:12px 16px;background:#f0fdf4;color:#374151;">'
            . nl2br(htmlspecialchars($notes ?: '(No notes provided)'))
            . '</blockquote>'
            . (!empty($files) ? '<p>Please see the attached file(s) for additional information.</p>' : '')
            . '<hr style="margin:24px 0;border:none;border-top:1px solid #e5e7eb;">'
            . '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-left:5px solid #0d9b00;border-radius:8px;padding:16px 20px;margin:0 0 8px;">'
            . '<p style="margin:0;font-size:14px;color:#374151;line-height:1.65;">'
            . 'Please submit the required additional documents to: '
            . '<a href="mailto:' . htmlspecialchars($adminEmail) . '" style="color:#0d9b00;font-weight:700;text-decoration:none;">' . htmlspecialchars($adminEmail) . '</a>.'
            . '</p>'
            . '</div>'
            . '</div>';

        try {
            $email = (new Email())
                ->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))
                ->replyTo($adminEmail ?: 'noreply@fticreserva.website')
                ->to($to)
                ->subject('Note from Admin Regarding Your Reservation – Reserva FTIC')
                ->html($html);

            foreach ($files as $file) {
                if (!$file instanceof UploadedFile) {
                    continue;
                }
                $email->attachFromPath(
                    $file->getRealPath(),
                    $file->getClientOriginalName(),
                    $file->getMimeType() ?? 'application/octet-stream',
                );
            }

            $this->mailer->send($email);
            $this->logger->info('Admin notes email sent to user', ['to' => $to, 'reservation_id' => $reservation->getId()]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send admin notes email to user', ['to' => $to, 'error' => $e->getMessage()]);
        }
    }

    private function createUserNotification(Reservation $reservation, User $user, string $status, string $title, string $message): void
    {
        try {
            $notification = new Notification();
            $notification->setUser($user);
            $notification->setType('reservation');
            $notification->setTitle($title);
            $notification->setMessage($message);
            $notification->setStatus($status);
            $notification->setReferenceId($reservation->getId());
            $notification->setIsRead(false);
            $notification->setCreatedAt(new \DateTime());

            $this->em->persist($notification);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create user notification', ['error' => $e->getMessage()]);
        }
    }

    public function notifyInboxMessage(Message $message): void
    {
        $recipient = $message->getRecipient();
        $sender    = $message->getSender();

        if (!$recipient || !$recipient->getEmail()) {
            return;
        }

        $to            = $recipient->getEmail();
        $recipientName = trim(($recipient->getFirstName() ?? '') . ' ' . ($recipient->getLastName() ?? '')) ?: $to;
        $senderName    = trim(($sender?->getFirstName() ?? '') . ' ' . ($sender?->getLastName() ?? '')) ?: ($sender?->getEmail() ?? 'Someone');
        $senderEmail   = $sender?->getEmail() ?? 'noreply@fticreserva.website';
        $isReply       = str_starts_with($message->getSubject(), 'Re: ');

        $inboxUrl = $this->urlGenerator->generate('inbox_index', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $html = '<div style="font-family:sans-serif;max-width:600px;margin:auto;padding:24px;">'
            . '<div style="background:#f0fdf4;border-left:5px solid #0d9b00;border-radius:8px;padding:16px 20px;margin-bottom:24px;">'
            . '<p style="margin:0;font-size:15px;font-weight:700;color:#166534;">'
            . ($isReply ? 'You have a new reply on Reserva FTIC' : 'You have a new message on Reserva FTIC')
            . '</p></div>'
            . '<p>Dear <strong>' . htmlspecialchars($recipientName) . '</strong>,</p>'
            . '<p><strong>' . htmlspecialchars($senderName) . '</strong>'
            . ' (<a href="mailto:' . htmlspecialchars($senderEmail) . '" style="color:#0d9b00;">' . htmlspecialchars($senderEmail) . '</a>)'
            . ' sent you a message via the Reserva FTIC Inbox.</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:16px 0;">'
            . '<tr style="background:#f9fafb;"><td style="padding:8px 12px;font-weight:bold;color:#6b7280;width:30%;">Subject</td>'
            . '<td style="padding:8px 12px;">' . htmlspecialchars($message->getSubject()) . '</td></tr>'
            . '</table>'
            . '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;margin:16px 0;">'
            . '<p style="margin:0;font-size:14px;line-height:1.65;white-space:pre-wrap;color:#111827;">'
            . nl2br(htmlspecialchars($message->getBody()))
            . '</p></div>'
            . '<div style="text-align:center;margin-top:24px;">'
            . '<a href="' . $inboxUrl . '" style="display:inline-block;padding:10px 24px;background:#0d9b00;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;">Open Inbox</a>'
            . '</div>'
            . '<hr style="margin:24px 0;border:none;border-top:1px solid #e5e7eb;">'
            . '<p style="color:#9ca3af;font-size:12px;text-align:center;">This message was sent through the Reserva FTIC internal messaging system. Log in to reply.</p>'
            . '</div>';

        try {
            $email = (new Email())
                ->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))
                ->replyTo(new Address($senderEmail, $senderName))
                ->to($to)
                ->subject(($isReply ? '[Reply] ' : '[New Message] ') . $message->getSubject() . ' – Reserva FTIC')
                ->html($html);

            $this->mailer->send($email);
            $this->logger->info('Inbox message email sent', ['to' => $to, 'subject' => $message->getSubject()]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send inbox message email', ['to' => $to, 'error' => $e->getMessage()]);
        }
    }
}