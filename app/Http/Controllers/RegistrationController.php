<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Registration;
use App\Services\Notifications;
use App\Services\RegistrationSaver;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Legitimación en la Federación. Cada jugador carga su propia ficha;
 * el manager ve el estado del plantel, baja la documentación y reclama.
 * Los archivos viven en el disco privado del servidor y solo salen por
 * los endpoints de descarga del manager — nunca hay URL pública.
 */
class RegistrationController extends Controller
{
    public function __construct(protected RegistrationSaver $saver)
    {
    }

    public function show(): Response
    {
        $current = app(CurrentClub::class);
        $own = $this->registrationFor($current);

        return Inertia::render('Legitimacion', [
            'registration' => $this->saver->serialize($own),
            'missing' => $own->missingFields(),
            'config' => $this->saver->config(),
            'roster' => $current->actsAsManager() ? $this->roster($current) : null,
            // Link público firmado para los que todavía no tienen cuenta
            // (sin WhatsApp activo no pueden pasar el OTP). Vence en 30 días.
            'public_url' => $current->actsAsManager()
                ? URL::temporarySignedRoute('legitimacion.publica', now()->addDays(30), ['club' => $current->club()->slug])
                : null,
        ]);
    }

    /** Guardado parcial: solo pisa lo que vino en el request. */
    public function store(Request $request): RedirectResponse
    {
        $this->saver->save($this->registrationFor(app(CurrentClub::class)), $request);

        return back();
    }

    /** Descarga de un documento. Solo el manager real, solo su club. */
    public function doc(Registration $registration, string $field): StreamedResponse
    {
        app(CurrentClub::class)->assertOwns($registration);
        Gate::authorize('create', Event::class);

        $column = Registration::fileFields()[$field] ?? abort(404);
        abort_unless($registration->$column && Storage::exists($registration->$column), 404);

        return Storage::response($registration->$column);
    }

    /** ZIP con los datos y documentos de un jugador, para la Federación. */
    public function zip(Registration $registration): BinaryFileResponse
    {
        app(CurrentClub::class)->assertOwns($registration);
        Gate::authorize('create', Event::class);

        $registration->loadMissing('member.user');
        $name = str($registration->full_name ?? $registration->member?->user?->name ?? "ficha-{$registration->id}")->slug();
        $path = storage_path("app/legitimacion-{$registration->id}.zip");

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString("{$name}-datos.txt", implode("\n", [
            'Nume / Nombre: '.$registration->full_name,
            'Data nașterii: '.$registration->birth_date?->format('d.m.Y'),
            'Naționalitate: '.$registration->nationality,
            'CNP: '.($registration->cnp ?: '—'),
            'Pașaport: '.($registration->passport_number ?: '—'),
            'Cluburi anterioare în România: '.($registration->previous_clubs ?: '—'),
            'Legitimat anterior: '.($registration->played_federated ? 'DA — '.$registration->federated_details : 'NU'),
            'Plată 195 RON: '.($registration->payment_marked ? 'DA' : 'NU'),
        ]));

        foreach (Registration::fileFields() as $field => $column) {
            if ($registration->$column && Storage::exists($registration->$column)) {
                $ext = pathinfo($registration->$column, PATHINFO_EXTENSION);
                $zip->addFromString("{$name}-{$field}.{$ext}", Storage::get($registration->$column));
            }
        }

        $zip->close();

        return response()->download($path, "legitimacion-{$name}.zip")->deleteFileAfterSend();
    }

    /**
     * El manager marca una ficha completa como presentada en la Federación
     * (o lo deshace si se equivocó). No toca submitted_at ni purge_after:
     * la purga corre desde la entrega del jugador igual.
     */
    public function markSent(Registration $registration): RedirectResponse
    {
        app(CurrentClub::class)->assertOwns($registration);
        Gate::authorize('create', Event::class);

        if ($registration->status === Registration::STATUS_COMPLETO) {
            $registration->update(['status' => Registration::STATUS_ENVIADO]);
        } elseif ($registration->status === Registration::STATUS_ENVIADO) {
            $registration->update(['status' => Registration::STATUS_COMPLETO]);
        } else {
            abort(400); // una ficha incompleta no se puede presentar
        }

        return back();
    }

    /** Recordatorio por WhatsApp + campanita a los que no completaron. */
    public function remind(WhatsAppChannel $whatsapp): RedirectResponse
    {
        Gate::authorize('create', Event::class);
        $current = app(CurrentClub::class);

        $registrations = Registration::query()
            ->where('season', config('legitimacion.season'))
            ->whereNotNull('member_id')
            ->get()
            ->keyBy('member_id');

        $pending = $current->club()->activeMembers()->with('user')->get()
            ->filter(function ($member) use ($registrations) {
                $status = $registrations->get($member->id)?->status ?? Registration::STATUS_PENDIENTE;

                return $status === Registration::STATUS_PENDIENTE;
            });

        foreach ($pending as $member) {
            $locale = $member->user->locale ?? config('app.fallback_locale');
            $whatsapp->sendText($member->user->phone, __('legitimacion.whatsapp_reminder', [
                'name' => strtok($member->user->name ?? '', ' ') ?: (string) $member->user->name,
            ], $locale));
        }

        app(Notifications::class)->deliver(
            $current->id(),
            $pending->pluck('id'),
            'legitimacion',
            'notifications.legitimacion_reminder',
            [],
            '/legitimacion',
        );

        return back()->with('status', $pending->count());
    }

    /** La ficha propia de la temporada. La crea vacía la primera vez. */
    protected function registrationFor(CurrentClub $current): Registration
    {
        return Registration::firstOrCreate([
            'club_id' => $current->id(),
            'member_id' => $current->member()->id,
            'season' => config('legitimacion.season'),
        ]);
    }

    /**
     * Vista delegado: una fila por miembro activo, con qué le falta, más
     * las fichas del formulario público (gente que aún no tiene cuenta).
     */
    protected function roster(CurrentClub $current): array
    {
        $registrations = Registration::query()
            ->where('season', config('legitimacion.season'))
            ->get();

        $byMember = $registrations->whereNotNull('member_id')->keyBy('member_id');

        $rows = $current->club()->activeMembers()->with('user:id,name')->orderBy('shirt_number')->get()
            ->map(function ($member) use ($byMember) {
                $reg = $byMember->get($member->id);

                return [
                    'member_id' => $member->id,
                    'registration_id' => $reg?->id,
                    'name' => $member->user->name,
                    'shirt_number' => $member->shirt_number,
                    'guest' => false,
                    'status' => $reg?->status ?? Registration::STATUS_PENDIENTE,
                    'missing' => $reg?->missingFields(), // null = ni empezó
                    'payment_marked' => (bool) ($reg?->payment_marked),
                ];
            });

        $guests = $registrations->whereNull('member_id')->sortBy('created_at')
            ->map(fn (Registration $reg) => [
                'member_id' => null,
                'registration_id' => $reg->id,
                'name' => $reg->full_name,
                'shirt_number' => null,
                'guest' => true,
                'status' => $reg->status,
                'missing' => $reg->missingFields(),
                'payment_marked' => (bool) $reg->payment_marked,
            ]);

        return $rows->concat($guests)->values()->all();
    }
}
