<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Reservation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
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

    public function notifyApproved(Reservation $reservation): void
    {
        $user = $reservation->getUser();
        if (!$user) {
            return;
        }

        $this->sendUserEmail($reservation, $user, 'Approved',
            'Great news! Your facility reservation has been approved. Please review the details below.',
        );

        $this->createUserNotification($reservation, $user, 'Approved',
            'Reservation Approved',
            sprintf('Your reservation for %s on %s has been approved.',
                $reservation->getFacility()?->getName() ?? 'a facility',
                $reservation->getReservationDate()?->format('F j, Y') ?? ''
            ),
        );
    }

    public function notifyRejected(Reservation $reservation): void
    {
        $user = $reservation->getUser();
        if (!$user) {
            return;
        }

        $reason = $reservation->getRejectionReason() ?: 'No reason provided.';

        $this->sendUserEmail($reservation, $user, 'Rejected',
            'We regret to inform you that your facility reservation request has been rejected.',
            'Reason: ' . $reason,
        );

        $this->createUserNotification($reservation, $user, 'Rejected',
            'Reservation Rejected',
            sprintf('Your reservation for %s on %s was rejected. Reason: %s',
                $reservation->getFacility()?->getName() ?? 'a facility',
                $reservation->getReservationDate()?->format('F j, Y') ?? '',
                $reason
            ),
        );
    }

    public function notifyCancelled(Reservation $reservation): void
    {
        $user = $reservation->getUser();
        if (!$user) {
            return;
        }

        $this->sendUserEmail($reservation, $user, 'Cancelled',
            'Your facility reservation has been cancelled.',
        );

        $this->createUserNotification($reservation, $user, 'Cancelled',
            'Reservation Cancelled',
            sprintf('Your reservation for %s on %s has been cancelled.',
                $reservation->getFacility()?->getName() ?? 'a facility',
                $reservation->getReservationDate()?->format('F j, Y') ?? ''
            ),
        );
    }

    public function notifyUpdated(Reservation $reservation): void
    {
        $user = $reservation->getUser();
        if (!$user) {
            return;
        }

        $this->sendUserEmail($reservation, $user, 'Updated',
            'Your facility reservation request has been updated by the administrator. Please review the latest details below.',
        );

        $this->createUserNotification($reservation, $user, 'Updated',
            'Reservation Updated',
            sprintf('Your reservation for %s on %s has been updated.',
                $reservation->getFacility()?->getName() ?? 'a facility',
                $reservation->getReservationDate()?->format('F j, Y') ?? ''
            ),
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
