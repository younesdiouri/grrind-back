<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\ApplicationStatus;
use App\Community\Domain\Exception\ApplicationAlreadyDecided;
use App\Community\Domain\Exception\ApplicationNotFound;
use App\Community\Domain\Exception\GuildIsFull;
use App\Community\Domain\Exception\GuildNotFound;
use App\Community\Domain\Exception\PlayerAlreadyInAGuild;
use App\Community\Domain\Exception\PlayerNotFound;
use App\Community\Domain\GuildApplication;
use App\Community\Domain\GuildRules;
use App\Community\Infrastructure\Doctrine\GuildApplicationRepository;
use App\Community\Infrastructure\Doctrine\GuildMembershipRepository;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use App\Community\Infrastructure\Security\GuildMutationAuthorization;
use App\Community\Infrastructure\Security\GuildVoter;
use App\Shared\Application\PlayerProfiles;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GuildRecruitment
{
    public function __construct(
        private GuildRepository $guilds,
        private GuildApplicationRepository $applications,
        private GuildMembershipRepository $memberships,
        private GuildMutationAuthorization $authorization,
        private GuildRules $rules,
        private PlayerProfiles $profiles,
        private ClockInterface $clock,
    ) {
    }

    public function submit(Uuid $guildId, Uuid $player): GuildApplication
    {
        return $this->guilds->transactional(function () use ($guildId, $player): GuildApplication {
            $guild = $this->guilds->lockForUpdate($guildId);
            if (null === $guild || !$guild->recruitmentOpen) {
                throw new GuildNotFound();
            }

            $this->applications->lockPlayer($player);
            if (null !== $this->memberships->ofPlayer($player)) {
                throw new PlayerAlreadyInAGuild();
            }
            $pending = $this->applications->findOneBy(['guildId' => $guildId, 'playerId' => $player, 'status' => ApplicationStatus::Pending]);
            if (null !== $pending) {
                return $pending;
            }
            if ($guild->size() >= $this->rules->maximumMembers()) {
                throw new GuildIsFull($this->rules->maximumMembers());
            }

            $profile = $this->profiles->of([$player])[$player->toRfc4122()] ?? throw new PlayerNotFound();
            $application = new GuildApplication($guildId, $guild->name(), $player, $profile->displayName);
            $this->applications->add($application);

            return $application;
        });
    }

    public function decide(Uuid $guildId, Uuid $actor, Uuid $applicationId, bool $accept): GuildApplication
    {
        return $this->guilds->transactional(function () use ($guildId, $actor, $applicationId, $accept): GuildApplication {
            $guild = $this->authorization->lock($guildId, $actor, GuildVoter::EDIT);
            $application = $this->applications->findOneBy(['id' => $applicationId, 'guildId' => $guildId]) ?? throw new ApplicationNotFound();
            $this->applications->lockPlayer($application->playerId);
            $this->applications->refresh($application);
            $decision = $accept ? ApplicationStatus::Accepted : ApplicationStatus::Rejected;
            if ($application->status === $decision) {
                return $application;
            }
            if (ApplicationStatus::Pending !== $application->status || !$guild->recruitmentOpen) {
                throw new ApplicationAlreadyDecided();
            }
            if ($accept) {
                if (null !== $this->memberships->ofPlayer($application->playerId)) {
                    throw new PlayerAlreadyInAGuild();
                }
                $guild->admit($application->playerId, $this->rules, $this->clock->now());
            }

            $application->status = $decision;
            if ($accept) {
                $this->guilds->commit();
                $this->applications->withdrawForPlayer($application->playerId);
            }

            return $application;
        });
    }
}
