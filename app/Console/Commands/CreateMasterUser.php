<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateMasterUser extends Command
{
    protected $signature = 'users:create-master';

    protected $description = 'Create the initial master user without public registration';

    public function handle(): int
    {
        if (User::query()->where('role', User::ROLE_MASTER)->exists()) {
            $this->error('Já existe um usuário master.');

            return self::FAILURE;
        }

        $name = $this->ask('Nome');
        $email = $this->ask('E-mail');
        $password = $this->secret('Senha (mínimo 12 caracteres)');

        if (! is_string($name) || trim($name) === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Nome ou e-mail inválido.');

            return self::INVALID;
        }

        if (! is_string($password) || strlen($password) < 12) {
            $this->error('A senha deve ter ao menos 12 caracteres.');

            return self::INVALID;
        }

        User::query()->create([
            'name' => trim($name),
            'email' => mb_strtolower($email),
            'password' => $password,
            'role' => User::ROLE_MASTER,
            'ativo' => true,
        ]);

        $this->info('Usuário master criado.');

        return self::SUCCESS;
    }
}
