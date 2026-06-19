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
use App\Repository\MentorProfileRepository;
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
class MentoringController extends AbstractController
{
    private NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    #[Route('', name: 'mentoring_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em, SpecializationRepository $specializationRepository, MentorProfileRepository $mentorProfileRepository): Response
    {
        $this->ensureFacultyMentorProfiles($em);

        $currentUser = $this->getUser();
        $mentorProfile = $currentUser instanceof User
            ? $em->getRepository(MentorProfile::class)->findOneBy(['user' => $currentUser])
            : null;

        $specializations = $specializationRepository->findAllOrderedByName();

        // Build specialization statistics — aggregate only, no full entity hydration
        $specializationRows = $em->createQueryBuilder()
            ->select('r.preferredExpertise AS specialization, COUNT(r.id) AS total')
            ->from(MentorCustomRequest::class, 'r')
            ->where('r.preferredExpertise IS NOT NULL')
            ->groupBy('r.preferredExpertise')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();

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

        $mentors = $mentorProfileRepository->filterActiveProfiles($qb->getQuery()->getResult());

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
        $leaderboard = $mentorProfileRepository->filterActiveProfiles($leaderboard);

        $availability = array_values(array_filter(
            $em->getRepository(MentorAvailability::class)->findBy(['isBooked' => false], ['availableDate' => 'ASC', 'startTime' => 'ASC'], 50),
            static fn (MentorAvailability $slot): bool => $slot->getMentor()->getUser() !== null && in_array('ROLE_MENTOR', $slot->getMentor()->getUser()->getRoles(), true)
        ));
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

    #[Route('/{id}', name: 'mentoring_show', methods: ['GET'])]
    public function show(MentorProfile $profile, EntityManagerInterface $em, SpecializationRepository $specializationRepository): Response
    {
        $this->ensureFacultyMentorProfiles($em);
        if (!$this->isActiveMentorProfile($profile)) {
            throw $this->createNotFoundException();
        }

        $availabilities = $em->getRepository(MentorAvailability::class)->findBy([
            'mentor' => $profile,
            'isBooked' => false
        ], ['availableDate' => 'ASC', 'startTime' => 'ASC']);

        $appointmentsWithThisMentor = $em->getRepository(MentoringAppointment::class)->findBy([
            'student' => $this->getUser(),
            'mentor' => $profile
        ], ['scheduledAt' => 'DESC']);

        // Fetch mentor requests (scheduled mentoring sessions)
        $customRequestsWithThisMentor = $em->getRepository(\App\Entity\MentorCustomRequest::class)->findBy([
            'student' => $this->getUser(),
            'mentorProfile' => $profile
        ], ['createdAt' => 'DESC']);

        $currentUser = $this->getUser();
        $canSendCustomRequest = $currentUser instanceof User
            && $profile->getUser() !== null
            && $profile->getUser()->getId() !== $currentUser->getId();

        // Get all specializations for the request form
        $specializations = $specializationRepository->findAllOrderedByName();

        return $this->render('mentoring/show.html.twig', [
            'mentor' => $profile,
            'availabilities' => $availabilities,
            'myAppointments' => $appointmentsWithThisMentor,
            'myCustomRequests' => $customRequestsWithThisMentor,
            'canSendCustomRequest' => $canSendCustomRequest,
            'allSpecializations' => $specializations,
        ]);
    }

    #[Route('/{id}/preview', name: 'mentoring_preview', methods: ['GET'])]
    public function preview(MentorProfile $profile, EntityManagerInterface $em): Response
    {
        $this->ensureFacultyMentorProfiles($em);
        if (!$this->isActiveMentorProfile($profile)) {
            throw $this->createNotFoundException();
        }

        $currentUser = $this->getUser();
        $canSendCustomRequest = $currentUser instanceof User
            && $profile->getUser() !== null
            && $profile->getUser()->getId() !== $currentUser->getId();

        return $this->render('mentoring/_mentor_preview_modal_content.html.twig', [
            'mentor' => $profile,
            'canSendCustomRequest' => $canSendCustomRequest,
        ]);
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

    /**
     * @param array{type:string,id:?int,label:string,action:string,prev:?string,next:?string,note?:?string} $ctx
     */
    private function auditLog(EntityManagerInterface $em, array $ctx): void
    {
        $actor = $this->getUser();
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
                ->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))
                ->to($admin->getEmail())
                ->subject('New Mentor Assistance Request')
                ->html($this->renderView('emails/mentor_assistance_request.html.twig', [
                    'request' => $mentorRequest,
                    'adminUrl' => $adminUrl,
                ]));

            $mailer->send($email);
        } catch (\Throwable $e) {
            // Email delivery should not block the in-system notification.
        }
    }

    private function sendMentorAssistanceResponseEmail(MailerInterface $mailer, User $student, MentorCustomRequest $mentorRequest): void
    {
        try {
            $email = (new Email())
                ->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))
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

    private function isActiveMentorProfile(MentorProfile $profile): bool
    {
        $user = $profile->getUser();
        return $user !== null && in_array('ROLE_MENTOR', $user->getRoles(), true);
    }
}
