<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ClassSchedule;
use App\Entity\User;
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
        private readonly Security $security,
    ) {
    }

    /**
     * @return array{success: bool, message: string, channels: string}
     */
    public function notifyFaculty(ClassSchedule $schedule, ?string $customMessage = null, array $additionalContext = []): array
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
        $inAppMessage = $this->buildInAppMessage($message, $additionalContext);

        try {
            $this->sendEmail($recipientEmail, $schedule, $message, $additionalContext);
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
                $inAppMessage,
                'info',
                $schedule->getId(),
            );
            $inAppSent = true;
            $channels[] = 'in_app';
        }

        $channelLabel = $inAppSent ? 'in_app+email' : 'email_only';

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

    /**
     * @param array<string, mixed> $additionalContext
     */
    private function buildInAppMessage(string $baseMessage, array $additionalContext = []): string
    {
        $previous = $additionalContext['previousSchedule'] ?? null;
        $current = $additionalContext['currentSchedule'] ?? null;
        $summary = $additionalContext['changeSummary'] ?? [];

        if (!is_array($previous) || !is_array($current)) {
            return $baseMessage;
        }

        $lines = [$baseMessage, '', 'Old Schedule'];
        $lines[] = $this->formatScheduleSnapshot($previous);
        $lines[] = '';
        $lines[] = 'Updated Schedule';
        $lines[] = $this->formatScheduleSnapshot($current);

        if (is_array($summary) && $summary !== []) {
            $lines[] = '';
            $lines[] = 'Changes';
            foreach ($summary as $change) {
                if (!is_array($change)) {
                    continue;
                }

                $label = (string) ($change['label'] ?? 'Field');
                $old = (string) ($change['old'] ?? '');
                $new = (string) ($change['new'] ?? '');
                $lines[] = sprintf('%s: %s -> %s', $label, $old !== '' ? $old : '—', $new !== '' ? $new : '—');
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, string> $snapshot
     */
    private function formatScheduleSnapshot(array $snapshot): string
    {
        $course = trim(($snapshot['courseCode'] ?? '') . (($snapshot['section'] ?? '') !== '' ? ' · ' . ($snapshot['section'] ?? '') : ''));
        $facility = $snapshot['facility'] ?? 'Unknown facility';
        $date = $snapshot['date'] ?? '';
        $start = $snapshot['start'] ?? '';
        $end = $snapshot['end'] ?? '';

        return sprintf(
            '%s at %s on %s from %s to %s',
            $course !== '' ? $course : 'Class schedule',
            $facility,
            $date,
            $start,
            $end,
        );
    }

    private function sendEmail(string $to, ClassSchedule $schedule, string $message, array $additionalContext = []): void
    {
        $context = array_merge([
            'schedule' => $schedule,
            'message' => $message,
            'facilityName' => $schedule->getFacility()?->getName(),
        ], $additionalContext);

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))
            ->to($to)
            ->subject('Class schedule update — ' . $schedule->getCourseCode())
            ->htmlTemplate('email/class_schedule_notify.html.twig')
            ->context($context);

        $this->mailer->send($email);
    }
}
