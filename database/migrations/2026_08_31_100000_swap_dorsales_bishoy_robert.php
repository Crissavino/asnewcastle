<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-off pedido por el dueño: intercambiar los dorsales de Bishoy (18) y
 * Robert (21). Bishoy queda con el 21, Robert con el 18.
 *
 * El dorsal es único por club, así que el swap pasa por un valor temporal
 * (NULL) para no violar la restricción. Es defensiva: si no encuentra a los
 * jugadores esperados (por nombre), no hace nada y no rompe el deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bishoy = DB::table('members')
            ->join('users', 'members.user_id', '=', 'users.id')
            ->whereNull('members.left_at')
            ->where('members.shirt_number', 18)
            ->select('members.id', 'users.name')
            ->first();

        $robert = DB::table('members')
            ->join('users', 'members.user_id', '=', 'users.id')
            ->whereNull('members.left_at')
            ->where('members.shirt_number', 21)
            ->select('members.id', 'users.name')
            ->first();

        // Seguridad: solo si son quienes esperamos.
        if (! $bishoy || ! $robert) {
            return;
        }
        if (! str_contains(strtolower($bishoy->name), 'bishoy')) {
            return;
        }
        if (! str_contains(strtolower($robert->name), 'robert')) {
            return;
        }

        DB::transaction(function () use ($bishoy, $robert) {
            DB::table('members')->where('id', $bishoy->id)->update(['shirt_number' => null]); // libera el 18
            DB::table('members')->where('id', $robert->id)->update(['shirt_number' => 18]);    // Robert -> 18
            DB::table('members')->where('id', $bishoy->id)->update(['shirt_number' => 21]);    // Bishoy -> 21
        });
    }

    public function down(): void
    {
        $bishoy = DB::table('members')
            ->join('users', 'members.user_id', '=', 'users.id')
            ->whereNull('members.left_at')
            ->where('members.shirt_number', 21)
            ->where('users.name', 'like', '%Bishoy%')
            ->select('members.id')
            ->first();

        $robert = DB::table('members')
            ->join('users', 'members.user_id', '=', 'users.id')
            ->whereNull('members.left_at')
            ->where('members.shirt_number', 18)
            ->where('users.name', 'like', '%Robert%')
            ->select('members.id')
            ->first();

        if (! $bishoy || ! $robert) {
            return;
        }

        DB::transaction(function () use ($bishoy, $robert) {
            DB::table('members')->where('id', $bishoy->id)->update(['shirt_number' => null]);
            DB::table('members')->where('id', $robert->id)->update(['shirt_number' => 21]);
            DB::table('members')->where('id', $bishoy->id)->update(['shirt_number' => 18]);
        });
    }
};
