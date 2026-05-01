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
        $mentorProfile = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $user]);

        if ($request->isMethod('POST')) {
            $token = $request->request->get('_csrf_token');
            if (!$csrfTokenManager->isTokenValid(new \Symfony\Component\Security\Csrf\CsrfToken('profile', $token))) {
                $this->addFlash('error', 'Invalid CSRF token.');
            } else {
                // Update user
                $firstName = $request->request->get('first_name');
                $middleName = $request->request->get('middle_name');
                $lastName = $request->request->get('last_name');
                $identification = $request->request->get('identification');
                $institutionalEmail = $request->request->get('institutional_email');
                $degree = $request->request->get('degree');
                $degreeName = $request->request->get('degree_name');
                $email = $request->request->get('email');
                $password = $request->request->get('password');

                $user->setFirstName($firstName);
                $user->setMiddleName($middleName);
                $user->setLastName($lastName);
                $user->setIdentification($identification);
                $user->setInstitutionalEmail($institutionalEmail);
                $user->setDegree($degree);
                $user->setDegreeName($degreeName);
                if ($email) $user->setEmail($email);
                if ($password) $user->setPassword($passwordHasher->hashPassword($user, $password));

                $em->persist($user);

                // Update mentor profile
                $mentorProfile = $em->getRepository(MentorProfile::class)->findOneBy(['user' => $user]);
                if (!$mentorProfile) {
                    $mentorProfile = new MentorProfile();
                    $mentorProfile->setUser($user);
                }

                $displayName = $request->request->get('display_name');
                $specialization = $request->request->get('specialization');
                $bio = $request->request->get('bio');

                $mentorProfile->setDisplayName($displayName ?: $firstName . ' ' . $lastName);
                $mentorProfile->setSpecialization($specialization ?: 'Faculty Mentor');
                $mentorProfile->setBio($bio);

                $em->persist($mentorProfile);

                try {
                    $em->flush();
                    $this->addFlash('success', 'Profile saved! Name: ' . $firstName . ', Degree: ' . $degreeName);
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Save failed: ' . $e->getMessage());
                }
            }

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/index.html.twig', [
            'mentorProfile' => $mentorProfile
        ]);
    }
}

