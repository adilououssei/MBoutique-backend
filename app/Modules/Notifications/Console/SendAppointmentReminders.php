<?php

namespace App\Modules\Notifications\Console;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Notifications\Notifications\StoreAlert;
use App\Modules\Notifications\Support\LocalTime;
use App\Modules\Notifications\Support\StoreRecipients;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Rappel une heure avant chaque rendez-vous prévu ou confirmé : à l'employé
 * concerné s'il a un compte, sinon à l'accueil (`rendez_vous.modifier`).
 * Planifiée toutes les 5 minutes (routes/console.php) ; idempotente : un
 * rendez-vous déjà rappelé ne l'est pas deux fois.
 */
class SendAppointmentReminders extends Command
{
    protected $signature = 'notifications:rappels-rendez-vous {--minutes=60 : Fenêtre avant le rendez-vous}';

    protected $description = 'Envoie les rappels des rendez-vous qui commencent bientôt';

    public function handle(StoreRecipients $recipients): int
    {
        $from = now();
        $to = now()->addMinutes((int) $this->option('minutes'));
        $sent = 0;

        // Hors requête HTTP : aucune boutique active, la portée « boutique » ne filtre pas.
        $appointments = Appointment::query()
            ->whereIn('statut', [AppointmentStatus::Planned->value, AppointmentStatus::Confirmed->value])
            ->whereBetween('debut_le', [$from, $to])
            ->with(['employee.user', 'service', 'customer'])
            ->get();

        foreach ($appointments as $appointment) {
            $alreadyReminded = DatabaseNotification::query()
                ->where('data->categorie', StoreAlert::APPOINTMENT_REMINDER)
                ->where('data->rendez_vous_id', $appointment->id)
                ->exists();
            if ($alreadyReminded) {
                continue;
            }

            $recipientsList = $appointment->employee?->user
                ? collect([$appointment->employee->user])
                : $recipients->withPermission($appointment->boutique_id, 'rendez_vous.modifier');

            $client = $appointment->customer?->nom ?? $appointment->nom_client ?? 'un client';
            Notification::send($recipientsList, new StoreAlert(
                $appointment->boutique_id,
                StoreAlert::APPOINTMENT_REMINDER,
                'Rendez-vous à '.LocalTime::format($appointment->debut_le, $appointment->boutique_id),
                "{$appointment->service?->nom} avec {$client}".($appointment->employee ? " ({$appointment->employee->nom})" : '').'.',
                ['ecran' => 'rendez_vous', 'id' => $appointment->id],
                ['rendez_vous_id' => $appointment->id],
            ));
            $sent++;
        }

        $this->info("{$sent} rappel(s) envoyé(s).");

        return self::SUCCESS;
    }
}
