<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MentorAvailability;
use App\Entity\MentorCustomRequest;
use App\Entity\MentorProfile;
use App\Entity\MentoringAppointment;
use App\Entity\MentoringAuditLog;
use App\Entity\User;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/mentoring')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class MentorRequestController extends AbstractController
{
    public function __construct(private readonly NotificationService $notificationService) {}

    #[Route('/my-requests/data', name: 'mentoring_my_requests_data', methods: ['GET'])]
    public function myRequestsData(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['requests' => [], 'ts' => time()]);
        }
        $reqs = $em->getRepository(MentorCustomRequest::class)->findBy(['student' => $user], ['createdAt' => 'DESC']);
        $data = array_map(static function (MentorCustomRequest $r): array {
            $mp = $r->getMentorProfile();
            return [
                'id'                      => $r->getId(),
                'mentorProfileName'       => $mp?->getDisplayName() ?? '',
                'preferredExpertise'      => $r->getPreferredExpertise() ?? '',
                'departmentCourse'        => $r->getDepartmentCourse() ?? '',
                'availableDates'          => $r->getAvailableDates() ?? '',
                'preferredSchedule'       => $r->getPreferredSchedule() ?? '',
                'assignedMentorName'      => $r->getAssignedMentorName() ?? '',
                'assignedMentorExpertise' => $r->getAssignedMentorExpertise() ?? '',
                'availableTime'           => $r->getAvailableTime() ?? '',
                'meetingMethod'           => $r->getMeetingMethod() ?? '',
                'adminInstructions'       => $r->getAdminInstructions() ?? '',
                'externalMentorEmail'     => $r->getExternalMentorEmail() ?? '',
                'message'                 => $r->getMessage() ?? '',
                'status'                  => $r->getStatus(),
                'createdAt'               => $r->getCreatedAt()->format('M d, Y'),
            ];
        }, $reqs);
        $response = $this->json(['requests' => $data, 'ts' => time()]);
        $response->setMaxAge(0)->headers->addCacheControlDirective('no-store');
        return $response;
    }

    #[Route('/{id}/custom-request', name: 'mentoring_custom_request', methods: ['POST'])]
    public function customRequest(MentorProfile $profile, Request $request, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';

        if (!$this->isCsrfTokenValid('custom_request_' . $profile->getId(), $request->request->get('_token'))) {
            if ($isAjax) return new JsonResponse(['error' => 'Invalid request.'], Response::HTTP_BAD_REQUEST);
            $this->addFlash('error', 'Invalid request.');
            return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException('You must be logged in to send a mentoring request.');
        }

        if ($profile->getUser() === null) {
            if ($isAjax) return new JsonResponse(['error' => 'This mentor profile does not have an associated user account.'], Response::HTTP_BAD_REQUEST);
            $this->addFlash('error', 'This mentor profile does not have an associated user account.');
            return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
        }

        if (!in_array('ROLE_MENTOR', $profile->getUser()->getRoles(), true)) {
            if ($isAjax) return new JsonResponse(['error' => 'This mentor is no longer available.'], Response::HTTP_BAD_REQUEST);
            $this->addFlash('error', 'This mentor is no longer available.');
            return $this->redirectToRoute('mentoring_index');
        }

        if ($profile->getUser()->getId() === $currentUser->getId()) {
            if ($isAjax) return new JsonResponse(['error' => 'You cannot send a mentor request to your own mentor profile.'], Response::HTTP_FORBIDDEN);
            $this->addFlash('error', 'You cannot send a mentor request to your own mentor profile.');
            return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
        }

        $message = trim($request->request->get('message', ''));
        if (strlen($message) < 10) {
            if ($isAjax) return new JsonResponse(['error' => 'Message too short. Please provide at least 10 characters.'], Response::HTTP_BAD_REQUEST);
            $this->addFlash('error', 'Message too short. Please provide at least 10 characters.');
            return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
        }

        $scheduledDateStr = $request->request->get('scheduled_date');
        $scheduledTime    = $request->request->get('scheduled_time');

        if (!$scheduledDateStr || !$scheduledTime) {
            if ($isAjax) return new JsonResponse(['error' => 'Please select both a date and time for the mentoring session.'], Response::HTTP_BAD_REQUEST);
            $this->addFlash('error', 'Please select both a date and time for the mentoring session.');
            return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
        }

        $scheduledDate = \DateTime::createFromFormat('Y-m-d', $scheduledDateStr);
        if (!$scheduledDate) {
            if ($isAjax) return new JsonResponse(['error' => 'Invalid date format.'], Response::HTTP_BAD_REQUEST);
            $this->addFlash('error', 'Invalid date format.');
            return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
        }

        $availabilityDays = $profile->getAvailabilityDays() ?? [];
        if (!empty($availabilityDays)) {
            $dayNames    = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $selectedDay = $dayNames[(int) $scheduledDate->format('w')];
            if (!in_array($selectedDay, $availabilityDays, true)) {
                $msg = 'This mentor is only available on: ' . implode(', ', $availabilityDays) . '. Please select a valid date.';
                if ($isAjax) return new JsonResponse(['error' => $msg], Response::HTTP_BAD_REQUEST);
                $this->addFlash('error', $msg);
                return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
            }
        }

        $customRequest = (new MentorCustomRequest())
            ->setStudent($currentUser)
            ->setMentorProfile($profile)
            ->setMessage($message)
            ->setScheduledDate($scheduledDate)
            ->setScheduledTime($scheduledTime)
            ->setStatus('Pending');

        try {
            $em->persist($customRequest);
            $em->flush();
        } catch (\Exception $e) {
            if ($isAjax) return new JsonResponse(['error' => 'Failed to save request: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
            $this->addFlash('error', 'Failed to save request: ' . $e->getMessage());
            return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
        }

        $requestUrl  = $this->generateUrl('mentoring_index', [], UrlGeneratorInterface::ABSOLUTE_URL) . '#custom-requests';
        $studentName = trim(($currentUser->getFirstName() ?? '') . ' ' . ($currentUser->getLastName() ?? '')) ?: $currentUser->getEmail();

        try {
            $this->notificationService->create($profile->getUser(), 'mentor_request', 'New Mentoring Request', 'You received a new mentoring request from ' . $studentName . '.', 'New', $customRequest->getId());
        } catch (\Throwable $e) {}

        try {
            $emailHtml = $this->renderView('emails/mentor_custom_request.html.twig', [
                'mentorName'   => $profile->getDisplayName(),
                'studentName'  => $studentName,
                'studentEmail' => $currentUser->getEmail(),
                'message'      => $message,
                'requestId'    => $customRequest->getId(),
                'requestUrl'   => $requestUrl,
            ]);
            $mailer->send((new Email())->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))->to($profile->getUser()->getEmail())->subject('New Custom Mentoring Request from ' . $studentName)->html($emailHtml));

            $studentEmailHtml = $this->renderView('emails/student_custom_request_confirmation.html.twig', [
                'studentName'   => $studentName,
                'mentorName'    => $profile->getDisplayName(),
                'mentorEmail'   => $profile->getUser()->getEmail(),
                'message'       => $message,
                'scheduledDate' => $scheduledDate->format('F d, Y'),
                'scheduledTime' => $scheduledTime,
                'requestId'     => $customRequest->getId(),
                'requestUrl'    => $requestUrl,
            ]);
            $mailer->send((new Email())->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))->to($currentUser->getEmail())->subject('Your Mentoring Request Has Been Sent')->html($studentEmailHtml));
        } catch (\Exception $e) {}

        if ($isAjax) {
            return new JsonResponse(['success' => true, 'message' => 'Mentor request sent successfully!', 'mentorName' => $profile->getDisplayName()]);
        }

        $this->addFlash('success', 'Mentor request sent! ' . $profile->getDisplayName() . ' will receive an email with your message and can respond within 24-48 hours.');
        return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
    }

    #[Route('/assistance-request', name: 'mentoring_assistance_request', methods: ['POST'])]
    public function assistanceRequest(Request $request, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        if (!$this->isCsrfTokenValid('mentor_assistance_request', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException('You must be logged in to request mentor assistance.');
        }

        $fullName                = trim((string) $request->request->get('full_name'));
        $departmentCourse        = trim((string) $request->request->get('department_course'));
        $preferredExpertise      = trim((string) $request->request->get('preferred_expertise'));
        $preferredExpertiseOther = trim((string) $request->request->get('preferred_expertise_other'));
        if ($preferredExpertise === 'Other' && $preferredExpertiseOther !== '') {
            $preferredExpertise = $preferredExpertiseOther;
        }
        $availableDates = trim((string) $request->request->get('available_dates'));
        $scheduleStart  = trim((string) $request->request->get('preferred_schedule_start'));
        $scheduleEnd    = trim((string) $request->request->get('preferred_schedule_end'));
        $fmtTime        = static function (string $t): string {
            $dt = \DateTime::createFromFormat('H:i', $t);
            return $dt ? $dt->format('g:i A') : $t;
        };
        $preferredSchedule = ($scheduleStart !== '' && $scheduleEnd !== '') ? $fmtTime($scheduleStart) . ' \u2013 ' . $fmtTime($scheduleEnd) : '';
        $message           = trim((string) $request->request->get('message'));

        if ($fullName === '' || $departmentCourse === '' || $preferredExpertise === '' || $availableDates === '' || $preferredSchedule === '') {
            $this->addFlash('error', 'Please complete the required mentor request fields.');
            return $this->redirectToRoute('mentoring_index', [], Response::HTTP_SEE_OTHER);
        }

        $mentorRequest = (new MentorCustomRequest())
            ->setStudent($currentUser)
            ->setMentorProfile(null)
            ->setFullName($fullName)
            ->setDepartmentCourse($departmentCourse)
            ->setPreferredExpertise($preferredExpertise)
            ->setAvailableDates($availableDates)
            ->setPreferredSchedule($preferredSchedule)
            ->setMessage($message !== '' ? $message : 'No additional notes provided.')
            ->setStatus('Pending');

        try {
            $em->persist($mentorRequest);
            $em->flush();
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to save mentor request: ' . $e->getMessage());
            return $this->redirectToRoute('mentoring_index', [], Response::HTTP_SEE_OTHER);
        }

        try {
            $adminUrl   = $this->generateUrl('mentoring_superadmin_requests', [], UrlGeneratorInterface::ABSOLUTE_URL) . '#mentor-requests';
            $admins     = $em->getRepository(User::class)->findAdmins();
            $firstAdmin = true;
            foreach ($admins as $admin) {
                try {
                    $this->notificationService->notifyAdminNewMentorAssistanceRequest($admin, $mentorRequest->getId(), $fullName);
                } catch (\Exception $e) {}
                if ($firstAdmin) {
                    try {
                        $this->sendMentorAssistanceRequestEmail($mailer, $admin, $mentorRequest, $adminUrl);
                    } catch (\Exception $e) {}
                    $firstAdmin = false;
                }
            }
            try {
                $this->notificationService->notifyMentorAssistanceStatus($currentUser, $mentorRequest->getId(), 'Pending', 'Your mentor assistance request has been submitted and is now pending review.');
            } catch (\Exception $e) {}

            $this->addFlash('success', 'Your mentor request has been submitted. Admin will review it and send mentor details once a match is found.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Your request was submitted but there was an issue sending notifications. Please contact support if needed.');
        }

        return $this->redirectToRoute('mentoring_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/assistance-request/{id}/update', name: 'mentoring_assistance_update', methods: ['POST'])]
    public function updateAssistanceRequest(MentorCustomRequest $mentorRequest, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('mentor_assistance_update_' . $mentorRequest->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User || $mentorRequest->getStudent()?->getId() !== $currentUser->getId()) {
            throw $this->createAccessDeniedException('You can only update your own mentor requests.');
        }

        $action = (string) $request->request->get('action', 'update');
        if ($action === 'cancel') {
            if (in_array($mentorRequest->getStatus(), ['Cancelled', 'Completed'], true)) {
                $this->addFlash('error', 'This request has already been ' . strtolower($mentorRequest->getStatus()) . '.');
                return $this->redirectToRoute('mentoring_index', [], Response::HTTP_SEE_OTHER);
            }
            $cancellationReason = trim((string) $request->request->get('cancellation_reason', ''));
            if (strlen($cancellationReason) < 10) {
                $this->addFlash('error', 'Please provide a cancellation reason with at least 10 characters.');
                return $this->redirectToRoute('mentoring_index', [], Response::HTTP_SEE_OTHER);
            }
            $mentorRequest->setStatus('Cancelled')->setCancellationReason($cancellationReason);
            $em->flush();
            $this->addFlash('success', 'Your mentor request has been cancelled.');
            return $this->redirectToRoute('mentoring_index', [], Response::HTTP_SEE_OTHER);
        }

        if (!$mentorRequest->isAssistanceRequest() || $mentorRequest->getStatus() !== 'Pending') {
            $this->addFlash('error', 'Only pending assistance requests can be edited. You can still cancel it.');
            return $this->redirectToRoute('mentoring_index', [], Response::HTTP_SEE_OTHER);
        }

        $mentorRequest
            ->setFullName(trim((string) $request->request->get('full_name')) ?: $mentorRequest->getFullName())
            ->setDepartmentCourse(trim((string) $request->request->get('department_course')) ?: $mentorRequest->getDepartmentCourse())
            ->setPreferredExpertise(trim((string) $request->request->get('preferred_expertise')) ?: $mentorRequest->getPreferredExpertise())
            ->setAvailableDates(trim((string) $request->request->get('available_dates')) ?: $mentorRequest->getAvailableDates())
            ->setPreferredSchedule(trim((string) $request->request->get('preferred_schedule')) ?: $mentorRequest->getPreferredSchedule())
            ->setMessage(trim((string) $request->request->get('message')) ?: $mentorRequest->getMessage());

        $em->flush();
        $this->addFlash('success', 'Your mentor request has been updated.');
        return $this->redirectToRoute('mentoring_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/mentor-request/{id}/respond', name: 'mentoring_admin_request_respond', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function respondToAssistanceRequest(MentorCustomRequest $mentorRequest, Request $request, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        if (!$this->isCsrfTokenValid('mentor_assistance_respond_' . $mentorRequest->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $action = (string) $request->request->get('action', '');
        $status = $action === 'no_mentor_match' ? 'Cancelled' : (string) $request->request->get('status', 'Pending');
        if (!in_array($status, ['Pending', 'Reviewing', 'Assigned', 'Completed', 'Cancelled'], true)) {
            throw $this->createNotFoundException();
        }

        if ($action === 'no_mentor_match') {
            return $this->handleNoMentorMatch($mentorRequest, $request, $em, $mailer);
        }

        $mentorId        = (int) $request->request->get('mentor_id', 0);
        $mentorName      = trim((string) $request->request->get('mentor_name'));
        $expertise       = trim((string) $request->request->get('expertise'));
        $expertiseOther  = trim((string) $request->request->get('expertise_other'));
        if ($expertise === 'Other' && $expertiseOther !== '') {
            $expertise = $expertiseOther;
        }
        if ($mentorId > 0) {
            $existingMentor = $em->getRepository(MentorProfile::class)->find($mentorId);
            if ($existingMentor) {
                $mentorName = $mentorName ?: $existingMentor->getDisplayName();
                $expertise  = $expertise ?: $existingMentor->getSpecialization();
            }
        }

        $availableDates = trim((string) $request->request->get('available_dates'));
        $timeStart      = trim((string) $request->request->get('available_time_start'));
        $timeEnd        = trim((string) $request->request->get('available_time_end'));
        $fmtTime        = static function (string $t): string {
            $dt = \DateTime::createFromFormat('H:i', $t);
            return $dt ? $dt->format('g:i A') : $t;
        };
        $availableTime   = ($timeStart !== '' && $timeEnd !== '') ? $fmtTime($timeStart) . ' \u2013 ' . $fmtTime($timeEnd) : trim((string) $request->request->get('available_time'));
        $meetingMethod   = trim((string) $request->request->get('meeting_method'));
        $meetingLink     = trim((string) $request->request->get('meeting_link'));
        $meetingLocation = trim((string) $request->request->get('meeting_location'));
        $instructions    = trim((string) $request->request->get('instructions'));
        $isExternalPanel = $mentorId <= 0 && $mentorName !== '';

        if (in_array($status, ['Assigned', 'Completed'], true) && ($mentorName === '' || $expertise === '' || $availableDates === '' || $availableTime === '' || $meetingMethod === '')) {
            $this->addFlash('error', 'Mentor name, expertise, dates, time, and meeting method are required before assigning a request.');
            $redirectRoute = $this->isGranted('ROLE_SUPER_ADMIN') ? 'mentoring_superadmin_requests' : 'admin_role_mentorship_coordination';
            return $this->redirectToRoute($redirectRoute, [], Response::HTTP_SEE_OTHER);
        }

        if (in_array($status, ['Assigned', 'Completed'], true) && $isExternalPanel && !$this->containsEmailAddress($instructions)) {
            $this->addFlash('error', 'An email address is required for External Panel Mentors.');
            $redirectRoute = $this->isGranted('ROLE_SUPER_ADMIN') ? 'mentoring_superadmin_requests' : 'admin_role_mentorship_coordination';
            return $this->redirectToRoute($redirectRoute, [], Response::HTTP_SEE_OTHER);
        }

        $prevStatus     = $mentorRequest->getStatus();
        $requesterLabel = $mentorRequest->getFullName() ?: ($mentorRequest->getStudent() ? trim(($mentorRequest->getStudent()->getFirstName() ?? '') . ' ' . ($mentorRequest->getStudent()->getLastName() ?? '')) : 'Unknown');

        $mentorRequest
            ->setStatus($status)
            ->setAssignedMentorName($mentorName ?: null)
            ->setAssignedMentorExpertise($expertise ?: null)
            ->setAvailableDates($availableDates ?: null)
            ->setAvailableTime($availableTime ?: null)
            ->setMeetingMethod($meetingMethod ?: null)
            ->setMeetingLink($meetingLink !== '' ? $meetingLink : null)
            ->setMeetingLocation($meetingLocation !== '' ? $meetingLocation : null)
            ->setAdminInstructions($instructions ?: null);

        if (in_array($status, ['Assigned', 'Completed', 'Cancelled'], true)) {
            $mentorRequest->markResponded();
        }

        $student = $mentorRequest->getStudent();
        if ($student) {
            $notificationMessage = $status === 'Cancelled' ? 'Your mentor assistance request has been cancelled.' : 'A mentor match has been sent for your assistance request.';
            try {
                $this->notificationService->notifyMentorAssistanceStatus($student, $mentorRequest->getId(), $status, $notificationMessage);
            } catch (\Exception $e) {}
            if (in_array($status, ['Assigned', 'Completed'], true)) {
                try {
                    $this->sendMentorAssistanceResponseEmail($mailer, $student, $mentorRequest);
                } catch (\Exception $e) {}
            }
        }

        $noteText = $instructions !== '' ? $instructions : ($mentorName !== '' ? 'Assigned to: ' . $mentorName : null);
        try {
            $this->auditLog($em, ['type' => 'custom_request', 'id' => $mentorRequest->getId(), 'label' => $requesterLabel, 'action' => 'update_status', 'prev' => $prevStatus, 'next' => $status, 'note' => $noteText]);
        } catch (\Exception $e) {}

        $em->flush();

        try {
            $actor        = $this->getUser();
            $actorName    = $actor instanceof User ? trim($actor->getFirstName() . ' ' . $actor->getLastName()) : 'Super Admin';
            $requesterName = $mentorRequest->getFullName() ?: ($student ? trim($student->getFirstName() . ' ' . $student->getLastName()) : 'Student');
            foreach ($em->getRepository(User::class)->findAdmins() as $u) {
                if ($u === $actor) continue;
                try {
                    $this->notificationService->notifyAdminMentorRequestUpdated($u, $mentorRequest->getId(), $actorName, $status, $requesterName);
                } catch (\Exception $e) {}
            }
        } catch (\Exception $e) {}

        $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
        if ($isAjax) {
            return $this->json(['success' => true, 'message' => 'Mentor request updated and the requester has been notified.']);
        }

        $this->addFlash('success', 'Mentor request updated and the requester has been notified.');
        $redirectRoute = $this->isGranted('ROLE_SUPER_ADMIN') ? 'mentoring_superadmin_requests' : 'admin_role_mentorship_coordination';
        return $this->redirectToRoute($redirectRoute, [], Response::HTTP_SEE_OTHER);
    }

    private function handleNoMentorMatch(MentorCustomRequest $mentorRequest, Request $request, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        $prevStatus = $mentorRequest->getStatus();
        $student = $mentorRequest->getStudent();
        $reason = 'No Mentor Match';
        $message = 'Unfortunately, we were unable to find an available mentor that matches your request at this time. Your mentoring request has been canceled. You may submit another request in the future when mentors become available.';
        $requesterLabel = $mentorRequest->getFullName() ?: ($student ? trim(($student->getFirstName() ?? '') . ' ' . ($student->getLastName() ?? '')) : 'Unknown');
        $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
        $redirectRoute = $this->isGranted('ROLE_SUPER_ADMIN') ? 'mentoring_superadmin_requests' : 'admin_role_mentorship_coordination';

        if (in_array($prevStatus, ['Cancelled', 'Completed'], true)) {
            $errorMessage = $prevStatus === 'Cancelled'
                ? 'This mentor request has already been cancelled.'
                : 'Completed mentor requests cannot be marked as No Mentor Match.';
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => $errorMessage], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('mentor_error', $errorMessage);
            return $this->redirectToRoute($redirectRoute, [], Response::HTTP_SEE_OTHER);
        }

        $mentorRequest
            ->setStatus('Cancelled')
            ->setCancellationReason($reason)
            ->setAdminInstructions($message)
            ->markResponded();

        try {
            $this->auditLog($em, [
                'type' => 'custom_request',
                'id' => $mentorRequest->getId(),
                'label' => $requesterLabel,
                'action' => 'no_mentor_match',
                'prev' => $prevStatus,
                'next' => 'Cancelled',
                'note' => $reason,
            ]);
        } catch (\Exception $e) {}

        $em->flush();

        if ($student) {
            try {
                $this->notificationService->notifyMentorAssistanceStatus($student, $mentorRequest->getId(), 'Cancelled', $message);
            } catch (\Exception $e) {}

            try {
                $this->sendNoMentorMatchEmail($mailer, $student, $mentorRequest, $message, $reason);
            } catch (\Exception $e) {}
        }

        try {
            $actor         = $this->getUser();
            $actorName     = $actor instanceof User ? trim(($actor->getFirstName() ?? '') . ' ' . ($actor->getLastName() ?? '')) : 'Admin';
            $actorName     = $actorName !== '' ? $actorName : ($actor instanceof User ? $actor->getEmail() : 'Admin');
            $requesterName = $requesterLabel !== '' ? $requesterLabel : 'Student';
            foreach ($em->getRepository(User::class)->findAdmins() as $u) {
                if ($u === $actor) {
                    continue;
                }
                try {
                    $this->notificationService->notifyAdminMentorRequestUpdated($u, $mentorRequest->getId(), $actorName, 'Cancelled', $requesterName);
                } catch (\Exception $e) {}
            }
        } catch (\Exception $e) {}

        if ($isAjax) {
            return $this->json(['success' => true, 'message' => 'Mentor request marked as No Mentor Match. The requester has been notified.']);
        }

        $this->addFlash('mentor_success', 'Mentor request marked as No Mentor Match. The requester has been notified.');
        return $this->redirectToRoute($redirectRoute, [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/custom-request/{id}/review', name: 'mentoring_custom_request_review', methods: ['POST'])]
    #[IsGranted('ROLE_MENTOR')]
    public function reviewCustomRequest(MentorCustomRequest $customRequest, Request $request, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        if (!$this->isCsrfTokenValid('custom_request_review_' . $customRequest->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException('You must be logged in to review requests.');
        }

        $mentorProfile = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $currentUser]);
        if (!$mentorProfile || $customRequest->getMentorProfile()?->getId() !== $mentorProfile->getId()) {
            throw $this->createAccessDeniedException('You can only review your own mentoring requests.');
        }

        if ($customRequest->getStatus() !== 'Pending') {
            $this->addFlash('error', 'This request has already been reviewed.');
            return $this->redirectToRoute('mentoring_index', [], Response::HTTP_SEE_OTHER);
        }

        $decision      = strtolower((string) $request->request->get('decision', ''));
        $mentorResponse = trim((string) $request->request->get('mentor_response', ''));

        if (!in_array($decision, ['accept', 'decline'], true)) {
            throw $this->createNotFoundException();
        }

        $student = $customRequest->getStudent();
        if (!$student) {
            throw $this->createNotFoundException();
        }

        $studentName = trim(($student->getFirstName() ?? '') . ' ' . ($student->getLastName() ?? '')) ?: $student->getEmail();
        $status      = $decision === 'accept' ? 'accepted' : 'declined';
        $title       = $decision === 'accept' ? 'Mentor Request Accepted' : 'Mentor Request Declined';
        $flashMessage = $decision === 'accept' ? 'Mentor request accepted! Meeting details have been sent to the student.' : 'Mentor request declined.';

        $facilityReservedBy = null;
        if ($decision === 'accept') {
            $meetingType     = trim((string) $request->request->get('meeting_type', ''));
            $meetingLink     = trim((string) $request->request->get('meeting_link', ''));
            $meetingLocation = trim((string) $request->request->get('meeting_location', ''));
            $facilityReservedBy = trim((string) $request->request->get('facility_reserved_by', ''));

            if ($meetingType)        { $customRequest->setMeetingType($meetingType); }
            if ($meetingLink)        { $customRequest->setMeetingLink($meetingLink); }
            if ($meetingLocation)    { $customRequest->setMeetingLocation($meetingLocation); }
            if ($facilityReservedBy) { $customRequest->setFacilityReservedBy($facilityReservedBy); }

            $meetingDetails = [];
            if ($customRequest->getScheduledDate()) { $meetingDetails[] = 'Date: ' . $customRequest->getScheduledDate()->format('F d, Y'); }
            if ($customRequest->getScheduledTime()) { $meetingDetails[] = 'Time: ' . $customRequest->getScheduledTime(); }
            if ($meetingType)  { $meetingDetails[] = 'Meeting Type: ' . $meetingType; }
            if ($meetingLink)  { $meetingDetails[] = 'Meeting Link: ' . $meetingLink; }

            if ($meetingType === 'Face-to-Face') {
                if ($facilityReservedBy === 'mentor')  { $meetingDetails[] = 'Location: Mentor will reserve a facility'; }
                elseif ($facilityReservedBy === 'student') { $meetingDetails[] = 'Location: You need to reserve a facility'; }
                elseif ($facilityReservedBy === 'outside') { $meetingDetails[] = 'Location: ' . $meetingLocation; }
            }

            $message = 'Your custom mentoring request has been accepted by ' . $mentorProfile->getDisplayName() . '.';
            if (!empty($meetingDetails)) {
                $message .= ' Meeting details: ' . implode(' | ', $meetingDetails);
            }
            if ($meetingType === 'Face-to-Face') {
                if ($facilityReservedBy === 'mentor')  { $message .= ' The mentor will reserve a facility and notify you of the location.'; }
                elseif ($facilityReservedBy === 'student') { $message .= ' Please reserve a facility through the FTIC reservation system before the session.'; }
            }
        } else {
            $message = 'Your custom mentoring request has been declined by ' . $mentorProfile->getDisplayName() . '.';
        }

        if ($mentorResponse !== '') { $customRequest->setMentorResponse($mentorResponse); }
        $customRequest->setStatus($status);

        if ($decision === 'accept') {
            $mentorProfile->setEngagementPoints(($mentorProfile->getEngagementPoints() ?? 0) + 1);
        }

        $studentRequestUrl = $this->generateUrl('mentoring_index', [], UrlGeneratorInterface::ABSOLUTE_URL) . '#my-custom-requests';

        try {
            $emailHtml = $this->renderView('emails/mentor_custom_request_status.html.twig', [
                'studentName'       => $studentName,
                'mentorName'        => $mentorProfile->getDisplayName(),
                'status'            => ucfirst($status),
                'message'           => $message,
                'mentorResponse'    => $mentorResponse ?: null,
                'requestUrl'        => $studentRequestUrl,
                'facilityReservedBy' => $facilityReservedBy,
            ]);
            $mailer->send((new Email())->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))->to($student->getEmail())->subject($title)->html($emailHtml));
        } catch (\Exception $e) {}

        $this->notificationService->create($student, 'mentor', $title, $message, ucfirst($status), $customRequest->getId());
        $em->flush();

        $this->addFlash('success', $flashMessage);
        return $this->redirectToRoute('mentoring_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/availability/{id}/request', name: 'mentoring_request', methods: ['POST'])]
    public function requestAppointment(MentorAvailability $availability, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('request_mentoring_' . $availability->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($availability->isBooked()) {
            $this->addFlash('error', 'That mentoring slot is already booked.');
            return $this->redirectToRoute('mentoring_index');
        }

        $mentorUser = $availability->getMentor()->getUser();
        if ($mentorUser === null || !in_array('ROLE_MENTOR', $mentorUser->getRoles(), true)) {
            $this->addFlash('error', 'This mentor is no longer available.');
            return $this->redirectToRoute('mentoring_index');
        }

        $scheduledAt = new \DateTime($availability->getAvailableDate()->format('Y-m-d') . ' ' . $availability->getStartTime()->format('H:i'));
        $appointment = (new MentoringAppointment())
            ->setStudent($this->getUser())
            ->setMentor($availability->getMentor())
            ->setAvailability($availability)
            ->setScheduledAt($scheduledAt)
            ->setTopic($request->request->get('topic'));

        $availability->setIsBooked(true);
        $availability->getMentor()->addEngagementPoints(5);

        $em->persist($appointment);
        $em->flush();

        $this->addFlash('success', 'Mentoring appointment requested successfully!');
        return $this->redirectToRoute('mentoring_index');
    }

    /**
     * @param array{type:string,id:?int,label:string,action:string,prev:?string,next:?string,note?:?string} $ctx
     */
    private function auditLog(EntityManagerInterface $em, array $ctx): void
    {
        $actor     = $this->getUser();
        $actorName = null;
        $actorRole = null;
        if ($actor instanceof User) {
            $actorName = trim(($actor->getFirstName() ?? '') . ' ' . ($actor->getLastName() ?? '')) ?: $actor->getEmail();
            $actorRole = $this->isGranted('ROLE_SUPER_ADMIN') ? 'Super Admin' : 'Admin';
        }

        /** @var \App\Repository\MentoringAuditLogRepository $repo */
        $repo = $em->getRepository(MentoringAuditLog::class);
        if ($repo->existsRecent($ctx['type'], $ctx['id'], $ctx['action'], $ctx['next'], $actor instanceof User ? $actor->getId() : null)) {
            return;
        }

        $log = (new MentoringAuditLog())
            ->setSubjectType($ctx['type'])
            ->setSubjectId($ctx['id'])
            ->setSubjectLabel($ctx['label'])
            ->setAction($ctx['action'])
            ->setPreviousStatus($ctx['prev'])
            ->setNewStatus($ctx['next'])
            ->setPerformedBy($actor instanceof User ? $actor : null)
            ->setPerformedByName($actorName)
            ->setPerformedByRole($actorRole)
            ->setNote($ctx['note'] ?? null);
        $em->persist($log);
    }

    private function sendMentorAssistanceRequestEmail(MailerInterface $mailer, User $admin, MentorCustomRequest $mentorRequest, string $adminUrl): void
    {
        try {
            $mailer->send((new Email())->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))->to($admin->getEmail())->subject('New Mentor Assistance Request')->html($this->renderView('emails/mentor_assistance_request.html.twig', ['request' => $mentorRequest, 'adminUrl' => $adminUrl])));
        } catch (\Throwable $e) {}
    }

    private function sendMentorAssistanceResponseEmail(MailerInterface $mailer, User $student, MentorCustomRequest $mentorRequest): void
    {
        try {
            $mailer->send((new Email())->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))->to($student->getEmail())->subject('Mentor Details for Your Request')->html($this->renderView('emails/mentor_assistance_response.html.twig', ['request' => $mentorRequest, 'student' => $student, 'requestUrl' => $this->generateUrl('mentoring_index', [], UrlGeneratorInterface::ABSOLUTE_URL) . '#my-custom-requests'])));
        } catch (\Throwable $e) {}
    }

    private function sendNoMentorMatchEmail(MailerInterface $mailer, User $student, MentorCustomRequest $mentorRequest, string $message, string $reason): void
    {
        try {
            $mailer->send((new Email())
                ->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))
                ->to($student->getEmail())
                ->subject('Mentoring Request Canceled - No Mentor Match')
                ->html($this->renderView('emails/mentor_no_match.html.twig', [
                    'request' => $mentorRequest,
                    'student' => $student,
                    'message' => $message,
                    'reason' => $reason,
                    'requestTitle' => $this->mentorRequestTitle($mentorRequest),
                    'requestUrl' => $this->generateUrl('mentoring_index', [], UrlGeneratorInterface::ABSOLUTE_URL) . '#cancelled',
                ])));
        } catch (\Throwable $e) {}
    }

    private function mentorRequestTitle(MentorCustomRequest $mentorRequest): string
    {
        if ($mentorRequest->getPreferredExpertise()) {
            return $mentorRequest->getPreferredExpertise();
        }

        if ($mentorRequest->getMentorProfile()) {
            return 'Request for ' . $mentorRequest->getMentorProfile()->getDisplayName();
        }

        return 'Mentoring Request #' . $mentorRequest->getId();
    }

    private function containsEmailAddress(string $value): bool
    {
        return preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value) === 1;
    }
}
