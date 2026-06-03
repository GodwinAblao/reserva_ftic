<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Facility;
use App\Entity\FacilityImage;
use App\Repository\FacilityRepository;
use App\Repository\FacilityImageRepository;
use App\Service\SupabaseStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/facility')]
class FacilityController extends AbstractController
{
    private SupabaseStorageService $storageService;

    public function __construct(SupabaseStorageService $storageService)
    {
        $this->storageService = $storageService;
    }
    #[Route('', name: 'app_facility_index', methods: ['GET'])]
    public function index(FacilityRepository $facilityRepository): Response
    {
        $facilities = $facilityRepository->findEnabled();

        return $this->render('facility/index.html.twig', [
            'facilities' => $facilities,
        ]);
    }

    #[Route('/management', name: 'app_facility_management', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function management(FacilityRepository $facilityRepository, Request $request): Response
    {
        $facilities = $facilityRepository->findAll();
        $success = $request->query->get('success');
        return $this->render('facility/management.html.twig', [
            'facilities' => $facilities,
            'success' => $success,
        ]);
    }

    #[Route('/new', name: 'app_facility_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request, FacilityRepository $facilityRepository, EntityManagerInterface $entityManager): Response
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
                    // Delete old image file if it exists
                    $this->deleteImageFile($facility->getImage());
                    $facility->setImage($imagePath);
                }
            }

            $this->handleMultipleImageUploads($request, $facility, $entityManager);
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
    public function edit(Request $request, Facility $facility, FacilityRepository $facilityRepository, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $facility->setName((string) $request->request->get('name'));
            $facility->setCapacity((int) $request->request->get('capacity'));
            $facility->setDescription((string) $request->request->get('description'));

            // Debug: Check what files are received
            $allFiles = $request->files->all();
            $galleryFiles = $request->files->get('images');
            error_log('DEBUG - All files: ' . json_encode($allFiles));
            error_log('DEBUG - Gallery files: ' . json_encode($galleryFiles));

            // Handle image upload
            $uploadedFile = $request->files->get('image');
            if ($uploadedFile instanceof UploadedFile) {
                $imagePath = $this->handleImageUpload($uploadedFile);
                if ($imagePath) {
                    // Delete old image file if it exists
                    $this->deleteImageFile($facility->getImage());
                    $facility->setImage($imagePath);
                } else {
                    // Upload failed - error message already added by handleImageUpload
                    // Continue saving other data but redirect with error
                    $entityManager->persist($facility);
                    $entityManager->flush();
                    return $this->redirectToRoute('app_facility_edit', ['id' => $facility->getId(), 'error' => 'image_upload_failed']);
                }
            }

            error_log('DEBUG - Before handleMultipleImageUploads, checking request files...');
            error_log('DEBUG - Request files count: ' . count($request->files->all()));
            error_log('DEBUG - Files keys: ' . implode(', ', array_keys($request->files->all())));
            
            $galleryCount = $this->handleMultipleImageUploads($request, $facility, $entityManager);
            error_log('DEBUG - After handleMultipleImageUploads, gallery images added: ' . $galleryCount);
            
            $facilityRepository->save($facility, true);

            return $this->redirectToRoute('app_facility_management', ['success' => 'edited']);
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

    #[Route('/{id}/delete-main-image', name: 'app_facility_delete_main_image', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteMainImage(Request $request, Facility $facility, FacilityRepository $facilityRepository): Response
    {
        if ($this->isCsrfTokenValid('delete_image' . $facility->getId(), $request->request->get('_token'))) {
            // Delete the actual file
            $this->deleteImageFile($facility->getImage());
            
            $facility->setImage(null);
            $facilityRepository->save($facility, true);
            $this->addFlash('success', 'Facility image deleted successfully!');
        }

        return $this->redirectToRoute('app_facility_edit', ['id' => $facility->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_facility_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Facility $facility, FacilityRepository $facilityRepository): Response
    {
        if ($this->isCsrfTokenValid('delete' . $facility->getId(), $request->request->get('_token'))) {
            // Delete main image file
            $this->deleteImageFile($facility->getImage());
            
            // Delete all gallery image files
            foreach ($facility->getImages() as $image) {
                $this->deleteImageFile($image->getPath());
            }
            
            $facilityRepository->remove($facility, true);
        }

        return $this->redirectToRoute('app_facility_management', ['success' => 'deleted']);
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

        if (!$facilityId) {
            $this->addFlash('error', 'Unable to remove image: no facility associated.');
            return $this->redirectToRoute('app_facility_management');
        }

        if ($this->isCsrfTokenValid('delete_image' . $image->getId(), (string) $request->request->get('_token'))) {
            // Delete the actual file
            $this->deleteImageFile($image->getPath());
            
            $imageRepository->remove($image, true);
            $this->addFlash('success', 'Facility image removed.');
        } else {
            $this->addFlash('error', 'Invalid CSRF token.');
        }

        return $this->redirectToRoute('app_facility_edit', ['id' => $facilityId]);
    }

    private function handleImageUpload(UploadedFile $file): ?string
    {
        try {
            $result = $this->storageService->uploadFile($file, 'facilities');

            if (!$result['success']) {
                $this->addFlash('error', 'Failed to upload image: ' . ($result['error'] ?? 'Unknown error'));
                return null;
            }

            return $result['url'];
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to upload image: ' . $e->getMessage());
            return null;
        }
    }

    private function deleteImageFile(?string $imageUrl): void
    {
        if (!$imageUrl) {
            return;
        }

        // Extract path from Supabase URL
        // URL format: https://[project].supabase.co/storage/v1/object/public/[bucket]/[path]
        $parsedUrl = parse_url($imageUrl);
        if (!isset($parsedUrl['path'])) {
            return;
        }

        $path = $parsedUrl['path'];
        $pattern = '/storage\/v1\/object\/public\/[^\/]+\//';
        if (preg_match($pattern, $path, $matches)) {
            $storagePath = preg_replace($pattern, '', $path);
            $this->storageService->deleteFile($storagePath);
        }
    }

    private function handleMultipleImageUploads(Request $request, Facility $facility, EntityManagerInterface $entityManager): int
    {
        $uploadedFiles = $request->files->get('images');
        error_log('DEBUG - Gallery upload started. Raw files: ' . json_encode($uploadedFiles ? 'present' : 'null'));

        // Handle single file case (not in array)
        if ($uploadedFiles instanceof UploadedFile) {
            $uploadedFiles = [$uploadedFiles];
        }

        // Handle the array structure from images[] input
        if (is_array($uploadedFiles)) {
            // Flatten in case of nested array structure
            $flatFiles = [];
            foreach ($uploadedFiles as $key => $file) {
                if ($file instanceof UploadedFile) {
                    $flatFiles[] = $file;
                    error_log('DEBUG - Found valid file at key ' . $key . ': ' . $file->getClientOriginalName());
                } elseif (is_array($file)) {
                    // Handle nested array case
                    foreach ($file as $nestedFile) {
                        if ($nestedFile instanceof UploadedFile) {
                            $flatFiles[] = $nestedFile;
                            error_log('DEBUG - Found nested file: ' . $nestedFile->getClientOriginalName());
                        }
                    }
                }
            }
            $uploadedFiles = $flatFiles;
        }

        // Must be a non-empty array
        if (!is_array($uploadedFiles) || empty($uploadedFiles)) {
            error_log('DEBUG - No gallery files to upload');
            return 0;
        }

        error_log('DEBUG - Processing ' . count($uploadedFiles) . ' gallery files');
        
        $position = $facility->getImages()->count();
        $uploadedCount = 0;
        
        foreach ($uploadedFiles as $uploadedFile) {
            // Skip if not a valid uploaded file
            if (!$uploadedFile instanceof UploadedFile) {
                error_log('DEBUG - Skipping invalid uploaded file');
                continue;
            }
            
            error_log('DEBUG - Processing gallery file: ' . $uploadedFile->getClientOriginalName());
            
            // Upload the file
            $imagePath = $this->handleImageUpload($uploadedFile);
            if (!$imagePath) {
                error_log('DEBUG - Gallery upload failed for: ' . $uploadedFile->getClientOriginalName());
                continue;
            }
            error_log('DEBUG - Gallery upload success, URL: ' . $imagePath);
            
            // Create and configure the image entity
            $image = new FacilityImage();
            $image->setPath($imagePath);
            $image->setPosition($position++);
            $image->setFacility($facility);
            
            // Persist directly to ensure it's saved
            $entityManager->persist($image);
            
            // Also add to facility's collection for cascade
            $facility->addImage($image);
            
            // Set as main image if facility doesn't have one
            if (!$facility->getImage()) {
                $facility->setImage($imagePath);
            }
            
            $uploadedCount++;
        }
        
        // Flush immediately to ensure images are saved
        $entityManager->flush();

        if ($uploadedCount > 0) {
            $this->addFlash('success', $uploadedCount . ' gallery image(s) uploaded successfully!');
        }

        return $uploadedCount;
    }

    #[Route('/{id}/toggle-reservation', name: 'app_facility_toggle_reservation', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggleReservation(Request $request, Facility $facility, FacilityRepository $facilityRepository): Response
    {
        if ($this->isCsrfTokenValid('toggle_reservation_' . $facility->getId(), $request->request->get('_token'))) {
            $currentStatus = $facility->isAvailableForReservation();
            $facility->setAvailableForReservation(!$currentStatus);
            $facilityRepository->save($facility, true);
        }

        return $this->redirectToRoute('app_facility_management');
    }
}
