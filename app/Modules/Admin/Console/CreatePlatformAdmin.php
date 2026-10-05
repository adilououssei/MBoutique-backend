<?php

namespace App\Modules\Admin\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Crée un administrateur de la plateforme, ou promeut un compte existant.
 * Seul moyen d'obtenir ce statut : il n'est jamais accordé par l'API.
 */
class CreatePlatformAdmin extends Command
{
    protected $signature = 'admin:creer
        {email : Adresse e-mail du compte}
        {--nom= : Nom affiché (création uniquement)}
        {--mot-de-passe= : Mot de passe (création uniquement, 8 caractères minimum)}';

    protected $description = "Crée un administrateur de la plateforme (interface d'administration), ou promeut un compte existant";

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            $user->forceFill(['est_admin_plateforme' => true])->save();
            $this->info("{$user->nom} ({$email}) est maintenant administrateur de la plateforme.");

            return self::SUCCESS;
        }

        $name = $this->option('nom') ?: $this->ask('Nom affiché');
        $password = $this->option('mot-de-passe') ?: $this->secret('Mot de passe (8 caractères minimum)');

        $validator = Validator::make(
            ['email' => $email, 'nom' => $name, 'mot_de_passe' => $password],
            ['email' => ['required', 'email'], 'nom' => ['required', 'string', 'max:255'], 'mot_de_passe' => ['required', 'string', 'min:8']],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create(['nom' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['est_admin_plateforme' => true, 'email_verified_at' => now()])->save();
        $this->info("Administrateur créé : {$name} ({$email}). Connexion sur la page d'accueil du site.");

        return self::SUCCESS;
    }
}
