<?php

namespace App\Services;

use App\Models\Registration;
use App\Rules\ValidCnp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Guardado (parcial) de una ficha de legitimación. Lo comparten el
 * formulario logueado y el público: misma validación, mismos archivos.
 */
class RegistrationSaver
{
    public function save(Registration $reg, Request $request): void
    {
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

        // Guardado parcial: solo pisa lo que vino en el request
        foreach (['full_name', 'birth_date', 'nationality', 'cnp', 'passport_number', 'previous_clubs', 'federated_details', 'played_federated', 'payment_marked'] as $field) {
            if ($request->has($field)) {
                $reg->$field = $validated[$field];
            }
        }

        if ($request->has('consent')) {
            $reg->consent_at = $request->boolean('consent') ? ($reg->consent_at ?? now()) : null;
        }

        // Los archivos se guardan por id de ficha: la fila tiene que existir
        if (! $reg->exists) {
            $reg->save();
        }

        foreach (Registration::fileFields() as $input => $column) {
            if ($file = $request->file($input)) {
                if ($reg->$column) {
                    Storage::delete($reg->$column);
                }
                $reg->$column = $file->storeAs(
                    "legitimacion/{$reg->club_id}/{$reg->id}",
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
    }

    /** La ficha para el form. Nunca manda paths: solo si hay archivo. */
    public function serialize(Registration $reg): array
    {
        return [
            'full_name' => $reg->full_name ?? $reg->member?->user?->name,
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

    public function config(): array
    {
        return [
            'deadline' => config('legitimacion.deadline'),
            'daysLeft' => (int) now()->startOfDay()->diffInDays(config('legitimacion.deadline'), false),
            'fee' => config('legitimacion.fee'),
            'iban' => config('legitimacion.iban'),
            'season' => config('legitimacion.season'),
        ];
    }
}
