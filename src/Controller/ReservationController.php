<?php

namespace App\Controller;

use App\Entity\Reservation;
use App\Entity\Facility;
use App\Entity\User;
use App\Entity\Notification;
use App\Repository\ReservationRepository;
use App\Repository\FacilityRepository;
use App\Service\ConflictDetectionService;
use App\Service\FacilityAvailabilityService;
use App\Service\NotificationService;
use App\Service\ReservationAutoExpireService;
use App\Service\ReservationMailer;
use App\Service\ScheduleRevisionService;
use App\Repository\UserRepository;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

#[Route('/facility')]
class ReservationController extends AbstractController
{
    #[Route('/schedule-revision', name: 'facility_schedule_revision', methods: ['GET'])]
    public function scheduleRevision(ScheduleRevisionService $revision): JsonResponse
    {
        $response = $this->json(['revision' => $revision->getRevision()]);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }

    #[Route('/{id}/reserve', name: 'facility_reserve', methods: ['GET', 'POST'])]
public function reserve(
        Facility $facility,
        Request $request,
        ReservationRepository $reservationRepo,
        FacilityAvailabilityService $availabilityService,
        ScheduleRevisionService $scheduleRevision,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        UserRepository $userRepository,
        ReservationMailer $reservationMailer
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        // Check if facility is available for reservations
        if (!$facility->isAvailableForReservation()) {
            $this->addFlash('error', 'This facility is not available for reservations at this time.');
            return $this->redirectToRoute('app_facility_index');
        }

        if ($request->isMethod('POST')) {
            // Validate CSRF token first to prevent session issues
            $submittedToken = $request->request->get('csrf_token');
            $sessionToken = $request->getSession()->get('_csrf/reservation');
            
            if (!$this->isCsrfTokenValid('reservation', $submittedToken)) {
                // Return 200 with error message so client can handle it gracefully without logout
                return $this->json(
                    ['error' => 'Invalid security token. Please refresh the page and try again.', 'message' => 'Invalid security token. Please refresh the page and try again.'],
                    Response::HTTP_OK
                );
            }

            try {
                $name = $request->request->get('name');
                $email = $request->request->get('email');
                $contact = $request->request->get('contact');
                $dateStr = $request->request->get('reservation_date');
                $startTimeStr = $request->request->get('reservation_start_time');
                $endTimeStr = $request->request->get('reservation_end_time');
                $capacity = (int)$request->request->get('capacity');
                $eventName = trim((string)$request->request->get('event_name')) ?: null;
                $purpose = $request->request->get('purpose');
                $eventPurpose = $request->request->get('event_purpose');
                $eventPurposeOther = $request->request->get('event_purpose_other');
                $institutionalEvent = (bool) $request->request->get('institutional_event', false);

                $startTime = \DateTime::createFromFormat('H:i', $startTimeStr);
                $endTime   = \DateTime::createFromFormat('H:i', $endTimeStr);
                $date      = \DateTime::createFromFormat('Y-m-d', $dateStr);

                if ($validationError = $this->validateReservationInput($startTime, $endTime, $date, $capacity, $facility)) {
                    return $validationError;
                }

                // Institutional events may override existing schedules — skip conflict check
                if (!$institutionalEvent && $reservationRepo->isTimeRangeBooked($facility, $date, $startTime, $endTime, null, ['Approved', 'Pending'])) {
                    // Find alternative facilities
                    $alternatives = $reservationRepo->findAvailableAlternatives(
                        $capacity,
                        $date,
                        $startTime,
                        $endTime,
                        $facility
                    );

                    return $this->json(
                        [
                            'error' => 'This facility already has a reservation or class scheduled during that time. Please choose another time or facility.',
                            'message' => 'This facility already has a reservation or class scheduled during that time. Please choose another time or facility.',
                            'alternatives' => array_map(fn($alt) => [
                                'id' => $alt->getId(),
                                'name' => $alt->getName(),
                                'capacity' => $alt->getCapacity(),
                            ], $alternatives),
                        ],
                        Response::HTTP_CONFLICT
                    );
                }

                // Store reservation data in session (do NOT persist to DB yet)
                // The reservation will only be created when the user confirms their facility choice
                $request->getSession()->set('pending_reservation', [
                    'facility_id' => $facility->getId(),
                    'name' => $name,
                    'event_name' => $eventName,
                    'email' => $email,
                    'contact' => $contact,
                    'date' => $dateStr,
                    'start_time' => $startTimeStr,
                    'end_time' => $endTimeStr,
                    'capacity' => $capacity,
                    'purpose' => $purpose,
                    'event_purpose' => $eventPurpose,
                    'event_purpose_other' => $eventPurposeOther,
                    'institutional_event' => $institutionalEvent,
                ]);

                // Redirect to suggest alternatives page (session-based, no DB record yet)
                return $this->json([
                    'success' => true,
                    'redirect' => $this->generateUrl('user_suggest_facility', ['id' => $facility->getId()]),
                ]);
            } catch (\Throwable $exception) {
                // Do not expose internal errors to the user, but return a JSON-friendly message for AJAX requests.
                return $this->json(
                    ['error' => 'An unexpected error occurred while submitting the reservation. Please try again later.', 'message' => 'An unexpected error occurred while submitting the reservation. Please try again later.'],
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }
        }

        $availability = $availabilityService->buildAvailabilityMap($facility, new \DateTime('today'), 90);

        [$userEmail, $userName] = $this->resolveUserDisplayInfo($this->getUser());

        $existingReservation = $this->resolveRestoreReservation($request, $reservationRepo, $this->getUser(), $facility);

        return $this->render('reservation/reserve.html.twig', [
            'facility' => $facility,
            'bookedTimes' => json_encode($availability['bookedTimes']),
            'pendingTimes' => json_encode($availability['pendingTimes']),
            'classTimes' => json_encode($availability['classTimes']),
            'blockedTimes' => json_encode($availability['blockedTimes']),
            'maintenanceTimes' => json_encode($availability['maintenanceTimes']),
            'scheduleRevision' => $scheduleRevision->getRevision(),
            'userEmail' => $userEmail,
            'userName' => $userName,
            'existingReservation' => $existingReservation,
        ]);
    }

    #[Route('/{id}/availability', name: 'facility_reserve_availability', methods: ['GET'])]
    public function availability(
        Facility $facility,
        Request $request,
        FacilityAvailabilityService $availabilityService,
        ScheduleRevisionService $scheduleRevision,
    ): JsonResponse {
        $days = max(1, min(120, (int) $request->query->get('days', 90)));
        $startDate = \DateTime::createFromFormat('!Y-m-d', (string) $request->query->get('start'));
        if (!$startDate) {
            $startDate = new \DateTime('today');
        }

        $payload = $availabilityService->buildAvailabilityMap($facility, $startDate, $days);
        $payload['scheduleRevision'] = $scheduleRevision->getRevision();

        $response = $this->json($payload);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }

    #[Route('/check-availability', name: 'check_availability', methods: ['POST'])]
    public function checkAvailability(
        Request $request,
        ReservationRepository $reservationRepo,
        FacilityRepository $facilityRepo
    ): JsonResponse {
        $facilityId = $request->request->get('facility_id');
        $dateStr = $request->request->get('date');
        $startTimeStr = $request->request->get('start_time');
        $endTimeStr = $request->request->get('end_time');

        $facility = $facilityRepo->find($facilityId);
        if (!$facility) {
            return $this->json(['available' => false, 'error' => 'Facility not found']);
        }

        $date = \DateTime::createFromFormat('Y-m-d', $dateStr);
        $startTime = \DateTime::createFromFormat('H:i', $startTimeStr);
        $endTime = \DateTime::createFromFormat('H:i', $endTimeStr);

        if (!$date || !$startTime || !$endTime) {
            return $this->json(['available' => false, 'error' => 'Invalid date or time format']);
        }

        $available = !$reservationRepo->isTimeRangeBooked($facility, $date, $startTime, $endTime, null, ['Approved', 'Pending']);

        return $this->json(['available' => $available]);
    }

    #[Route('/suggest-alternatives', name: 'suggest_alternatives', methods: ['POST'])]
    public function suggestAlternatives(
        Request $request,
        ReservationRepository $reservationRepo,
        FacilityRepository $facilityRepo
    ): JsonResponse {
        $facilityId = $request->request->get('facility_id');
        $dateStr = $request->request->get('date');
        $startTimeStr = $request->request->get('start_time');
        $endTimeStr = $request->request->get('end_time');
        $capacity = (int)$request->request->get('capacity', 0);

        if ($capacity <= 0) {
            return $this->json(['alternatives' => [], 'message' => 'Capacity must be greater than 0']);
        }

        $facility = $facilityRepo->find($facilityId);
        if (!$facility) {
            return $this->json(['alternatives' => [], 'error' => 'Facility not found']);
        }

        $date = \DateTime::createFromFormat('Y-m-d', $dateStr);
        $startTime = \DateTime::createFromFormat('H:i', $startTimeStr);
        $endTime = \DateTime::createFromFormat('H:i', $endTimeStr);

        if (!$date || !$startTime || !$endTime) {
            return $this->json(['alternatives' => [], 'error' => 'Invalid date or time format']);
        }

        // Find alternative facilities
        $alternatives = $reservationRepo->findAvailableAlternatives(
            $capacity,
            $date,
            $startTime,
            $endTime,
            $facility
        );

        return $this->json([
            'alternatives' => array_map(fn($alt) => [
                'id' => $alt->getId(),
                'name' => $alt->getName(),
                'capacity' => $alt->getCapacity(),
                'description' => $alt->getDescription(),
                'image' => $alt->getImage(),
            ], $alternatives),
        ]);
    }

    #[Route('/reservations', name: 'user_reservations')]
    #[IsGranted('ROLE_USER')]
    public function userReservations(
        ReservationRepository $reservationRepo,
        ReservationAutoExpireService $autoExpireService,
    ): Response {
        $user = $this->getUser();

        // Auto-cancel any pending reservations whose start time has passed.
        // This keeps the page accurate in real-time without requiring a cron job.
        if ($user instanceof User) {
            $autoExpireService->expireOverdueForUser($user);
        }

        $reservations = $reservationRepo->findByUser($user);

        // Separate reservations by status
        $categorized = [
            'Approved' => [],
            'Pending' => [],
            'Suggested' => [],
            'Rejected' => [],
            'Cancelled' => [],
        ];
        $cancellationBlocked = [];

        foreach ($reservations as $reservation) {
            $status = $reservation->getStatus();
            if (isset($categorized[$status])) {
                $categorized[$status][] = $reservation;
            }

            $cancellationBlocked[$reservation->getId()] = $this->isCancellationRestricted($reservation);
        }

        // Get most recent reservation for the modal (if any)
        $recentReservation = null;
        $allReservations = array_merge(
            $categorized['Approved'],
            $categorized['Pending'],
            $categorized['Suggested']
        );
        if (!empty($allReservations)) {
            // Sort by createdAt descending to get the most recent
            usort($allReservations, function($a, $b) {
                return $b->getCreatedAt() <=> $a->getCreatedAt();
            });
            $recentReservation = $allReservations[0];
        }

        return $this->render('reservation/user_reservations.html.twig', [
            'reservations' => $categorized,
            'recentReservation' => $recentReservation,
            'cancellationBlocked' => $cancellationBlocked,
        ]);
    }

    #[Route('/reservations/{id}/cancel', name: 'cancel_reservation', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancelReservation(
        Reservation $reservation,
        Request $request,
        ReservationRepository $reservationRepo,
        EntityManagerInterface $em,
        ReservationMailer $reservationMailer,
        UserRepository $userRepository,
        NotificationService $notificationService
    ): Response {
        if (!$this->isCsrfTokenValid('cancel_reservation_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Verify user owns this reservation
        if ($reservation->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if ($reservation->getStatus() === 'Approved' && $this->isCancellationRestricted($reservation)) {
            $this->addFlash('reservation_error', 'This approved reservation can no longer be cancelled because it is within 24 hours of the scheduled time.');

            return $this->redirectToRoute('user_reservations');
        }

        $reservation->setStatus('Cancelled');
        $cancellationReason = $request->request->get('cancellation_reason');
        $reservation->setCancellationReason($cancellationReason);
        $em->flush();

        $reservationMailer->notifyCancelled($reservation);
        $this->notifyAdminsReservationCancelled($reservation, $userRepository, $notificationService);

        $this->addFlash('reservation_success', 'Reservation cancelled successfully.');

        return $this->redirectToRoute('user_reservations');
    }

    private function notifyAdminsReservationCancelled(
        Reservation $reservation,
        UserRepository $userRepository,
        NotificationService $notificationService,
    ): void {
        $requesterName = $reservation->getName() ?: ($reservation->getUser()?->getEmail() ?? 'A user');
        $facilityName = $reservation->getFacility()?->getName() ?? 'a facility';
        $date = $reservation->getReservationDate()?->format('F j, Y') ?? 'the scheduled date';
        $time = $reservation->getReservationStartTime() && $reservation->getReservationEndTime()
            ? $reservation->getReservationStartTime()->format('g:i A') . ' - ' . $reservation->getReservationEndTime()->format('g:i A')
            : 'the scheduled time';
        $reason = trim((string) $reservation->getCancellationReason());
        $message = sprintf(
            '%s cancelled their reservation request for %s on %s, %s.',
            $requesterName,
            $facilityName,
            $date,
            $time
        );

        if ($reason !== '') {
            $message .= ' Reason: ' . $reason;
        }

        foreach ($userRepository->findAdmins() as $admin) {
            try {
                $notificationService->notifyAdminWithEmail(
                    $admin,
                    'reservation',
                    'Reservation Request Cancelled',
                    $message,
                    'Cancelled',
                    $reservation->getId()
                );
            } catch (\Throwable $e) {
                error_log('Failed to notify admin of reservation cancellation: ' . $e->getMessage());
            }
        }
    }

    #[Route('/reservations/{id}/accept-suggestion', name: 'accept_suggestion', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function acceptSuggestion(
        Reservation $reservation,
        ReservationRepository $reservationRepo,
        EntityManagerInterface $em,
        ReservationMailer $reservationMailer
    ): Response {
        if ($reservation->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $suggestedFacility = $reservation->getSuggestedFacility();
        if ($reservation->getStatus() !== 'Suggested' || !$suggestedFacility) {
            $this->addFlash('reservation_error', 'No suggested facility to accept.');

            return $this->redirectToRoute('user_reservations');
        }

        $date = $reservation->getReservationDate();
        $startTime = $reservation->getReservationStartTime();
        $endTime = $reservation->getReservationEndTime();

        if ($reservationRepo->isTimeRangeBooked($suggestedFacility, $date, $startTime, $endTime)) {
            $this->addFlash('reservation_error', 'The suggested facility is no longer available at that time.');

            return $this->redirectToRoute('user_reservations');
        }

        $reservation->setFacility($suggestedFacility);
        $reservation->setSuggestedFacility(null);
        $reservation->setStatus('Approved');
        $em->flush();

        $reservationMailer->notifySuggestionAccepted($reservation);

        $this->addFlash('reservation_success', 'Suggestion accepted. Your reservation is now booked.');

        return $this->redirectToRoute('user_reservations');
    }

    #[Route('/reservations/{id}/decline-suggestion', name: 'decline_suggestion', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function declineSuggestion(
        Reservation $reservation,
        ReservationRepository $reservationRepo,
        EntityManagerInterface $em,
        ReservationMailer $reservationMailer
    ): Response {
        if ($reservation->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if ($reservation->getStatus() !== 'Suggested') {
            $this->addFlash('reservation_error', 'No suggested facility to decline.');

            return $this->redirectToRoute('user_reservations');
        }

        $date = $reservation->getReservationDate();
        $startTime = $reservation->getReservationStartTime();
        $endTime = $reservation->getReservationEndTime();

        if ($reservationRepo->isTimeRangeBooked($reservation->getFacility(), $date, $startTime, $endTime)) {
            $this->addFlash('reservation_error', 'The original facility is no longer available at that time.');

            return $this->redirectToRoute('user_reservations');
        }

        $reservation->setSuggestedFacility(null);
        $reservation->setStatus('Approved');
        $em->flush();

        $reservationMailer->notifySuggestionDeclined($reservation);

        $this->addFlash('reservation_success', 'Suggestion declined. Your original reservation is now booked.');

        return $this->redirectToRoute('user_reservations');
    }

    #[Route('/reservations/{id}/suggest-alternatives', name: 'user_suggest_facility', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function suggestAlternativesUser(
        int $id,
        Request $request,
        FacilityRepository $facilityRepo,
        ReservationRepository $reservationRepo
    ): Response {
        $sessionData = $request->getSession()->get('pending_reservation');
        if (!$sessionData || (int)$sessionData['facility_id'] !== $id) {
            $this->addFlash('error', 'No pending reservation found. Please submit a reservation first.');
            return $this->redirectToRoute('app_facility_index');
        }

        $facility = $facilityRepo->find($id);
        if (!$facility) {
            $this->addFlash('error', 'Facility not found.');
            return $this->redirectToRoute('app_facility_index');
        }

        $date = \DateTime::createFromFormat('Y-m-d', $sessionData['date']);
        $startTime = \DateTime::createFromFormat('H:i', $sessionData['start_time']);
        $endTime = \DateTime::createFromFormat('H:i', $sessionData['end_time']);
        $capacity = (int)$sessionData['capacity'];

        $alternatives = $reservationRepo->findAvailableAlternatives(
            $capacity,
            $date,
            $startTime,
            $endTime,
            $facility
        );

        // Build a data object for the template (not a DB entity)
        $reservationData = [
            'facility' => $facility,
            'eventName' => $sessionData['event_name'],
            'reservationDate' => $date,
            'reservationStartTime' => $startTime,
            'reservationEndTime' => $endTime,
            'capacity' => $capacity,
            'purpose' => $sessionData['purpose'],
            'eventPurpose' => $sessionData['event_purpose'],
            'eventPurposeOther' => $sessionData['event_purpose_other'],
            'institutionalEvent' => $sessionData['institutional_event'] ?? false,
        ];

        return $this->render('reservation/suggest_alternatives.html.twig', [
            'reservation' => $reservationData,
            'alternatives' => $alternatives,
        ]);
    }

    #[Route('/reservations/{id}/select-alternative/{facilityId}', name: 'user_select_alternative', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function selectAlternative(
        int $id,
        int $facilityId,
        Request $request,
        EntityManagerInterface $em,
        FacilityRepository $facilityRepo,
        MailerInterface $mailer,
        UserRepository $userRepository,
        ReservationMailer $reservationMailer,
        ConflictDetectionService $conflictDetectionService
    ): Response {
        $sessionData = $request->getSession()->get('pending_reservation');
        if (!$sessionData) {
            $this->addFlash('error', 'No pending reservation found.');
            return $this->redirectToRoute('app_facility_index');
        }

        if (!$this->isCsrfTokenValid('select_alternative_' . $id, $request->request->get('_token'))) {
            $this->addFlash('reservation_error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_facility_index');
        }

        $facility = $facilityRepo->find($facilityId);
        if (!$facility) {
            $this->addFlash('error', 'Facility not found.');
            return $this->redirectToRoute('app_facility_index');
        }

        // Create the reservation now with the selected alternative facility
        $reservation = $this->createReservationFromSession($sessionData, $facility, $em);
        $request->getSession()->remove('pending_reservation');

        $response = $this->submitPendingReservation($reservation, $em, $mailer, $userRepository, $reservationMailer);

        if ($reservation->isInstitutionalEvent()) {
            try { $conflictDetectionService->detectAndStoreConflicts($reservation); } catch (\Throwable) {}
        }

        return $response;
    }

    #[Route('/reservations/{id}/keep-original-facility', name: 'user_keep_original_facility', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function keepOriginalFacility(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        FacilityRepository $facilityRepo,
        MailerInterface $mailer,
        UserRepository $userRepository,
        ReservationMailer $reservationMailer,
        ConflictDetectionService $conflictDetectionService
    ): Response {
        $sessionData = $request->getSession()->get('pending_reservation');
        if (!$sessionData) {
            $this->addFlash('error', 'No pending reservation found.');
            return $this->redirectToRoute('app_facility_index');
        }

        if (!$this->isCsrfTokenValid('keep_original_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_facility_index');
        }

        $facility = $facilityRepo->find($sessionData['facility_id']);
        if (!$facility) {
            $this->addFlash('error', 'Facility not found.');
            return $this->redirectToRoute('app_facility_index');
        }

        // Create the reservation now with the original facility
        $reservation = $this->createReservationFromSession($sessionData, $facility, $em);
        $request->getSession()->remove('pending_reservation');

        $response = $this->submitPendingReservation($reservation, $em, $mailer, $userRepository, $reservationMailer);

        if ($reservation->isInstitutionalEvent()) {
            try { $conflictDetectionService->detectAndStoreConflicts($reservation); } catch (\Throwable) {}
        }

        return $response;
    }

