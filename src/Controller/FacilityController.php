<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Facility;
use App\Entity\FacilityImage;
use App\Repository\FacilityRepository;
use App\Repository\FacilityImageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/facility')]
class FacilityController extends AbstractController
{
    #[Route('', name: 'app_facility_index', methods: ['GET'])]
    public function index(FacilityRepository $facilityRepository): Response
    {
        $facilities = $facilityRepository->findAll();

        return $this->render('facility/index.html.twig', [
            'facilities' => $facilities,
        ]);
    }

    #[Route('/management', name: 'app_facility_management', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function management(FacilityRepository $facilityRepository): Response
    {
        $facilities = $facilityRepository->findAll();

        return $this->render('facility/management.html.twig', [
            'facilities' => $facilities,
        ]);
    }

    #[Route('/new', name: 'app_facility_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request, FacilityRepository $facilityRepository): Response
    {
        $facility = new Facility();

        if ($request->isMethod('POST')) {
            $facility->setName((string) $request->request->get('name'));
            $facility->setCapacity((int) $request->request->get('capacity'));
            $facility->setDescription((string) $request->request->get('description'));

            // Handle image upload
            $uploadedFile = $request->files->get('image');
            if ($uploadedFile instanceof UploadedFile) {
                $imagePath = $this->handleImageUpload($uploadedFile);
                if ($imagePath) {
                    $facility->setImage($imagePath);
                }
            }

            $this->handleMultipleImageUploads($request, $facility);
            $facilityRepository->save($facility, true);

            $this->addFlash('success', 'Facility created successfully!');

            return $this->redirectToRoute('app_facility_management');
        }

        return $this->render('facility/new.html.twig', [
            'facility' => $facility,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_facility_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, Facility $facility, FacilityRepository $facilityRepository): Response
    {
        if ($request->isMethod('POST')) {
            $facility->setName((string) $request->request->get('name'));
            $facility->setCapacity((int) $request->request->get('capacity'));
            $facility->setDescription((string) $request->request->get('description'));

            // Handle image upload
            $uploadedFile = $request->files->get('image');
            if ($uploadedFile instanceof UploadedFile) {
                $imagePath = $this->handleImageUpload($uploadedFile);
                if ($imagePath) {
                    $facility->setImage($imagePath);
                }
            }

            $this->handleMultipleImageUploads($request, $facility);
            $facilityRepository->save($facility, true);

            $this->addFlash('success', 'Facility updated successfully!');

            return $this->redirectToRoute('app_facility_management');
        }

        return $this->render('facility/edit.html.twig', [
            'facility' => $facility,
        ]);
    }

    #[Route('/{id}/view', name: 'app_facility_view', methods: ['GET'])]
    public function view(Facility $facility): Response
    {
        return $this->render('facility/view.html.twig', [
            'facility' => $facility,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_facility_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Facility $facility, FacilityRepository $facilityRepository): Response
    {
        if ($this->isCsrfTokenValid('delete' . $facility->getId(), $request->request->get('_token'))) {
            $facilityRepository->remove($facility, true);

            $this->addFlash('success', 'Facility deleted successfully!');
        }

        return $this->redirectToRoute('app_facility_management');
    }

    #[Route('/{id}/images/reorder', name: 'app_facility_images_reorder', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reorderImages(Request $request, Facility $facility, FacilityImageRepository $imageRepository): Response
    {
        $positions = $request->request->all('positions');

        foreach ($facility->getImages() as $image) {
            $imageId = (string) $image->getId();
            if (isset($positions[$imageId])) {
                $image->setPosition((int) $positions[$imageId]);
                $imageRepository->save($image);
            }
        }

        $imageRepository->getEntityManager()->flush();
        $this->addFlash('success', 'Facility image order updated.');

        return $this->redirectToRoute('app_facility_edit', ['id' => $facility->getId()]);
    }

    #[Route('/images/{id}/delete', name: 'app_facility_image_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteImage(Request $request, FacilityImage $image, FacilityImageRepository $imageRepository): Response
    {
        $facilityId = $image->getFacility()?->getId();

        if ($this->isCsrfTokenValid('delete_image' . $image->getId(), (string) $request->request->get('_token'))) {
            $imageRepository->remove($image, true);
            $this->addFlash('success', 'Facility image removed.');
        }

        return $this->redirectToRoute('app_facility_edit', ['id' => $facilityId]);
    }

    private function handleImageUpload(UploadedFile $file): ?string
    {
        try {
            $filename = uniqid() . '.' . $file->guessExtension();
            $file->move($this->getParameter('kernel.project_dir') . '/public/uploads', $filename);

            return '/uploads/' . $filename;
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to upload image: ' . $e->getMessage());

            return null;
        }
    }

    private function handleMultipleImageUploads(Request $request, Facility $facility): void
    {
        $uploadedFiles = $request->files->all('images');
        if (!is_array($uploadedFiles)) {
            return;
        }

        $position = $facility->getImages()->count();
        foreach ($uploadedFiles as $uploadedFile) {
            if (!$uploadedFile instanceof UploadedFile) {
                continue;
            }

            $imagePath = $this->handleImageUpload($uploadedFile);
            if (!$imagePath) {
                continue;
            }

            $image = new FacilityImage();
            $image->setPath($imagePath);
            $image->setPosition($position++);
            $facility->addImage($image);

            if (!$facility->getImage()) {
                $facility->setImage($imagePath);
            }
        }
    }

    #[Route('/{id}/toggle-reservation', name: 'app_facility_toggle_reservation', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggleReservation(Request $request, Facility $facility, FacilityRepository $facilityRepository): Response
    {
        if ($this->isCsrfTokenValid('toggle_reservation_' . $facility->getId(), $request->request->get('_token'))) {
            $currentStatus = $facility->isAvailableForReservation();
            $facility->setAvailableForReservation(!$currentStatus);
            $facilityRepository->save($facility, true);

            $status = $facility->isAvailableForReservation() ? 'enabled' : 'disabled';
            $this->addFlash('success', 'Facility reservation status has been ' . $status . '.');
        }

        return $this->redirectToRoute('app_facility_management');
    }
}
