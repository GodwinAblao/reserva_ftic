<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LandingPageContent;

class LandingPageContentService
{
    public function defaults(): array
    {
        return [
            'heroTitle' => 'Your All-in-One Hub for Reservations, Mentoring, and Learning at FTIC',
            'heroDescription' => 'Reserva simplifies room reservations, mentoring sessions, and access to FTIC research and innovation articles - helping students and faculty stay organized, connected, and productive within the FEU Tech Innovation Center.',
            'heroImage' => '/innovcent_header_photo.jpg',
            'heroCtaLabel' => 'Get Started',
            'heroCtaUrl' => '/register',
            'offersTitle' => 'What we Offer',
            'offersSubtitle' => 'Everything you need to stay organized and connected at FTIC',
            'offers' => [
                [
                    'key' => 'facility',
                    'title' => 'Smart Facility Reservation',
                    'description' => 'Check room availability in real time and book rooms for classes, meetings, and events',
                    'linkLabel' => 'View Facilities',
                    'linkUrl' => '#facilities',
                    'image' => '/uploads/Facility.jpeg',
                ],
                [
                    'key' => 'research',
                    'title' => 'FTIC Research & Innovation Hub',
                    'description' => 'Access FTIC research publications, explore innovation content, and discover academic achievements',
                    'linkLabel' => 'Get Started',
                    'linkUrl' => '/register',
                    'image' => '/uploads/innovation-hub.jpg',
                ],
                [
                    'key' => 'mentoring',
                    'title' => 'Mentoring Sessions Made Easy',
                    'description' => 'Connect with mentors and schedule consultations quickly',
                    'linkLabel' => 'Get Started',
                    'linkUrl' => '/register',
                    'image' => '/uploads/mentoring.jpg',
                ],
                [
                    'key' => 'leaderboard',
                    'title' => 'Mentorship Leaderboard',
                    'description' => 'Earn recognition through mentoring participation and track engagement',
                    'linkLabel' => 'Get Started',
                    'linkUrl' => '/register',
                    'image' => '/uploads/mentor-leaderboard.jpg',
                ],
            ],
            'aboutTitle' => 'About Reserva',
            'aboutSubtitle' => 'Transforming the FTIC experience',
            'aboutImage' => '/images/Reserva-Logo.png',
            'aboutParagraphs' => [
                'Reserva is a modern web-based platform created for the FEU Tech Innovation Center (FTIC) to simplify facility reservations, mentoring coordination, and access to FTIC research and innovation content.',
                'By transforming manual processes into a centralized digital experience, Reserva helps create a more efficient, accessible, and connected environment for students and faculty.',
            ],
            'missionTitle' => 'Our Mission',
            'missionSubtitle' => 'What drives the platform forward',
            'missionItems' => [
                [
                    'title' => 'Vision',
                    'text' => 'Simplify reservation and scheduling processes while creating a smarter and more organized FTIC experience',
                ],
                [
                    'title' => 'Mission',
                    'text' => 'Improve access to mentoring opportunities and centralize FTIC research and innovation resources',
                ],
                [
                    'title' => 'Goals',
                    'text' => 'Encourage collaboration and engagement across the FTIC community for a connected environment',
                ],
            ],
            'faqTitle' => 'Frequently Asked Questions',
            'faqSubtitle' => 'Everything you need to know about Reserva',
            'faqItems' => [
                [
                    'question' => 'What is Reserva?',
                    'answer' => 'Reserva is a comprehensive web-based platform designed for the FEU Tech Innovation Center that simplifies facility reservations, mentoring coordination, and academic resource access in one place.',
                ],
                [
                    'question' => 'What can I do in Reserva?',
                    'answer' => 'You can reserve rooms and facilities, request mentoring sessions, access academic resources, manage schedules, and track activities in one centralized dashboard.',
                ],
                [
                    'question' => 'How does room reservation work?',
                    'answer' => 'Check room availability in real time, select your preferred room and time slot, submit your reservation request, and wait for approval.',
                ],
                [
                    'question' => 'How do mentoring sessions work?',
                    'answer' => 'You can search for mentors based on skills and expertise, request a session, and receive confirmation once approved.',
                ],
                [
                    'question' => 'Can students apply as mentors?',
                    'answer' => 'Yes. Students can apply to become mentors and share their expertise with others in the FTIC community.',
                ],
                [
                    'question' => 'What is the Mentorship Leaderboard?',
                    'answer' => 'The Mentorship Leaderboard recognizes active mentors and tracks mentor engagement and contributions.',
                ],
                [
                    'question' => 'What resources can I access?',
                    'answer' => 'You can access research papers, academic articles, innovation-focused content, and other educational materials for the FTIC community.',
                ],
                [
                    'question' => 'Can I manage my schedule in Reserva?',
                    'answer' => 'Yes. Reserva provides a centralized dashboard where you can view and manage reservations, mentoring sessions, and schedules.',
                ],
            ],
            'socialSectionTitle' => 'Follow Us',
            'socialSectionSubtitle' => 'Stay connected with FTIC on our official social channels',
            'contactSectionTitle' => 'Contact Us',
            'contactSectionSubtitle' => 'Reach us through our official email',
            'contactEmail' => 'innovate@fit.edu.ph',
            'socialLinks' => [
                [
                    'platform' => 'Facebook',
                    'url' => 'https://www.facebook.com/feutechinnovationcenter',
                ],
                [
                    'platform' => 'Instagram',
                    'url' => 'https://www.instagram.com/feutechinnovationcenter/',
                ],
                [
                    'platform' => 'TikTok',
                    'url' => 'https://www.tiktok.com/@feutechinnovcenter',
                ],
            ],
            'contactLinks' => [
                [
                    'label' => 'Email',
                    'url' => 'mailto:innovate@fit.edu.ph',
                    'displayText' => 'innovate@fit.edu.ph',
                ],
            ],
        ];
    }