    private function createReservationFromSession(array $data, Facility $facility, EntityManagerInterface $em): Reservation
    {
        $reservation = new Reservation();
        $reservation->setUser($this->getUser());
        $reservation->setFacility($facility);
        $reservation->setName($data['name']);
        $reservation->setEventName($data['event_name']);
        $reservation->setEmail($data['email']);
        $reservation->setContact($data['contact']);
        $reservation->setReservationDate(\DateTime::createFromFormat('Y-m-d', $data['date']));
        $reservation->setReservationStartTime(\DateTime::createFromFormat('H:i', $data['start_time']));
        $reservation->setReservationEndTime(\DateTime::createFromFormat('H:i', $data['end_time']));
        $reservation->setCapacity((int)$data['capacity']);
        $reservation->setPurpose($data['purpose']);
        $reservation->setEventPurpose($data['event_purpose']);
        $reservation->setEventPurposeOther($data['event_purpose_other']);
        $reservation->setInstitutionalEvent((bool) ($data['institutional_event'] ?? false));

        $em->persist($reservation);

        return $reservation;
    }

    private function validateReservationInput(
        mixed $startTime,
        mixed $endTime,
        mixed $date,
        int $capacity,
        Facility $facility,
    ): ?JsonResponse {
        if (!$startTime || !$endTime) {
            return $this->json(['error' => 'Please select a valid start and end time', 'message' => 'Please select a valid start and end time'], Response::HTTP_BAD_REQUEST);
        }
        $dayStart = \DateTime::createFromFormat('H:i', '07:00');
        $dayEnd   = \DateTime::createFromFormat('H:i', '20:00');
        if ($startTime < $dayStart || $endTime > $dayEnd || $endTime <= $startTime) {
            return $this->json(['error' => 'Reservation time must be between 7:00 AM and 8:00 PM', 'message' => 'Reservation time must be between 7:00 AM and 8:00 PM'], Response::HTTP_BAD_REQUEST);
        }
        $startMinutes = ((int)$startTime->format('H')) * 60 + (int)$startTime->format('i');
        $endMinutes   = ((int)$endTime->format('H'))   * 60 + (int)$endTime->format('i');
        if ($startMinutes % 10 !== 0 || $endMinutes % 10 !== 0) {
            return $this->json(['error' => 'Please select times in 10-minute intervals', 'message' => 'Please select times in 10-minute intervals'], Response::HTTP_BAD_REQUEST);
        }
        if ($capacity > $facility->getCapacity()) {
            return $this->json(['error' => "Capacity cannot exceed facility maximum of {$facility->getCapacity()}", 'message' => "Capacity cannot exceed facility maximum of {$facility->getCapacity()}"], Response::HTTP_BAD_REQUEST);
        }
        if (!$date) {
            return $this->json(['error' => 'Please select a valid reservation date', 'message' => 'Please select a valid reservation date'], Response::HTTP_BAD_REQUEST);
        }
        $reservationDateTime = clone $date;
        $reservationDateTime->setTime((int)$startTime->format('H'), (int)$startTime->format('i'));
        if ($reservationDateTime < new \DateTime()) {
            return $this->json(['error' => 'Cannot create reservations for past time slots. Please select a future time.', 'message' => 'Cannot create reservations for past time slots. Please select a future time.'], Response::HTTP_BAD_REQUEST);
        }
        return null;
    }

