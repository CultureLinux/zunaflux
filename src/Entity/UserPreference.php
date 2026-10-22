<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'user_preference')]
final class UserPreference
{
    #[ORM\Id] #[ORM\Column(type: 'uuid')] private Uuid $id;
    #[ORM\OneToOne] #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')] private User $user;
    #[ORM\Column(length: 10)] private string $theme = 'system';
    #[ORM\Column(length: 12)] private string $density = 'comfortable';
    #[ORM\Column(name: 'font_scale', length: 10)] private string $fontScale = 'normal';
    #[ORM\Column(length: 20)] private string $accent = 'cyan';
    #[ORM\Column(name: 'read_mode', length: 12)] private string $readMode = 'on_preview';
    #[ORM\Column(name: 'sidebar_collapsed')] private bool $sidebarCollapsed = false;
    #[ORM\Column(length: 64)] private string $timezone = 'Europe/Paris';
    #[ORM\Column(length: 2, options: ['default' => 'fr'])] private string $locale = 'fr';
    public function __construct(User $user) { $this->id = Uuid::v7(); $this->user = $user; }
    public function getTheme(): string { return $this->theme; } public function getDensity(): string { return $this->density; } public function getFontScale(): string { return $this->fontScale; } public function getAccent(): string { return $this->accent; } public function getReadMode(): string { return $this->readMode; } public function isSidebarCollapsed(): bool { return $this->sidebarCollapsed; } public function getTimezone(): string { return $this->timezone; }
    public function getLocale(): string { return $this->locale; }
    /** @param array<string,mixed> $values */ public function update(array $values): void { foreach (['locale'=>['fr','en','de'],'theme'=>['system','light','dark'],'density'=>['compact','comfortable'],'fontScale'=>['small','normal','large'],'accent'=>['cyan','orange','emerald','blue','violet','rose','red','amber','lime','slate'],'readMode'=>['manual','on_preview']] as $key => $allowed) { if (isset($values[$key]) && is_string($values[$key]) && in_array($values[$key], $allowed, true)) { $property = $key; $this->$property = $values[$key]; } } if (isset($values['sidebarCollapsed']) && is_bool($values['sidebarCollapsed'])) $this->sidebarCollapsed = $values['sidebarCollapsed']; if (isset($values['timezone']) && is_string($values['timezone']) && in_array($values['timezone'], timezone_identifiers_list(), true)) $this->timezone = $values['timezone']; }
}
