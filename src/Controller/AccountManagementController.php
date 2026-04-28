<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/account-management')]
class AccountManagementController extends AbstractController
{
    #[Route('', name: 'app_account_management')]
    public function index(UserRepository $userRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $users = $userRepository->findAll();

        return $this->render('account_management/index.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/new', name: 'app_account_management_new')]
    public function new(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher, CsrfTokenManagerInterface $csrfTokenManager, SluggerInterface $slugger): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($request->getMethod() === 'POST') {
            $token = $request->request->get('_csrf_token');
            if (!$csrfTokenManager->isTokenValid(new CsrfToken('account_create', $token))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $roles = (array) ($request->request->all()['roles'] ?? ['ROLE_STUDENT']);
            $identification = $request->request->get('identification');
            $institutionalEmail = $request->request->get('institutional_email');
            $firstName = $request->request->get('first_name');
            $middleName = $request->request->get('middle_name');
            $lastName = $request->request->get('last_name');
            $degree = $request->request->get('degree');
            $degreeName = $request->request->get('degree_name');

            if (!$email || !$password) {
                $this->addFlash('error', 'Email and password are required.');

                return $this->redirectToRoute('app_account_management_new');
            }

            $user = new User();
            $user->setEmail($email);
            $hashedPassword = $passwordHasher->hashPassword($user, $password);
            $user->setPassword($hashedPassword);
            $user->setIdentification($identification);
            $user->setInstitutionalEmail($institutionalEmail);
            $user->setFirstName($firstName);
            $user->setMiddleName($middleName);
            $user->setLastName($lastName);
            $user->setDegree($degree);
            $user->setDegreeName($degreeName);

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

        return $this->render('account_management/new.html.twig');
    }

    #[Route('/{id}', name: 'app_account_management_view')]
    public function view(User $user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        return $this->render('account_management/view.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_account_management_edit')]
    public function edit(User $user, Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher, CsrfTokenManagerInterface $csrfTokenManager, SluggerInterface $slugger): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($request->getMethod() === 'POST') {
            $token = $request->request->get('_csrf_token');
            if (!$csrfTokenManager->isTokenValid(new CsrfToken('account_edit', $token))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $roles = (array) $request->request->all()['roles'] ?? [];
            $identification = $request->request->get('identification');
            $institutionalEmail = $request->request->get('institutional_email');
            $firstName = $request->request->get('first_name');
            $middleName = $request->request->get('middle_name');
            $lastName = $request->request->get('last_name');
            $degree = $request->request->get('degree');
            $degreeName = $request->request->get('degree_name');

            if ($email) {
                $user->setEmail($email);
            }

            if ($password) {
                $hashedPassword = $passwordHasher->hashPassword($user, $password);
                $user->setPassword($hashedPassword);
            }

            $user->setIdentification($identification);
            $user->setInstitutionalEmail($institutionalEmail);
            $user->setFirstName($firstName);
            $user->setMiddleName($middleName);
            $user->setLastName($lastName);
            $user->setDegree($degree);
            $user->setDegreeName($degreeName);

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
                    $this->addFlash('error', 'Failed to upload profile picture.');
                }
            }

            if (!empty($roles) && is_array($roles)) {
                $user->setRoles($roles);
            }

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'Account updated successfully.');

            return $this->redirectToRoute('app_account_management');
        }

        return $this->render('account_management/edit.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_account_management_delete', methods: ['POST'])]
    public function delete(User $user, Request $request, EntityManagerInterface $em, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $token = $request->request->get('_csrf_token');
        if (!$csrfTokenManager->isTokenValid(new CsrfToken('delete_' . $user->getId(), $token))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($user === $this->getUser()) {
            $this->addFlash('error', 'You cannot delete your own account.');

            return $this->redirectToRoute('app_account_management');
        }

        $em->remove($user);
        $em->flush();

        $this->addFlash('success', 'Account deleted successfully.');

        return $this->redirectToRoute('app_account_management');
    }
}