    public function toViewData(?LandingPageContent $content): array
    {
        $defaults = $this->defaults();

        if (!$content) {
            return $defaults;
        }

        return [
            'heroTitle' => $content->getHeroTitle() ?: $defaults['heroTitle'],
            'heroDescription' => $content->getHeroDescription() ?: $defaults['heroDescription'],
            'heroImage' => $content->getHeroImage() ?: $defaults['heroImage'],
            'heroCtaLabel' => $content->getHeroCtaLabel() ?: $defaults['heroCtaLabel'],
            'heroCtaUrl' => $content->getHeroCtaUrl() ?: $defaults['heroCtaUrl'],
            'offersTitle' => $content->getOffersTitle() ?: $defaults['offersTitle'],
            'offersSubtitle' => $content->getOffersSubtitle() ?: $defaults['offersSubtitle'],
            'offers' => $this->normalizeOfferImages($this->decodeArray($content->getOffersJson(), $defaults['offers'])),
            'aboutTitle' => $content->getAboutTitle() ?: $defaults['aboutTitle'],
            'aboutSubtitle' => $content->getAboutSubtitle() ?: $defaults['aboutSubtitle'],
            'aboutImage' => $content->getAboutImage() ?: $defaults['aboutImage'],
            'aboutParagraphs' => $this->decodeArray($content->getAboutParagraphsJson(), $defaults['aboutParagraphs']),
            'missionTitle' => $content->getMissionTitle() ?: $defaults['missionTitle'],
            'missionSubtitle' => $content->getMissionSubtitle() ?: $defaults['missionSubtitle'],
            'missionItems' => $this->decodeArray($content->getMissionJson(), $defaults['missionItems']),
            'faqTitle' => $content->getFaqTitle() ?: $defaults['faqTitle'],
            'faqSubtitle' => $content->getFaqSubtitle() ?: $defaults['faqSubtitle'],
            'faqItems' => $this->decodeArray($content->getFaqJson(), $defaults['faqItems']),
            'socialSectionTitle' => $content->getSocialSectionTitle() ?: $defaults['socialSectionTitle'],
            'socialSectionSubtitle' => $content->getSocialSectionSubtitle() ?: $defaults['socialSectionSubtitle'],
            'contactSectionTitle' => $content->getContactSectionTitle() ?: $defaults['contactSectionTitle'],
            'contactSectionSubtitle' => $content->getContactSectionSubtitle() ?: $defaults['contactSectionSubtitle'],
            'contactEmail' => $content->getContactEmail() ?: $defaults['contactEmail'],
            'socialLinks' => $this->mergeMissingIndexedDefaults(
                $this->decodeArray($content->getSocialLinksJson(), $defaults['socialLinks']),
                $defaults['socialLinks']
            ),
            'contactLinks' => $this->mergeMissingIndexedDefaults(
                $this->decodeArray($content->getContactLinksJson(), $defaults['contactLinks']),
                $defaults['contactLinks']
            ),
        ];
    }

    public function encodeArray(array $value): string
    {
        return json_encode(array_values($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    public function decodeArray(?string $json, array $fallback): array
    {
        if (!$json) {
            return $fallback;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || $decoded === []) {
            return $fallback;
        }

        return $decoded;
    }

    private function mergeMissingIndexedDefaults(array $items, array $defaults): array
    {
        $result = [];
        foreach ($defaults as $index => $fallback) {
            $value = $items[$index] ?? null;
            $result[] = is_array($value) ? array_merge($fallback, $value) : $fallback;
        }

        foreach (array_slice($items, count($defaults)) as $value) {
            if (is_array($value)) {
                $result[] = $value;
            }
        }

        return $result;
    }

    private function normalizeOfferImages(array $offers): array
    {
        return array_map(function (array $offer): array {
            if (!isset($offer['image']) || !is_string($offer['image'])) {
                return $offer;
            }

            $offer['image'] = $this->canonicalOfferImagePath($offer['image']);

            return $offer;
        }, $offers);
    }

    private function canonicalOfferImagePath(string $path): string
    {
        $normalized = trim($path);
        $legacyMap = [
            '/uploads/innovation%20hub.jpg' => '/uploads/innovation-hub.jpg',
            '/uploads/innovation hub.jpg' => '/uploads/innovation-hub.jpg',
            '/uploads/mentor%20leaderboard.jpg' => '/uploads/mentor-leaderboard.jpg',
            '/uploads/mentor leaderboard.jpg' => '/uploads/mentor-leaderboard.jpg',
        ];

        if (isset($legacyMap[$normalized])) {
            return $legacyMap[$normalized];
        }

        return $normalized;
    }
}
