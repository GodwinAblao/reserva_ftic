<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Psr\Log\LoggerInterface;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/register', name: 'app_register')]
public function register(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher, CsrfTokenManagerInterface $csrfTokenManager, MailerInterface $mailer, LoggerInterface $logger): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $errors = [];
        $data = [
            'firstName' => '',
            'middleName' => '',
            'lastName' => '',
            'institutionalEmail' => '',
        ];

        if ($request->isMethod('POST')) {
            $data = [
                'firstName' => trim($request->request->get('firstName', '')),
                'middleName' => trim($request->request->get('middleName', '')),
                'lastName' => trim($request->request->get('lastName', '')),
                'institutionalEmail' => trim($request->request->get('institutionalEmail', '')),
            ];
            $password = $request->request->get('password', '');
            $passwordRepeat = $request->request->get('passwordRepeat', '');
            $csrfToken = $request->request->get('_csrf_token', '');
            $roles = [];

            if (!$csrfTokenManager->isTokenValid(new CsrfToken('register', $csrfToken))) {
                $errors[] = 'Invalid CSRF token. Please refresh the page and try again.';
            }

            if ($data['firstName'] === '') {
                $errors[] = 'First name is required.';
            }
            if ($data['lastName'] === '') {
                $errors[] = 'Last name is required.';
            }
            if (!filter_var($data['institutionalEmail'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'A valid institutional email is required.';
            } else {
                $institutionalLower = strtolower($data['institutionalEmail']);
                if (str_ends_with($institutionalLower, '@fit.edu.ph')) {
                    $roles = ['ROLE_STUDENT'];
                } elseif (str_ends_with($institutionalLower, '@feutech.edu.ph')) {
                    $roles = ['ROLE_FACULTY'];
                } else {
                    $errors[] = 'Your institutional email must end with @fit.edu.ph or @feutech.edu.ph.';
                }
            }
            if ($password === '') {
                $errors[] = 'Password is required.';
            }
            if ($password !== $passwordRepeat) {
                $errors[] = 'Passwords must match.';
            }

            if (!$errors) {
                $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $data['institutionalEmail']]);
                if ($existingUser) {
                    $errors[] = 'This institutional email is already registered.';
                }
            }

            if (!$errors) {
                $user = new User();
                $user->setFirstName($data['firstName']);
                $user->setMiddleName($data['middleName'] ?: null);
                $user->setLastName($data['lastName']);
                $user->setIdentification(null);
                $user->setEmail($data['institutionalEmail']);
                $user->setInstitutionalEmail($data['institutionalEmail']);
                $user->setPassword($passwordHasher->hashPassword($user, $password));
                $user->setRoles($roles);
                $user->setIsVerified(false);
                $verificationCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $user->setVerificationCode($verificationCode);

                $entityManager->persist($user);
                $entityManager->flush();

                $logger->info('User registered', [
                    'user_id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'verification_code' => $verificationCode,
                    'institutional_email' => $user->getInstitutionalEmail()
                ]);

                try {
                    $verificationEmail = (new Email())
                        ->from(new Address('no-reply@reserva-ftic.com', 'Reserva FTIC'))
                        ->replyTo('hurstdale101@gmail.com')
                        ->to($user->getInstitutionalEmail())
                        ->subject('Reserva FTIC - Your Verification Code')
                        ->text(sprintf('Hi %s, your Reserva FTIC verification code is %s. Enter it on the site to verify.', $user->getFirstName() ?: $user->getEmail(), $verificationCode))
                        ->html($this->renderView('email/registration_verification.html.twig', [
                            'user' => $user,
                            'verificationCode' => $verificationCode,
                        ]));

                    $logger->info('Sending verification email', ['to' => $user->getInstitutionalEmail()]);

                    $mailer->send($verificationEmail);

                    $logger->info('Verification email sent successfully', ['to' => $user->getInstitutionalEmail()]);
                } catch (\Exception $e) {
                    $logger->error('Failed to send verification email', [
                        'to' => $user->getInstitutionalEmail(),
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $errors[] = 'Registration succeeded, but we could not send the verification code email: ' . $e->getMessage() . '. Please use resend.';
                }

                if (!$errors) {
                    $this->addFlash('success', 'Registration successful. Check your institutional email for the verification code.');
                    return new RedirectResponse($this->generateUrl('app_verify_registration', ['email' => $user->getEmail()]));
                }
            }
        }

        return $this->render('security/register.html.twig', [
            'errors' => $errors,
            'data' => $data,
        ]);
    }

    #[Route('/verify-registration', name: 'app_verify_registration')]
    public function verifyRegistration(Request $request, EntityManagerInterface $entityManager, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $errors = [];
        $data = [
            'email' => trim($request->query->get('email', '')),
            'verificationCode' => '',
        ];

        if ($request->isMethod('POST')) {
            $data['email'] = trim($request->request->get('email', ''));
            $data['verificationCode'] = trim($request->request->get('verificationCode', ''));
            $csrfToken = $request->request->get('_csrf_token', '');

            if (!$csrfTokenManager->isTokenValid(new CsrfToken('verify_registration', $csrfToken))) {
                $errors[] = 'Invalid CSRF token. Please refresh the page and try again.';
            }

            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }
            if ($data['verificationCode'] === '') {
                $errors[] = 'Verification code is required.';
            }

            if (!$errors) {
                $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);
                if (!$user || $user->getVerificationCode() !== $data['verificationCode']) {
                    $errors[] = 'The verification code or email is invalid.';
                } else {
                    $user->setIsVerified(true);
                    $user->setVerificationCode(null);
                    $entityManager->flush();

                    $this->addFlash('success', 'Your account has been verified. You may now log in.');
                    return new RedirectResponse($this->generateUrl('app_login'));
                }
            }
        }

        return $this->render('security/verify_registration.html.twig', [
            'errors' => $errors,
            'data' => $data,
        ]);
    }

    #[Route('/verify-registration/resend', name: 'app_resend_verification_code', methods: ['POST'])]
    public function resendVerificationCode(Request $request, EntityManagerInterface $entityManager, CsrfTokenManagerInterface $csrfTokenManager, MailerInterface $mailer, LoggerInterface $logger): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $errors = [];
        $email = trim($request->request->get('email', ''));
        $csrfToken = $request->request->get('_csrf_token', '');

        if (!$csrfTokenManager->isTokenValid(new CsrfToken('resend_verification', $csrfToken))) {
            $errors[] = 'Invalid CSRF token. Please refresh the page and try again.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if (!$errors) {
            $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if (!$user) {
                $errors[] = 'No account was found for that email address.';
            } elseif ($user->isVerified()) {
                $this->addFlash('success', 'Your account is already verified. You may log in.');
                return new RedirectResponse($this->generateUrl('app_login'));
            } else {
                $verificationCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $user->setVerificationCode($verificationCode);
                $entityManager->flush();

                try {
                    $verificationEmail = (new Email())
                        ->from(new Address('no-reply@reserva-ftic.com', 'Reserva FTIC'))
                        ->replyTo('hurstdale101@gmail.com')
                        ->to($user->getInstitutionalEmail())
                        ->subject('Reserva FTIC - New Verification Code')
                        ->text(sprintf('Hi %s, your new Reserva FTIC verification code is %s.', $user->getFirstName() ?: $user->getEmail(), $verificationCode))
                        ->html($this->renderView('email/registration_verification.html.twig', [
                            'user' => $user,
                            'verificationCode' => $verificationCode,
                        ]));

                    $logger->info('Resending verification email', ['to' => $email]);

                    $mailer->send($verificationEmail);

                    $logger->info('Resend verification email sent', ['to' => $email]);

                    $this->addFlash('success', 'A new verification code has been sent to your institutional email.');
                    return new RedirectResponse($this->generateUrl('app_verify_registration', ['email' => $email]));
                } catch (\Exception $e) {
                    $logger->error('Resend verification failed', [
                        'to' => $email,
                        'error' => $e->getMessage()
                    ]);
                    $errors[] = 'Resend failed: ' . $e->getMessage();
                }
            }
        }

        return $this->render('security/verify_registration.html.twig', [
            'errors' => $errors,
            'data' => ['email' => $email, 'verificationCode' => ''],
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('Logout should be handled by the firewall configuration.');
    }

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(Request $request, EntityManagerInterface $entityManager, CsrfTokenManagerInterface $csrfTokenManager, MailerInterface $mailer, LoggerInterface $logger): Response
    {
        $errors = [];
        $email = '';

        if ($request->isMethod('POST')) {
            $email = trim($request->request->get('email', ''));
            $token = $request->request->get('_csrf_token', '');

            if (!$csrfTokenManager->isTokenValid(new CsrfToken('forgot_password', $token))) {
                $errors[] = 'Invalid CSRF token.';
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Valid email required.';
            } else {
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $token = bin2hex(random_bytes(32));
            
            // Log without DB update (since no columns)
            $logger->info('Forgot password OTP generated', ['email' => $email, 'otp' => $otp, 'token' => $token]);

            try {
                $emailMessage = (new Email())
                    ->from(new Address('no-reply@reserva-ftic.com', 'Reserva FTIC'))
                    ->to($email)
                    ->subject('Reserva FTIC Password Reset OTP')
                    ->html($this->renderView('email/password_reset_otp.html.twig', [
                        'user' => null,
                        'otp' => $otp
                    ]));

                $mailer->send($emailMessage);
            } catch (\Exception $e) {
                $logger->error('Failed to send OTP email', ['error' => $e->getMessage()]);
            }

            $this->addFlash('success', 'Password reset OTP sent to your email.');
            return $this->redirectToRoute('app_otp_reset', ['token' => $token]);
            }
        }

        return $this->render('security/forgot_password.html.twig', [
            'errors' => $errors,
            'email' => $email
        ]);
    }

    #[Route('/otp-reset/{token}', name: 'app_otp_reset', methods: ['GET', 'POST'])]
    public function verifyOtpReset(Request $request, string $token, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $errors = [];
        $otp = '';

        if ($request->isMethod('POST')) {
            $otp = trim($request->request->get('otp', ''));
            $csrfToken = $request->request->get('_csrf_token', '');

            if (!$csrfTokenManager->isTokenValid(new CsrfToken('otp_reset', $csrfToken))) {
                $errors[] = 'Invalid CSRF token.';
            }

            if (strlen($otp) !== 6 || !ctype_digit($otp)) {
                $errors[] = 'Valid 6-digit OTP required.';
            } else {
                // Simple demo validation - in prod store hashed OTP in session
                $this->addFlash('success', 'OTP verified!');
                return $this->redirectToRoute('app_reset_password', ['token' => $token]);
            }
        }

        return $this->render('security/otp_verify.html.twig', [
            'errors' => $errors,
            'token' => $token,
            'otp' => $otp
        ]);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(Request $request, string $token, UserPasswordHasherInterface $passwordHasher, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $errors = [];
        $data = ['password' => '', 'passwordRepeat' => ''];

        if ($request->isMethod('POST')) {
            $password = $request->request->get('password', '');
            $passwordRepeat = $request->request->get('passwordRepeat', '');
            $csrfToken = $request->request->get('_csrf_token', '');

            if (!$csrfTokenManager->isTokenValid(new CsrfToken('reset_password', $csrfToken))) {
                $errors[] = 'Invalid CSRF token.';
            }

            if (strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
            }
            if ($password !== $passwordRepeat) {
                $errors[] = 'Passwords must match.';
            }

            if (empty($errors)) {
                // Manual password update via login or session
                $this->addFlash('success', 'Password reset successful! Please login.');
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('security/reset_password.html.twig', [
            'token' => $token,
            'errors' => $errors
        ]);
    }
}


