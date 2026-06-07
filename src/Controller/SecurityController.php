<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ResetTokenContext;
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
    public function login(AuthenticationUtils $authenticationUtils, Request $request): Response
    {
        $response = $this->noCacheResponse();

        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        // Store error in flash bag for display
        if ($error) {
            // Check if email is from allowed domains
            if ($this->isNonInstitutionalEmail($lastUsername)) {
                $errorMessage = 'Please use your institutional email (@fit.edu.ph or @feutech.edu.ph).';
            } else {
                $errorMessage = 'Invalid email or password. Please try again.';
            }
            $request->getSession()->getFlashBag()->add('login_error', $errorMessage);
        }

        $response->setContent($this->renderView('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]));

        return $response;
    }

    #[Route('/register', name: 'app_register')]
public function register(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher, CsrfTokenManagerInterface $csrfTokenManager, MailerInterface $mailer, LoggerInterface $logger): Response
    {
        $response = $this->noCacheResponse();

        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $session = $request->getSession();

        // Ensure session is started and CSRF tokens exist (needed for fresh sessions/email links)
        // Only do this on GET requests to avoid invalidating tokens when forms are submitted
        if ($request->isMethod('GET')) {
            if (!$session->isStarted()) {
                $session->start();
            }
            $csrfTokenManager->refreshToken('register');
            $csrfTokenManager->refreshToken('verify_registration');
            $csrfTokenManager->refreshToken('resend_verification');
        }

        $errors = [];
        $data = [
            'firstName' => '',
            'middleName' => '',
            'lastName' => '',
            'email' => trim($request->query->get('email', '')),
        ];

        // Check if coming from email verification link
        $showVerifyModal = $request->query->get('verify') === '1';
        $preFilledCode = trim($request->query->get('code', ''));

        if ($request->isMethod('POST')) {
            $result = $this->handleRegistrationPost($request, $entityManager, $passwordHasher, $csrfTokenManager, $mailer, $logger, $data, $errors);
            if ($result instanceof Response) {
                return $result;
            }
        }

        if ($this->isAjaxRequest($request)) {
            $logger->error('Registration validation failed', ['errors' => $errors, 'email' => $data['email']]);
            return new JsonResponse([
                'success' => false,
                'errors' => $errors,
            ], 400);
        }

        $response->setContent($this->renderView('security/register.html.twig', [
            'errors' => $errors,
            'data' => $data,
            'show_verify_modal' => $showVerifyModal,
            'prefilled_code' => $preFilledCode,
        ]));

        return $response;
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

        // Ensure session is started and CSRF token exists (needed for fresh sessions/email links)
        // Only do this on GET requests to avoid invalidating tokens when forms are submitted
        if ($request->isMethod('GET')) {
            if (!$session->isStarted()) {
                $session->start();
            }
            $csrfTokenManager->refreshToken('verify_registration');
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
                    return $this->createVerifiedUser($pendingData, $session, $entityManager, $request);
                }
            }
        }

        if ($this->isAjaxRequest($request)) {
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
                $result = $this->resendToPending($pendingData, $session, $mailer, $request, $errors);
                if ($result instanceof Response) return $result;
            } else {
                $result = $this->resendToExistingUser($email, $session, $entityManager, $mailer, $request, $errors);
                if ($result instanceof Response) return $result;
            }
        }

        if ($this->isAjaxRequest($request)) {
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

            if (!$errors) {
                $forgotErrors = $this->validateForgotEmail($email, $entityManager);
                array_push($errors, ...$forgotErrors);
            }
            if (!$errors) {
                $otp        = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $resetToken = bin2hex(random_bytes(32));
                $logger->info('Forgot password OTP generated', ['email' => $email, 'otp' => $otp, 'token' => $resetToken]);

                $session = $request->getSession();
                $session->set('reset_email', $email);
                $session->set('reset_token_expires', time() + 600);
                $session->set('reset_token', $resetToken);
                $session->set('reset_otp', $otp);

                $this->sendOtpEmail($email, $otp, $mailer, $logger);

                if ($this->isAjaxRequest($request)) {
                    return new JsonResponse(['success' => true, 'token' => $resetToken]);
                }
                return $this->redirectToRoute('app_otp_reset', ['token' => $resetToken]);
            }
        }

        if ($this->isAjaxRequest($request)) {
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

        if (!$this->isOtpSessionValid($storedOtp, $resetToken, $token)) {
            $errors[] = 'Invalid or expired OTP session. Please request a new password reset.';
        }

        if ($request->isMethod('POST')) {
            $otp = trim($request->request->get('otp', ''));
            $csrfToken = $request->request->get('_csrf_token', '');

            if (!$csrfTokenManager->isTokenValid(new CsrfToken('otp_reset', $csrfToken))) {
                $errors[] = 'Invalid CSRF token.';
            }

            if (!$this->isValidOtpFormat($otp)) {
                $errors[] = 'Valid 6-digit OTP required.';
            } elseif ($otp !== $storedOtp) {
                $errors[] = 'Invalid OTP. The code you entered does not match the one sent to your email.';
            } else {
                // OTP is valid - proceed to password reset
                if ($this->isAjaxRequest($request)) {
                    return new JsonResponse([
                        'success' => true,
                        'token' => $token,
                    ]);
                }

                return $this->redirectToRoute('app_reset_password', ['token' => $token]);
            }

            if ($this->isAjaxRequest($request)) {
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
        $ctx = new ResetTokenContext(
            $session,
            $session->get('reset_email'),
            $session->get('reset_token'),
            (int) $session->get('reset_token_expires', 0),
        );

        $errors = [];

        array_push($errors, ...$this->validateResetToken($ctx, $token));

        if ($request->isMethod('POST')) {
            $password = $request->request->get('password', '');
            $passwordRepeat = $request->request->get('passwordRepeat', '');
            $csrfToken = $request->request->get('_csrf_token', '');

            if (!$csrfTokenManager->isTokenValid(new CsrfToken('reset_password', $csrfToken))) {
                $errors[] = 'Invalid CSRF token.';
            }

            array_push($errors, ...$this->validatePassword($password));

            if ($ctx->resetEmail) {
                $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $ctx->resetEmail]);
                if ($user && $passwordHasher->isPasswordValid($user, $password)) {
                    $errors[] = 'New password cannot be the same as old password.';
                }
            }

            if ($password !== $passwordRepeat) {
                $errors[] = 'Passwords must match.';
            }

            if (empty($errors)) {
                $result = $this->applyPasswordReset($ctx, $password, $entityManager, $passwordHasher, $request, $errors);
                if ($result instanceof Response) return $result;
            }

            if ($this->isAjaxRequest($request)) {
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
    private function isNonInstitutionalEmail(?string $email): bool
    {
        if (!$email) return false;
        $lower = strtolower($email);
        return !str_ends_with($lower, '@fit.edu.ph') && !str_ends_with($lower, '@feutech.edu.ph');
    }

    private function handleRegistrationPost(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        CsrfTokenManagerInterface $csrfTokenManager,
        MailerInterface $mailer,
        LoggerInterface $logger,
        array &$data,
        array &$errors,
    ): ?Response {
        $data = [
            'firstName'  => trim($request->request->get('firstName', '')),
            'middleName' => trim($request->request->get('middleName', '')),
            'lastName'   => trim($request->request->get('lastName', '')),
            'email'      => trim($request->request->get('email', '')),
        ];
        $password       = $request->request->get('password', '');
        $passwordRepeat = $request->request->get('passwordRepeat', '');
        $csrfToken      = $request->request->get('_csrf_token', '');

        if (!$csrfTokenManager->isTokenValid(new CsrfToken('register', $csrfToken))) {
            $errors[] = 'Invalid CSRF token. Please refresh the page and try again.';
        }
        if ($data['firstName'] === '') $errors[] = 'First name is required.';
        if ($data['lastName']  === '') $errors[] = 'Last name is required.';
        [$roles, $emailErrors] = $this->resolveEmailRole($data['email']);
        array_push($errors, ...$emailErrors);
        if ($password === '') $errors[] = 'Password is required.';

        if (!$errors) {
            array_push($errors, ...$this->validateRegistrationPassword($data['email'], $password, $passwordRepeat, $em));
        }

        if ($errors) return null;

        $session      = $request->getSession();
        $hashedPw     = $passwordHasher->hashPassword(new User(), $password);
        $code         = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $pendingData  = [
            'firstName'        => $data['firstName'],
            'middleName'       => $data['middleName'] ?: null,
            'lastName'         => $data['lastName'],
            'email'            => $data['email'],
            'hashedPassword'   => $hashedPw,
            'roles'            => $roles,
            'verificationCode' => $code,
        ];
        $session->set('pending_registration', $pendingData);
        $logger->info('Registration pending', ['email' => $data['email'], 'verification_code' => $code]);

        $emailSent = false;
        try {
            $mailer->send($this->buildVerificationEmail($data['email'], $data['firstName'] . ' ' . $data['lastName'], $code, 'Reserva FTIC - Verify Your Registration'));
            $logger->info('Registration verification email sent successfully', ['to' => $data['email']]);
            $emailSent = true;
        } catch (\Exception $e) {
            $logger->error('Failed to send registration verification email', ['to' => $data['email'], 'error' => $e->getMessage()]);
        }

        if ($this->isAjaxRequest($request)) {
            return new JsonResponse([
                'success'   => true,
                'email'     => $data['email'],
                'emailSent' => $emailSent,
                'notice'    => $emailSent ? null : 'Verification email could not be sent. Please use "Resend code" to try again.',
            ]);
        }
        return new RedirectResponse($this->generateUrl('app_verify_registration'));
    }

    private function createVerifiedUser(
        array $pendingData,
        \Symfony\Component\HttpFoundation\Session\SessionInterface $session,
        EntityManagerInterface $em,
        Request $request,
    ): Response {
        $user = (new User())
            ->setFirstName($pendingData['firstName'])
            ->setMiddleName($pendingData['middleName'])
            ->setLastName($pendingData['lastName'])
            ->setEmail($pendingData['email'])
            ->setPassword($pendingData['hashedPassword'])
            ->setRoles($pendingData['roles'])
            ->setIsVerified(true)
            ->setVerificationCode(null);

        $em->persist($user);
        $em->flush();
        $session->remove('pending_registration');
        $session->remove('verification_resend_available_at');

        if ($this->isAjaxRequest($request)) {
            return new JsonResponse(['success' => true, 'redirectTo' => $this->generateUrl('app_login')]);
        }
        $this->addFlash('success', 'Registration complete');
        return new RedirectResponse($this->generateUrl('app_login'));
    }

    private function resendToPending(
        array &$pendingData,
        \Symfony\Component\HttpFoundation\Session\SessionInterface $session,
        MailerInterface $mailer,
        Request $request,
        array &$errors,
    ): ?Response {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $pendingData['verificationCode'] = $code;
        $session->set('pending_registration', $pendingData);
        try {
            $mailer->send($this->buildVerificationEmail($pendingData['email'], $pendingData['firstName'] . ' ' . $pendingData['lastName'], $code, 'Reserva FTIC - New Verification Code'));
            $session->set('verification_resend_available_at', time() + 120);
            if ($this->isAjaxRequest($request)) {
                return new JsonResponse(['success' => true, 'message' => 'Verification code resent successfully.', 'email' => $pendingData['email'], 'cooldownRemaining' => 120]);
            }
            return new RedirectResponse($this->generateUrl('app_verify_registration'));
        } catch (\Exception $e) {
            $errors[] = 'Resend failed: ' . $e->getMessage();
            return null;
        }
    }

    private function resendToExistingUser(
        string $email,
        \Symfony\Component\HttpFoundation\Session\SessionInterface $session,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        Request $request,
        array &$errors,
    ): ?Response {
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $errors[] = 'No account was found for that email address.';
            return null;
        }
        if ($user->isVerified()) {
            $this->addFlash('success', 'Your account is already verified. You may log in.');
            return new RedirectResponse($this->generateUrl('app_login'));
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $user->setVerificationCode($code);
        $em->flush();
        try {
            $mailer->send($this->buildVerificationEmail($user->getEmail(), trim(($user->getFirstName() ?: '') . ' ' . ($user->getLastName() ?: '')), $code, 'Reserva FTIC - New Verification Code'));
            $session->set('verification_resend_available_at', time() + 120);
            if ($this->isAjaxRequest($request)) {
                return new JsonResponse(['success' => true, 'message' => 'Verification code resent successfully.', 'email' => $email, 'cooldownRemaining' => 120]);
            }
            return new RedirectResponse($this->generateUrl('app_verify_registration', ['email' => $email]));
        } catch (\Exception $e) {
            $errors[] = 'Resend failed: ' . $e->getMessage();
            return null;
        }
    }

    private function applyPasswordReset(
        ResetTokenContext $ctx,
        string $password,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        Request $request,
        array &$errors,
    ): ?Response {
        $user = $em->getRepository(User::class)->findOneBy(['email' => $ctx->resetEmail]);
        if (!$user) {
            $errors[] = 'User not found. Please try forgot password again.';
            return null;
        }
        $user->setPassword($passwordHasher->hashPassword($user, $password));
        $em->persist($user);
        $em->flush();
        $ctx->clearSession();
        $ctx->session->remove('reset_otp');
        if ($this->isAjaxRequest($request)) {
            return new JsonResponse(['success' => true, 'message' => 'Password reset']);
        }
        $this->addFlash('success', 'Password reset');
        return $this->redirectToRoute('app_login');
    }

    private function isAjaxRequest(Request $request): bool
    {
        return $request->isXmlHttpRequest()
            || str_contains((string) $request->headers->get('Accept'), 'application/json');
    }

    private function noCacheResponse(): Response
    {
        $response = new Response();
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        return $response;
    }

    private function validatePassword(string $password): array
    {
        $errors = [];
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $password)) {
            $errors[] = 'Password must contain uppercase, lowercase, number, and special character.';
        }
        $common = ['123456', 'password', '12345678', 'qwerty', 'abc123', 'Password1', 'admin', 'letmein'];
        if (in_array(strtolower($password), $common, true)) {
            $errors[] = 'Password is too common. Choose a stronger one.';
        }
        return $errors;
    }

    private function resolveEmailRole(string $email): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [[], ['A valid email is required.']];
        }
        $lower = strtolower($email);
        if (str_ends_with($lower, '@fit.edu.ph')) {
            return [['ROLE_STUDENT'], []];
        }
        if (str_ends_with($lower, '@feutech.edu.ph')) {
            return [['ROLE_FACULTY'], []];
        }
        return [[], ['Your email must end with @fit.edu.ph or @feutech.edu.ph.']];
    }

    private function buildVerificationEmail(string $to, string $name, string $code, string $subject): Email
    {
        return (new Email())
            ->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))
            ->replyTo('hurstdale101@gmail.com')
            ->to($to)
            ->subject($subject)
            ->text(sprintf('Hi %s, your Reserva FTIC verification code is %s.', $name, $code))
            ->html($this->renderView('email/registration_verification.html.twig', [
                'user'             => null,
                'email'            => $to,
                'verificationCode' => $code,
                'name'             => $name,
            ]));
    }

    private function validateResetToken(ResetTokenContext $ctx, string $token): array
    {
        if (!$ctx->isValid($token)) {
            $ctx->clearSession();
            return ['Invalid or expired reset token. Please request a new password reset.'];
        }
        return [];
    }

    private function validateForgotEmail(string $email, EntityManagerInterface $em): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['Valid email required.'];
        }
        $domain = substr(strrchr($email, '@'), 1);
        if (!in_array($domain, ['fit.edu.ph', 'feutech.edu.ph'], true)) {
            return ['Only @fit.edu.ph and @feutech.edu.ph email addresses are allowed.'];
        }
        $user = $em->getRepository(User::class)->findOneByEmailCaseInsensitive($email);
        if (!$user) {
            return ['No account is associated with this email address.'];
        }
        return [];
    }

    private function validateRegistrationPassword(string $email, string $password, string $passwordRepeat, EntityManagerInterface $em): array
    {
        $errors = [];
        if ($em->getRepository(User::class)->findOneBy(['email' => $email])) {
            $errors[] = 'This email is already registered.';
        }
        array_push($errors, ...$this->validatePassword($password));
        if ($password !== $passwordRepeat) {
            $errors[] = 'Passwords must match.';
        }
        return $errors;
    }

    private function sendOtpEmail(string $email, string $otp, MailerInterface $mailer, LoggerInterface $logger): void
    {
        try {
            $mailer->send(
                (new Email())
                    ->from(new Address('noreply@fticreserva.website', 'Reserva FTIC'))
                    ->to($email)
                    ->subject('Reserva FTIC Password Reset OTP')
                    ->html($this->renderView('email/password_reset_otp.html.twig', ['user' => null, 'otp' => $otp]))
            );
            $logger->info('OTP email sent successfully', ['to' => $email]);
        } catch (\Exception $e) {
            $logger->error('Failed to send OTP email', ['error' => $e->getMessage(), 'to' => $email]);
        }
    }

    private function isValidOtpFormat(string $otp): bool
    {
        return strlen($otp) === 6 && ctype_digit($otp);
    }

    private function isOtpSessionValid(?string $storedOtp, ?string $resetToken, string $token): bool
    {
        return $storedOtp !== null && $resetToken !== null && $token === $resetToken;
    }
}


