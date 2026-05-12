<?php

namespace App\Controller;

use App\Entity\Reservation;
use App\Entity\Facility;
use App\Entity\User;
use App\Entity\Notification;
use App\Repository\ReservationRepository;
use App\Repository\FacilityRepository;
use App\Repository\FacilityScheduleBlockRepository;
use App\Repository\UserRepository;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[Route('/facility')]
class ReservationController extends AbstractController
{
    #[Route('/{id}/reserve', name: 'facility_reserve', methods: ['GET', 'POST'])]
public function reserve(
        Facility $facility,
        Request $request,
        ReservationRepository $reservationRepo,
        FacilityScheduleBlockRepository $blockRepo,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        UserRepository $userRepository
    ): Response {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $email = $request->request->get('email');
            $contact = $request->request->get('contact');
            $dateStr = $request->request->get('reservation_date');
            $startTimeStr = $request->request->get('reservation_start_time');
            $endTimeStr = $request->request->get('reservation_end_time');
            $capacity = (int)$request->request->get('capacity');
            $purpose = $request->request->get('purpose');

            // Validate time range is between 8 AM and 5 PM
            $startTime = \DateTime::createFromFormat('H:i', $startTimeStr);
            $endTime = \DateTime::createFromFormat('H:i', $endTimeStr);
            if (!$startTime || !$endTime) {
                return $this->json(
                    ['error' => 'Please select a valid start and end time'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            $dayStart = \DateTime::createFromFormat('H:i', '08:00');
            $dayEnd = \DateTime::createFromFormat('H:i', '17:00');
            if ($startTime < $dayStart || $endTime > $dayEnd || $endTime <= $startTime) {
                return $this->json(
                    ['error' => 'Reservation time must be between 8:00 AM and 5:00 PM'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            // Enforce 15-minute steps
            $startMinutes = ((int)$startTime->format('H')) * 60 + (int)$startTime->format('i');
            $endMinutes = ((int)$endTime->format('H')) * 60 + (int)$endTime->format('i');
            if (($startMinutes % 15 !== 0) || ($endMinutes % 15 !== 0)) {
                return $this->json(
                    ['error' => 'Please select times in 15-minute intervals'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            // Validate capacity doesn't exceed facility capacity
            if ($capacity > $facility->getCapacity()) {
                return $this->json(
                    ['error' => "Capacity cannot exceed facility maximum of {$facility->getCapacity()}"],
                    Response::HTTP_BAD_REQUEST
                );
            }

            // Check if time slot is already booked
            $date = \DateTime::createFromFormat('Y-m-d', $dateStr);
            if ($reservationRepo->isTimeRangeBooked($facility, $date, $startTime, $endTime, null, ['Approved', 'Pending', 'Suggested'])) {
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
                        'error' => 'This time range is already booked. Please choose another time or facility.',
                        'alternatives' => array_map(fn($alt) => [
                            'id' => $alt->getId(),
                            'name' => $alt->getName(),
                            'capacity' => $alt->getCapacity(),
                        ], $alternatives),
                    ],
                    Response::HTTP_CONFLICT
                );
            }

            // Create reservation
            $reservation = new Reservation();
            $reservation->setUser($this->getUser());
            $reservation->setFacility($facility);
            $reservation->setName($name);
            $reservation->setEmail($email);
            $reservation->setContact($contact);
            $reservation->setReservationDate($date);
            $reservation->setReservationStartTime($startTime);
            $reservation->setReservationEndTime($endTime);
            $reservation->setCapacity($capacity);
            $reservation->setPurpose($purpose);
            $reservation->setStatus('Pending');

            $em->persist($reservation);
            $em->flush();

            // Check if user's capacity is less than facility capacity - suggest alternatives
            if ($capacity < $facility->getCapacity()) {
                $alternatives = $reservationRepo->findAvailableAlternatives(
                    $capacity,
                    $date,
                    $startTime,
                    $endTime,
                    $facility
                );

                // If alternatives exist, return redirect URL
                if (count($alternatives) > 0) {
                    return $this->json([
                        'success' => true,
                        'redirect' => $this->generateUrl('user_suggest_facility', ['id' => $reservation->getId()]),
                    ]);
                }
            }

// Send notification to super admin
            $this->notifyAdminNewReservation($reservation, $mailer, $em, $userRepository);

            return $this->json(['success' => true, 'message' => 'Reservation submitted successfully! Your request is pending approval.']);
        }

        // Get booked times for calendar
        $bookedTimes = [];
        $pendingTimes = [];
        $startDate = new \DateTime();
        for ($i = 0; $i < 30; $i++) {
            $date = (new \DateTime())->modify("+$i days");
            $dateStr = $date->format('Y-m-d');
            $ranges = $reservationRepo->getBookedRangesForDate($facility, $date);
            $blockedRanges = array_map(
                fn($block) => [
                    'start' => $block->getStartTime(),
                    'end' => $block->getEndTime(),
                ],
                $blockRepo->findForDate($facility, $date)
            );
            $bookedTimes[$dateStr] = array_map(
                fn($range) => [
                    'start' => $range['start']->format('H:i'),
                    'end' => $range['end']->format('H:i'),
                ],
                array_merge($ranges, $blockedRanges)
            );
            $pendingRanges = $reservationRepo->getPendingRangesForDate($facility, $date);
            $pendingTimes[$dateStr] = array_map(
                fn($range) => [
                    'start' => $range['start']->format('H:i'),
                    'end' => $range['end']->format('H:i'),
                ],
                $pendingRanges
            );
        }

        $user = $this->getUser();
        $userEmail = '';
        $userName = '';
        if ($user instanceof User) {
            $userEmail = $user->getEmail();
            $userName = $user->getFirstName() ?? $user->getEmail();
        }

        return $this->render('reservation/reserve.html.twig', [
            'facility' => $facility,
            'bookedTimes' => json_encode($bookedTimes),
            'pendingTimes' => json_encode($pendingTimes),
            'userEmail' => $userEmail,
            'userName' => $userName,
        ]);
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

        $available = !$reservationRepo->isTimeRangeBooked($facility, $date, $startTime, $endTime);

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
    public function userReservations(ReservationRepository $reservationRepo): Response
    {
        $reservations = $reservationRepo->findByUser($this->getUser());

        // Separate reservations by status
        $categorized = [
            'Approved' => [],
            'Pending' => [],
            'Rejected' => [],
            'Cancelled' => [],
        ];

        foreach ($reservations as $reservation) {
            $status = $reservation->getStatus();
            if (isset($categorized[$status])) {
                $categorized[$status][] = $reservation;
            }
        }

        return $this->render('reservation/user_reservations.html.twig', [
            'reservations' => $categorized,
        ]);
    }

    #[Route('/reservations/{id}/cancel', name: 'cancel_reservation', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancelReservation(
        Reservation $reservation,
        Request $request,
        ReservationRepository $reservationRepo,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isCsrfTokenValid('cancel_reservation_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Verify user owns this reservation
        if ($reservation->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $reservation->setStatus('Cancelled');
        $em->flush();

        $this->addFlash('success', 'Reservation cancelled successfully.');

        return $this->redirectToRoute('user_reservations');
    }

    #[Route('/reservations/{id}/accept-suggestion', name: 'accept_suggestion', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function acceptSuggestion(
        Reservation $reservation,
        ReservationRepository $reservationRepo,
        EntityManagerInterface $em
    ): Response {
        if ($reservation->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $suggestedFacility = $reservation->getSuggestedFacility();
        if ($reservation->getStatus() !== 'Suggested' || !$suggestedFacility) {
            $this->addFlash('error', 'No suggested facility to accept.');

            return $this->redirectToRoute('user_reservations');
        }

        $date = $reservation->getReservationDate();
        $startTime = $reservation->getReservationStartTime();
        $endTime = $reservation->getReservationEndTime();

        if ($reservationRepo->isTimeRangeBooked($suggestedFacility, $date, $startTime, $endTime)) {
            $this->addFlash('error', 'The suggested facility is no longer available at that time.');

            return $this->redirectToRoute('user_reservations');
        }

        $reservation->setFacility($suggestedFacility);
        $reservation->setSuggestedFacility(null);
        $reservation->setStatus('Approved');
        $em->flush();

        $this->addFlash('success', 'Suggestion accepted. Your reservation is now booked.');

        return $this->redirectToRoute('user_reservations');
    }

    #[Route('/reservations/{id}/decline-suggestion', name: 'decline_suggestion', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function declineSuggestion(
        Reservation $reservation,
        ReservationRepository $reservationRepo,
        EntityManagerInterface $em
    ): Response {
        if ($reservation->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if ($reservation->getStatus() !== 'Suggested') {
            $this->addFlash('error', 'No suggested facility to decline.');

            return $this->redirectToRoute('user_reservations');
        }

        $date = $reservation->getReservationDate();
        $startTime = $reservation->getReservationStartTime();
        $endTime = $reservation->getReservationEndTime();

        if ($reservationRepo->isTimeRangeBooked($reservation->getFacility(), $date, $startTime, $endTime)) {
            $this->addFlash('error', 'The original facility is no longer available at that time.');

            return $this->redirectToRoute('user_reservations');
        }

        $reservation->setSuggestedFacility(null);
        $reservation->setStatus('Approved');
        $em->flush();

        $this->addFlash('success', 'Suggestion declined. Your original reservation is now booked.');

        return $this->redirectToRoute('user_reservations');
    }

    #[Route('/reservations/{id}/suggest-alternatives', name: 'user_suggest_facility', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function suggestAlternativesUser(
        Reservation $reservation,
        ReservationRepository $reservationRepo
    ): Response {
        // Verify user owns this reservation
        if ($reservation->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $date = $reservation->getReservationDate();
        $startTime = $reservation->getReservationStartTime();
        $endTime = $reservation->getReservationEndTime();
        $capacity = $reservation->getCapacity();

        $alternatives = $reservationRepo->findAvailableAlternatives(
            $capacity,
            $date,
            $startTime,
            $endTime,
            $reservation->getFacility()
        );

        return $this->render('reservation/suggest_alternatives.html.twig', [
            'reservation' => $reservation,
            'alternatives' => $alternatives,
        ]);
    }

    #[Route('/reservations/{id}/select-alternative/{facilityId}', name: 'user_select_alternative', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
public function selectAlternative(
        Reservation $reservation,
        int $facilityId,
        Request $request,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        UserRepository $userRepository
    ): Response {
        if (!$this->isCsrfTokenValid('select_alternative_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Verify user owns this reservation
        if ($reservation->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $facility = $em->getRepository(Facility::class)->find($facilityId);
        if (!$facility) {
            $this->addFlash('error', 'Facility not found.');
            return $this->redirectToRoute('user_reservations');
        }

        $reservation->setFacility($facility);
        $reservation->setStatus('Pending');
        $em->flush();

// Send notification to super admin
        $this->notifyAdminNewReservation($reservation, $mailer, $em, $userRepository);

        $this->addFlash('success', 'Reservation submitted successfully! Your request is pending approval.');

        return $this->redirectToRoute('user_reservations');
    }

    #[Route('/reservations/{id}/keep-original-facility', name: 'user_keep_original_facility', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function keepOriginalFacility(
        Reservation $reservation,
        Request $request,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        UserRepository $userRepository
    ): Response {
        if (!$this->isCsrfTokenValid('keep_original_' . $reservation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Verify user owns this reservation
        if ($reservation->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $reservation->setStatus('Pending');
        $em->flush();

        // Send notification to super admin
        $this->notifyAdminNewReservation($reservation, $mailer, $em, $userRepository);

        $this->addFlash('success', 'Reservation submitted successfully! Your request is pending approval.');

        return $this->redirectToRoute('user_reservations');
    }

private function notifyAdminNewReservation(Reservation $reservation, MailerInterface $mailer, EntityManagerInterface $em, UserRepository $userRepository): void
    {
        // Log that we're trying to notify admin
        error_log('notifyAdminNewReservation called for reservation: ' . $reservation->getId());

        // 1. Send email notification to admin
        try {
            $email = (new Email())
                ->from('Reserva FTIC <hurstdale101@gmail.com>')
                ->to('admin@reserva-ftic.edu.ph')
                ->subject('New Facility Reservation Request')
                ->html($this->renderView('email/new_reservation.html.twig', [
                    'reservation' => $reservation,
                ]))
            ;

            $mailer->send($email);
            error_log('Email notification sent to admin');
        } catch (\Exception $e) {
            // Log error but don't fail the reservation
            \error_log('Failed to send admin notification: ' . $e->getMessage());
        }

        // 2. Create database notifications for all super admin users
        try {
            $admins = $userRepository->findAdmins();
            error_log('Found ' . count($admins) . ' admin(s)');

            foreach ($admins as $admin) {
                error_log('Creating notification for admin: ' . $admin->getEmail());
                
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
            error_log('Notifications created successfully');
        } catch (\Exception $e) {
            // Log error but don't fail the reservation
            \error_log('Failed to create admin notification: ' . $e->getMessage());
        }
    }
}
