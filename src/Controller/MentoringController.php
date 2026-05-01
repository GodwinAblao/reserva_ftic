<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MentorAvailability;
use App\Entity\MentorApplication;
use App\Entity\MentorProfile;
use App\Entity\MentoringAppointment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/mentoring')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class MentoringController extends AbstractController
{
    #[Route('', name: 'mentoring_index', methods: ['GET'])]
    public function index(EntityManagerInterface $em): Response
    {
        $this->ensureFacultyMentorProfiles($em);

        $mentors = $em->getRepository(MentorProfile::class)->findBy([], ['engagementPoints' => 'DESC']);
        $appointments = $em->getRepository(MentoringAppointment::class)->findBy(['student' => $this->getUser()], ['scheduledAt' => 'DESC']);
        $leaderboard = $em->getRepository(MentorProfile::class)->findBy([], ['engagementPoints' => 'DESC'], 10);
        $specializations = $this->specializationStats($em);
        $availability = $em->getRepository(MentorAvailability::class)->findBy(['isBooked' => false], ['availableDate' => 'ASC', 'startTime' => 'ASC']);
        $applications = $em->getRepository(MentorApplication::class)->findBy(['student' => $this->getUser()], ['createdAt' => 'DESC']);

        return $this->render('mentoring/index.html.twig', [
            'mentors' => $mentors,
            'appointments' => $appointments,
            'leaderboard' => $leaderboard,
            'specializations' => $specializations,
            'availability' => $availability,
            'applications' => $applications,
        ]);
    }

    #[Route('/admin', name: 'mentoring_admin', methods: ['GET'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function admin(EntityManagerInterface $em): Response
    {
        $this->ensureFacultyMentorProfiles($em);

        return $this->render('mentoring/admin.html.twig', [
            'mentors' => $em->getRepository(MentorProfile::class)->findBy([], ['displayName' => 'ASC']),
            'appointments' => $em->getRepository(MentoringAppointment::class)->findBy([], ['scheduledAt' => 'DESC']),
            'applications' => $em->getRepository(MentorApplication::class)->findBy([], ['createdAt' => 'DESC']),
            'users' => $em->getRepository(User::class)->findAll(),
        ]);
    }

    #[Route('/mentor-application', name: 'mentoring_apply', methods: ['POST'])]
    #[IsGranted('ROLE_STUDENT')]
    public function applyForMentor(Request $request, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        if (!$this->isCsrfTokenValid('mentor_application', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $this->getUser();
        $email = trim((string) $request->request->get('email'));
        $reason = trim((string) $request->request->get('reason'));
        $specialization = trim((string) $request->request->get('specialization'));

        if (!$user instanceof User || $email === '' || $reason === '' || $specialization === '') {
            $this->addFlash('error', 'Email, specialization, and reason are required.');

            return $this->redirectToRoute('mentoring_index');
        }

        if (strcasecmp($email, $user->getEmail()) !== 0 && strcasecmp($email, (string) $user->getInstitutionalEmail()) !== 0) {
            $this->addFlash('error', 'Use the email connected to your student account.');

            return $this->redirectToRoute('mentoring_index');
        }

        $active = $em->createQueryBuilder()
            ->select('a')
            ->from(MentorApplication::class, 'a')
            ->where('a.student = :student')
            ->andWhere('a.status IN (:statuses)')
            ->setParameter('student', $user)
            ->setParameter('statuses', ['Awaiting OTP', 'Pending Review'])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($active) {
            $this->addFlash('error', 'You already have an active mentor application.');

            return $this->redirectToRoute('mentoring_index');
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $application = (new MentorApplication())
            ->setStudent($user)
            ->setEmail($email)
            ->setReason($reason)
            ->setSpecialization($specialization)
            ->setOtpCode($otp)
            ->setOtpExpiresAt(new \DateTime('+10 minutes'));

        $em->persist($application);
        $em->flush();

        try {
            $message = (new Email())
                ->from(new Address('hurstdale101@gmail.com', 'Reserva FTIC'))
                ->to($email)
                ->subject('Reserva FTIC Mentor Application OTP')
                ->text(sprintf('Your mentor application OTP is %s. It expires in 10 minutes.', $otp));

            $mailer->send($message);
            $this->addFlash('success', 'OTP sent to your email. Enter it below to submit your request to the Super Admin.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Application saved, but OTP email could not be sent: ' . $e->getMessage());
        }

        return $this->redirectToRoute('mentoring_index');
    }

    #[Route('/mentor-application/{id}/verify', name: 'mentoring_verify_application', methods: ['POST'])]
    #[IsGranted('ROLE_STUDENT')]
    public function verifyApplication(MentorApplication $application, Request $request, EntityManagerInterface $em): Response
    {
        if ($application->getStudent() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('verify_mentor_application_' . $application->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($application->getStatus() !== 'Awaiting OTP') {
            $this->addFlash('error', 'This application is no longer waiting for OTP verification.');

            return $this->redirectToRoute('mentoring_index');
        }

        if ($application->getOtpExpiresAt() < new \DateTime()) {
            $this->addFlash('error', 'OTP expired. Please submit a new application.');

            return $this->redirectToRoute('mentoring_index');
        }

        if (trim((string) $request->request->get('otp')) !== $application->getOtpCode()) {
            $this->addFlash('error', 'Invalid OTP.');

            return $this->redirectToRoute('mentoring_index');
        }

        $application
            ->setIsOtpVerified(true)
            ->setStatus('Pending Review');
        $em->flush();

        $this->addFlash('success', 'Your mentor application is now pending Super Admin review.');

        return $this->redirectToRoute('mentoring_index');
    }

    #[Route('/admin/application/{id}/{decision}', name: 'mentoring_review_application', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function reviewApplication(MentorApplication $application, string $decision, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('review_mentor_application_' . $application->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!in_array($decision, ['approve', 'decline'], true)) {
            throw $this->createNotFoundException();
        }

        if ($application->getStatus() !== 'Pending Review' || !$application->isOtpVerified()) {
            $this->addFlash('error', 'Only OTP-verified pending applications can be reviewed.');

            return $this->redirectToRoute('mentoring_admin');
        }

        if ($decision === 'decline') {
            $application
                ->setStatus('Declined')
                ->setAdminNote($request->request->get('admin_note'));
            $em->flush();

            $this->addFlash('success', 'Mentor application declined.');

            return $this->redirectToRoute('mentoring_admin');
        }

        $student = $application->getStudent();
        $roles = $student->getRoles();
        $roles[] = 'ROLE_STUDENT';
        $roles[] = 'ROLE_MENTOR';
        $student->setRoles(array_values(array_unique($roles)));

        $existingProfile = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $student]);
        if (!$existingProfile) {
            $name = trim(($student->getFirstName() ?? '') . ' ' . ($student->getLastName() ?? '')) ?: $student->getEmail();
            $profile = (new MentorProfile())
                ->setUser($student)
                ->setDisplayName($name)
                ->setSpecialization($application->getSpecialization())
                ->setBio($application->getReason());

            $em->persist($profile);
        }

        $application->setStatus('Approved');
        $em->flush();

        $this->addFlash('success', 'Student approved as mentor.');

        return $this->redirectToRoute('mentoring_admin');
    }

    #[Route('/admin/mentor', name: 'mentoring_create_mentor', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function createMentor(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('create_mentor', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $em->getRepository(User::class)->find((int) $request->request->get('user_id'));
        if (!$user) {
            $this->addFlash('error', 'User not found.');

            return $this->redirectToRoute('mentoring_admin');
        }

        $existing = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $user]);
        if ($existing) {
            $this->addFlash('error', 'This user already has a mentor profile.');

            return $this->redirectToRoute('mentoring_admin');
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

        $this->addFlash('success', 'Mentor profile created.');

        return $this->redirectToRoute('mentoring_admin');
    }

    #[Route('/admin/mentor/{id}/availability', name: 'mentoring_add_availability', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
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

            return $this->redirectToRoute('mentoring_admin');
        }

        $availability = (new MentorAvailability())
            ->setMentor($mentor)
            ->setAvailableDate($date)
            ->setStartTime($start)
            ->setEndTime($end);

        $em->persist($availability);
        $em->flush();

        $this->addFlash('success', 'Availability added.');

        return $this->redirectToRoute('mentoring_admin');
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

        return $this->render('mentoring/show.html.twig', [
            'mentor' => $profile,
            'availabilities' => $availabilities,
            'myAppointments' => $appointmentsWithThisMentor,
        ]);
    }

    #[Route('/{id}/custom-request', name: 'mentoring_custom_request', methods: ['POST'])]
    public function customRequest(MentorProfile $profile, Request $request, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        if (!$this->isCsrfTokenValid('custom_request_' . $profile->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid request.');
            return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
        }

        $message = trim($request->request->get('message', ''));
        if (strlen($message) < 10) {
            $this->addFlash('error', 'Message too short.');
            return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
        }

        $customRequest = new \App\Entity\MentorCustomRequest();
        $customRequest->setStudent($this->getUser())
            ->setMentorProfile($profile)
            ->setMessage($message);

        $em->persist($customRequest);
        $em->flush();

        // Email mentor
        $user = $profile->getUser();
        try {
            $emailMessage = (new Email())
                ->from('noreply@reserva-ftic.edu.ph')
                ->to($user->getEmail())
                ->subject('New Custom Mentoring Request')
                ->html('<h2>New Mentoring Request</h2><p><strong>From:</strong> ' . $this->getUser()->getEmail() . '</p><p><strong>Message:</strong><br>' . nl2br($message) . '</p><p>Review in your profile requests tab.</p>');

            $mailer->send($emailMessage);
        } catch (\Exception $e) {
            // Log but don't fail
        }

        $this->addFlash('success', 'Custom request sent! The mentor will receive an email and can respond via profile.');

        return $this->redirectToRoute('mentoring_show', ['id' => $profile->getId()]);
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
    #[IsGranted('ROLE_SUPER_ADMIN')]
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

        return $this->redirectToRoute('mentoring_admin');
    }

    private function specializationStats(EntityManagerInterface $em): array
    {
        return $em->createQueryBuilder()
            ->select('m.specialization, COUNT(a.id) AS total')
            ->from(MentoringAppointment::class, 'a')
            ->join('a.mentor', 'm')
            ->groupBy('m.specialization')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();
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
}
