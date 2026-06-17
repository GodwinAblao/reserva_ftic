<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Enforces role-based route boundaries.
 *
 * - ROLE_SUPER_ADMIN: /superadmin/*, /super-admin/*, /analytics/*, /admin/analytics/*, /account-management/*, /profile/*, /dashboard
 * - ROLE_ADMIN:       /admin/*, /analytics/*, /admin/analytics/*, /account-management/*, /profile/*, /dashboard
 * - Regular users:    /facility/*, /profile/*, /dashboard, /mentoring/* (non-admin), /research/*, /leaderboard/*
 *
 * Mismatched access → redirect to the user's own dashboard.
 */
class RoleGuardrailSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AuthorizationCheckerInterface $authChecker,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Run AFTER the firewall (priority 8) but before the controller resolver
        // Negative priority = runs after higher-priority listeners
        return [KernelEvents::REQUEST => ['onKernelRequest', -10]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return; // Not authenticated — let security handle it
        }

        // Only act on authenticated users with roles
        try {
            $isAuthenticated = $this->authChecker->isGranted('IS_AUTHENTICATED_FULLY');
        } catch (\Exception) {
            return;
        }

        if (!$isAuthenticated) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();

        // Skip public/shared paths that all roles can access
        if ($this->isPublicPath($path)) {
            return;
        }

        $isSuperAdmin = $this->authChecker->isGranted('ROLE_SUPER_ADMIN');
        $isAdmin = $this->authChecker->isGranted('ROLE_ADMIN');

        if (str_starts_with($path, '/superadmin/analytics/ledger') && !$isSuperAdmin) {
            $targetRoute = $isAdmin ? 'admin_role_home' : 'app_dashboard';
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate($targetRoute)));
            return;
        }

        // Determine redirect based on role vs path mismatch
        $redirect = null;

        if ($isSuperAdmin) {
            $redirect = $this->guardSuperAdmin($path);
        } elseif ($isAdmin) {
            $redirect = $this->guardAdmin($path);
        } else {
            $redirect = $this->guardUser($path);
        }

        if ($redirect !== null) {
            $event->setResponse(new RedirectResponse($redirect));
        }
    }

    /**
     * Paths accessible to all authenticated users (no guardrail needed).
     */
    private function isPublicPath(string $path): bool
    {
        $publicPrefixes = [
            '/health',
            '/login',
            '/register',
            '/verify-registration',
            '/logout',
            '/forgot-password',
            '/reset-password',
            '/otp-reset',
            '/profile',
            '/dashboard',
            '/api/notifications',
            '/api/user',
            '/_profiler',
            '/_wdt',
            '/assets',
            '/build',
            '/images',
            '/styles',
        ];

        foreach ($publicPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Super Admin: allowed on /superadmin, /super-admin, /admin, /analytics, /account-management, /dashboard
     * Blocked from: /facility user pages (reserve, view, reservations)
     */
    private function guardSuperAdmin(string $path): ?string
    {
        // --- Admin paths guardrail: Super Admin has own views, redirect away from /admin/* ---
        if (str_starts_with($path, '/admin')) {
            // Allow shared routes that Super Admin needs
            if (str_starts_with($path, '/admin/analytics')) {
                return null;
            }
            return $this->urlGenerator->generate('admin_home');
        }

        // --- Facility guardrail ---
        if (str_starts_with($path, '/facility')) {
            // Allow Super Admin facility management routes only
            if (str_starts_with($path, '/facility/management') ||
                str_starts_with($path, '/facility/new') ||
                str_starts_with($path, '/facility/schedule-revision') ||
                preg_match('#^/facility/\d+/(view|edit|delete|toggle-reservation|images|delete-main-image)#', $path) ||
                preg_match('#^/facility/images/\d+/delete#', $path)
            ) {
                return null;
            }
            return $this->urlGenerator->generate('admin_home');
        }

        // --- Leaderboard guardrail: user-only ---
        if (str_starts_with($path, '/leaderboard')) {
            return $this->urlGenerator->generate('admin_home');
        }

        // --- Mentoring guardrail ---
        if (str_starts_with($path, '/mentoring')) {
            // Allow admin mentoring routes
            if (str_starts_with($path, '/mentoring/super-admin') ||
                str_starts_with($path, '/mentoring/admin')
            ) {
                return null;
            }
            // Block user mentoring pages: /mentoring, /mentoring/{id}, /mentoring/{id}/preview, etc.
            return $this->urlGenerator->generate('admin_home');
        }

        return null;
    }

    /**
     * Admin: allowed on /admin, /analytics, /account-management, /dashboard
     * Blocked from: /superadmin, /super-admin, ALL /facility sub-paths (management is Super Admin only)
     */
    private function guardAdmin(string $path): ?string
    {
        // Admin should NOT access Super Admin pages
        $superAdminPrefixes = [
            '/superadmin',
            '/super-admin',
            '/account-manager',
            '/account-management',
        ];

        foreach ($superAdminPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $this->urlGenerator->generate('admin_role_home');
            }
        }

        // Admin blocked from ALL /facility paths (facility management = Super Admin only)
        if (str_starts_with($path, '/facility')) {
            if (str_starts_with($path, '/facility/schedule-revision')) {
                return null;
            }
            return $this->urlGenerator->generate('admin_role_home');
        }

        // --- Research / Innovation Hub guardrail ---
        // Admin blocked from ALL /research paths (Super Admin only module)
        if (str_starts_with($path, '/research')) {
            return $this->urlGenerator->generate('admin_role_home');
        }

        // --- Leaderboard guardrail: user-only ---
        if (str_starts_with($path, '/leaderboard')) {
            return $this->urlGenerator->generate('admin_role_home');
        }

        // --- Mentoring guardrail ---
        if (str_starts_with($path, '/mentoring')) {
            // Block Admin from ALL /mentoring/super-admin/* routes (Super Admin only)
            if (str_starts_with($path, '/mentoring/super-admin')) {
                return $this->urlGenerator->generate('admin_role_home');
            }

            // Block Admin from Super Admin-only mentoring actions under /mentoring/admin/*
            if (preg_match('#^/mentoring/admin/mentor$#', $path) ||                          // create mentor
                preg_match('#^/mentoring/admin/mentor/\d+/edit#', $path) ||                   // edit mentor
                preg_match('#^/mentoring/admin/mentor/\d+/delete#', $path) ||                 // delete mentor
                preg_match('#^/mentoring/admin/application/\d+/delete#', $path)               // delete application
            ) {
                return $this->urlGenerator->generate('admin_role_home');
            }

            // Allow remaining admin mentoring routes (respond, availability, appointment status)
            if (str_starts_with($path, '/mentoring/admin')) {
                return null;
            }

            // Block user mentoring pages: /mentoring, /mentoring/{id}, /mentoring/{id}/preview, etc.
            return $this->urlGenerator->generate('admin_role_home');
        }

        return null;
    }

    /**
     * Regular user: allowed on /facility, /dashboard, /mentoring (non-admin), /research, /leaderboard
     * Blocked from: /superadmin, /super-admin, /admin, /analytics, /account-management
     */
    private function guardUser(string $path): ?string
    {
        $adminPrefixes = [
            '/superadmin',
            '/super-admin',
            '/admin',
            '/analytics',
            '/account-management',
            '/account-manager',
        ];

        foreach ($adminPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $this->urlGenerator->generate('app_dashboard');
            }
        }

        // Block user from admin mentoring routes
        if (str_starts_with($path, '/mentoring/super-admin') ||
            str_starts_with($path, '/mentoring/admin')
        ) {
            return $this->urlGenerator->generate('app_dashboard');
        }

        // Block user from facility management routes (Super Admin only)
        if (str_starts_with($path, '/facility/management') ||
            str_starts_with($path, '/facility/new') ||
            preg_match('#^/facility/\d+/(edit|delete|toggle-reservation|images|delete-main-image)#', $path) ||
            preg_match('#^/facility/images/\d+/delete#', $path)
        ) {
            return $this->urlGenerator->generate('app_dashboard');
        }

        // Block user from research management routes (Super Admin only)
        if (str_starts_with($path, '/research/new') ||
            preg_match('#^/research/\d+/(edit|delete)#', $path)
        ) {
            return $this->urlGenerator->generate('app_dashboard');
        }

        return null;
    }
}
