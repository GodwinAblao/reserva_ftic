<?php

declare(strict_types=1);

namespace App\Service;

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
            . ($reservation->isInstitutionalEvent() ? '<tr><td style="padding:8px;font-weight:bold;color:#1e40af;">Type</td><td style="padding:8px;"><span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:4px;">Institutional Event</span></td></tr>' : '')
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
}
