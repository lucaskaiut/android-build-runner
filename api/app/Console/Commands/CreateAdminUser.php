<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    protected $signature = 'app:create-admin
                            {--name= : Nome do administrador}
                            {--email= : E-mail de login}
                            {--password= : Senha (prefira o prompt interativo)}';

    protected $description = 'Cria o primeiro usuário administrador do painel';

    public function handle(): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Nome do administrador'));
        $email = mb_strtolower((string) ($this->option('email') ?: $this->ask('E-mail de login')));

        $validator = Validator::make(
            ['name' => $name, 'email' => $email],
            ['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("Já existe um usuário com o e-mail {$email}.");

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: $this->secret('Senha (mínimo 12 caracteres, não será exibida)'));

        if (strlen($password) < 12) {
            $this->error('A senha deve ter pelo menos 12 caracteres.');

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);
        $user->forceFill(['is_admin' => true])->save();

        $this->info("Administrador criado: {$user->email}");

        return self::SUCCESS;
    }
}
