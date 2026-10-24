<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Locale;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Shared\Application\ArtworkCatalog;
use App\Shared\Domain\NotificationCategory;
use App\Shared\Domain\Timezone;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final readonly class UpdateProfileHandler
{
    public function __construct(private UserRepository $users, private ArtworkCatalog $artwork)
    {
    }

    public function __invoke(User $user, UpdateProfile $command): User
    {
        if (null !== $command->appearance && !$this->artwork->selectable($command->appearance)) {
            throw new UnprocessableEntityHttpException('Cette apparence n’est pas disponible.');
        }

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

        if (null !== $command->appearance) {
            $user->changeAppearance($command->appearance);
        }

        if (null !== $command->avatar) {
            $user->changeAvatar($command->avatar);
        }

        foreach ($command->notificationPreferences as $categoryValue => $enabled) {
            $user->setNotificationPreference(NotificationCategory::from($categoryValue), $enabled);
        }

        $this->users->commit();

        return $user;
    }
}
