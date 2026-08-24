<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Registration;
use App\Rules\ValidCnp;
use App\Services\Notifications;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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
    public function show(): Response
    {
        $current = app(CurrentClub::class);
        $own = $this->registrationFor($current);

        return Inertia::render('Legitimacion', [
            'registration' => $this->serializeOwn($own),
            'missing' => $own->missingFields(),
            'config' => [
                'deadline' => config('legitimacion.deadline'),
                'daysLeft' => (int) now()->startOfDay()->diffInDays(config('legitimacion.deadline'), false),
                'fee' => config('legitimacion.fee'),
                'iban' => config('legitimacion.iban'),
                'season' => config('legitimacion.season'),
            ],
            'roster' => $current->actsAsManager() ? $this->roster($current) : null,
        ]);
    }

    /** Guardado parcial: solo pisa lo que vino en el request. */
    public function store(Request $request): RedirectResponse
    {
        $current = app(CurrentClub::class);
        $reg = $this->registrationFor($current);

        $validated = $request->validate([
            'full_name' => ['nullable', 'string', 'max:120'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'size:2'],
            'cnp' => ['nullable', new ValidCnp],
            'passport_number' => ['nullable', 'string', 'max:30'],
            'previous_clubs' => ['nullable', 'string', 'max:500'],
            'played_federated' => ['nullable', 'boolean'],
            'federated_details' => ['nullable', 'string', 'max:500'],
            'payment_marked' => ['nullable', 'boolean'],
            'consent' => ['nullable', 'boolean'],
            'photo' => ['nullable', 'image', 'max:5120'],
            'id_doc' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'passport' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'payment_proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        foreach (['full_name', 'birth_date', 'nationality', 'cnp', 'passport_number', 'previous_clubs', 'federated_details', 'played_federated', 'payment_marked'] as $field) {
            if ($request->has($field)) {
                $reg->$field = $validated[$field];
            }
        }

        if ($request->has('consent')) {
            $reg->consent_at = $request->boolean('consent') ? ($reg->consent_at ?? now()) : null;
        }

        foreach (Registration::fileFields() as $input => $column) {
            if ($file = $request->file($input)) {
                if ($reg->$column) {
                    Storage::delete($reg->$column);
                }
                $reg->$column = $file->storeAs(
                    "legitimacion/{$reg->club_id}/{$reg->member_id}",
                    $input.'-'.now()->timestamp.'.'.strtolower($file->extension())
                );
            }
        }

        // Coherencia: un rumano no carga pasaporte y un extranjero no carga CNP
        if ($reg->nationality === 'RO') {
            $reg->passport_number = null;
        } elseif ($reg->nationality) {
            $reg->cnp = null;
        }

        $reg->save();
        $reg->refreshStatus();

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
        $name = str($registration->member->user->name)->slug();
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

    /** Recordatorio por WhatsApp + campanita a los que no completaron. */
    public function remind(WhatsAppChannel $whatsapp): RedirectResponse
    {
        Gate::authorize('create', Event::class);
        $current = app(CurrentClub::class);

        $registrations = Registration::query()
            ->where('season', config('legitimacion.season'))
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

    /** La ficha propia para el form. Nunca manda paths: solo si hay archivo. */
    protected function serializeOwn(Registration $reg): array
    {
        return [
            'full_name' => $reg->full_name ?? $reg->member->user->name,
            'birth_date' => $reg->birth_date?->format('Y-m-d'),
            'nationality' => $reg->nationality,
            'cnp' => $reg->cnp,
            'passport_number' => $reg->passport_number,
            'previous_clubs' => $reg->previous_clubs,
            'played_federated' => $reg->played_federated,
            'federated_details' => $reg->federated_details,
            'payment_marked' => (bool) $reg->payment_marked,
            'consented' => $reg->consent_at !== null,
            'status' => $reg->status,
            'files' => collect(Registration::fileFields())->map(fn ($column) => (bool) $reg->$column),
        ];
    }

    /** Vista delegado: una fila por miembro activo, con qué le falta. */
    protected function roster(CurrentClub $current): array
    {
        $registrations = Registration::query()
            ->where('season', config('legitimacion.season'))
            ->get()
            ->keyBy('member_id');

        return $current->club()->activeMembers()->with('user:id,name')->orderBy('shirt_number')->get()
            ->map(function ($member) use ($registrations) {
                $reg = $registrations->get($member->id);

                return [
                    'member_id' => $member->id,
                    'registration_id' => $reg?->id,
                    'name' => $member->user->name,
                    'shirt_number' => $member->shirt_number,
                    'status' => $reg?->status ?? Registration::STATUS_PENDIENTE,
                    'missing' => $reg?->missingFields(), // null = ni empezó
                    'payment_marked' => (bool) ($reg?->payment_marked),
                    'files' => $reg
                        ? collect(Registration::fileFields())->map(fn ($column) => (bool) $reg->$column)
                        : null,
                ];
            })->values()->all();
    }
}
