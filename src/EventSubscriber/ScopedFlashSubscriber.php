<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class ScopedFlashSubscriber implements EventSubscriberInterface
{
    private const SCOPES = [
        'reservation_' => [
            'user_reservations',
            'admin_reservations',
            'admin_role_reservation_monitoring',
            'admin_edit_reservation',
            'admin_role_edit_reservation',
            'admin_calendar',
            'admin_role_calendar',
            'app_facility_index',
            'facility_reserve',
            'user_suggest_facility',
        ],
        'profile_' => [
            'app_profile',
            'profile_availability',
            'profile_requests',
        ],
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['hideScopedFlashesFromUnrelatedRoutes', -64],
            KernelEvents::RESPONSE => ['restoreHiddenScopedFlashes', 64],
        ];
    }

    public function hideScopedFlashesFromUnrelatedRoutes(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $route = (string) $request->attributes->get('_route', '');
        $flashBag = $request->getSession()->getFlashBag();
        $hidden = [];

        foreach ($flashBag->peekAll() as $type => $messages) {
            foreach (self::SCOPES as $prefix => $allowedRoutes) {
                if (!str_starts_with((string) $type, $prefix) || in_array($route, $allowedRoutes, true)) {
                    continue;
                }

                $hidden[$type] = $flashBag->get($type);
                break;
            }
        }

        if ($hidden !== []) {
            $request->attributes->set('_scoped_hidden_flashes', $hidden);
        }
    }

    public function restoreHiddenScopedFlashes(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $hidden = $request->attributes->get('_scoped_hidden_flashes', []);
        if (!is_array($hidden) || $hidden === []) {
            return;
        }

        $flashBag = $request->getSession()->getFlashBag();
        foreach ($hidden as $type => $messages) {
            if (!is_array($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                $flashBag->add((string) $type, $message);
            }
        }
    }
}
