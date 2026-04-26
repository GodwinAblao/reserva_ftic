<?php

namespace App\Controller;

use App\Entity\Facility;
use App\Entity\Reservation;
use App\Repository\FacilityRepository;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/super-admin')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class SuperAdminReservationController extends AbstractController
{
    #[Route('/reservations', name: 'admin_reservations')]
    public function listReservations(ReservationRepository $reservationRepo): Response
    {
        $pending = $reservationRepo->findBy(['status' => 'Pending'], ['createdAt' => 'DESC']);
        $approved = $reservationRepo->findBy(['status' => 'Approved'], ['reservationDate' => 'DESC']);
        $rejected = $reservationRepo->findBy(['status' => 'Rejected'], ['reservationDate' => 'DESC']);

        return $this->render('super_admin/reservations.html.twig', [
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
        ]);
    }

    #[Route('/reservations/{id}/approve', name: 'admin_approve_reservation', methods: ['POST'])]
    public function approveReservation(
        Reservation $reservation,
        ReservationRepository $reservationRepo,
        EntityManagerInterface $em
    ): Response {
        $date = $reservation->getReservationDate();
        $startTime = $reservation->getReservationStartTime();
        $endTime = $reservation->getReservationEndTime();

        if ($reservationRepo->isTimeRangeBooked($reservation->getFacility(), $date, $startTime, $endTime)) {
            $this->addFlash('error', 'Cannot approve: this time range is already approved for this facility.');

            return $this->redirectToRoute('admin_reservations');
        }

        $reservation->setStatus('Approved');
        $em->flush();

        $this->addFlash('success', 'Reservation approved successfully.');

        return $this->redirectToRoute('admin_reservations');
    }

    #[Route('/reservations/{id}/reject', name: 'admin_reject_reservation', methods: ['POST'])]
    public function rejectReservation(
        Reservation $reservation,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $reason = $request->request->get('reason') ?? 'Not specified';
        $reservation->setStatus('Rejected');
        $reservation->setRejectionReason($reason);
        $em->flush();

        $this->addFlash('success', 'Reservation rejected successfully.');

        return $this->redirectToRoute('admin_reservations');
    }

    #[Route('/calendar', name: 'admin_calendar')]
    public function calendar(
        ReservationRepository $reservationRepo,
        FacilityRepository $facilityRepo
    ): Response {
        $facilities = $facilityRepo->findAll();
        
        return $this->render('super_admin/calendar.html.twig', [
            'facilities' => $facilities,
        ]);
    }

    #[Route('/calendar/data', name: 'admin_calendar_data')]
    public function calendarData(
        Request $request,
        ReservationRepository $reservationRepo,
        FacilityRepository $facilityRepo
    ): JsonResponse {
        $start = $request->query->get('start');
        $end = $request->query->get('end');
        $facilityId = $request->query->get('facility');
        $status = $request->query->get('status');

        // Build query
        $qb = $reservationRepo->createQueryBuilder('r')
            ->leftJoin('r.facility', 'f')
            ->addSelect('f')
            ->where('r.reservationDate BETWEEN :start AND :end')
            ->setParameter('start', new \DateTime($start))
            ->setParameter('end', new \DateTime($end))
            ->orderBy('r.reservationDate', 'ASC')
            ->addOrderBy('r.reservationStartTime', 'ASC');

        if ($facilityId) {
            $qb->andWhere('f.id = :facilityId')
               ->setParameter('facilityId', $facilityId);
        }

        if ($status) {
            $qb->andWhere('r.status = :status')
               ->setParameter('status', $status);
        }

        $reservations = $qb->getQuery()->getResult();

        // Format data
        $data = array_map(function($reservation) {
            return [
                'id' => $reservation->getId(),
                'name' => $reservation->getName(),
                'email' => $reservation->getEmail(),
                'contact' => $reservation->getContact(),
                'reservationDate' => $reservation->getReservationDate()->format('Y-m-d'),
                'reservationStartTime' => $reservation->getReservationStartTime()->format('H:i'),
                'reservationEndTime' => $reservation->getReservationEndTime()->format('H:i'),
                'capacity' => $reservation->getCapacity(),
                'purpose' => $reservation->getPurpose(),
                'status' => $reservation->getStatus(),
                'facility' => [
                    'id' => $reservation->getFacility()->getId(),
                    'name' => $reservation->getFacility()->getName(),
                    'capacity' => $reservation->getFacility()->getCapacity(),
                ],
            ];
        }, $reservations);

        return $this->json(['reservations' => $data]);
    }

    #[Route('/reservations/{id}/details', name: 'admin_reservation_details')]
    public function reservationDetails(Reservation $reservation): JsonResponse
    {
        return $this->json([
            'id' => $reservation->getId(),
            'name' => $reservation->getName(),
            'email' => $reservation->getEmail(),
            'contact' => $reservation->getContact(),
            'reservationDate' => $reservation->getReservationDate()->format('Y-m-d'),
            'reservationStartTime' => $reservation->getReservationStartTime()->format('H:i'),
            'reservationEndTime' => $reservation->getReservationEndTime()->format('H:i'),
            'capacity' => $reservation->getCapacity(),
            'purpose' => $reservation->getPurpose(),
            'status' => $reservation->getStatus(),
            'facility' => [
                'id' => $reservation->getFacility()->getId(),
                'name' => $reservation->getFacility()->getName(),
                'capacity' => $reservation->getFacility()->getCapacity(),
            ],
        ]);
    }

    #[Route('/reservations/{id}/edit', name: 'admin_edit_reservation', methods: ['GET'])]
    public function editReservation(
        Reservation $reservation,
        FacilityRepository $facilityRepo
    ): Response {
        $facilities = $facilityRepo->findAll();
        
        return $this->render('super_admin/edit_reservation.html.twig', [
            'reservation' => $reservation,
            'facilities' => $facilities,
        ]);
    }

    #[Route('/reservations/{id}/update', name: 'admin_update_reservation', methods: ['POST'])]
    public function updateReservation(
        Reservation $reservation,
        Request $request,
        ReservationRepository $reservationRepo,
        EntityManagerInterface $em
    ): Response {
        // Update basic information
        $reservation->setName($request->request->get('name'));
        $reservation->setEmail($request->request->get('email'));
        $reservation->setContact($request->request->get('contact'));
        $reservation->setCapacity((int)$request->request->get('capacity'));
        $reservation->setPurpose($request->request->get('purpose'));
        $reservation->setStatus($request->request->get('status'));

        // Update dates and times
        $reservationDate = new \DateTime($request->request->get('reservationDate'));
        $startTime = \DateTime::createFromFormat('H:i', $request->request->get('reservationStartTime'));
        $endTime = \DateTime::createFromFormat('H:i', $request->request->get('reservationEndTime'));

        $reservation->setReservationDate($reservationDate);
        $reservation->setReservationStartTime($startTime);
        $reservation->setReservationEndTime($endTime);

        // Update facility if provided
        $facilityId = $request->request->get('facility');
        if ($facilityId) {
            $facility = $em->getRepository(Facility::class)->find($facilityId);
            if ($facility) {
                $reservation->setFacility($facility);
            }
        }

        // Check for time conflicts only if the date/time has changed and status is being set to Approved
        if ($request->request->get('status') === 'Approved') {
            if ($reservationRepo->isTimeRangeBooked($reservation->getFacility(), $reservationDate, $startTime, $endTime, $reservation->getId())) {
                $this->addFlash('error', 'Cannot update: this time range is already booked for this facility.');
                return $this->redirectToRoute('admin_edit_reservation', ['id' => $reservation->getId()]);
            }
        }

        $reservation->setUpdatedAt(new \DateTime());
        $em->flush();

        $this->addFlash('success', 'Reservation updated successfully.');

        return $this->redirectToRoute('admin_calendar');
    }
}
