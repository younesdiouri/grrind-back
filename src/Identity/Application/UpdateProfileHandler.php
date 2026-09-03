<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Shared\Domain\Locale;
use App\Shared\Domain\NotificationCategory;
use App\Shared\Domain\Timezone;

final readonly class UpdateProfileHandler
{
    public function __construct(private UserRepository $users)
    {
    }

    public function __invoke(User $user, UpdateProfile $command): User
    {
        if (null !== $command->displayName) {
            $user->rename($command->displayName);
        }

        if (null !== $command->timezone) {
            // Changer de fuseau ne réécrit aucune session passée : les horodatages
            // restent en UTC, seule leur lecture change. Un déménagement ne doit
            // ni casser ni rallonger un streak.
            $user->moveTo(Timezone::fromString($command->timezone));
        }

        if (null !== $command->locale) {
            $user->prefer(Locale::from($command->locale));
        }

        foreach ($command->notificationPreferences as $categoryValue => $enabled) {
            $user->setNotificationPreference(NotificationCategory::from($categoryValue), $enabled);
        }

        $this->users->commit();

        return $user;
    }
}
