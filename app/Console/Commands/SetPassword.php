<?php

namespace App\Console\Commands;

use App\Auth\Access;
use App\Auth\PasswordPolicy;
use App\Auth\SessionUser;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\ChangeLog;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SetPassword extends Command
{
    protected $signature = 'documentator:set-password {username : The sign-in name} {--create : Make the account if it does not exist} {--name= : Full name for a new account} {--role=admin : The role of a new account (viewer, editor or admin)}';

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

        if (! Access::exists((string) $this->option('role'))) {
            $this->error('The role must be one of: '.implode(', ', array_keys(Access::roles())).'.');

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
        $user ??= (new User(['username' => $username, 'name' => (string) ($this->option('name') ?: $username)]))->forceFill(['role' => (string) $this->option('role')]);
        $columns = ['username', 'name', 'role', 'password', 'active', 'auth_source', 'external_id', 'must_change_password', 'password_changed_at'];
        // the account and its change rows are one save: both or neither
        DB::transaction(function () use ($user, $password, $created, $columns) {
            $before = $created ? [] : Arr::only($user->getAttributes(), $columns);
            $user->fill(['password' => $password, 'password_changed_at' => now()->utc()])->save();
            $after = Arr::only((array) DB::table('users')->where('id', $user->id)->first(), $columns);
            $created ? ChangeLog::created('users', $user->id, $after, null) : ChangeLog::updated('users', $user->id, $before, $after, null);
        });

        if (! $created) {
            SessionUser::endAllFor($user);
        }
        AuditLog::record($user, $created ? 'user.created' : 'user.password-set', 'from the command line', entityType: 'user', entityId: (string) $user->id);

        $this->info($created ? "Account {$username} made." : "Password for {$username} changed; they are signed out everywhere.");

        return self::SUCCESS;
    }
}
