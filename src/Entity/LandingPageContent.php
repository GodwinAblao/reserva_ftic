<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'landing_page_content')]
class LandingPageContent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $heroTitle = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $heroDescription = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $heroImage = null;

    #[ORM\Column(length: 100)]
    private string $heroCtaLabel = 'Get Started';

    #[ORM\Column(length: 255)]
    private string $heroCtaUrl = '/register';

    #[ORM\Column(length: 255)]
    private string $offersTitle = 'What we Offer';

    #[ORM\Column(length: 255)]
    private string $offersSubtitle = 'Everything you need to stay organized and connected at FTIC';

    #[ORM\Column(type: Types::TEXT)]
    private string $offersJson = '[]';

    #[ORM\Column(length: 255)]
    private string $aboutTitle = 'About Reserva';

    #[ORM\Column(length: 255)]
    private string $aboutSubtitle = 'Transforming the FTIC experience';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $aboutImage = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $aboutParagraphsJson = '[]';

    #[ORM\Column(length: 255)]
    private string $missionTitle = 'Our Mission';

    #[ORM\Column(length: 255)]
    private string $missionSubtitle = 'What we stand for';

    #[ORM\Column(type: Types::TEXT)]
    private string $missionJson = '[]';

    #[ORM\Column(length: 255)]
    private string $faqTitle = 'Frequently Asked Questions';

    #[ORM\Column(length: 255)]
    private string $faqSubtitle = 'Everything you need to know about Reserva';

    #[ORM\Column(type: Types::TEXT)]
    private string $faqJson = '[]';

    #[ORM\Column(length: 255)]
    private string $socialSectionTitle = 'Follow Us';

    #[ORM\Column(length: 255)]
    private string $socialSectionSubtitle = 'Stay connected with FTIC on our official social channels';

    #[ORM\Column(length: 255)]
    private string $contactSectionTitle = 'Contact Us';

    #[ORM\Column(length: 255)]
    private string $contactSectionSubtitle = 'Reach us through our official email';

    #[ORM\Column(length: 255)]
    private string $contactEmail = 'innovate@fit.edu.ph';

    #[ORM\Column(type: Types::TEXT)]
    private string $socialLinksJson = '[]';

    #[ORM\Column(type: Types::TEXT)]
    private string $contactLinksJson = '[]';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHeroTitle(): string
    {
        return $this->heroTitle;
    }

    public function setHeroTitle(string $heroTitle): self
    {
        $this->heroTitle = $heroTitle;
        $this->touch();
        return $this;
    }

    public function getHeroDescription(): string
    {
        return $this->heroDescription;
    }

    public function setHeroDescription(string $heroDescription): self
    {
        $this->heroDescription = $heroDescription;
        $this->touch();
        return $this;
    }

    public function getHeroImage(): ?string
    {
        return $this->heroImage;
    }

    public function setHeroImage(?string $heroImage): self
    {
        $this->heroImage = $heroImage;
        $this->touch();
        return $this;
    }

    public function getHeroCtaLabel(): string
    {
        return $this->heroCtaLabel;
    }

    public function setHeroCtaLabel(string $heroCtaLabel): self
    {
        $this->heroCtaLabel = $heroCtaLabel;
        $this->touch();
        return $this;
    }

    public function getHeroCtaUrl(): string
    {
        return $this->heroCtaUrl;
    }

    public function setHeroCtaUrl(string $heroCtaUrl): self
    {
        $this->heroCtaUrl = $heroCtaUrl;
        $this->touch();
        return $this;
    }

    public function getOffersTitle(): string
    {
        return $this->offersTitle;
    }

    public function setOffersTitle(string $offersTitle): self
    {
        $this->offersTitle = $offersTitle;
        $this->touch();
        return $this;
    }

    public function getOffersSubtitle(): string
    {
        return $this->offersSubtitle;
    }

    public function setOffersSubtitle(string $offersSubtitle): self
    {
        $this->offersSubtitle = $offersSubtitle;
        $this->touch();
        return $this;
    }

    public function getOffersJson(): string
    {
        return $this->offersJson;
    }

    public function setOffersJson(string $offersJson): self
    {
        $this->offersJson = $offersJson;
        $this->touch();
        return $this;
    }

    public function getAboutTitle(): string
    {
        return $this->aboutTitle;
    }

    public function setAboutTitle(string $aboutTitle): self
    {
        $this->aboutTitle = $aboutTitle;
        $this->touch();
        return $this;
    }

    public function getAboutSubtitle(): string
    {
        return $this->aboutSubtitle;
    }

    public function setAboutSubtitle(string $aboutSubtitle): self
    {
        $this->aboutSubtitle = $aboutSubtitle;
        $this->touch();
        return $this;
    }

    public function getAboutImage(): ?string
    {
        return $this->aboutImage;
    }

    public function setAboutImage(?string $aboutImage): self
    {
        $this->aboutImage = $aboutImage;
        $this->touch();
        return $this;
    }

    public function getAboutParagraphsJson(): string
    {
        return $this->aboutParagraphsJson;
    }

    public function setAboutParagraphsJson(string $aboutParagraphsJson): self
    {
        $this->aboutParagraphsJson = $aboutParagraphsJson;
        $this->touch();
        return $this;
    }

    public function getMissionTitle(): string
    {
        return $this->missionTitle;
    }

    public function setMissionTitle(string $missionTitle): self
    {
        $this->missionTitle = $missionTitle;
        $this->touch();
        return $this;
    }

    public function getMissionSubtitle(): string
    {
        return $this->missionSubtitle;
    }

    public function setMissionSubtitle(string $missionSubtitle): self
    {
        $this->missionSubtitle = $missionSubtitle;
        $this->touch();
        return $this;
    }

    public function getMissionJson(): string
    {
        return $this->missionJson;
    }

    public function setMissionJson(string $missionJson): self
    {
        $this->missionJson = $missionJson;
        $this->touch();
        return $this;
    }

    public function getFaqTitle(): string
    {
        return $this->faqTitle;
    }

    public function setFaqTitle(string $faqTitle): self
    {
        $this->faqTitle = $faqTitle;
        $this->touch();
        return $this;
    }

    public function getFaqSubtitle(): string
    {
        return $this->faqSubtitle;
    }

    public function setFaqSubtitle(string $faqSubtitle): self
    {
        $this->faqSubtitle = $faqSubtitle;
        $this->touch();
        return $this;
    }

    public function getFaqJson(): string
    {
        return $this->faqJson;
    }

    public function setFaqJson(string $faqJson): self
    {
        $this->faqJson = $faqJson;
        $this->touch();
        return $this;
    }

    public function getSocialSectionTitle(): string
    {
        return $this->socialSectionTitle;
    }

    public function setSocialSectionTitle(string $socialSectionTitle): self
    {
        $this->socialSectionTitle = $socialSectionTitle;
        $this->touch();
        return $this;
    }

    public function getSocialSectionSubtitle(): string
    {
        return $this->socialSectionSubtitle;
    }

    public function setSocialSectionSubtitle(string $socialSectionSubtitle): self
    {
        $this->socialSectionSubtitle = $socialSectionSubtitle;
        $this->touch();
        return $this;
    }

    public function getContactSectionTitle(): string
    {
        return $this->contactSectionTitle;
    }

    public function setContactSectionTitle(string $contactSectionTitle): self
    {
        $this->contactSectionTitle = $contactSectionTitle;
        $this->touch();
        return $this;
    }

    public function getContactSectionSubtitle(): string
    {
        return $this->contactSectionSubtitle;
    }

    public function setContactSectionSubtitle(string $contactSectionSubtitle): self
    {
        $this->contactSectionSubtitle = $contactSectionSubtitle;
        $this->touch();
        return $this;
    }

    public function getContactEmail(): string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(string $contactEmail): self
    {
        $this->contactEmail = $contactEmail;
        $this->touch();
        return $this;
    }

    public function getSocialLinksJson(): string
    {
        return $this->socialLinksJson;
    }

    public function setSocialLinksJson(string $socialLinksJson): self
    {
        $this->socialLinksJson = $socialLinksJson;
        $this->touch();
        return $this;
    }

    public function getContactLinksJson(): string
    {
        return $this->contactLinksJson;
    }

    public function setContactLinksJson(string $contactLinksJson): self
    {
        $this->contactLinksJson = $contactLinksJson;
        $this->touch();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
