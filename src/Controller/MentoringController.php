<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MentorAvailability;
use App\Entity\MentorCustomRequest;
use App\Entity\MentorApplication;
use App\Entity\MentorProfile;
use App\Entity\MentoringAppointment;
use App\Entity\MentoringAuditLog;
use App\Entity\User;
use App\Service\NotificationService;
use App\Repository\SpecializationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/mentoring')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class MentoringController extends AbstractController
{
    private NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }
    #[Route('', name: 'mentoring_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em, SpecializationRepository $specializationRepository): Response
    {
        $this->ensureFacultyMentorProfiles($em);

        $currentUser = $this->getUser();
        $mentorProfile = $currentUser instanceof User
            ? $em->getRepository(MentorProfile::class)->findOneBy(['user' => $currentUser])
            : null;

        $specializations = $specializationRepository->findAllOrderedByName();

        // Build specialization statistics for preferred specializations section
        $specializationStats = [];
        $customRequests = $em->getRepository(MentorCustomRequest::class)->findAll();
        foreach ($customRequests as $req) {
            $spec = $req->getPreferredExpertise();
            if ($spec) {
                if (!isset($specializationStats[$spec])) {
                    $specializationStats[$spec] = 0;
                }
                $specializationStats[$spec]++;
            }
        }

        // Only include specializations that have at least one custom request
        $specializationRows = [];
        foreach ($specializationStats as $specName => $total) {
            $specializationRows[] = [
                'specialization' => $specName,
                'total'          => $total,
            ];
        }

        // Sort by request count (descending)
        usort($specializationRows, fn($a, $b) => $b['total'] - $a['total']);

        // Get search and filter parameters
        $searchTerm = $request->query->get('search', '');
        $specializationFilter = $request->query->get('specialization', '');

        // Build query for mentors with filters
        $qb = $em->getRepository(MentorProfile::class)->createQueryBuilder('m')
            ->leftJoin('m.user', 'u')
            ->orderBy('m.engagementPoints', 'DESC');

        if ($searchTerm) {
            $qb->andWhere('CONCAT(u.firstName, \' \', u.lastName) LIKE :search OR u.email LIKE :search OR m.displayName LIKE :search')
                ->setParameter('search', '%' . $searchTerm . '%');
        }

        if ($specializationFilter) {
            $qb->andWhere('m.specialization = :specialization')
                ->setParameter('specialization', $specializationFilter);
        }

        $mentors = $qb->getQuery()->getResult();

        // Hide current user's own mentor profile from the listing
        if ($mentorProfile) {
            $mentors = array_filter($mentors, fn(MentorProfile $m) => $m->getId() !== $mentorProfile->getId());
        }

        $mentorApplicationMeta = [];

        $mentorUserIds = array_values(array_filter(array_map(
            static fn (MentorProfile $mentor): ?int => $mentor->getUser()?->getId(),
            $mentors
        )));

        if ($mentorUserIds !== []) {
            $approvedApplications = $em->getRepository(MentorApplication::class)->createQueryBuilder('ma')
                ->leftJoin('ma.student', 's')
                ->andWhere('s.id IN (:userIds)')
                ->andWhere('ma.status = :status')
                ->setParameter('userIds', $mentorUserIds)
                ->setParameter('status', 'Approved')
                ->orderBy('ma.createdAt', 'DESC')
                ->getQuery()
                ->getResult();

            foreach ($approvedApplications as $application) {
                if (!$application instanceof MentorApplication) {
                    continue;
                }

                $studentId = $application->getStudent()?->getId();
                if ($studentId === null || isset($mentorApplicationMeta[$studentId])) {
                    continue;
                }

                $mentorApplicationMeta[$studentId] = [
                    'specialization' => $application->getSpecialization(),
                ];
            }
        }

        // Check if this is an AJAX request
        $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
        if ($isAjax) {
            // Return the mentor cards and preferred specialization section as HTML
            $mentorHtml = $this->renderView('mentoring/_mentor_cards.html.twig', [
                'mentors' => $mentors,
                'mentorApplicationMeta' => $mentorApplicationMeta,
            ]);
            $specializationsHtml = $this->renderView('mentoring/_preferred_specializations.html.twig', [
                'specializations' => $specializationRows,
            ]);
            return new JsonResponse([
                'html' => $mentorHtml,
                'specializationsHtml' => $specializationsHtml,
            ]);
        }

        $appointments = $currentUser instanceof User
            ? $em->getRepository(MentoringAppointment::class)->findBy(['student' => $currentUser], ['scheduledAt' => 'DESC'])
            : [];
        $leaderboard = $em->getRepository(MentorProfile::class)->createQueryBuilder('mp')
            ->where('mp.engagementPoints > 0')
            ->orderBy('mp.engagementPoints', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $availability = $em->getRepository(MentorAvailability::class)->findBy(['isBooked' => false], ['availableDate' => 'ASC', 'startTime' => 'ASC']);
        $applications = $currentUser instanceof User
            ? $em->getRepository(MentorApplication::class)->findBy(['student' => $currentUser], ['createdAt' => 'DESC'])
            : [];
        $customRequestRepo = $em->getRepository(MentorCustomRequest::class);
        $rawSentRequests = $currentUser instanceof User
            ? $customRequestRepo->findByStudent($currentUser)
            : [];
        usort($rawSentRequests, static function ($a, $b) {
            $activeStatuses = ['Pending', 'Accepted', 'In Progress'];
            $aActive = in_array($a->getStatus(), $activeStatuses, true);
            $bActive = in_array($b->getStatus(), $activeStatuses, true);
            if ($aActive !== $bActive) return $aActive ? -1 : 1;
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });
        $sentCustomRequests = $rawSentRequests;
        $incomingCustomRequests = $mentorProfile
            ? $customRequestRepo->findByMentor($mentorProfile)
            : [];

        // Check if user can apply as mentor (no active applications and not already a mentor)
        $canApplyAsMentor = $currentUser instanceof User 
            && ($this->isGranted('ROLE_STUDENT') || $this->isGranted('ROLE_FACULTY'))
            && !$this->isGranted('ROLE_MENTOR')
            && empty(array_filter($applications, fn($app) => in_array($app->getStatus(), ['Pending', 'Approved'])));

        return $this->render('mentoring/index.html.twig', [
            'mentors' => $mentors,
            'mentorApplicationMeta' => $mentorApplicationMeta,
            'appointments' => $appointments,
            'leaderboard' => $leaderboard,
            'specializations' => $specializationRows,
            'allSpecializations' => $specializations,
            'availability' => $availability,
            'applications' => $applications,
            'sentCustomRequests' => $sentCustomRequests,
            'incomingCustomRequests' => $incomingCustomRequests,
            'mentorProfile' => $mentorProfile,
            'canApplyAsMentor' => $canApplyAsMentor,
        ]);
    }

    #[Route('/super-admin', name: 'mentoring_super-admin', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function admin(): Response
    {
        return $this->redirectToRoute('mentoring_superadmin_requests', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/super-admin/mentors', name: 'mentoring_mentors_list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function mentorsList(EntityManagerInterface $em): Response
    {
        return $this->render('mentoring/mentors-list.html.twig', [
            'mentors' => $em->getRepository(MentorProfile::class)->findBy([], ['displayName' => 'ASC']),
            'is_super_admin' => $this->isGranted('ROLE_SUPER_ADMIN'),
        ]);
    }

    #[Route('/super-admin/mentor-requests', name: 'mentoring_superadmin_requests', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminMentorRequests(Request $request, EntityManagerInterface $em, SpecializationRepository $specializationRepository): Response
    {
        $this->ensureFacultyMentorProfiles($em);
        $allRequests = $em->getRepository(MentorCustomRequest::class)->findBy([], ['createdAt' => 'DESC'], 50);
        $assistanceRequests = array_values(array_filter($allRequests, fn($r) => $r->isAssistanceRequest()));
        $directRequests     = array_values(array_filter($allRequests, fn($r) => !$r->isAssistanceRequest()));
        
        $specializations = $specializationRepository->findAllOrderedByName();
        
        return $this->render('mentoring/superadmin-mentor-requests.html.twig', [
            'requests'           => $assistanceRequests,
            'directRequests'     => $directRequests,
            'mentors'            => $em->getRepository(MentorProfile::class)->findBy([], ['displayName' => 'ASC']),
            'leaderboard'        => $em->getRepository(MentorProfile::class)->findBy([], ['engagementPoints' => 'DESC'], 10),
            'users'              => $em->getRepository(User::class)->findAll(),
            'applications'       => $em->getRepository(MentorApplication::class)->findBy([], ['createdAt' => 'DESC']),
            'appointments'       => $em->getRepository(MentoringAppointment::class)->findBy([], ['scheduledAt' => 'DESC'], 20),
            'is_super_admin'     => $this->isGranted('ROLE_SUPER_ADMIN'),
            'auditLogs'          => $em->getRepository(MentoringAuditLog::class)->findRecent(60),
            'allSpecializations' => $specializations,
        ]);
    }

    #[Route('/super-admin/mentor-requests/data', name: 'mentoring_superadmin_requests_data', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminMentorRequestsData(EntityManagerInterface $em): JsonResponse
    {
        $allRequests  = $em->getRepository(MentorCustomRequest::class)->findBy([], ['createdAt' => 'DESC'], 50);
        $assistanceReqs = array_values(array_filter($allRequests, fn($r) => $r->isAssistanceRequest()));
        $applications = $em->getRepository(MentorApplication::class)->findBy([], ['createdAt' => 'DESC']);

        $mapReq = static function (MentorCustomRequest $r): array {
            $s = $r->getStudent();
            return [
                'id'                  => $r->getId(),
                'fullName'            => $r->getFullName() ?: trim(($s?->getFirstName() ?? '') . ' ' . ($s?->getLastName() ?? '')),
                'email'               => $s?->getEmail() ?? '',
                'departmentCourse'    => $r->getDepartmentCourse() ?? '',
                'preferredExpertise'  => $r->getPreferredExpertise() ?? '',
                'availableDates'      => $r->getAvailableDates() ?? '',
                'preferredSchedule'   => $r->getPreferredSchedule() ?? '',
                'availableTime'       => $r->getAvailableTime() ?? '',
                'assignedMentorName'  => $r->getAssignedMentorName() ?? '',
                'assignedMentorExpertise' => $r->getAssignedMentorExpertise() ?? '',
                'meetingMethod'       => $r->getMeetingMethod() ?? '',
                'adminInstructions'   => $r->getAdminInstructions() ?? '',
                'message'             => $r->getMessage() ?? '',
                'status'              => $r->getStatus(),
                'createdAt'           => $r->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        };

        $mapApp = static function (MentorApplication $a): array {
            return [
                'id'            => $a->getId(),
                'firstName'     => $a->getFirstName() ?? '',
                'lastName'      => $a->getLastName() ?? '',
                'email'         => $a->getEmail(),
                'specialization'=> $a->getSpecialization(),
                'status'        => $a->getStatus(),
                'createdAt'     => $a->getCreatedAt()->format('Y-m-d H:i:s'),
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
    public function auditLogData(EntityManagerInterface $em): JsonResponse
    {
        $logs = $em->getRepository(MentoringAuditLog::class)->findRecent(60);
        $data = array_map(static function (MentoringAuditLog $log): array {
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
        }, $logs);
        $response = $this->json(['logs' => $data, 'ts' => time()]);
        $response->setMaxAge(0)->headers->addCacheControlDirective('no-store');
        return $response;
    }

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
                'message'                 => $r->getMessage() ?? '',
                'status'                  => $r->getStatus(),
                'createdAt'               => $r->getCreatedAt()->format('M d, Y'),
            ];
        }, $reqs);
        $response = $this->json(['requests' => $data, 'ts' => time()]);
        $response->setMaxAge(0)->headers->addCacheControlDirective('no-store');
        return $response;
    }

    #[Route('/mentor-application', name: 'mentoring_apply', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function applyForMentor(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('mentor_application', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$this->isGranted('ROLE_STUDENT') && !$this->isGranted('ROLE_FACULTY')) {
            throw $this->createAccessDeniedException('Only students and faculty may apply as mentor.');
        }

        $user = $this->getUser();
        
        // Get form data
        $email = trim((string) $request->request->get('email'));
        $firstName = trim((string) $request->request->get('firstName'));
        $middleName = trim((string) $request->request->get('middleName'));
        $lastName = trim((string) $request->request->get('lastName'));
        $programCourse = trim((string) $request->request->get('programCourse'));
        $specialization = trim((string) $request->request->get('specialization'));
        $currentProfession = trim((string) $request->request->get('currentProfession'));
        $highestEducation = trim((string) $request->request->get('highestEducation'));
        $mentoringPublicBio = trim((string) $request->request->get('mentoringPublicBio'));
        $availabilityTime = trim((string) $request->request->get('availabilityTime'));
        $availabilityStart = trim((string) $request->request->get('availabilityStart'));
        $availabilityEnd = trim((string) $request->request->get('availabilityEnd'));
        $availabilityDays = $request->request->all('availabilityDays') ?? [];

        // Validation
        if (!$user instanceof User || $email === '' || $specialization === '' || $firstName === '' || $lastName === '') {
            $this->addFlash('error', 'Name, email, and specialization are required.');
            return $this->redirectToRoute('mentoring_index');
        }

        // Validate highest education is required
        if ($highestEducation === '') {
            $this->addFlash('error', 'Highest Educational Attainment is required.');
            return $this->redirectToRoute('mentoring_index');
        }

        // Validate at least one mentoring day is selected
        if (empty($availabilityDays) || !is_array($availabilityDays)) {
            $this->addFlash('error', 'Please select at least one mentoring day.');
            return $this->redirectToRoute('mentoring_index');
        }

        // Validate time range is within 7AM-7PM
        if ($availabilityStart && $availabilityEnd) {
            $startHour = (int)substr($availabilityStart, 0, 2);
            $endHour = (int)substr($availabilityEnd, 0, 2);
            if ($startHour < 7 || $startHour > 19 || $endHour < 7 || $endHour > 19) {
                $this->addFlash('error', 'Mentoring time must be between 7:00 AM and 7:00 PM.');
                return $this->redirectToRoute('mentoring_index');
            }
            if ($availabilityEnd <= $availabilityStart) {
                $this->addFlash('error', 'End time must be after start time.');
                return $this->redirectToRoute('mentoring_index');
            }
        }

        // Validate supporting documents is required
        $files = $request->files->get('supportingDocuments');
        $hasValidFiles = false;
        if ($files) {
            foreach ($files as $file) {
                if ($file && $file->isValid()) {
                    $hasValidFiles = true;
                    break;
                }
            }
        }
        if (!$hasValidFiles) {
            $this->addFlash('error', 'Supporting documents are required. Please upload at least one file (JPG, PNG, or PDF).');
            return $this->redirectToRoute('mentoring_index');
        }

        // Validate files but don't move them yet (defer to async)
        $uploadedFiles = [];
        if ($files) {
            foreach ($files as $file) {
                if ($file && $file->isValid()) {
                    // Validate file size (5MB max)
                    if ($file->getSize() > 5 * 1024 * 1024) {
                        $this->addFlash('error', 'File ' . $file->getClientOriginalName() . ' exceeds 5MB limit.');
                        return $this->redirectToRoute('mentoring_index');
                    }
                    // Validate file type
                    $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
                    if (!in_array($file->getMimeType(), $allowedTypes)) {
                        $this->addFlash('error', 'File ' . $file->getClientOriginalName() . ' must be JPG, PNG, or PDF.');
                        return $this->redirectToRoute('mentoring_index');
                    }
                    // Store file info for async processing
                    $extension = $file->getClientOriginalExtension();
                    if (empty($extension)) {
                        $extension = 'pdf';
                    }
                    $uploadedFiles[] = [
                        'file' => $file,
                        'extension' => $extension,
                        'filename' => 'mentor_' . uniqid() . '.' . $extension
                    ];
                }
            }
        }

        // Check for existing active application
        $active = $em->createQueryBuilder()
            ->select('a')
            ->from(MentorApplication::class, 'a')
            ->where('a.student = :student')
            ->andWhere('a.status IN (:statuses)')
            ->setParameter('student', $user)
            ->setParameter('statuses', ['Pending', 'Approved'])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($active) {
            $this->addFlash('error', 'You already have an active mentor application.');

            return $this->redirectToRoute('mentoring_index');
        }

        // Create application directly (no OTP)
        $application = (new MentorApplication())
            ->setStudent($user)
            ->setEmail($email)
            ->setFirstName($firstName)
            ->setMiddleName($middleName ?: null)
            ->setLastName($lastName)
            ->setProgramCourse($programCourse ?: null)
            ->setSpecialization($specialization)
            ->setCurrentProfession($currentProfession ?: null)
            ->setHighestEducation($highestEducation ?: null)
            ->setMentoringPublicBio($mentoringPublicBio ?: null)
            ->setAvailabilityTime($availabilityTime ?: null)
            ->setSupportingDocuments(null) // Will be set after file upload
            ->setStatus('Pending');

        $em->persist($application);
        $em->flush();

        // Defer file uploads and notifications to after response to prevent blocking
        register_shutdown_function(function() use ($uploadedFiles, $em, $user, $application, $firstName, $lastName) {
            try {
                // Move files and update application
                $proofFiles = [];
                $targetDir = $this->getParameter('kernel.project_dir') . '/public/uploads/profiles';
                
                foreach ($uploadedFiles as $fileInfo) {
                    $fileInfo['file']->move($targetDir, $fileInfo['filename']);
                    $proofFiles[] = $fileInfo['filename'];
                }
                
                $application->setSupportingDocuments($proofFiles ?: null);
                $em->flush();

                // Notify the user
                $this->notificationService->notifyMentorApplicationSubmitted($user, $application->getId());

                // Notify only super admins
                $superAdmins = $em->getRepository(User::class)->createQueryBuilder('u')
                    ->where('u.roles LIKE :role')
                    ->setParameter('role', '%ROLE_SUPER_ADMIN%')
                    ->getQuery()
                    ->getResult();
                
                foreach ($superAdmins as $admin) {
                    $this->notificationService->create(
                        $admin,
                        'mentor',
                        'New Mentor Application',
                        'A new mentor application has been submitted by ' . $firstName . ' ' . $lastName . '.',
                        'Pending',
                        $application->getId()
                    );
                }
            } catch (\Exception $e) {
                error_log('Failed to process application (async): ' . $e->getMessage());
            }
        });

        $this->addFlash('success', 'Your mentor application has been submitted and is pending Super Admin review.');

        return $this->redirectToRoute('mentoring_index');
    }

#[Route('/admin/application/{id}/{decision}', name: 'mentoring_review_application', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reviewApplication(MentorApplication $application, string $decision, Request $request, EntityManagerInterface $em): Response
    {
        // Only Super Admin can approve or decline mentor applications
        if (!$this->isGranted('ROLE_SUPER_ADMIN')) {
            $this->addFlash('error', 'Only Super Admin can approve or decline mentor applications.');
            return $this->redirectToRoute('mentoring_superadmin_requests');
        }

        if (!$this->isCsrfTokenValid('review_mentor_application_' . $application->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!in_array($decision, ['approve', 'decline'], true)) {
            throw $this->createNotFoundException();
        }

        // Allow reviewing any Pending application (no OTP required)
        if (!in_array($application->getStatus(), ['Pending', 'Pending Review'], true)) {
            $this->addFlash('error', 'Only pending applications can be reviewed.');

            return $this->redirectToRoute('mentoring_superadmin_requests');
        }

        if ($decision === 'decline') {
            $prevStatus = $application->getStatus();
            $application
                ->setStatus('Rejected')
                ->setAdminNote($request->request->get('admin_note'));
            $subjectLabel = trim(($application->getFirstName() ?? '') . ' ' . ($application->getLastName() ?? '')) ?: $application->getEmail();
            $this->auditLog($em, 'application', $application->getId(), $subjectLabel, 'reject', $prevStatus, 'Rejected', $request->request->get('admin_note'));
            $em->flush();

            // Notify the user
            $this->notificationService->notifyMentorApplicationRejected($application->getStudent(), $application->getId(), $request->request->get('admin_note'));

            $this->addFlash('success', 'Mentor application rejected.');

            return $this->redirectToRoute('mentoring_superadmin_requests');
        }

        // Approve - set validity period if provided
$validUntil = $request->request->get('valid_until');
        if (!$validUntil) {
            $this->addFlash('error', 'Valid until date is required when approving a mentor application.');
            return $this->redirectToRoute('mentoring_superadmin_requests');
        }
        $validUntilDate = \DateTime::createFromFormat('Y-m-d', $validUntil);
        if ($validUntilDate) {
            $application->setValidUntil($validUntilDate);
        }

        $student = $application->getStudent();
        $roles = $student->getRoles();
        if (!in_array('ROLE_STUDENT', $roles)) {
            $roles[] = 'ROLE_STUDENT';
        }
        if (!in_array('ROLE_MENTOR', $roles)) {
            $roles[] = 'ROLE_MENTOR';
        }
        $student->setRoles(array_values(array_unique($roles)));

        $existingProfile = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $student]);
        if (!$existingProfile) {
            $name = trim(($application->getFirstName() ?? '') . ' ' . ($application->getLastName() ?? '')) ?: $student->getEmail();
            $profile = (new MentorProfile())
                ->setUser($student)
                ->setDisplayName($name)
                ->setSpecialization($application->getSpecialization())
                ->setBio($application->getMentoringPublicBio() ?: $application->getReason());

            $em->persist($profile);
        }

        $prevStatus = $application->getStatus();
        $application->setStatus('Approved');
        $subjectLabel = trim(($application->getFirstName() ?? '') . ' ' . ($application->getLastName() ?? '')) ?: $application->getEmail();
        $this->auditLog($em, 'application', $application->getId(), $subjectLabel, 'approve', $prevStatus, 'Approved', $validUntil ? 'Valid until: ' . $validUntil : null);
        $em->flush();

        // Notify the user
        $this->notificationService->notifyMentorApplicationApproved($student, $application->getId());

        $this->addFlash('success', 'Student approved as mentor.');

        return $this->redirectToRoute('mentoring_superadmin_requests');
    }

    #[Route('/admin/application/{id}/delete', name: 'mentoring_delete_application', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function deleteApplication(MentorApplication $application, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_mentor_application_' . $application->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $student = $application->getStudent();
        $applicationId = $application->getId();

        $em->remove($application);
        $em->flush();

        $this->notificationService->notifyMentorApplicationRejected($student, $applicationId, 'Your mentor application was deleted by Super Admin.');
        $this->addFlash('success', 'Mentor application deleted and the user has been notified.');

        return $this->redirectToRoute('mentoring_superadmin_requests');
    }

    #[Route('/application/{id}/cancel', name: 'mentoring_cancel_application', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancelApplication(MentorApplication $application, Request $request, EntityManagerInterface $em): Response
    {
        // Security check: only the owner can cancel their own application
        if ($application->getStudent() !== $this->getUser()) {
            $this->addFlash('error', 'You can only cancel your own applications.');
            return $this->redirectToRoute('mentoring_index');
        }

        // Only pending applications can be cancelled
        if (!in_array($application->getStatus(), ['Pending', 'Pending Review'], true)) {
            $this->addFlash('error', 'Only pending applications can be cancelled.');
            return $this->redirectToRoute('mentoring_index');
        }

        if (!$this->isCsrfTokenValid('cancel_application_' . $application->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $application->setStatus('Cancelled');
        $em->flush();

        $this->addFlash('success', 'Your mentor application has been cancelled.');
        return $this->redirectToRoute('mentoring_index');
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
            $this->addFlash('error', 'User not found.');

            return $this->redirectToRoute('mentoring_superadmin_requests');
        }

        $existing = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $user]);
        if ($existing) {
            $this->addFlash('error', 'This user already has a mentor profile.');

            return $this->redirectToRoute('mentoring_superadmin_requests');
        }

        $availabilityDays = $request->request->all('availabilityDays') ?? [];
        $availStart = trim((string) $request->request->get('availability_start'));
        $availEnd   = trim((string) $request->request->get('availability_end'));

        // Validate at least one mentoring day is selected
        if (empty($availabilityDays) || !is_array($availabilityDays)) {
            $this->addFlash('error', 'Please select at least one mentoring day.');
            return $this->redirectToRoute('mentoring_superadmin_requests');
        }

        $profile = (new MentorProfile())
            ->setUser($user)
            ->setDisplayName((string) $request->request->get('display_name', $user->getEmail()))
            ->setSpecialization((string) $request->request->get('specialization', 'General'))
            ->setBio($request->request->get('bio'))
            ->setAvailabilityDays($availabilityDays)
            ->setAvailabilityStart($availStart !== '' ? $availStart : null)
            ->setAvailabilityEnd($availEnd !== '' ? $availEnd : null);

        $roles = $user->getRoles();
        $roles[] = 'ROLE_MENTOR';
        $user->setRoles(array_values(array_unique($roles)));

        $em->persist($profile);
        $mentorLabel = trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? '')) ?: $user->getEmail();
        $this->auditLog($em, 'application', null, $mentorLabel, 'create_mentor', null, 'Active', 'Mentor profile created manually');
        $em->flush();

        // Notify the user that a mentor profile was created for them
        $this->notificationService->notifyMentorProfileCreated($user);

        $this->addFlash('success', 'Mentor profile created.');

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

            $displayName           = trim((string) $request->request->get('display_name'));
            $specialization        = trim((string) $request->request->get('specialization'));
            $specializationOther   = trim((string) $request->request->get('specialization_other'));
            if ($specialization === 'Other' && $specializationOther !== '') {
                $specialization = $specializationOther;
            }
            $bio              = trim((string) $request->request->get('bio'));
            $education        = trim((string) $request->request->get('mentor_education'));
            $availabilityDays = $request->request->all('availabilityDays') ?? [];
            $availStart       = trim((string) $request->request->get('availability_start'));
            $availEnd         = trim((string) $request->request->get('availability_end'));

            if ($displayName === '' || $specialization === '') {
                $this->addFlash('error', 'Display name and specialization are required.');
                return $this->redirectToRoute('mentoring_edit_mentor', ['id' => $mentor->getId()]);
            }

            if (empty($availabilityDays) || !is_array($availabilityDays)) {
                $this->addFlash('error', 'Please select at least one mentoring day.');
                return $this->redirectToRoute('mentoring_edit_mentor', ['id' => $mentor->getId()]);
            }

            if ($availStart !== '' && $availEnd !== '' && $availEnd <= $availStart) {
                $this->addFlash('error', 'End time must be after start time.');
                return $this->redirectToRoute('mentoring_edit_mentor', ['id' => $mentor->getId()]);
            }

            $mentor->setDisplayName($displayName);
            $mentor->setSpecialization($specialization);
            $mentor->setBio($bio ?: null);
            $mentor->setEducation($education ?: null);
            $mentor->setAvailabilityDays($availabilityDays);
            $mentor->setAvailabilityStart($availStart !== '' ? $availStart : null);
            $mentor->setAvailabilityEnd($availEnd !== '' ? $availEnd : null);

            $em->flush();

            $this->addFlash('success', 'Mentor profile updated successfully.');
            return $this->redirectToRoute('mentoring_superadmin_requests');
        }

        return $this->render('mentoring/edit_mentor.html.twig', [
            'mentor' => $mentor,
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

        $user = $mentor->getUser();
        
        // Remove ROLE_MENTOR from user
        $roles = $user->getRoles();
        $roles = array_filter($roles, fn($role) => $role !== 'ROLE_MENTOR');
        $user->setRoles(array_values($roles));

        // Delete mentor profile
        $em->remove($mentor);
        $em->flush();

        // Notify user about mentor profile deletion
        $this->notificationService->notifyMentorProfileDeleted($user);

        // Delete any related mentor applications for this user
        $applications = $em->getRepository(MentorApplication::class)->findBy(['student' => $user]);
        foreach ($applications as $application) {
            $em->remove($application);
        }
        $em->flush();

        $this->addFlash('success', 'Mentor profile deleted successfully and user has been notified.');
        return $this->redirectToRoute('mentoring_superadmin_requests');
    }

    #[Route('/admin/mentor/{id}/availability', name: 'mentoring_add_availability', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function addAvailability(MentorProfile $mentor, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('mentor_availability_' . $mentor->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $date = \DateTime::createFromFormat('Y-m-d', (string) $request->request->get('available_date'));
        $start = \DateTime::createFromFormat('H:i', (string) $request->request->get('start_time'));
        $end = \DateTime::createFromFormat('H:i', (string) $request->request->get('end_time'));

        if (!$date || !$start || !$end || $end <= $start) {
            $this->addFlash('error', 'Please enter a valid availability date and time range.');

            return $this->redirectToRoute('mentoring_superadmin_requests');
        }

        $availability = (new MentorAvailability())
            ->setMentor($mentor)
            ->setAvailableDate($date)
            ->setStartTime($start)
            ->setEndTime($end);

        $em->persist($availability);
        $em->flush();

        $this->addFlash('success', 'Availability added.');

        return $this->redirectToRoute('mentoring_superadmin_requests');
    }

    #[Route('/{id}', name: 'mentoring_show', methods: ['GET'])]
    public function show(MentorProfile $profile, EntityManagerInterface $em): Response
    {
        $this->ensureFacultyMentorProfiles($em);

        $availabilities = $em->getRepository(MentorAvailability::class)->findBy([
            'mentor' => $profile,
            'isBooked' => false
        ], ['availableDate' => 'ASC', 'startTime' => 'ASC']);

        $appointmentsWithThisMentor = $em->getRepository(MentoringAppointment::class)->findBy([
            'student' => $this->getUser(),
            'mentor' => $profile
        ], ['scheduledAt' => 'DESC']);

        // Fetch custom requests (scheduled mentoring sessions)
        $customRequestsWithThisMentor = $em->getRepository(\App\Entity\MentorCustomRequest::class)->findBy([
            'student' => $this->getUser(),
            'mentorProfile' => $profile
        ], ['createdAt' => 'DESC']);

        $currentUser = $this->getUser();
        $canSendCustomRequest = $currentUser instanceof User
            && $profile->getUser() !== null
            && $profile->getUser()->getId() !== $currentUser->getId();

        return $this->render('mentoring/show.html.twig', [
            'mentor' => $profile,
            'availabilities' => $availabilities,
            'myAppointments' => $appointmentsWithThisMentor,
            'myCustomRequests' => $customRequestsWithThisMentor,
            'canSendCustomRequest' => $canSendCustomRequest,
        ]);
    }

    #[Route('/{id}/preview', name: 'mentoring_preview', methods: ['GET'])]
    public function preview(MentorProfile $profile, EntityManagerInterface $em): Response
    {
        $this->ensureFacultyMentorProfiles($em);

        $currentUser = $this->getUser();
        $canSendCustomRequest = $currentUser instanceof User
            && $profile->getUser() !== null
            && $profile->getUser()->getId() !== $currentUser->getId();

        return $this->render('mentoring/_mentor_preview_modal_content.html.twig', [
            'mentor' => $profile,
            'canSendCustomRequest' => $canSendCustomRequest,
        ]);
    }

    #[Route('/{id}/custom-request', name: 'mentoring_custom_request', methods: ['POST'])]
    public function customRequest(MentorProfile $profile, Request $request, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        if (!$this->isCsrfTokenValid('custom_request_' . $profile->getId(), $request->request->get('_token'))) {
            $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
            if ($isAjax) {
                return new JsonResponse(['error' => 'Invalid request.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Invalid request.');
            return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException('You must be logged in to send a mentoring request.');
        }

        if ($profile->getUser() === null) {
            $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
            if ($isAjax) {
                return new JsonResponse(['error' => 'This mentor profile does not have an associated user account.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'This mentor profile does not have an associated user account.');
            return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
        }

        if ($profile->getUser()->getId() === $currentUser->getId()) {
            $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
            if ($isAjax) {
                return new JsonResponse(['error' => 'You cannot send a custom request to your own mentor profile.'], Response::HTTP_FORBIDDEN);
            }
            $this->addFlash('error', 'You cannot send a custom request to your own mentor profile.');
            return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
        }

        $message = trim($request->request->get('message', ''));
        if (strlen($message) < 10) {
            $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
            if ($isAjax) {
                return new JsonResponse(['error' => 'Message too short. Please provide at least 10 characters.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Message too short. Please provide at least 10 characters.');
            return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
        }

        // Handle scheduled date and time
        $scheduledDateStr = $request->request->get('scheduled_date');
        $scheduledTime = $request->request->get('scheduled_time');
        
        if (!$scheduledDateStr || !$scheduledTime) {
            $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
            if ($isAjax) {
                return new JsonResponse(['error' => 'Please select both a date and time for the mentoring session.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Please select both a date and time for the mentoring session.');
            return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
        }

        $scheduledDate = \DateTime::createFromFormat('Y-m-d', $scheduledDateStr);
        if (!$scheduledDate) {
            $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
            if ($isAjax) {
                return new JsonResponse(['error' => 'Invalid date format.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Invalid date format.');
            return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
        }

        $customRequest = new \App\Entity\MentorCustomRequest();
        $customRequest->setStudent($currentUser)
            ->setMentorProfile($profile)
            ->setMessage($message)
            ->setScheduledDate($scheduledDate)
            ->setScheduledTime($scheduledTime)
            ->setStatus('Pending');

        try {
            $em->persist($customRequest);
            $em->flush();
        } catch (\Exception $e) {
            $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
            if ($isAjax) {
                return new JsonResponse(['error' => 'Failed to save request: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            $this->addFlash('error', 'Failed to save request: ' . $e->getMessage());
            return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
        }

        $requestUrl = $this->generateUrl('mentoring_index', [], UrlGeneratorInterface::ABSOLUTE_URL) . '#custom-requests';

        // Create an in-site notification for the mentor so they see the request on the website
        try {
            $studentName = trim(($currentUser->getFirstName() ?? '') . ' ' . ($currentUser->getLastName() ?? ''));
            $studentName = $studentName !== '' ? $studentName : $currentUser->getEmail();
            $this->notificationService->create(
                $profile->getUser(),
                'mentor_request',
                'New Mentoring Request',
                'You received a new mentoring request from ' . $studentName . '.',
                'New',
                $customRequest->getId()
            );
        } catch (\Throwable $e) {
            // Do not break the flow if notification creation fails; it will be logged by the app if configured
        }

        // Email mentor with professional template
        $user = $profile->getUser();
        $student = $currentUser;
        try {
            $studentName = trim(($student->getFirstName() ?? '') . ' ' . ($student->getLastName() ?? ''));
            $studentName = $studentName !== '' ? $studentName : $student->getEmail();
            
            $emailHtml = $this->renderView('emails/mentor_custom_request.html.twig', [
                'mentorName' => $profile->getDisplayName(),
                'studentName' => $studentName,
                'studentEmail' => $student->getEmail(),
                'message' => $message,
                'requestId' => $customRequest->getId(),
                'requestUrl' => $requestUrl,
            ]);

            $emailMessage = (new Email())
                ->from('noreply@fticreserva.website')
                ->to($user->getEmail())
                ->subject('New Custom Mentoring Request from ' . $studentName)
                ->html($emailHtml);

            $mailer->send($emailMessage);
            
            // Send confirmation email to student
            $studentEmailHtml = $this->renderView('emails/student_custom_request_confirmation.html.twig', [
                'studentName' => $studentName,
                'mentorName' => $profile->getDisplayName(),
                'mentorEmail' => $user->getEmail(),
                'message' => $message,
                'scheduledDate' => $scheduledDate ? $scheduledDate->format('F d, Y') : null,
                'scheduledTime' => $scheduledTime,
                'requestId' => $customRequest->getId(),
                'requestUrl' => $requestUrl,
            ]);
            
            $studentEmailMessage = (new Email())
                ->from('noreply@fticreserva.website')
                ->to($student->getEmail())
                ->subject('Your Mentoring Request Has Been Sent')
                ->html($studentEmailHtml);
            
            $mailer->send($studentEmailMessage);
        } catch (\Exception $e) {
            // Log but don't fail - user has already seen success message
        }

        $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
        if ($isAjax) {
            return new JsonResponse([
                'success' => true,
                'message' => 'Custom request sent successfully!',
                'mentorName' => $profile->getDisplayName()
            ]);
        }

        $this->addFlash('success', 'Custom request sent! ' . $profile->getDisplayName() . ' will receive an email with your message and can respond within 24-48 hours.');

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

        $fullName = trim((string) $request->request->get('full_name'));
        $departmentCourse = trim((string) $request->request->get('department_course'));
        $preferredExpertise      = trim((string) $request->request->get('preferred_expertise'));
        $preferredExpertiseOther = trim((string) $request->request->get('preferred_expertise_other'));
        if ($preferredExpertise === 'Other' && $preferredExpertiseOther !== '') {
            $preferredExpertise = $preferredExpertiseOther;
        }
        $availableDates = trim((string) $request->request->get('available_dates'));
        $scheduleStart = trim((string) $request->request->get('preferred_schedule_start'));
        $scheduleEnd = trim((string) $request->request->get('preferred_schedule_end'));
        $fmtTime = static function (string $t): string {
            $dt = \DateTime::createFromFormat('H:i', $t);
            return $dt ? $dt->format('g:i A') : $t;
        };
        $preferredSchedule = ($scheduleStart !== '' && $scheduleEnd !== '') ? $fmtTime($scheduleStart) . ' – ' . $fmtTime($scheduleEnd) : '';
        $message = trim((string) $request->request->get('message'));

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
            $adminUrl = $this->generateUrl('mentoring_superadmin_requests', [], UrlGeneratorInterface::ABSOLUTE_URL) . '#mentor-requests';
            $admins = $em->getRepository(User::class)->findAdmins();
            $firstAdmin = true;
            
            foreach ($admins as $admin) {
                try {
                    $adminNotification = $this->notificationService->notifyAdminNewMentorAssistanceRequest($admin, $mentorRequest->getId(), $fullName);
                    error_log('Admin notification created successfully for user ' . $admin->getId() . ' with ID: ' . $adminNotification->getId());
                } catch (\Exception $e) {
                    error_log('Failed to create admin notification for user ' . $admin->getId() . ': ' . $e->getMessage());
                }
                
                // Only email the first admin to prevent SMTP lag; others get in-app notification
                if ($firstAdmin) {
                    try {
                        $this->sendMentorAssistanceRequestEmail($mailer, $admin, $mentorRequest, $adminUrl);
                    } catch (\Exception $e) {
                        error_log('Failed to send admin email: ' . $e->getMessage());
                    }
                    $firstAdmin = false;
                }
            }

            try {
                $userNotification = $this->notificationService->notifyMentorAssistanceStatus(
                    $currentUser,
                    $mentorRequest->getId(),
                    'Pending',
                    'Your mentor assistance request has been submitted and is now pending review.'
                );
                error_log('User notification created successfully with ID: ' . $userNotification->getId());
            } catch (\Exception $e) {
                error_log('Failed to create user notification: ' . $e->getMessage());
                error_log('Notification creation error trace: ' . $e->getTraceAsString());
            }

            $this->addFlash('success', 'Your mentor request has been submitted. Admin will review it and send mentor details once a match is found.');
        } catch (\Exception $e) {
            error_log('Error in mentor assistance request processing: ' . $e->getMessage());
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
            $mentorRequest->setStatus('Cancelled');
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

        $status = (string) $request->request->get('status', 'Pending');
        if (!in_array($status, ['Pending', 'Reviewing', 'Assigned', 'Completed', 'Cancelled'], true)) {
            throw $this->createNotFoundException();
        }

        // Resolve mentor name — either picked from existing mentors or typed manually
        $mentorId = (int) $request->request->get('mentor_id', 0);
        $mentorNameManual = trim((string) $request->request->get('mentor_name'));
        $mentorName = $mentorNameManual;
        $expertise      = trim((string) $request->request->get('expertise'));
        $expertiseOther = trim((string) $request->request->get('expertise_other'));
        if ($expertise === 'Other' && $expertiseOther !== '') {
            $expertise = $expertiseOther;
        }
        if ($mentorId > 0) {
            $existingMentor = $em->getRepository(MentorProfile::class)->find($mentorId);
            if ($existingMentor) {
                $mentorName = $mentorName ?: $existingMentor->getDisplayName();
                $expertise = $expertise ?: $existingMentor->getSpecialization();
            }
        }

        $availableDates = trim((string) $request->request->get('available_dates'));
        $timeStart = trim((string) $request->request->get('available_time_start'));
        $timeEnd   = trim((string) $request->request->get('available_time_end'));
        $fmtTime = static function (string $t): string {
            $dt = \DateTime::createFromFormat('H:i', $t);
            return $dt ? $dt->format('g:i A') : $t;
        };
        $availableTime = ($timeStart !== '' && $timeEnd !== '') ? $fmtTime($timeStart) . ' – ' . $fmtTime($timeEnd) : trim((string) $request->request->get('available_time'));
        $meetingMethod   = trim((string) $request->request->get('meeting_method'));
        $meetingLink     = trim((string) $request->request->get('meeting_link'));
        $meetingLocation = trim((string) $request->request->get('meeting_location'));
        $instructions = trim((string) $request->request->get('instructions'));

        if (in_array($status, ['Assigned', 'Completed'], true) && ($mentorName === '' || $expertise === '' || $availableDates === '' || $availableTime === '' || $meetingMethod === '')) {
            $this->addFlash('error', 'Mentor name, expertise, dates, time, and meeting method are required before assigning a request.');
            return $this->redirectToRoute('mentoring_superadmin_requests', [], Response::HTTP_SEE_OTHER);
        }

        $prevStatus = $mentorRequest->getStatus();
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
            $notificationMessage = $status === 'Cancelled'
                ? 'Your mentor assistance request has been cancelled.'
                : 'A mentor match has been sent for your assistance request.';

            $this->notificationService->notifyMentorAssistanceStatus($student, $mentorRequest->getId(), $status, $notificationMessage);

            if (in_array($status, ['Assigned', 'Completed'], true)) {
                $this->sendMentorAssistanceResponseEmail($mailer, $student, $mentorRequest);
            }
        }

        $noteText = $instructions !== '' ? $instructions : ($mentorName !== '' ? 'Assigned to: ' . $mentorName : null);
        $this->auditLog($em, 'custom_request', $mentorRequest->getId(), $requesterLabel, 'update_status', $prevStatus, $status, $noteText);
        $em->flush();

        $actor = $this->getUser();
        $actorName = $actor instanceof User ? trim($actor->getFirstName() . ' ' . $actor->getLastName()) : 'Super Admin';
        $requesterName = $mentorRequest->getFullName() ?: ($student ? trim($student->getFirstName() . ' ' . $student->getLastName()) : 'Student');
        foreach ($em->getRepository(User::class)->findAdmins() as $u) {
            if ($u === $actor) continue;
            $this->notificationService->notifyAdminMentorRequestUpdated($u, $mentorRequest->getId(), $actorName, $status, $requesterName);
        }

        $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
        if ($isAjax) {
            return $this->json(['success' => true, 'message' => 'Mentor request updated and the requester has been notified.']);
        }

        $this->addFlash('success', 'Mentor request updated and the requester has been notified.');

        return $this->redirectToRoute('mentoring_superadmin_requests', [], Response::HTTP_SEE_OTHER);
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

        $decision = strtolower((string) $request->request->get('decision', ''));
        $mentorResponse = trim((string) $request->request->get('mentor_response', ''));

        if (!in_array($decision, ['accept', 'decline'], true)) {
            throw $this->createNotFoundException();
        }

        $student = $customRequest->getStudent();
        if (!$student) {
            throw $this->createNotFoundException();
        }

        $studentName = trim(($student->getFirstName() ?? '') . ' ' . ($student->getLastName() ?? ''));
        $studentName = $studentName !== '' ? $studentName : $student->getEmail();

        $status = $decision === 'accept' ? 'accepted' : 'declined';
        $title = $decision === 'accept' ? 'Custom Mentoring Request Accepted' : 'Custom Mentoring Request Declined';
        $flashMessage = $decision === 'accept' ? 'Custom request accepted! Meeting details have been sent to the student.' : 'Custom request declined.';
        
        // Handle meeting details when accepting
        $facilityReservedBy = null;
        if ($decision === 'accept') {
            $meetingType = trim((string) $request->request->get('meeting_type', ''));
            $meetingLink = trim((string) $request->request->get('meeting_link', ''));
            $meetingLocation = trim((string) $request->request->get('meeting_location', ''));
            $facilityReservedBy = trim((string) $request->request->get('facility_reserved_by', ''));
            
            // Set meeting details on the request
            if ($meetingType) {
                $customRequest->setMeetingType($meetingType);
            }
            if ($meetingLink) {
                $customRequest->setMeetingLink($meetingLink);
            }
            if ($meetingLocation) {
                $customRequest->setMeetingLocation($meetingLocation);
            }
            if ($facilityReservedBy) {
                $customRequest->setFacilityReservedBy($facilityReservedBy);
            }
            
            // Build detailed message with meeting info
            $meetingDetails = [];
            if ($customRequest->getScheduledDate()) {
                $meetingDetails[] = 'Date: ' . $customRequest->getScheduledDate()->format('F d, Y');
            }
            if ($customRequest->getScheduledTime()) {
                $meetingDetails[] = 'Time: ' . $customRequest->getScheduledTime();
            }
            if ($meetingType) {
                $meetingDetails[] = 'Meeting Type: ' . $meetingType;
            }
            if ($meetingLink) {
                $meetingDetails[] = 'Meeting Link: ' . $meetingLink;
            }
            
            // Handle location based on F2F option
            if ($meetingType === 'Face-to-Face') {
                if ($facilityReservedBy === 'mentor') {
                    $meetingDetails[] = 'Location: Mentor will reserve a facility';
                } elseif ($facilityReservedBy === 'student') {
                    $meetingDetails[] = 'Location: You need to reserve a facility';
                } elseif ($facilityReservedBy === 'outside') {
                    $meetingDetails[] = 'Location: ' . $meetingLocation;
                }
            }
            
            $message = 'Your custom mentoring request has been accepted by ' . $mentorProfile->getDisplayName() . '.';
            if (!empty($meetingDetails)) {
                $message .= ' Meeting details: ' . implode(' | ', $meetingDetails);
            }
            
            // Add facility reservation instruction based on who handles it
            if ($meetingType === 'Face-to-Face') {
                if ($facilityReservedBy === 'mentor') {
                    $message .= ' The mentor will reserve a facility and notify you of the location.';
                } elseif ($facilityReservedBy === 'student') {
                    $message .= ' Please reserve a facility through the FTIC reservation system before the session.';
                }
            }
        } else {
            $message = 'Your custom mentoring request has been declined by ' . $mentorProfile->getDisplayName() . '.';
        }

        if ($mentorResponse !== '') {
            $customRequest->setMentorResponse($mentorResponse);
        }
        $customRequest->setStatus($status);

        // Increment engagement points when mentor accepts a request
        if ($decision === 'accept') {
            $mentorProfile->setEngagementPoints(($mentorProfile->getEngagementPoints() ?? 0) + 1);
        }

        $studentRequestUrl = $this->generateUrl('mentoring_index', [], UrlGeneratorInterface::ABSOLUTE_URL) . '#my-custom-requests';

        try {
            $emailHtml = $this->renderView('emails/mentor_custom_request_status.html.twig', [
                'studentName' => $studentName,
                'mentorName' => $mentorProfile->getDisplayName(),
                'status' => ucfirst($status),
                'message' => $message,
                'mentorResponse' => $mentorResponse ?: null,
                'requestUrl' => $studentRequestUrl,
                'facilityReservedBy' => $facilityReservedBy,
            ]);

            $emailMessage = (new Email())
                ->from('noreply@fticreserva.website')
                ->to($student->getEmail())
                ->subject($title)
                ->html($emailHtml);

            $mailer->send($emailMessage);
        } catch (\Exception $e) {
            // Email failure should not block the in-site status update.
        }

        $this->notificationService->create(
            $student,
            'mentor',
            $title,
            $message,
            ucfirst($status),
            $customRequest->getId()
        );

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

    private function specializationStats(EntityManagerInterface $em): array
    {
        $appointmentCounts = $em->createQueryBuilder()
            ->select('m.specialization AS specialization, COUNT(a.id) AS total')
            ->from(MentoringAppointment::class, 'a')
            ->join('a.mentor', 'm')
            ->groupBy('m.specialization')
            ->getQuery()
            ->getArrayResult();

        $customRequestCounts = $em->createQueryBuilder()
            ->select('m.specialization AS specialization, COUNT(cr.id) AS total')
            ->from(MentorCustomRequest::class, 'cr')
            ->join('cr.mentorProfile', 'm')
            ->groupBy('m.specialization')
            ->getQuery()
            ->getArrayResult();

        $totals = [];
        foreach (array_merge($appointmentCounts, $customRequestCounts) as $row) {
            $spec = $row['specialization'];
            $totals[$spec] = ($totals[$spec] ?? 0) + (int) $row['total'];
        }

        arsort($totals);

        $results = [];
        foreach ($totals as $specialization => $total) {
            $results[] = [
                'specialization' => $specialization,
                'total' => $total,
            ];
        }

        return $results;
    }

    private function auditLog(
        EntityManagerInterface $em,
        string $subjectType,
        ?int $subjectId,
        string $subjectLabel,
        string $action,
        ?string $previousStatus,
        ?string $newStatus,
        ?string $note = null
    ): void {
        $actor = $this->getUser();
        $actorName = null;
        $actorRole = null;
        if ($actor instanceof User) {
            $actorName = trim(($actor->getFirstName() ?? '') . ' ' . ($actor->getLastName() ?? ''));
            if ($actorName === '') {
                $actorName = $actor->getEmail();
            }
            $actorRole = $this->isGranted('ROLE_SUPER_ADMIN') ? 'Super Admin' : 'Admin';
        }

        /** @var \App\Repository\MentoringAuditLogRepository $repo */
        $repo = $em->getRepository(MentoringAuditLog::class);
        if ($repo->existsRecent($subjectType, $subjectId, $action, $newStatus, $actor instanceof User ? $actor->getId() : null)) {
            return;
        }

        $log = (new MentoringAuditLog())
            ->setSubjectType($subjectType)
            ->setSubjectId($subjectId)
            ->setSubjectLabel($subjectLabel)
            ->setAction($action)
            ->setPreviousStatus($previousStatus)
            ->setNewStatus($newStatus)
            ->setPerformedBy($actor instanceof User ? $actor : null)
            ->setPerformedByName($actorName)
            ->setPerformedByRole($actorRole)
            ->setNote($note);
        $em->persist($log);
    }

    private function ensureFacultyMentorProfiles(EntityManagerInterface $em): void
    {
        // Previously this method automatically granted `ROLE_MENTOR` to all users
        // with `ROLE_FACULTY` and created `MentorProfile` entries. Per the
        // product decision, faculty must now apply through the same mentor
        // application process as students and not be auto-enrolled. Keep this
        // method as a no-op to preserve backward compatibility for callers.
        return;
    }

    private function sendMentorAssistanceRequestEmail(MailerInterface $mailer, User $admin, MentorCustomRequest $mentorRequest, string $adminUrl): void
    {
        try {
            $email = (new Email())
                ->from('noreply@fticreserva.website')
                ->to($admin->getEmail())
                ->subject('New Mentor Assistance Request')
                ->html($this->renderView('emails/mentor_assistance_request.html.twig', [
                    'request' => $mentorRequest,
                    'adminUrl' => $adminUrl,
                ]));

            $mailer->send($email);
        } catch (\Throwable $e) {
            // Email delivery should not block the in-system workflow.
        }
    }

    private function sendMentorAssistanceResponseEmail(MailerInterface $mailer, User $student, MentorCustomRequest $mentorRequest): void
    {
        try {
            $email = (new Email())
                ->from('noreply@fticreserva.website')
                ->to($student->getEmail())
                ->subject('Mentor Details for Your Request')
                ->html($this->renderView('emails/mentor_assistance_response.html.twig', [
                    'request' => $mentorRequest,
                    'student' => $student,
                    'requestUrl' => $this->generateUrl('mentoring_index', [], UrlGeneratorInterface::ABSOLUTE_URL) . '#my-custom-requests',
                ]));

            $mailer->send($email);
        } catch (\Throwable $e) {
            // Email delivery should not block the in-system notification.
        }
    }
}
