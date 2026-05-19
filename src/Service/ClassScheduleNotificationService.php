<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ClassSchedule;
use App\Entity\ClassScheduleNotificationLog;
use App\Entity\User;
use App\Repository\ClassScheduleNotificationLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Bundle\SecurityBundle\Security;

class ClassScheduleNotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly NotificationService $notificationService,
        private readonly ClassScheduleFacultyMatcher $facultyMatcher,
        private readonly ClassScheduleNotificationLogRepository $logRepository,
        private readonly Security $security,
        private readonly ReservationAuditLogger $auditLogger,
    ) {
    }

    /**
     * @return array{success: bool, message: string, channels: string}
     */
    public function notifyFaculty(ClassSchedule $schedule, ?string $customMessage = null): array
    {
        $actor = $this->security->getUser();
        if (!$actor instanceof User) {
            return ['success' => false, 'message' => 'You must be logged in to send notifications.', 'channels' => ''];
        }

        $recipientEmail = $this->resolveRecipientEmail($schedule);
        if ($recipientEmail === null) {
            return ['success' => false, 'message' => 'No faculty email is available for this class schedule.', 'channels' => ''];
        }

        $facultyUser = $this->facultyMatcher->resolveFacultyUser($recipientEmail)
            ?? $this->facultyMatcher->resolveFacultyUser($schedule->getFacultyEmail());

        $message = $customMessage ?? $this->buildDefaultMessage($schedule);
        $emailSent = false;
        $inAppSent = false;
        $channels = [];

        try {
            $this->sendEmail($recipientEmail, $schedule, $message);
            $emailSent = true;
            $channels[] = 'email';
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Could not send email: ' . $e->getMessage(),
                'channels' => '',
            ];
        }

        if ($facultyUser !== null) {
            $this->notificationService->create(
                $facultyUser,
                'class_schedule',
                'Class schedule update',
                $message,
                'info',
                $schedule->getId(),
            );
            $inAppSent = true;
            $channels[] = 'in_app';
        }

        $channelLabel = $inAppSent ? 'in_app+email' : 'email_only';

        $log = new ClassScheduleNotificationLog();
        $log->setClassSchedule($schedule);
        $log->setNotifiedBy($actor);
        $log->setFacultyUser($facultyUser);
        $log->setRecipientEmail($recipientEmail);
        $log->setActorRoleLabel($this->auditLogger->resolveActorRoleLabel($actor));
        $log->setChannels($channelLabel);
        $log->setMessage($message);
        $log->setEmailSent($emailSent);
        $log->setInAppSent($inAppSent);
        $log->setPreviousFacility($schedule->getPreviousFacility());
        $log->setNewFacility($schedule->getFacility());
        $this->em->persist($log);
        $this->em->flush();

        return [
            'success' => true,
            'message' => $inAppSent
                ? 'Notification sent by email and in the system.'
                : 'Email notification sent (faculty is not verified in the system yet).',
            'channels' => $channelLabel,
        ];
    }

    private function resolveRecipientEmail(ClassSchedule $schedule): ?string
    {
        $facultyUser = $schedule->getFacultyUser();
        if ($facultyUser !== null && $this->facultyMatcher->isVerifiedFaculty($facultyUser)) {
            return $facultyUser->getEmail();
        }

        $csvEmail = $schedule->getFacultyEmail();
        if ($csvEmail !== null && filter_var($csvEmail, FILTER_VALIDATE_EMAIL)) {
            return $csvEmail;
        }

        return null;
    }

    private function buildDefaultMessage(ClassSchedule $schedule): string
    {
        $facility = $schedule->getFacility()?->getName() ?? 'Unknown facility';
        $date = $schedule->getScheduleDate()?->format('M d, Y') ?? '';
        $start = $schedule->getStartTime()?->format('g:i A') ?? '';
        $end = $schedule->getEndTime()?->format('g:i A') ?? '';
        $course = $schedule->getCourseCode();
        $section = $schedule->getSection() ? ' Section ' . $schedule->getSection() : '';

        if ($schedule->isRelocated() && $schedule->getPreviousFacility()) {
            $previous = $schedule->getPreviousFacility()->getName();

            return sprintf(
                'Your class %s%s has been allocated to %s (previously %s) on %s from %s to %s.',
                $course,
                $section,
                $facility,
                $previous,
                $date,
                $start,
                $end,
            );
        }

        return sprintf(
            'Your class %s%s is scheduled at %s on %s from %s to %s.',
            $course,
            $section,
            $facility,
            $date,
            $start,
            $end,
        );
    }

    private function sendEmail(string $to, ClassSchedule $schedule, string $message): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('hurstdale101@gmail.com', 'Reserva FTIC'))
            ->to($to)
            ->subject('Class schedule update — ' . $schedule->getCourseCode())
            ->htmlTemplate('email/class_schedule_notify.html.twig')
            ->context([
                'schedule' => $schedule,
                'message' => $message,
                'facilityName' => $schedule->getFacility()?->getName(),
            ]);

        $this->mailer->send($email);
    }
}
