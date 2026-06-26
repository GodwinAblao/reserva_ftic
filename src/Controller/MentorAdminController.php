<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MentorApplication;
use App\Entity\MentorAvailability;
use App\Entity\MentorCustomRequest;
use App\Entity\MentorProfile;
use App\Entity\MentoringAppointment;
use App\Entity\MentoringAuditLog;
use App\Entity\User;
use App\Repository\SpecializationRepository;
use App\Repository\UserRepository;
use App\Repository\MentorProfileRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/mentoring')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class MentorAdminController extends AbstractController
{
    public function __construct(private readonly NotificationService $notificationService) {}

    #[Route('/super-admin', name: 'mentoring_super-admin', methods: ['GET'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function admin(): Response
    {
        return $this->redirectToRoute('mentoring_superadmin_requests', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/super-admin/mentors', name: 'mentoring_mentors_list', methods: ['GET'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function mentorsList(MentorProfileRepository $mentorProfileRepository): Response
    {
        return $this->render('mentoring/mentors-list.html.twig', [
            'mentors' => $mentorProfileRepository->findActiveOrderedByDisplayName(),
            'is_super_admin' => $this->isGranted('ROLE_SUPER_ADMIN'),
        ]);
    }

    #[Route('/super-admin/mentor-requests', name: 'mentoring_superadmin_requests', methods: ['GET'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function adminMentorRequests(Request $request, EntityManagerInterface $em, SpecializationRepository $specializationRepository, UserRepository $userRepository, MentorProfileRepository $mentorProfileRepository): Response
    {
        $allRequests = $em->getRepository(MentorCustomRequest::class)->findBy([], ['createdAt' => 'DESC'], 50);
        $assistanceRequests = array_values(array_filter($allRequests, fn($r) => $r->isAssistanceRequest()));
        $directRequests     = array_values(array_filter($allRequests, fn($r) => !$r->isAssistanceRequest()));

        $specializations = $specializationRepository->findAllOrderedByName();

        $allItems = [];
        foreach ($assistanceRequests as $req) {
            $allItems[] = ['type' => 'request', 'item' => $req, 'createdAt' => $req->getCreatedAt(), 'id' => $req->getId()];
        }

        $applications = $em->getRepository(MentorApplication::class)->findBy([], ['createdAt' => 'DESC'], 100);
        foreach ($applications as $application) {
            $allItems[] = ['type' => 'application', 'item' => $application, 'createdAt' => $application->getCreatedAt(), 'id' => $application->getId()];
        }

        usort($allItems, fn($a, $b) => $b['createdAt'] <=> $a['createdAt']);

        return $this->render('mentoring/superadmin-mentor-requests.html.twig', [
            'requests'           => $assistanceRequests,
            'directRequests'     => $directRequests,
            'allItems'           => $allItems,
            'mentors'            => $mentorProfileRepository->findActiveOrderedByDisplayName(),
            'leaderboard'        => $mentorProfileRepository->findActiveLeaderboard(10),
            'users'              => $userRepository->findEligibleMentorCreationUsers(),
            'applications'       => $applications,
            'appointments'       => $em->getRepository(MentoringAppointment::class)->findBy([], ['scheduledAt' => 'DESC'], 20),
            'is_super_admin'     => $this->isGranted('ROLE_SUPER_ADMIN'),
            'auditLogs'          => [],
            'allSpecializations' => $specializations,
        ]);
    }

    #[Route('/super-admin/mentor-requests/data', name: 'mentoring_superadmin_requests_data', methods: ['GET'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function adminMentorRequestsData(EntityManagerInterface $em): JsonResponse
    {
        $allRequests    = $em->getRepository(MentorCustomRequest::class)->findBy([], ['createdAt' => 'DESC'], 50);
        $assistanceReqs = array_values(array_filter($allRequests, fn($r) => $r->isAssistanceRequest()));
        $applications   = $em->getRepository(MentorApplication::class)->findBy([], ['createdAt' => 'DESC'], 100);

        $mapReq = static function (MentorCustomRequest $r): array {
            $s = $r->getStudent();
            return [
                'id'                      => $r->getId(),
                'fullName'                => $r->getFullName() ?: trim(($s?->getFirstName() ?? '') . ' ' . ($s?->getLastName() ?? '')),
                'email'                   => $s?->getEmail() ?? '',
                'departmentCourse'        => $r->getDepartmentCourse() ?? '',
                'preferredExpertise'      => $r->getPreferredExpertise() ?? '',
                'availableDates'          => $r->getAvailableDates() ?? '',
                'preferredSchedule'       => $r->getPreferredSchedule() ?? '',
                'availableTime'           => $r->getAvailableTime() ?? '',
                'assignedMentorName'      => $r->getAssignedMentorName() ?? '',
                'assignedMentorExpertise' => $r->getAssignedMentorExpertise() ?? '',
                'meetingMethod'           => $r->getMeetingMethod() ?? '',
                'adminInstructions'       => $r->getAdminInstructions() ?? '',
                'externalMentorEmail'     => $r->getExternalMentorEmail() ?? '',
                'message'                 => $r->getMessage() ?? '',
                'status'                  => $r->getStatus(),
                'createdAt'               => $r->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        };

        $mapApp = static function (MentorApplication $a): array {
            return [
                'id'             => $a->getId(),
                'firstName'      => $a->getFirstName() ?? '',
                'lastName'       => $a->getLastName() ?? '',
                'email'          => $a->getEmail(),
                'specialization' => $a->getSpecialization(),
                'status'         => $a->getStatus(),
                'createdAt'      => $a->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        };

        $response = $this->json([
            'requests'     => array_map($mapReq, $assistanceReqs),
            'applications' => array_map($mapApp, $applications),
            'ts'           => time(),
        ]);
        $response->setMaxAge(0)->headers->addCacheControlDirective('no-store');
        return $response;
    }

    #[Route('/super-admin/audit-log/data', name: 'mentoring_audit_log_data', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function auditLogData(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = max(5, min(50, (int) $request->query->get('per_page', 15)));
        $search  = trim((string) $request->query->get('search', ''));

        /** @var \App\Repository\MentoringAuditLogRepository $repo */
        $repo   = $em->getRepository(MentoringAuditLog::class);
        $result = $repo->findPaginated($page, $perPage, $search);

        $serialize = static function (MentoringAuditLog $log): array {
            return [
                'id'              => $log->getId(),
                'loggedAt'        => $log->getLoggedAt()->format('M d, Y'),
                'loggedAtTime'    => $log->getLoggedAt()->format('h:i A'),
                'subjectLabel'    => $log->getSubjectLabel(),
                'subjectId'       => $log->getSubjectId(),
                'subjectType'     => $log->getSubjectType(),
                'action'          => $log->getAction(),
                'previousStatus'  => $log->getPreviousStatus(),
                'newStatus'       => $log->getNewStatus(),
                'performedByName' => $log->getPerformedByName() ?? 'System',
                'performedByRole' => $log->getPerformedByRole() ?? '',
                'note'            => $log->getNote() ?? '',
            ];
        };

        $response = $this->json([
            'logs'     => array_map($serialize, $result['logs']),
            'total'    => $result['total'],
            'pages'    => $result['pages'],
            'page'     => $result['page'],
            'per_page' => $perPage,
            'ts'       => time(),
        ]);
        $response->setMaxAge(0)->headers->addCacheControlDirective('no-store');
        return $response;
    }

    #[Route('/admin/application/{id}/{decision}', name: 'mentoring_review_application', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reviewApplication(MentorApplication $application, string $decision, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isGranted('ROLE_SUPER_ADMIN')) {
            $this->addFlash('mentor_error', 'Only Super Admin can approve or decline mentor applications.');
            return $this->redirectToRoute('mentoring_superadmin_requests');
        }

        if (!$this->isCsrfTokenValid('review_mentor_application_' . $application->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!in_array($decision, ['approve', 'decline'], true)) {
            throw $this->createNotFoundException();
        }

        if (!in_array($application->getStatus(), ['Pending', 'Pending Review'], true)) {
            $this->addFlash('mentor_error', 'Only pending applications can be reviewed.');
            return $this->redirectToRoute('mentoring_superadmin_requests');
        }

        if ($decision === 'decline') {
            $prevStatus   = $application->getStatus();
            $application->setStatus('Rejected')->setAdminNote($request->request->get('admin_note'));
            $subjectLabel = trim(($application->getFirstName() ?? '') . ' ' . ($application->getLastName() ?? '')) ?: $application->getEmail();
            $this->auditLog($em, ['type' => 'application', 'id' => $application->getId(), 'label' => $subjectLabel, 'action' => 'reject', 'prev' => $prevStatus, 'next' => 'Rejected', 'note' => $request->request->get('admin_note')]);
            $em->flush();

            $this->notificationService->notifyMentorApplicationRejected($application->getStudent(), $application->getId(), $request->request->get('admin_note'));

            try {
                $admins      = $em->getRepository(User::class)->findAdmins();
                $actor       = $this->getUser();
                $applicantName = trim(($application->getFirstName() ?? '') . ' ' . ($application->getLastName() ?? '')) ?: $application->getEmail();
                $actorName   = $actor instanceof User ? trim($actor->getFirstName() . ' ' . $actor->getLastName()) : 'Super Admin';
                foreach ($admins as $admin) {
                    if ($admin === $actor) continue;
                    try {
                        $this->notificationService->notifyAdminWithEmail($admin, 'mentor', 'Mentor Application Rejected', $actorName . ' rejected the mentor application from ' . $applicantName . '.', 'Rejected', $application->getId());
                    } catch (\Exception $e) {}
                }
            } catch (\Exception $e) {}

            $this->addFlash('mentor_success', 'Mentor application rejected.');
            return $this->redirectToRoute('mentoring_superadmin_requests');
        }

        $validUntil = $request->request->get('valid_until');
        if (!$validUntil) {
            $this->addFlash('mentor_error', 'Valid until date is required when approving a mentor application.');
            return $this->redirectToRoute('mentoring_superadmin_requests');
        }
        $validUntilDate = \DateTime::createFromFormat('Y-m-d', $validUntil);
        if ($validUntilDate) {
            $application->setValidUntil($validUntilDate);
        }

        $student = $application->getStudent();
        $roles   = $student->getRoles();
        if (!in_array('ROLE_STUDENT', $roles)) { $roles[] = 'ROLE_STUDENT'; }
        if (!in_array('ROLE_MENTOR', $roles))  { $roles[] = 'ROLE_MENTOR'; }
        $student->setRoles(array_values(array_unique($roles)));

        $existingProfile = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $student]);
        if (!$existingProfile) {
            $name    = trim(($application->getFirstName() ?? '') . ' ' . ($application->getLastName() ?? '')) ?: $student->getEmail();
            $profile = (new MentorProfile())->setUser($student)->setDisplayName($name)->setSpecialization($application->getSpecialization())->setBio($application->getMentoringPublicBio() ?: $application->getReason());
            $em->persist($profile);
        }

        $prevStatus   = $application->getStatus();
        $application->setStatus('Approved');
        $subjectLabel = trim(($application->getFirstName() ?? '') . ' ' . ($application->getLastName() ?? '')) ?: $application->getEmail();
        $this->auditLog($em, ['type' => 'application', 'id' => $application->getId(), 'label' => $subjectLabel, 'action' => 'approve', 'prev' => $prevStatus, 'next' => 'Approved', 'note' => $validUntil ? 'Valid until: ' . $validUntil : null]);
        $em->flush();

        $this->notificationService->notifyMentorApplicationApproved($student, $application->getId());

        try {
            $admins      = $em->getRepository(User::class)->findAdmins();
            $actor       = $this->getUser();
            $applicantName = trim(($application->getFirstName() ?? '') . ' ' . ($application->getLastName() ?? '')) ?: $application->getEmail();
            $actorName   = $actor instanceof User ? trim($actor->getFirstName() . ' ' . $actor->getLastName()) : 'Super Admin';
            foreach ($admins as $admin) {
                if ($admin === $actor) continue;
                try {
                    $this->notificationService->notifyAdminWithEmail($admin, 'mentor', 'Mentor Application Approved', $actorName . ' approved the mentor application from ' . $applicantName . '.', 'Approved', $application->getId());
                } catch (\Exception $e) {}
            }
        } catch (\Exception $e) {}

        $this->addFlash('mentor_success', 'Student approved as mentor.');
        return $this->redirectToRoute('mentoring_superadmin_requests');
    }

    #[Route('/admin/application/{id}/delete', name: 'mentoring_delete_application', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function deleteApplication(MentorApplication $application, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_mentor_application_' . $application->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $student       = $application->getStudent();
        $applicationId = $application->getId();
        $em->remove($application);
        $em->flush();

        $this->notificationService->notifyMentorApplicationRejected($student, $applicationId, 'Your mentor application was deleted by Super Admin.');
        $this->addFlash('mentor_success', 'Mentor application deleted and the user has been notified.');
        return $this->redirectToRoute('mentoring_superadmin_requests');
    }

    #[Route('/admin/mentor', name: 'mentoring_create_mentor', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function createMentor(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('create_mentor', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $em->getRepository(User::class)->find((int) $request->request->get('user_id'));
        if (!$user) {
            $this->addFlash('mentor_error', 'User not found.');
            return $this->redirectToRoute('mentoring_superadmin_requests');
        }

        $existing = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $user]);
        if ($existing && in_array('ROLE_MENTOR', $user->getRoles(), true)) {
            $this->addFlash('mentor_error', 'This user already has an active mentor profile.');
            return $this->redirectToRoute('mentoring_superadmin_requests');
        }

        $availabilityDays = $request->request->all('availabilityDays') ?? [];
        $availStart       = trim((string) $request->request->get('availability_start'));
        $availEnd         = trim((string) $request->request->get('availability_end'));

        if (empty($availabilityDays) || !is_array($availabilityDays)) {
            $this->addFlash('mentor_error', 'Please select at least one mentoring day.');
            return $this->redirectToRoute('mentoring_superadmin_requests');
        }

        $profile = ($existing ?: (new MentorProfile())->setUser($user))
            ->setDisplayName((string) $request->request->get('display_name', $user->getEmail()))
            ->setSpecialization((string) $request->request->get('specialization', 'General'))
            ->setBio($request->request->get('bio'))
            ->setAvailabilityDays($availabilityDays)
            ->setAvailabilityStart($availStart !== '' ? $availStart : null)
            ->setAvailabilityEnd($availEnd !== '' ? $availEnd : null);

        $roles   = $user->getRoles();
        $roles[] = 'ROLE_MENTOR';
        $user->setRoles(array_values(array_unique($roles)));

        if (!$existing) {
            $em->persist($profile);
        }
        $mentorLabel = trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? '')) ?: $user->getEmail();
        $this->auditLog($em, ['type' => 'application', 'id' => null, 'label' => $mentorLabel, 'action' => 'create_mentor', 'prev' => null, 'next' => 'Active', 'note' => 'Mentor profile created manually']);
        $em->flush();

        $this->notificationService->notifyMentorProfileCreated($user);

        try {
            $admins     = $em->getRepository(User::class)->findAdmins();
            $mentorName = trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? '')) ?: $user->getEmail();
            foreach ($admins as $admin) {
                try {
                    $this->notificationService->notifyAdminWithEmail($admin, 'mentor', 'New Mentor Profile Created', 'A new mentor profile has been created for ' . $mentorName . '.', 'Active', $profile->getId());
                } catch (\Exception $e) {}
            }
        } catch (\Exception $e) {}

        $this->addFlash('mentor_success', 'Mentor profile created.');
        return $this->redirectToRoute('mentoring_superadmin_requests');
    }

    #[Route('/admin/mentor/{id}/edit', name: 'mentoring_edit_mentor', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function editMentor(MentorProfile $mentor, Request $request, EntityManagerInterface $em, SpecializationRepository $specializationRepository): Response
    {
        $specializations = $specializationRepository->findAllOrderedByName();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_mentor_' . $mentor->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $displayName         = trim((string) $request->request->get('display_name'));
            $programCourse       = trim((string) $request->request->get('program_course'));
            $specialization      = trim((string) $request->request->get('specialization'));
            $specializationOther = trim((string) $request->request->get('specialization_other'));
            if (in_array(strtolower($specialization), ['other', 'others'], true)) {
                $specialization = $specializationOther;
            }
            $bio              = trim((string) $request->request->get('bio'));
            $education        = trim((string) $request->request->get('mentor_education'));
            $availabilityDays = $request->request->all('availabilityDays') ?? [];
            $availStart       = trim((string) $request->request->get('availability_start'));
            $availEnd         = trim((string) $request->request->get('availability_end'));

            if ($displayName === '' || $programCourse === '' || $specialization === '' || $education === '' || $bio === '' || $availStart === '' || $availEnd === '') {
                $this->addFlash('mentor_error', 'Please complete all mentor profile fields before saving.');
                return $this->redirectToRoute('mentoring_edit_mentor', ['id' => $mentor->getId()]);
            }

            if (empty($availabilityDays) || !is_array($availabilityDays)) {
                $this->addFlash('mentor_error', 'Please select at least one mentoring day.');
                return $this->redirectToRoute('mentoring_edit_mentor', ['id' => $mentor->getId()]);
            }

            if ($availStart !== '' && $availEnd !== '' && $availEnd <= $availStart) {
                $this->addFlash('mentor_error', 'End time must be after start time.');
                return $this->redirectToRoute('mentoring_edit_mentor', ['id' => $mentor->getId()]);
            }

            $mentor->getUser()?->setDegreeName($programCourse);
            $mentor->setDisplayName($displayName)->setSpecialization($specialization)->setBio($bio)->setEducation($education)->setAvailabilityDays($availabilityDays)->setAvailabilityStart($availStart)->setAvailabilityEnd($availEnd);
            $em->flush();

            $this->addFlash('mentor_success', 'Mentor profile updated successfully.');
            return $this->redirectToRoute('mentoring_superadmin_requests');
        }

        return $this->render('mentoring/edit_mentor.html.twig', [
            'mentor'             => $mentor,
            'allSpecializations' => $specializations,
        ]);
    }

    #[Route('/admin/mentor/{id}/delete', name: 'mentoring_delete_mentor', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function deleteMentor(MentorProfile $mentor, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_mentor_' . $mentor->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user  = $mentor->getUser();
        $roles = array_values(array_filter($user->getRoles(), fn($role) => $role !== 'ROLE_MENTOR'));
        $user->setRoles($roles);
        $em->remove($mentor);
        $em->flush();

        $this->notificationService->notifyMentorProfileDeleted($user);

        $applications = $em->getRepository(MentorApplication::class)->findBy(['student' => $user]);
        foreach ($applications as $application) {
            $em->remove($application);
        }
        $em->flush();

        $this->addFlash('mentor_success', 'Mentor profile deleted successfully and user has been notified.');
        return $this->redirectToRoute('mentoring_superadmin_requests');
    }

    #[Route('/admin/mentor/{id}/availability', name: 'mentoring_add_availability', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function addAvailability(MentorProfile $mentor, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('mentor_availability_' . $mentor->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $date  = \DateTime::createFromFormat('Y-m-d', (string) $request->request->get('available_date'));
        $start = \DateTime::createFromFormat('H:i', (string) $request->request->get('start_time'));
        $end   = \DateTime::createFromFormat('H:i', (string) $request->request->get('end_time'));

        if (!$date || !$start || !$end || $end <= $start) {
            $this->addFlash('mentor_error', 'Please enter a valid availability date and time range.');
            return $this->redirectToRoute('mentoring_superadmin_requests');
        }

        $availability = (new MentorAvailability())->setMentor($mentor)->setAvailableDate($date)->setStartTime($start)->setEndTime($end);
        $em->persist($availability);
        $em->flush();

        $this->addFlash('mentor_success', 'Availability added.');
        return $this->redirectToRoute('mentoring_superadmin_requests');
    }

    #[Route('/admin/appointment/{id}/{status}', name: 'mentoring_update_status', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateStatus(MentoringAppointment $appointment, string $status, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('mentoring_status_' . $appointment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!in_array($status, ['Approved', 'Completed', 'Rejected'], true)) {
            throw $this->createNotFoundException();
        }

        $appointment->setStatus($status);
        if ($status === 'Completed') {
            $appointment->getMentor()->addEngagementPoints(15);
        }
        $em->flush();

        return $this->redirectToRoute('mentoring_superadmin_requests');
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
}
