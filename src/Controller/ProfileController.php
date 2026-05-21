<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

use App\Entity\User;
use App\Entity\MentorProfile;

class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request, 
        EntityManagerInterface $em, 
        UserPasswordHasherInterface $passwordHasher, 
        CsrfTokenManagerInterface $csrfTokenManager, 
        SluggerInterface $slugger
    ): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('You must be logged in to edit your profile.');
        }

        $mentorProfile = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $user]);

        $isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';

        if ($request->isMethod('POST')) {
            $token = $request->request->get('_csrf_token');
            if (!$csrfTokenManager->isTokenValid(new \Symfony\Component\Security\Csrf\CsrfToken('profile', $token))) {
                if ($isAjax) return $this->json(['success' => false, 'message' => 'Invalid CSRF token.']);
                $this->addFlash('error', 'Invalid CSRF token.');
            } else {
                // Update user
                $firstName = $request->request->get('first_name');
                $middleName = $request->request->get('middle_name');
                $lastName = $request->request->get('last_name');
                $degree = $request->request->get('degree');
                $degreeName = $request->request->get('degree_name');
                $currentPassword = $request->request->get('current_password');
                $password = $request->request->get('password');
                $passwordConfirm = $request->request->get('password_confirm');
                $profilePictureFile = $request->files->get('profile_picture');

                if ($firstName) $user->setFirstName($firstName);
                if ($middleName !== null) $user->setMiddleName($middleName ?: null);
                if ($lastName) $user->setLastName($lastName);
                if ($degree !== null) $user->setDegree($degree ?: null);
                if ($degreeName !== null) $user->setDegreeName($degreeName ?: null);

                // Password change — require current password + confirmation match
                if ($password) {
                    if (!$currentPassword) {
                        if ($isAjax) return $this->json(['success' => false, 'message' => 'Please enter your current password to set a new one.']);
                        $this->addFlash('error', 'Please enter your current password to set a new one.');
                        return $this->redirectToRoute('app_profile');
                    }
                    if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
                        if ($isAjax) return $this->json(['success' => false, 'message' => 'Current password is incorrect.']);
                        $this->addFlash('error', 'Current password is incorrect.');
                        return $this->redirectToRoute('app_profile');
                    }
                    if ($password !== $passwordConfirm) {
                        if ($isAjax) return $this->json(['success' => false, 'message' => 'New passwords do not match.']);
                        $this->addFlash('error', 'New passwords do not match.');
                        return $this->redirectToRoute('app_profile');
                    }
                    $user->setPassword($passwordHasher->hashPassword($user, $password));
                }

                if ($profilePictureFile instanceof UploadedFile && $profilePictureFile->isValid()) {
                    $originalFilename = pathinfo($profilePictureFile->getClientOriginalName(), PATHINFO_FILENAME);
                    $safeFilename = $slugger->slug($originalFilename);
                    $extension = $profilePictureFile->guessExtension() ?: 'jpg';
                    $newFilename = $safeFilename . '-' . uniqid() . '.' . $extension;

                    try {
                        $profilePictureFile->move(
                            $this->getParameter('kernel.project_dir') . '/public/uploads/profiles',
                            $newFilename
                        );

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

                $em->persist($user);

// Update mentor profile only if user has mentor role
                if (in_array('ROLE_MENTOR', $user->getRoles(), true)) {
                    $mentorProfile = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $user]);
                    if (!$mentorProfile) {
                        $mentorProfile = new MentorProfile();
                        $mentorProfile->setUser($user);
                    }

                    $displayName      = $request->request->get('display_name');
                    $specialization   = $request->request->get('specialization');
                    $bio              = $request->request->get('bio');
                    $education        = $request->request->get('mentor_education');
                    $availDay         = trim((string) $request->request->get('availability_day'));
                    $availStart       = trim((string) $request->request->get('availability_start'));
                    $availEnd         = trim((string) $request->request->get('availability_end'));

                    if ($displayName) {
                        $mentorProfile->setDisplayName($displayName);
                    }
                    if ($specialization) {
                        $mentorProfile->setSpecialization($specialization);
                    }
                    if ($bio !== null) {
                        $mentorProfile->setBio($bio);
                    }
                    if ($education !== null) {
                        $mentorProfile->setEducation($education ?: null);
                    }
                    $mentorProfile->setAvailabilityDay($availDay !== '' ? $availDay : null);
                    $mentorProfile->setAvailabilityStart($availStart !== '' ? $availStart : null);
                    $mentorProfile->setAvailabilityEnd($availEnd !== '' ? $availEnd : null);

                    $em->persist($mentorProfile);
                }

                try {
                    $em->flush();
                    if ($isAjax) return $this->json(['success' => true, 'message' => 'Profile updated successfully.']);
                    $this->addFlash('success', 'Profile updated successfully.');
                } catch (\Exception $e) {
                    if ($isAjax) return $this->json(['success' => false, 'message' => 'Save failed: ' . $e->getMessage()]);
                    $this->addFlash('error', 'Save failed: ' . $e->getMessage());
                }
            }

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/index.html.twig', [
            'mentorProfile' => $mentorProfile,
        ]);
    }
}
