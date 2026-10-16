<?php

namespace App\Domain\Identity\Actions;

use App\Models\User;
use App\Notifications\Auth\AccountAlreadyExistsNotification;
use App\Support\Locale;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Registers an account without revealing whether the email is already in use
 * (API_SPEC.md §2 "generic responses for email existence"; stricter-privacy rule of §0.2).
 *
 * - New email: create the user (and default preferences) and queue the verification email.
 * - Existing email: create nothing and queue an "account already exists" notice to that owner.
 *
 * Both paths hash the password, so response timing does not separate them.
 */
final class RegisterUser
{
    /**
     * @param  array{name: string, email: string, password: string}  $input
     */
    public function handle(array $input, Locale $locale): void
    {
        $email = User::normalizeEmail($input['email']);
        $passwordHash = Hash::make($input['password']);

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            $this->notifyExistingOwner($existing);

            return;
        }

        try {
            $user = DB::transaction(function () use ($input, $email, $passwordHash, $locale): User {
                $user = new User;
                $user->forceFill([
                    'name' => $input['name'],
                    'email' => $email,
                    'password' => $passwordHash,
                    'locale' => $locale->value,
                    'timezone' => 'UTC',
                ])->save();

                $user->preferences()->create();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent registration won the race for this email; behave like the "existing" path.
            $winner = User::query()->where('email', $email)->first();

            if ($winner !== null) {
                $this->notifyExistingOwner($winner);
            }

            return;
        }

        event(new Registered($user));
    }

    private function notifyExistingOwner(User $user): void
    {
        if (! $user->isDisabled()) {
            $user->notify(new AccountAlreadyExistsNotification);
        }
    }
}
