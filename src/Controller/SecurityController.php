<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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

            // Secure password validation
            if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[@$!%*?&])[A-Za-z\\d@$!%*?&]{8,}$/', $password)) {
                $errors[] = 'Password must contain uppercase, lowercase, number, and special character.';
            }

            $commonPasswords = ['123456', 'password', '12345678', 'qwerty', 'abc123', 'Password1', 'admin', 'letmein'];
            if (in_array(strtolower($password), $commonPasswords)) {
                $errors[] = 'Password is too common. Choose a stronger one.';
            }

            if (!$errors) {
                $tempUser = new User();
                $hashedPassword = $passwordHasher->hashPassword($tempUser, $password);
                $verificationCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                // Store pending data in session
                $session = $request->getSession();
                $pendingData = [
                    'firstName' => $data['firstName'],
                    'middleName' => $data['middleName'] ?: null,
                    'lastName' => $data['lastName'],
                    'email' => $data['institutionalEmail'],
                    'institutionalEmail' => $data['institutionalEmail'],
                    'hashedPassword' => $hashedPassword,
                    'roles' => $roles,
                    'verificationCode' => $verificationCode,
                ];
                $session->set('pending_registration', $pendingData);

                $logger->info('Registration pending', [
                    'email' => $pendingData['email'],
                    'verification_code' => $verificationCode,
                ]);

                try {
                    $verificationEmail = (new Email())
                        ->from(new Address('hurstdale101@gmail.com', 'Reserva FTIC'))
                        ->replyTo('hurstdale101@gmail.com')
                        ->to($pendingData['institutionalEmail'])
                        ->subject('Reserva FTIC - Verify Your Registration')
                        ->text(sprintf('Hi %s %s, your Reserva FTIC verification code is %s. Enter it on the site to complete registration.', $data['firstName'], $data['lastName'], $verificationCode))
                        ->html($this->renderView('email/registration_verification.html.twig', [
                            'user' => null,
                            'verificationCode' => $verificationCode,
                            'name' => $data['firstName'] . ' ' . $data['lastName'],
                        ]));

                    $logger->info('Sending registration verification email', ['to' => $pendingData['institutionalEmail']]);

                    $mailer->send($verificationEmail);

                    $logger->info('Registration verification email sent successfully', ['to' => $pendingData['institutionalEmail']]);
                } catch (\Exception $e) {
                    $logger->error('Failed to send registration verification email', [
                        'to' => $pendingData['institutionalEmail'],
                        'error' => $e->getMessage(),
                    ]);
                    $errors[] = 'Verification code email could not be sent: ' . $e->getMessage() . '. Please try again.';
                }

                if (!$errors) {
                    if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
                        return new JsonResponse([
                            'success' => true,
                            'email' => $pendingData['email'],
                        ]);
                    }

                    return new RedirectResponse($this->generateUrl('app_verify_registration'));
                }
            }
        }

        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse([
                'success' => false,
                'errors' => $errors,
            ], 400);
        }

        return $this->render('security/register.html.twig', [
            'errors' => $errors,
            'data' => $data,
        ]);
    }

    #[Route('/verify-registration', name: 'app_verify_registration')]
    public function verifyRegistration(Request $request, EntityManagerInterface $entityManager, CsrfTokenManagerInterface $csrfTokenManager, UserPasswordHasherInterface $passwordHasher): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $session = $request->getSession();
        $pendingData = $session->get('pending_registration');
        $resendAvailableAt = (int) $session->get('verification_resend_available_at', 0);

        $errors = [];
        $data = [
            'email' => trim($request->query->get('email', '')),
            'verificationCode' => '',
        ];

        if ($pendingData && empty($data['email'])) {
            $data['email'] = $pendingData['email'];
        }

        if (!$pendingData) {
            $errors[] = 'No pending registration found. Please start registration first.';
        }

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

            if (!$errors && $pendingData) {
                if ($data['email'] !== $pendingData['email'] || $data['verificationCode'] !== $pendingData['verificationCode']) {
                    $errors[] = 'The verification code or email is invalid.';
                } else {
                    // Create user from pending data
                    $user = new User();
                    $user->setFirstName($pendingData['firstName']);
                    $user->setMiddleName($pendingData['middleName']);
                    $user->setLastName($pendingData['lastName']);
                    $user->setIdentification(null);
                    $user->setEmail($pendingData['email']);
                    $user->setInstitutionalEmail($pendingData['institutionalEmail']);
                    $user->setPassword($pendingData['hashedPassword']);
                    $user->setRoles($pendingData['roles']);
                    $user->setIsVerified(true);  // Verified immediately
                    $user->setVerificationCode(null);

                    $entityManager->persist($user);
                    $entityManager->flush();

                    // $this->logger->info('User created from pending registration', ['user_id' => $user->getId(), 'email' => $user->getEmail()]);

                    // Clear session
                    $session->remove('pending_registration'); 
                    $session->remove('verification_resend_available_at');

                    if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
                        return new JsonResponse([
                            'success' => true,
                            'redirectTo' => $this->generateUrl('app_login'),
                        ]);
                    }

                    $this->addFlash('success', 'Registration complete');
                    return new RedirectResponse($this->generateUrl('app_login'));
                }
            }
        }

        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse([
                'success' => false,
                'errors' => $errors,
            ], 400);
        }

        return $this->render('security/verify_registration.html.twig', [
            'errors' => $errors,
            'data' => $data,
            'resendCooldownRemaining' => max(0, $resendAvailableAt - time()),
        ]);
    }

    #[Route('/verify-registration/resend', name: 'app_resend_verification_code', methods: ['POST'])]
    public function resendVerificationCode(Request $request, EntityManagerInterface $entityManager, CsrfTokenManagerInterface $csrfTokenManager, MailerInterface $mailer): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $session = $request->getSession();
        $pendingData = $session->get('pending_registration');
        $email = trim($request->request->get('email', ''));
        $csrfToken = $request->request->get('_csrf_token', '');

        $errors = [];

        if (!$csrfTokenManager->isTokenValid(new CsrfToken('resend_verification', $csrfToken))) {
            $errors[] = 'Invalid CSRF token. Please refresh the page and try again.';
        }

        $availableAt = (int) $session->get('verification_resend_available_at', 0);
        if (!$errors && $availableAt > time()) {
            $errors[] = 'Please wait before requesting another code.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if (!$errors) {
            if ($pendingData && $email === $pendingData['email']) {
                // Pending registration - regenerate code
                $verificationCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $pendingData['verificationCode'] = $verificationCode;
                $session->set('pending_registration', $pendingData);

                try {
                    $verificationEmail = (new Email())
                        ->from(new Address('hurstdale101@gmail.com', 'Reserva FTIC'))
                        ->replyTo('hurstdale101@gmail.com')
                        ->to($pendingData['institutionalEmail'])
                        ->subject('Reserva FTIC - New Verification Code')
                        ->text(sprintf('Hi %s %s, your new Reserva FTIC verification code is %s.', $pendingData['firstName'], $pendingData['lastName'], $verificationCode))
                        ->html($this->renderView('email/registration_verification.html.twig', [
                            'user' => null,
                            'verificationCode' => $verificationCode,
                            'name' => $pendingData['firstName'] . ' ' . $pendingData['lastName'],
                        ]));

                    // $this->logger->info('Resending verification for pending registration', ['to' => $email]);

                    $mailer->send($verificationEmail);

                    // $this->logger->info('Resend for pending sent', ['to' => $email]); 

                    $session->set('verification_resend_available_at', time() + 120);

                    if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
                        return new JsonResponse([
                            'success' => true,
                            'message' => 'Verification code resent successfully.',
                            'email' => $email,
                            'cooldownRemaining' => 120,
                        ]);
                    }

                    return new RedirectResponse($this->generateUrl('app_verify_registration'));
                } catch (\Exception $e) {
                        // $this->logger->error('Resend pending failed', [
                            // 'to' => $email,
                            // 'error' => $e->getMessage()
                        // ]);
                        $errors[] = 'Resend failed: ' . $e->getMessage(); 
                }
            } else {
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
                            ->from(new Address('hurstdale101@gmail.com', 'Reserva FTIC'))
                            ->replyTo('hurstdale101@gmail.com')
                            ->to($user->getInstitutionalEmail())
                            ->subject('Reserva FTIC - New Verification Code')
                            ->text(sprintf('Hi %s, your new Reserva FTIC verification code is %s.', $user->getFirstName() ?: $user->getEmail(), $verificationCode))
                            ->html($this->renderView('email/registration_verification.html.twig', [
                                'user' => $user,
                                'verificationCode' => $verificationCode,
                            ]));

                        // $this->logger->info('Resending verification email', ['to' => $email]);

                        $mailer->send($verificationEmail);

                        // $this->logger->info('Resend verification email sent', ['to' => $email]); 

                        $session->set('verification_resend_available_at', time() + 120);

                        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
                            return new JsonResponse([
                                'success' => true,
                                'message' => 'Verification code resent successfully.',
                                'email' => $email,
                                'cooldownRemaining' => 120,
                            ]);
                        }

                        return new RedirectResponse($this->generateUrl('app_verify_registration', ['email' => $email]));
                    } catch (\Exception $e) {
                        // $this->logger->error('Resend verification failed', [
                            // 'to' => $email,
                            // 'error' => $e->getMessage()
                        // ]);
                        $errors[] = 'Resend failed: ' . $e->getMessage(); 
                    }
                }
            }
        }

        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            $cooldownRemaining = max(0, ((int) $session->get('verification_resend_available_at', 0)) - time());

            return new JsonResponse([
                'success' => false,
                'errors' => $errors,
                'cooldownRemaining' => $cooldownRemaining,
            ], 400);
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
                $resetToken = bin2hex(random_bytes(32));

                // Log without DB update (since no columns)
                $logger->info('Forgot password OTP generated', ['email' => $email, 'otp' => $otp, 'token' => $resetToken]);

                $session = $request->getSession();
                $session->set('reset_email', $email);
                $session->set('reset_token_expires', time() + 600); // 10 minutes
                $session->set('reset_token', $resetToken);
                $session->set('reset_otp', $otp); // Store OTP for validation

                try {
                    $emailMessage = (new Email())
                        ->from(new Address('hurstdale101@gmail.com', 'Reserva FTIC'))
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

                if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
                    return new JsonResponse([
                        'success' => true,
                        'token' => $resetToken,
                    ]);
                }

                return $this->redirectToRoute('app_otp_reset', ['token' => $resetToken]);
            }
        }

        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse([
                'success' => false,
                'error' => $errors[0] ?? 'Unable to send OTP.',
            ], 400);
        }

        return $this->render('security/forgot_password.html.twig', [
            'errors' => $errors,
            'email' => $email
        ]);
    }

    #[Route('/otp-reset/{token}', name: 'app_otp_reset', methods: ['GET', 'POST'])]
    public function verifyOtpReset(Request $request, string $token, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $session = $request->getSession();
        $storedOtp = $session->get('reset_otp');
        $resetToken = $session->get('reset_token');
        
        $errors = [];
        $otp = '';

        // Validate token and OTP session exist
        if (!$storedOtp || !$resetToken || $token !== $resetToken) {
            $errors[] = 'Invalid or expired OTP session. Please request a new password reset.';
        }

        if ($request->isMethod('POST')) {
            $otp = trim($request->request->get('otp', ''));
            $csrfToken = $request->request->get('_csrf_token', '');

            if (!$csrfTokenManager->isTokenValid(new CsrfToken('otp_reset', $csrfToken))) {
                $errors[] = 'Invalid CSRF token.';
            }

            if (strlen($otp) !== 6 || !ctype_digit($otp)) {
                $errors[] = 'Valid 6-digit OTP required.';
            } elseif ($otp !== $storedOtp) {
                // Compare with the OTP that was sent
                $errors[] = 'Invalid OTP. The code you entered does not match the one sent to your email.';
            } else {
                // OTP is valid - proceed to password reset
                if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
                    return new JsonResponse([
                        'success' => true,
                        'token' => $token,
                    ]);
                }

                return $this->redirectToRoute('app_reset_password', ['token' => $token]);
            }

            if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
                return new JsonResponse([
                    'success' => false,
                    'error' => $errors[0] ?? 'Invalid OTP.',
                ], 400);
            }
        }

        return $this->render('security/otp_verify.html.twig', [
            'errors' => $errors,
            'token' => $token,
            'otp' => $otp
        ]);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(Request $request, string $token, UserPasswordHasherInterface $passwordHasher, CsrfTokenManagerInterface $csrfTokenManager, EntityManagerInterface $entityManager): Response
    {
        $session = $request->getSession();
        $resetEmail = $session->get('reset_email');
        $resetToken = $session->get('reset_token');
        $expires = $session->get('reset_token_expires', 0);

        $errors = [];

        if (!$resetEmail || !$resetToken || time() > $expires || $token !== $resetToken) {
            $errors[] = 'Invalid or expired reset token. Please request a new password reset.';
            $session->remove('reset_email');
            $session->remove('reset_token');
            $session->remove('reset_token_expires');
        }

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

            // Secure password validation
            if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[@$!%*?&])[A-Za-z\\d@$!%*?&]{8,}$/', $password)) {
                $errors[] = 'Password must contain uppercase, lowercase, number, and special character.';
            }

            $commonPasswords = ['123456', 'password', '12345678', 'qwerty', 'abc123', 'Password1'];
            if (in_array(strtolower($password), $commonPasswords)) {
                $errors[] = 'Password is too common. Choose a stronger one.';
            }

            // Check not same as old password
            $resetEmail = $session->get('reset_email');
            if ($resetEmail) {
                $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $resetEmail]);
                if ($user && $passwordHasher->isPasswordValid($user, $password)) {
                    $errors[] = 'New password cannot be the same as old password.';
                }
            }

            // Secure password validation
            if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[@$!%*?&])[A-Za-z\\d@$!%*?&]{8,}$/', $password)) {
                $errors[] = 'Password must contain uppercase, lowercase, number, and special character.';
            }

            $commonPasswords = ['123456', 'password', '12345678', 'qwerty', 'abc123', 'Password1'];
            if (in_array(strtolower($password), $commonPasswords)) {
                $errors[] = 'Password is too common. Choose a stronger one.';
            }
            if ($password !== $passwordRepeat) {
                $errors[] = 'Passwords must match.';
            }

            if (empty($errors)) {
                $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $resetEmail]);
                if (!$user) {
                    $errors[] = 'User not found. Please try forgot password again.';
                } else {
                    $user->setPassword($passwordHasher->hashPassword($user, $password));
                    $entityManager->persist($user);
                    $entityManager->flush();

                    // Clear session
                    $session->remove('reset_email');
                    $session->remove('reset_token');
                    $session->remove('reset_token_expires');
                    $session->remove('reset_otp');

                    if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
                        return new JsonResponse([
                            'success' => true,
                            'message' => 'Password reset',
                        ]);
                    }

                    $this->addFlash('success', 'Password reset');
                    return $this->redirectToRoute('app_login');
                }
            }

            if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
                return new JsonResponse([
                    'success' => false,
                    'error' => $errors[0] ?? 'Password reset failed.',
                ], 400);
            }
        }

        return $this->render('security/reset_password.html.twig', [
            'token' => $token,
            'errors' => $errors
        ]);
    }
}


