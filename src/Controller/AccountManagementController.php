<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

use App\Repository\SpecializationRepository;
use App\Entity\Specialization;
use Psr\Log\LoggerInterface;


#[Route('/account-management')]
class AccountManagementController extends AbstractController
{
    #[Route('', name: 'app_account_management')]
    public function index(Request $request, UserRepository $userRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 10;
        $search = trim((string) $request->query->get('q', ''));
        $role = trim((string) $request->query->get('role', ''));
        $result = $userRepository->findPaginatedForAccountManagement($page, $limit, $search, $role);
        $total = $result['total'];
        $pages = max(1, (int) ceil($total / $limit));
        if ($page > $pages) {
            return $this->redirectToRoute('app_account_management', [
                'page' => $pages,
                'q' => $search,
                'role' => $role,
            ]);
        }

        return $this->render('account_management/index.html.twig', [
            'users' => $result['items'],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => $pages,
            ],
            'filters' => [
                'q' => $search,
                'role' => $role,
            ],
        ]);
    }

    #[Route('/specializations', name: 'app_specialization_management')]
    public function specializationIndex(SpecializationRepository $specializationRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $specializations = $specializationRepository->findAllOrderedByName();

        return $this->render('account_management/specializations.html.twig', [
            'specializations' => $specializations,
        ]);
    }

    #[Route('/specializations/new', name: 'app_specialization_new', methods: ['POST'])]
    public function newSpecialization(Request $request, SpecializationRepository $specializationRepository, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $token = $request->request->get('_csrf_token');
        if (!$csrfTokenManager->isTokenValid(new CsrfToken('specialization_create', $token))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $name = $request->request->get('name');
        
        if (!$name) {
            $this->addFlash('error', 'Specialization name is required.');
            return $this->redirectToRoute('app_specialization_management');
        }

        // Check if specialization already exists
        $existing = $specializationRepository->findOneBy(['name' => $name]);
        if ($existing) {
            $this->addFlash('error', 'Specialization already exists.');
            return $this->redirectToRoute('app_specialization_management');
        }

        $specialization = new Specialization();
        $specialization->setName($name);
        $specializationRepository->save($specialization);

        $this->addFlash('success', 'Specialization added successfully.');

        return $this->redirectToRoute('app_specialization_management');
    }

    #[Route('/specializations/{id}/delete', name: 'app_specialization_delete', methods: ['POST'])]
    public function deleteSpecialization(Specialization $specialization, Request $request, SpecializationRepository $specializationRepository, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $token = $request->request->get('_csrf_token');
        if (!$csrfTokenManager->isTokenValid(new CsrfToken('delete_specialization_' . $specialization->getId(), $token))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $specializationRepository->remove($specialization);

        $this->addFlash('success', 'Specialization deleted successfully.');

        return $this->redirectToRoute('app_specialization_management');
    }

    #[Route('/new', name: 'app_account_management_new')]
    public function new(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher, CsrfTokenManagerInterface $csrfTokenManager, SluggerInterface $slugger, LoggerInterface $logger, SpecializationRepository $specializationRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $logger->info('Account management new page accessed', ['method' => $request->getMethod()]);

        $specializations = $specializationRepository->findAllOrderedByName();

        if ($request->getMethod() === 'POST') {
            $logger->info('POST request received for account creation');
            $token = $request->request->get('_csrf_token');
            if (!$csrfTokenManager->isTokenValid(new CsrfToken('account_create', $token))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $roles = (array) ($request->request->all()['roles'] ?? ['ROLE_STUDENT']);
            $firstName = $request->request->get('first_name');
            $middleName = $request->request->get('middle_name');
            $lastName = $request->request->get('last_name');
            $degree = $request->request->get('degree');
            $degreeName = $request->request->get('degree_name');
            $specialization = $request->request->get('specialization');

            if (!$email || !$password) {
                $this->addFlash('error', 'Email and password are required.');

                return $this->redirectToRoute('app_account_management_new');
            }

            // Validate institutional email domain
            $emailLower = strtolower($email);
            if (!str_ends_with($emailLower, '@fit.edu.ph') && !str_ends_with($emailLower, '@feutech.edu.ph')) {
                $this->addFlash('error', 'Email must end with @fit.edu.ph or @feutech.edu.ph');

                return $this->redirectToRoute('app_account_management_new');
            }

            $user = new User();
            $user->setEmail($email);
            $hashedPassword = $passwordHasher->hashPassword($user, $password);
            $user->setPassword($hashedPassword);
            $user->setFirstName($firstName);
            $user->setMiddleName($middleName);
            $user->setLastName($lastName);
            $user->setDegree($degree);
            $user->setDegreeName($degreeName);
            $user->setSpecialization($specialization);

            // Handle profile picture upload
            $profilePictureFile = $request->files->get('profile_picture');
            if ($profilePictureFile) {
                $originalFilename = pathinfo($profilePictureFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$profilePictureFile->guessExtension();

                try {
                    $profilePictureFile->move(
                        $this->getParameter('kernel.project_dir') . '/public/uploads/profiles',
                        $newFilename
                    );
                    $user->setProfilePicture($newFilename);
                } catch (FileException $e) {
                    $this->addFlash('error', 'Failed to upload profile picture.');
                }
            }

            if (!empty($roles) && is_array($roles)) {
                $user->setRoles($roles);
            } else {
                $user->setRoles(['ROLE_STUDENT']);
            }

            try {
                $em->persist($user);
                $em->flush();

                $this->addFlash('success', 'Account created successfully.');

                return $this->redirectToRoute('app_account_management');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Email already exists.');

                return $this->redirectToRoute('app_account_management_new');
            }
        }

        return $this->render('account_management/new.html.twig', [
            'specializations' => $specializations,
        ]);
    }

    #[Route('/{id}', name: 'app_account_management_view')]
    public function view(User $user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('account_management/view.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/{id}/view-ajax', name: 'app_account_management_view_ajax')]
    public function viewAjax(User $user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $html = $this->renderView('account_management/_view_content.html.twig', [
            'user' => $user,
        ]);

        return new JsonResponse([
            'success' => true,
            'html' => $html,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_account_management_edit')]
    public function edit(User $user, Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher, CsrfTokenManagerInterface $csrfTokenManager, SluggerInterface $slugger, LoggerInterface $logger, SpecializationRepository $specializationRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $specializations = $specializationRepository->findAllOrderedByName();

        if ($request->getMethod() === 'POST') {
            $logger->info('POST request received for account edit', ['user_id' => $user->getId()]);

            $token = $request->request->get('_csrf_token');
            if (!$csrfTokenManager->isTokenValid(new CsrfToken('account_edit', $token))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $roles = (array) $request->request->all()['roles'] ?? [];
            $firstName = $request->request->get('first_name');
            $middleName = $request->request->get('middle_name');
            $lastName = $request->request->get('last_name');
            $degree = $request->request->get('degree');
            $degreeName = $request->request->get('degree_name');
            $specialization = $request->request->get('specialization');

            // Validate institutional email domain if email is being changed
            if ($email && $email !== $user->getEmail()) {
                $emailLower = strtolower($email);
                if (!str_ends_with($emailLower, '@fit.edu.ph') && !str_ends_with($emailLower, '@feutech.edu.ph')) {
                    $this->addFlash('error', 'Email must end with @fit.edu.ph or @feutech.edu.ph');
                    return $this->redirectToRoute('app_account_management_edit', ['id' => $user->getId()]);
                }
            }

            if ($email) {
                $user->setEmail($email);
            }

            if ($password) {
                $hashedPassword = $passwordHasher->hashPassword($user, $password);
                $user->setPassword($hashedPassword);
            }

            $user->setFirstName($firstName);
            $user->setMiddleName($middleName);
            $user->setLastName($lastName);
            $user->setDegree($degree);
            $user->setDegreeName($degreeName);
            $user->setSpecialization($specialization);

            // Handle profile picture upload
            $profilePictureFile = $request->files->get('profile_picture');
            if ($profilePictureFile) {
                $originalFilename = pathinfo($profilePictureFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$profilePictureFile->guessExtension();

                try {
                    $profilePictureFile->move(
                        $this->getParameter('kernel.project_dir') . '/public/uploads/profiles',
                        $newFilename
                    );

                    // Delete old profile picture if exists
                    if ($user->getProfilePicture()) {
                        $oldFile = $this->getParameter('kernel.project_dir') . '/public/uploads/profiles/' . $user->getProfilePicture();
                        if (file_exists($oldFile)) {
                            unlink($oldFile);
                        }
                    }

                    $user->setProfilePicture($newFilename);
                } catch (FileException $e) {
                    $logger->error('Failed to upload profile picture', ['error' => $e->getMessage()]);
                    $this->addFlash('error', 'Failed to upload profile picture.');
                }
            }

            if (!empty($roles) && is_array($roles)) {
                $user->setRoles($roles);
            }

            try {
                $em->persist($user);
                $em->flush();

                $this->addFlash('success', 'Account updated successfully.');

                return $this->redirectToRoute('app_account_management');
            } catch (\Exception $e) {
                $logger->error('Failed to update account', ['error' => $e->getMessage()]);
                $this->addFlash('error', 'Email already exists or an error occurred.');

                return $this->redirectToRoute('app_account_management_edit', ['id' => $user->getId()]);
            }
        }

        return $this->render('account_management/edit.html.twig', [
            'user' => $user,
            'specializations' => $specializations,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_account_management_delete', methods: ['POST'])]
    public function delete(
        User $user, 
        Request $request, 
        EntityManagerInterface $em, 
        CsrfTokenManagerInterface $csrfTokenManager
    ): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $token = $request->request->get('_csrf_token');
        if (!$csrfTokenManager->isTokenValid(new CsrfToken('delete_' . $user->getId(), $token))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($user === $this->getUser()) {
            $this->addFlash('error', 'You cannot delete your own account.');

            return $this->redirectToRoute('app_account_management');
        }

        $userId = (int) $user->getId();

        // Profile picture file
        if ($user->getProfilePicture()) {
            $profilePath = $this->getParameter('kernel.project_dir') . '/public/uploads/profiles/' . $user->getProfilePicture();
            if (file_exists($profilePath)) {
                unlink($profilePath);
            }
        }

        $conn = $em->getConnection();
        $quotedUserTable = $conn->quoteIdentifier('user');
        $conn->beginTransaction();

        try {
            $mentorProfileId = $conn->fetchOne('SELECT id FROM mentor_profile WHERE user_id = ?', [$userId]);

            if ($mentorProfileId !== false && $mentorProfileId !== null) {
                $mentorProfileId = (int) $mentorProfileId;
                $conn->executeStatement('DELETE FROM mentoring_appointment WHERE mentor_id = ?', [$mentorProfileId]);
                $conn->executeStatement('DELETE FROM mentor_availability WHERE mentor_id = ?', [$mentorProfileId]);
                $conn->executeStatement('DELETE FROM mentor_custom_request WHERE mentor_profile_id = ?', [$mentorProfileId]);
                $conn->executeStatement('DELETE FROM mentor_profile WHERE id = ?', [$mentorProfileId]);
            }

            $conn->executeStatement('DELETE FROM mentor_custom_request WHERE student_id = ?', [$userId]);
            $conn->executeStatement('DELETE FROM mentoring_appointment WHERE student_id = ?', [$userId]);
            $conn->executeStatement('DELETE FROM mentor_application WHERE student_id = ?', [$userId]);
            $conn->executeStatement('DELETE FROM research_content WHERE author_id = ?', [$userId]);
            $conn->executeStatement('DELETE FROM notifications WHERE user_id = ?', [$userId]);
            $conn->executeStatement('DELETE FROM reservation WHERE user_id = ?', [$userId]);
            $conn->executeStatement('DELETE FROM ' . $quotedUserTable . ' WHERE id = ?', [$userId]);

            $conn->commit();
            $em->clear();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        $this->addFlash('success', 'Account and all related data deleted successfully.');

        return $this->redirectToRoute('app_account_management');
    }

}