    private function resolveUserDisplayInfo(mixed $user): array
    {
        if (!$user instanceof User) {
            return ['', ''];
        }
        $email = $user->getEmail();
        $name  = trim(implode(' ', array_filter([
            $user->getFirstName(),
            $user->getMiddleName(),
            $user->getLastName(),
        ]))) ?: $email;
        return [$email, $name];
    }

    private function resolveRestoreReservation(
        Request $request,
        ReservationRepository $reservationRepo,
        mixed $user,
        Facility $facility,
    ): ?Reservation {
        $restoreId = $request->query->get('restore');
        if (!$restoreId) return null;
        $res = $reservationRepo->find($restoreId);
        if ($res && $res->getUser() === $user && $res->getFacility() === $facility) {
            return $res;
        }
        return null;
    }

    private function guardReservationAccess(Reservation $reservation, Request $request, string $tokenId): ?Response
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('user_suggest_facility', ['id' => $reservation->getId()]);
        }
        $currentUser = $this->getUser();
        if (!$currentUser || $reservation->getUser() !== $currentUser) {
            $this->addFlash('reservation_error', 'You do not have permission to modify this reservation.');
            return $this->redirectToRoute('user_reservations');
        }
        return null;
    }

    private function submitPendingReservation(
        Reservation $reservation,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        UserRepository $userRepository,
        ReservationMailer $reservationMailer,
    ): Response {
        $reservation->setStatus('Pending');
        $em->flush();
        $this->notifyAdminNewReservation($reservation, $mailer, $em, $userRepository);
        $reservationMailer->notifyPending($reservation);
        $this->addFlash('reservation_success', 'Reservation submitted successfully! Your request is pending approval.');
        return $this->redirectToRoute('user_reservations');
    }

    private function isCancellationRestricted(Reservation $reservation): bool
    {
        $startDateTime = $this->getReservationStartDateTime($reservation);
        if (!$startDateTime) {
            return false;
        }

        $now = new \DateTimeImmutable('now', $startDateTime->getTimezone());

        return $startDateTime <= $now->modify('+24 hours');
    }

    private function getReservationStartDateTime(Reservation $reservation): ?\DateTimeImmutable
    {
        $reservationDate = $reservation->getReservationDate();
        $reservationStartTime = $reservation->getReservationStartTime();

        if (!$reservationDate || !$reservationStartTime) {
            return null;
        }

        $timezone = $reservationDate->getTimezone() ?: $reservationStartTime->getTimezone();
        $dateString = $reservationDate->format('Y-m-d') . ' ' . $reservationStartTime->format('H:i:s');
        $startDateTime = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateString, $timezone);

        if ($startDateTime instanceof \DateTimeImmutable) {
            return $startDateTime;
        }

        $fallback = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $reservationDate->format('Y-m-d') . ' ' . $reservationStartTime->format('H:i'), $timezone);

        return $fallback instanceof \DateTimeImmutable ? $fallback : null;
    }

    private function notifyAdminNewReservation(Reservation $reservation, MailerInterface $mailer, EntityManagerInterface $em, UserRepository $userRepository): void
    {
        $admins = $userRepository->findAdmins();
        $notifiedEmails = [];

        // 1. Send email notification to every admin and super admin account.
        foreach ($admins as $admin) {
            $adminEmail = trim($admin->getEmail());
            if ($adminEmail === '') {
                continue;
            }

            $emailKey = strtolower($adminEmail);
            if (isset($notifiedEmails[$emailKey])) {
                continue;
            }
            $notifiedEmails[$emailKey] = true;

            try {
                $adminName = trim(($admin->getFirstName() ?? '') . ' ' . ($admin->getLastName() ?? '')) ?: $adminEmail;
                $email = (new Email())
                    ->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))
                    ->to(new Address($adminEmail, $adminName))
                    ->subject('New Facility Reservation Request')
                    ->html($this->renderView('email/new_reservation.html.twig', [
                        'reservation' => $reservation,
                        'adminUrl' => $this->generateUrl(
                            in_array('ROLE_SUPER_ADMIN', $admin->getRoles(), true) ? 'admin_reservations' : 'admin_role_reservation_monitoring',
                            [],
                            UrlGeneratorInterface::ABSOLUTE_URL
                        ),
                    ]));

                $mailer->send($email);
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'Failed to send admin reservation email to %s for reservation #%s: %s',
                    $adminEmail,
                    $reservation->getId() ?? 'new',
                    $e->getMessage()
                ));
            }
        }

        // 2. Create database notifications for every admin and super admin account.
        try {
            foreach ($admins as $admin) {
                $notification = new Notification();
                $notification->setUser($admin);
                $notification->setType('reservation');
                $notification->setTitle('New Reservation Request');
                $notification->setMessage(sprintf(
                    'New facility reservation request from %s for %s on %s.',
                    $reservation->getName(),
                    $reservation->getFacility()->getName(),
                    $reservation->getReservationDate()->format('F j, Y')
                ));
                $notification->setStatus('Pending');
                $notification->setReferenceId($reservation->getId());
                $notification->setIsRead(false);
                $notification->setCreatedAt(new \DateTime());

                $em->persist($notification);
            }

            $em->flush();
        } catch (\Exception $e) {
        }
    }
}
