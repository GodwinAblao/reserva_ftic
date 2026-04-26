<?php

declare(strict_types=1);

namespace App\Controller;

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

class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile')]
    public function index(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher, CsrfTokenManagerInterface $csrfTokenManager, SluggerInterface $slugger): Response
    {
        if ($request->getMethod() === 'POST') {
            $token = $request->request->get('_csrf_token');
            if (!$csrfTokenManager->isTokenValid(new CsrfToken('profile', $token))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $user = $this->getUser();
            
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

            // Only Super Admin can edit other fields
            if ($this->isGranted('ROLE_SUPER_ADMIN')) {
                $email = $request->request->get('email');
                $password = $request->request->get('password');
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
            }

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'Profile updated successfully.');

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/index.html.twig');
    }
}
