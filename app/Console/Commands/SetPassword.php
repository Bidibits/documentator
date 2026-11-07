<?php

namespace App\Console\Commands;

use App\Auth\PasswordPolicy;
use App\Auth\SessionUser;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SetPassword extends Command
{
    protected $signature = 'documentator:set-password {username : The sign-in name} {--create : Make the account if it does not exist} {--name= : Full name for a new account}';

    protected $description = 'Set (or with --create, make) an account and its password. There is no sign-up page: this is how the first account starts.';

    public function handle(): int
    {
        $username = Str::lower(trim((string) $this->argument('username')));
        if (! preg_match(User::USERNAME_PATTERN, $username)) {
            $this->error('A username is 3-32 letters, digits, dots, dashes or underscores.');

            return self::FAILURE;
        }

        $user = User::findByUsername($username);
        if ($user === null && ! $this->option('create')) {
            $this->error("No account named {$username}. Add --create to make it.");

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password');
        if ($password !== (string) $this->secret('Password again')) {
            $this->error('The two passwords differ.');

            return self::FAILURE;
        }
        if (($problem = PasswordPolicy::problem($password, $username)) !== null) {
            $this->error("That password is not allowed ({$problem}).");

            return self::FAILURE;
        }

        $created = $user === null;
        $user ??= new User(['username' => $username, 'name' => (string) ($this->option('name') ?: $username)]);
        $user->fill(['password' => $password, 'password_changed_at' => now()])->save();

        if (! $created) {
            SessionUser::endAllFor($user);
        }
        AuditLog::record($user, $created ? 'user.created' : 'user.password-set', 'from the command line', entityType: 'user', entityId: (string) $user->id);

        $this->info($created ? "Account {$username} made." : "Password for {$username} changed; they are signed out everywhere.");

        return self::SUCCESS;
    }
}
