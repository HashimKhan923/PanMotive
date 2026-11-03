<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\AdminPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** The first admin can't be created from the admin portal, so it starts here. */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create {email} {--name=Platform Admin} {--role=super_admin}';

    protected $description = 'Create an admin account (or promote an existing user) and print a one-time password';

    public function handle(): int
    {
        (new RoleSeeder)->run();
        (new AdminPermissionSeeder)->run();

        $password = null;
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $password = Str::password(20, symbols: false);
            $user = User::create(['name' => $this->option('name'), 'email' => $this->argument('email'), 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->assignRole($this->option('role'));

        $this->info("{$user->email} is now {$this->option('role')}.");
        if ($password) {
            $this->line("Password: {$password}");
            $this->warn('It is shown only once. Change it after your first login.');
        }

        return self::SUCCESS;
    }
}
