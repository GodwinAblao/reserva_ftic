<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MentorApplication;
use App\Entity\User;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/mentoring')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class MentorApplicationController extends AbstractController
{
    public function __construct(private readonly NotificationService $notificationService) {}

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

        $email              = trim((string) $request->request->get('email'));
        $firstName          = trim((string) $request->request->get('firstName'));
        $middleName         = trim((string) $request->request->get('middleName'));
        $lastName           = trim((string) $request->request->get('lastName'));
        $programCourse      = trim((string) $request->request->get('programCourse'));
        $specialization     = trim((string) $request->request->get('specialization'));
        $currentProfession  = trim((string) $request->request->get('currentProfession'));
        $highestEducation   = trim((string) $request->request->get('highestEducation'));
        $mentoringPublicBio = trim((string) $request->request->get('mentoringPublicBio'));
        $availabilityTime   = trim((string) $request->request->get('availabilityTime'));
        $availabilityStart  = trim((string) $request->request->get('availabilityStart'));
        $availabilityEnd    = trim((string) $request->request->get('availabilityEnd'));
        $availabilityDays   = $request->request->all('availabilityDays') ?? [];

        if (!$user instanceof User || $email === '' || $specialization === '' || $firstName === '' || $lastName === '') {
            $this->addFlash('error', 'Name, email, and specialization are required.');
            return $this->redirectToRoute('mentoring_index');
        }

        if ($highestEducation === '') {
            $this->addFlash('error', 'Highest Educational Attainment is required.');
            return $this->redirectToRoute('mentoring_index');
        }

        if (empty($availabilityDays) || !is_array($availabilityDays)) {
            $this->addFlash('error', 'Please select at least one mentoring day.');
            return $this->redirectToRoute('mentoring_index');
        }

        if ($availabilityStart && $availabilityEnd) {
            $startHour = (int) substr($availabilityStart, 0, 2);
            $endHour   = (int) substr($availabilityEnd, 0, 2);
            if ($startHour < 7 || $startHour > 19 || $endHour < 7 || $endHour > 19) {
                $this->addFlash('error', 'Mentoring time must be between 7:00 AM and 7:00 PM.');
                return $this->redirectToRoute('mentoring_index');
            }
            if ($availabilityEnd <= $availabilityStart) {
                $this->addFlash('error', 'End time must be after start time.');
                return $this->redirectToRoute('mentoring_index');
            }
        }

        $uploadedFiles = $this->validateUploadedFiles($request);
        if ($uploadedFiles === null) {
            return $this->redirectToRoute('mentoring_index');
        }

        $active = $em->createQueryBuilder()
            ->select('a')->from(MentorApplication::class, 'a')
            ->where('a.student = :student')->andWhere('a.status IN (:statuses)')
            ->setParameter('student', $user)->setParameter('statuses', ['Pending', 'Approved'])
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();

        if ($active) {
            $this->addFlash('error', 'You already have an active mentor application.');
            return $this->redirectToRoute('mentoring_index');
        }

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
            ->setSupportingDocuments(null)
            ->setStatus('Pending');

        $em->persist($application);
        $em->flush();

        try {
            $this->notificationService->notifyMentorApplicationSubmitted($user, $application->getId());
            $admins        = $em->getRepository(User::class)->findAdmins();
            $applicantName = trim($firstName . ' ' . $lastName);
            foreach ($admins as $admin) {
                try {
                    $this->notificationService->notifyAdminNewMentorApplication($admin, $application->getId(), $applicantName);
                } catch (\Exception $e) {}
            }
        } catch (\Exception $e) {}

        register_shutdown_function(function () use ($uploadedFiles, $em, $application) {
            try {
                $proofFiles = [];
                $targetDir  = $this->getParameter('kernel.project_dir') . '/public/uploads/profiles';
                foreach ($uploadedFiles as $fileInfo) {
                    $fileInfo['file']->move($targetDir, $fileInfo['filename']);
                    $proofFiles[] = $fileInfo['filename'];
                }
                $application->setSupportingDocuments($proofFiles ?: null);
                $em->flush();
            } catch (\Exception $e) {}
        });

        $this->addFlash('success', 'Your mentor application has been submitted and is pending Super Admin review.');
        return $this->redirectToRoute('mentoring_index');
    }

    #[Route('/application/{id}/cancel', name: 'mentoring_cancel_application', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancelApplication(MentorApplication $application, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || $application->getStudent() !== $user) {
            $this->addFlash('error', 'You can only cancel your own applications.');
            return $this->redirectToRoute('mentoring_index');
        }

        if (!in_array($application->getStatus(), ['Pending', 'Pending Review'], true)) {
            $this->addFlash('error', 'Only pending applications can be cancelled.');
            return $this->redirectToRoute('mentoring_index');
        }

        if (!$this->isCsrfTokenValid('cancel_application_' . $application->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $application->setStatus('Cancelled');
        $em->flush();
        $this->notifyAdminsMentorApplicationCancelled($application, $user, $em);

        $this->addFlash('success', 'Your mentor application has been cancelled.');
        return $this->redirectToRoute('mentoring_index');
    }

    private function notifyAdminsMentorApplicationCancelled(MentorApplication $application, User $user, EntityManagerInterface $em): void
    {
        $applicantName = trim(($application->getFirstName() ?? '') . ' ' . ($application->getLastName() ?? ''))
            ?: trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? ''))
            ?: $user->getEmail();
        $message = $applicantName . ' cancelled their mentor application.';

        foreach ($em->getRepository(User::class)->findAdmins() as $admin) {
            try {
                $this->notificationService->notifyAdminWithEmail(
                    $admin,
                    'mentor',
                    'Mentor Application Cancelled',
                    $message,
                    'Cancelled',
                    $application->getId()
                );
            } catch (\Throwable $e) {
                error_log('Failed to notify admin of mentor application cancellation: ' . $e->getMessage());
            }
        }
    }

    /**
     * Validates uploaded files and returns structured array, or null on validation failure.
     *
     * @return array<int,array{file:\Symfony\Component\HttpFoundation\File\UploadedFile,extension:string,filename:string}>|null
     */
    private function validateUploadedFiles(Request $request): ?array
    {
        $files         = $request->files->get('supportingDocuments');
        $hasValidFiles = false;
        if ($files) {
            foreach ($files as $file) {
                if ($file && $file->isValid()) { $hasValidFiles = true; break; }
            }
        }
        if (!$hasValidFiles) {
            $this->addFlash('error', 'Supporting documents are required. Please upload at least one file (JPG, PNG, or PDF).');
            return null;
        }

        $uploadedFiles = [];
        $allowedTypes  = ['image/jpeg', 'image/png', 'application/pdf'];
        if ($files) {
            foreach ($files as $file) {
                if (!$file || !$file->isValid()) { continue; }
                if ($file->getSize() > 5 * 1024 * 1024) {
                    $this->addFlash('error', 'File ' . $file->getClientOriginalName() . ' exceeds 5MB limit.');
                    return null;
                }
                if (!in_array($file->getMimeType(), $allowedTypes)) {
                    $this->addFlash('error', 'File ' . $file->getClientOriginalName() . ' must be JPG, PNG, or PDF.');
                    return null;
                }
                $extension     = $file->getClientOriginalExtension() ?: 'pdf';
                $uploadedFiles[] = ['file' => $file, 'extension' => $extension, 'filename' => 'mentor_' . uniqid() . '.' . $extension];
            }
        }
        return $uploadedFiles;
    }
}
