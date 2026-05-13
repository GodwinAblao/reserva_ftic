<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MentorAvailability;
use App\Entity\MentorCustomRequest;
use App\Entity\MentorApplication;
use App\Entity\MentorProfile;
use App\Entity\MentoringAppointment;
use App\Entity\User;
use App\Service\NotificationService;
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
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $this->ensureFacultyMentorProfiles($em);

        $currentUser = $this->getUser();
        $mentorProfile = $currentUser instanceof User
            ? $em->getRepository(MentorProfile::class)->findOneBy(['user' => $currentUser])
            : null;

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
        $specializations = $this->specializationStats($em);

        // Check if this is an AJAX request
        $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
        if ($isAjax) {
            // Return the mentor cards and preferred specialization section as HTML
            $mentorHtml = $this->renderView('mentoring/_mentor_cards.html.twig', [
                'mentors' => $mentors,
            ]);
            $specializationsHtml = $this->renderView('mentoring/_preferred_specializations.html.twig', [
                'specializations' => $specializations,
            ]);
            return new JsonResponse([
                'html' => $mentorHtml,
                'specializationsHtml' => $specializationsHtml,
            ]);
        }

        $appointments = $currentUser instanceof User
            ? $em->getRepository(MentoringAppointment::class)->findBy(['student' => $currentUser], ['scheduledAt' => 'DESC'])
            : [];
        $leaderboard = $em->getRepository(MentorProfile::class)->findBy([], ['engagementPoints' => 'DESC'], 10);
        
        // Get all unique specializations for the dropdown
        $allSpecializations = $em->getRepository(MentorProfile::class)->createQueryBuilder('m')
            ->select('DISTINCT m.specialization')
            ->orderBy('m.specialization', 'ASC')
            ->getQuery()
            ->getResult();

        $availability = $em->getRepository(MentorAvailability::class)->findBy(['isBooked' => false], ['availableDate' => 'ASC', 'startTime' => 'ASC']);
        $applications = $currentUser instanceof User
            ? $em->getRepository(MentorApplication::class)->findBy(['student' => $currentUser], ['createdAt' => 'DESC'])
            : [];
        $customRequestRepo = $em->getRepository(MentorCustomRequest::class);
        $sentCustomRequests = $currentUser instanceof User
            ? $customRequestRepo->findByStudent($currentUser)
            : [];
        $incomingCustomRequests = $mentorProfile
            ? $customRequestRepo->findByMentor($mentorProfile)
            : [];

        // Check if user can apply as mentor (no active applications and not already a mentor)
        $canApplyAsMentor = $currentUser instanceof User 
            && $this->isGranted('ROLE_STUDENT') 
            && !$this->isGranted('ROLE_MENTOR')
            && empty(array_filter($applications, fn($app) => in_array($app->getStatus(), ['Pending', 'Approved'])));

        return $this->render('mentoring/index.html.twig', [
            'mentors' => $mentors,
            'appointments' => $appointments,
            'leaderboard' => $leaderboard,
            'specializations' => $specializations,
            'allSpecializations' => $allSpecializations,
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
    public function admin(Request $request, EntityManagerInterface $em): Response
    {
        $this->ensureFacultyMentorProfiles($em);

        $mentorRequestRepo = $em->getRepository(MentorCustomRequest::class);
        $statusFilter = trim((string) $request->query->get('status', ''));
        $departmentFilter = trim((string) $request->query->get('department', ''));
        $dateFilter = trim((string) $request->query->get('date', ''));

        return $this->render('mentoring/super-admin.html.twig', [
            'mentors' => $em->getRepository(MentorProfile::class)->findBy([], ['displayName' => 'ASC']),
            'appointments' => $em->getRepository(MentoringAppointment::class)->findBy([], ['scheduledAt' => 'DESC']),
            'applications' => $em->getRepository(MentorApplication::class)->findBy([], ['createdAt' => 'DESC']),
            'mentorRequests' => $mentorRequestRepo->findAssistanceRequests($statusFilter ?: null, $departmentFilter ?: null, $dateFilter ?: null),
            'requestFilters' => [
                'status' => $statusFilter,
                'department' => $departmentFilter,
                'date' => $dateFilter,
            ],
            'users' => $em->getRepository(User::class)->findAll(),
        ]);
    }

    #[Route('/admin/mentor-requests', name: 'mentoring_admin_requests', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminMentorRequests(Request $request, EntityManagerInterface $em): Response
    {
        $statusFilter = trim((string) $request->query->get('status', ''));
        $departmentFilter = trim((string) $request->query->get('department', ''));
        $dateFilter = trim((string) $request->query->get('date', ''));

        return $this->render('mentoring/admin-requests.html.twig', [
            'mentorRequests' => $em->getRepository(MentorCustomRequest::class)->findAssistanceRequests($statusFilter ?: null, $departmentFilter ?: null, $dateFilter ?: null),
            'requestFilters' => [
                'status' => $statusFilter,
                'department' => $departmentFilter,
                'date' => $dateFilter,
            ],
        ]);
    }

#[Route('/mentor-application', name: 'mentoring_apply', methods: ['POST'])]
    #[IsGranted('ROLE_STUDENT')]
    public function applyForMentor(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('mentor_application', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $this->getUser();
        
        // Get form data
        $email = trim((string) $request->request->get('email'));
        $firstName = trim((string) $request->request->get('firstName'));
        $middleName = trim((string) $request->request->get('middleName'));
        $lastName = trim((string) $request->request->get('lastName'));
        $contactNumber = trim((string) $request->request->get('contactNumber'));
        $specialization = trim((string) $request->request->get('specialization'));
        $yearsOfExperience = $request->request->get('yearsOfExperience') ? (int)$request->request->get('yearsOfExperience') : null;
        $currentProfession = trim((string) $request->request->get('currentProfession'));
        $highestEducation = trim((string) $request->request->get('highestEducation'));
        $supportingDescription = trim((string) $request->request->get('supportingDescription'));
        
        // Validation
        if (!$user instanceof User || $email === '' || $specialization === '' || $firstName === '' || $lastName === '') {
            $this->addFlash('error', 'Name, email, and specialization are required.');

            return $this->redirectToRoute('mentoring_index');
        }

        // Validate proof of expertise is required
        $files = $request->files->get('proofOfExpertise');
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
            $this->addFlash('error', 'Proof of Expertise is required. Please upload at least one file (JPG, PNG, or PDF).');
            return $this->redirectToRoute('mentoring_index');
        }

        // Handle file uploads (store as JSON array)
        $proofFiles = [];
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
                    // Store file with proper extension
                    $extension = $file->getClientOriginalExtension();
                    if (empty($extension)) {
                        $extension = 'pdf';
                    }
                    $newFilename = 'mentor_' . uniqid() . '.' . $extension;
                    $targetDir = $this->getParameter('kernel.project_dir') . '/public/uploads/profiles';
                    $file->move($targetDir, $newFilename);
                    $proofFiles[] = $newFilename;
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
            ->setContactNumber($contactNumber ?: null)
            ->setSpecialization($specialization)
            ->setYearsOfExperience($yearsOfExperience)
            ->setCurrentProfession($currentProfession ?: null)
            ->setHighestEducation($highestEducation ?: null)
            ->setSupportingDescription($supportingDescription ?: null)
            ->setProofOfExpertise($proofFiles ?: null)
            ->setStatus('Pending');

        $em->persist($application);
        $em->flush();

        // Notify the user
        $this->notificationService->notifyMentorApplicationSubmitted($user, $application->getId());

        // Notify all super admins
        $superAdmins = $em->getRepository(User::class)->findAdmins();
        foreach ($superAdmins as $admin) {
            $this->notificationService->notifyAdminNewMentorApplication($admin, $application->getId(), $firstName . ' ' . $lastName);
        }

        $this->addFlash('success', 'Your mentor application has been submitted and is pending Super Admin review.');

        return $this->redirectToRoute('mentoring_index');
    }

#[Route('/admin/application/{id}/{decision}', name: 'mentoring_review_application', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reviewApplication(MentorApplication $application, string $decision, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('review_mentor_application_' . $application->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!in_array($decision, ['approve', 'decline'], true)) {
            throw $this->createNotFoundException();
        }

        // Allow reviewing any Pending application (no OTP required)
        if (!in_array($application->getStatus(), ['Pending', 'Pending Review'], true)) {
            $this->addFlash('error', 'Only pending applications can be reviewed.');

            return $this->redirectToRoute('mentoring_super-admin');
        }

        if ($decision === 'decline') {
            $application
                ->setStatus('Rejected')
                ->setAdminNote($request->request->get('admin_note'));
            $em->flush();

            // Notify the user
            $this->notificationService->notifyMentorApplicationRejected($application->getStudent(), $application->getId(), $request->request->get('admin_note'));

            $this->addFlash('success', 'Mentor application rejected.');

            return $this->redirectToRoute('mentoring_super-admin');
        }

        // Approve - set validity period if provided
$validUntil = $request->request->get('valid_until');
        if (!$validUntil) {
            $this->addFlash('error', 'Valid until date is required when approving a mentor application.');
            return $this->redirectToRoute('mentoring_super-admin');
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
                ->setBio($application->getSupportingDescription() ?: $application->getReason());

            $em->persist($profile);
        }

        $application->setStatus('Approved');
        $em->flush();

        // Notify the user
        $this->notificationService->notifyMentorApplicationApproved($student, $application->getId(), $validUntilDate);

        $this->addFlash('success', 'Student approved as mentor.');

        return $this->redirectToRoute('mentoring_super-admin');
    }

    #[Route('/admin/application/{id}/delete', name: 'mentoring_delete_application', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
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

        return $this->redirectToRoute('mentoring_super-admin');
    }

    #[Route('/admin/mentor', name: 'mentoring_create_mentor', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createMentor(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('create_mentor', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $em->getRepository(User::class)->find((int) $request->request->get('user_id'));
        if (!$user) {
            $this->addFlash('error', 'User not found.');

            return $this->redirectToRoute('mentoring_super-admin');
        }

        $existing = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $user]);
        if ($existing) {
            $this->addFlash('error', 'This user already has a mentor profile.');

            return $this->redirectToRoute('mentoring_super-admin');
        }

        $profile = (new MentorProfile())
            ->setUser($user)
            ->setDisplayName((string) $request->request->get('display_name', $user->getEmail()))
            ->setSpecialization((string) $request->request->get('specialization', 'General'))
            ->setBio($request->request->get('bio'));

        $roles = $user->getRoles();
        $roles[] = 'ROLE_MENTOR';
        $user->setRoles(array_values(array_unique($roles)));

        $em->persist($profile);
        $em->flush();

        // Notify the user that a mentor profile was created for them
        $this->notificationService->notifyMentorProfileCreated($user);

        $this->addFlash('success', 'Mentor profile created.');

        return $this->redirectToRoute('mentoring_super-admin');
    }

    #[Route('/admin/mentor/{id}/edit', name: 'mentoring_edit_mentor', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function editMentor(MentorProfile $mentor, Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_mentor_' . $mentor->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $displayName = trim((string) $request->request->get('display_name'));
            $specialization = trim((string) $request->request->get('specialization'));
            $bio = trim((string) $request->request->get('bio'));

            if ($displayName === '') {
                $this->addFlash('error', 'Display name is required.');
                return $this->redirectToRoute('mentoring_edit_mentor', ['id' => $mentor->getId()]);
            }

            $mentor->setDisplayName($displayName);
            $mentor->setSpecialization($specialization ?: null);
            $mentor->setBio($bio ?: null);

            $em->flush();

            $this->addFlash('success', 'Mentor profile updated successfully.');
            return $this->redirectToRoute('mentoring_super-admin');
        }

        return $this->render('mentoring/edit_mentor.html.twig', [
            'mentor' => $mentor,
        ]);
    }

    #[Route('/admin/mentor/{id}/delete', name: 'mentoring_delete_mentor', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
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
        return $this->redirectToRoute('mentoring_super-admin');
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

            return $this->redirectToRoute('mentoring_super-admin');
        }

        $availability = (new MentorAvailability())
            ->setMentor($mentor)
            ->setAvailableDate($date)
            ->setStartTime($start)
            ->setEndTime($end);

        $em->persist($availability);
        $em->flush();

        $this->addFlash('success', 'Availability added.');

        return $this->redirectToRoute('mentoring_super-admin');
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

        $currentUser = $this->getUser();
        $canSendCustomRequest = $currentUser instanceof User
            && $profile->getUser() !== null
            && $profile->getUser()->getId() !== $currentUser->getId();

        return $this->render('mentoring/show.html.twig', [
            'mentor' => $profile,
            'availabilities' => $availabilities,
            'myAppointments' => $appointmentsWithThisMentor,
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
                return new JsonResponse(['error' => 'Message too short.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Message too short.');
            return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
        }

        $customRequest = new \App\Entity\MentorCustomRequest();
        $customRequest->setStudent($currentUser)
            ->setMentorProfile($profile)
            ->setMessage($message)
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
                ->from('noreply@reserva-ftic.edu.ph')
                ->to($user->getEmail())
                ->subject('New Custom Mentoring Request from ' . $studentName)
                ->html($emailHtml);

            $mailer->send($emailMessage);
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
        $preferredExpertise = trim((string) $request->request->get('preferred_expertise'));
        $preferredSchedule = trim((string) $request->request->get('preferred_schedule'));
        $message = trim((string) $request->request->get('message'));

        if ($fullName === '' || $departmentCourse === '' || $preferredExpertise === '' || $preferredSchedule === '') {
            $this->addFlash('error', 'Please complete the required mentor request fields.');
            return $this->redirectToRoute('mentoring_index', [], Response::HTTP_SEE_OTHER);
        }

        $mentorRequest = (new MentorCustomRequest())
            ->setStudent($currentUser)
            ->setMentorProfile(null)
            ->setFullName($fullName)
            ->setDepartmentCourse($departmentCourse)
            ->setPreferredExpertise($preferredExpertise)
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

        $adminUrl = $this->generateUrl('mentoring_super-admin', [], UrlGeneratorInterface::ABSOLUTE_URL) . '#mentor-requests';
        $admins = $em->getRepository(User::class)->findAdmins();
        foreach ($admins as $admin) {
            $this->notificationService->notifyAdminNewMentorAssistanceRequest($admin, $mentorRequest->getId(), $fullName);
            $this->sendMentorAssistanceRequestEmail($mailer, $admin, $mentorRequest, $adminUrl);
        }

        $this->notificationService->notifyMentorAssistanceStatus(
            $currentUser,
            $mentorRequest->getId(),
            'Pending',
            'Your mentor assistance request has been submitted and is now pending review.'
        );

        $this->addFlash('success', 'Your mentor request has been submitted. Admin will review it and send mentor details once a match is found.');

        return $this->redirectToRoute('mentoring_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/assistance-request/{id}/update', name: 'mentoring_assistance_update', methods: ['POST'])]
    public function updateAssistanceRequest(MentorCustomRequest $mentorRequest, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('mentor_assistance_update_' . $mentorRequest->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User || $mentorRequest->getStudent()?->getId() !== $currentUser->getId() || !$mentorRequest->isAssistanceRequest()) {
            throw $this->createAccessDeniedException('You can only update your own mentor assistance requests.');
        }

        if ($mentorRequest->getStatus() !== 'Pending') {
            $this->addFlash('error', 'Only pending mentor requests can be updated.');
            return $this->redirectToRoute('mentoring_index', [], Response::HTTP_SEE_OTHER);
        }

        $action = (string) $request->request->get('action', 'update');
        if ($action === 'cancel') {
            $mentorRequest->setStatus('Cancelled');
            $em->flush();
            $this->addFlash('success', 'Your mentor request has been cancelled.');
            return $this->redirectToRoute('mentoring_index', [], Response::HTTP_SEE_OTHER);
        }

        $mentorRequest
            ->setFullName(trim((string) $request->request->get('full_name')) ?: $mentorRequest->getFullName())
            ->setDepartmentCourse(trim((string) $request->request->get('department_course')) ?: $mentorRequest->getDepartmentCourse())
            ->setPreferredExpertise(trim((string) $request->request->get('preferred_expertise')) ?: $mentorRequest->getPreferredExpertise())
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

        if (!$mentorRequest->isAssistanceRequest()) {
            throw $this->createNotFoundException();
        }

        $status = (string) $request->request->get('status', 'Reviewing');
        if (!in_array($status, ['Pending', 'Reviewing', 'Assigned', 'Completed'], true)) {
            throw $this->createNotFoundException();
        }

        $mentorName = trim((string) $request->request->get('mentor_name'));
        $expertise = trim((string) $request->request->get('expertise'));
        $availableDates = trim((string) $request->request->get('available_dates'));
        $availableTime = trim((string) $request->request->get('available_time'));
        $meetingMethod = trim((string) $request->request->get('meeting_method'));
        $instructions = trim((string) $request->request->get('instructions'));

        if (in_array($status, ['Assigned', 'Completed'], true) && ($mentorName === '' || $expertise === '' || $availableDates === '' || $availableTime === '' || $meetingMethod === '')) {
            $this->addFlash('error', 'Mentor name, expertise, dates, time, and meeting method are required before assigning a request.');
            return $this->redirectToRoute('mentoring_super-admin', [], Response::HTTP_SEE_OTHER);
        }

        $mentorRequest
            ->setStatus($status)
            ->setAssignedMentorName($mentorName ?: null)
            ->setAssignedMentorExpertise($expertise ?: null)
            ->setAvailableDates($availableDates ?: null)
            ->setAvailableTime($availableTime ?: null)
            ->setMeetingMethod($meetingMethod ?: null)
            ->setAdminInstructions($instructions ?: null);

        if (in_array($status, ['Assigned', 'Completed'], true)) {
            $mentorRequest->markResponded();
        }

        $student = $mentorRequest->getStudent();
        if ($student) {
            $notificationMessage = $status === 'Reviewing'
                ? 'Admin is now reviewing your mentor assistance request.'
                : 'A mentor match has been sent for your assistance request.';

            $this->notificationService->notifyMentorAssistanceStatus($student, $mentorRequest->getId(), $status, $notificationMessage);

            if (in_array($status, ['Assigned', 'Completed'], true)) {
                $this->sendMentorAssistanceResponseEmail($mailer, $student, $mentorRequest);
            }
        }

        $em->flush();
        $this->addFlash('success', 'Mentor request updated and the requester has been notified.');

        return $this->redirectToRoute('mentoring_super-admin', [], Response::HTTP_SEE_OTHER);
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
        $flashMessage = $decision === 'accept' ? 'Custom request accepted.' : 'Custom request declined.';
        $message = $decision === 'accept'
            ? 'Your custom mentoring request has been accepted by ' . $mentorProfile->getDisplayName() . '.'
            : 'Your custom mentoring request has been declined by ' . $mentorProfile->getDisplayName() . '.';

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
            ]);

            $emailMessage = (new Email())
                ->from('noreply@reserva-ftic.edu.ph')
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

        return $this->redirectToRoute('mentoring_super-admin');
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

    private function ensureFacultyMentorProfiles(EntityManagerInterface $em): void
    {
        $users = $em->getRepository(User::class)->findAll();
        $changed = false;

        foreach ($users as $user) {
            if (!in_array('ROLE_FACULTY', $user->getRoles(), true)) {
                continue;
            }

            if (!in_array('ROLE_MENTOR', $user->getRoles(), true)) {
                $roles = $user->getRoles();
                $roles[] = 'ROLE_MENTOR';
                $user->setRoles(array_values(array_unique($roles)));
                $changed = true;
            }

            $profile = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $user]);
            if ($profile) {
                continue;
            }

            $name = trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? '')) ?: $user->getEmail();
            $profile = (new MentorProfile())
                ->setUser($user)
                ->setDisplayName($name)
                ->setSpecialization('Faculty Mentor')
                ->setBio('Automatically added faculty mentor.');

            $em->persist($profile);
            $changed = true;
        }

        if ($changed) {
            $em->flush();
        }
    }

    private function sendMentorAssistanceRequestEmail(MailerInterface $mailer, User $admin, MentorCustomRequest $mentorRequest, string $adminUrl): void
    {
        try {
            $email = (new Email())
                ->from('noreply@reserva-ftic.edu.ph')
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
                ->from('noreply@reserva-ftic.edu.ph')
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
