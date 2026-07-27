<?php
namespace App\Services\Checador;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceEventSynchronizationService
{
    private string $connection = 'portal_main';

    public function sincronizar(
        array $payload,
        array $context
    ): void {
        $checadas = collect($context['checadas'] ?? [])
            ->sortBy('check_time')
            ->values();

        $entradaLaboral = $checadas->first(function ($checada) {
            return data_get($checada, 'tipo') === 'in'
            && data_get($checada, 'clase') === 'work';
        });

        $salidaLaboral = $checadas
            ->filter(function ($checada) {
                return data_get($checada, 'tipo') === 'out'
                && data_get($checada, 'clase') === 'work';
            })
            ->last();

        /*
         * Una jornada con entrada y salida ya no corresponde
         * a una falta.
         */
        if ($entradaLaboral && $salidaLaboral) {
            $this->eliminarFalta($payload);
        }

        /*
         * El retardo depende de la primera entrada laboral.
         */
        $this->sincronizarRetardo(
            $payload,
            $context,
            $entradaLaboral
        );
    }

    private function eliminarFalta(array $payload): void
    {
        DB::connection($this->connection)
            ->table('calendario_eventos')
            ->where('id_portal', (int) $payload['id_portal'])
            ->where('id_cliente', (int) $payload['id_cliente'])
            ->where('id_empleado', (int) $payload['id_empleado'])
            ->where('id_tipo', 4)
            ->where('eliminado', 0)
            ->whereDate('inicio', '<=', $payload['fecha'])
            ->whereDate('fin', '>=', $payload['fecha'])
            ->update([
                'eliminado'  => 1,
                'updated_at' => now(),
            ]);
    }

    private function sincronizarRetardo(
        array $payload,
        array $context,
        $entradaLaboral
    ): void {
        $plantillaHorario = $context['plantillaHorario'] ?? null;
        $detalleHorario   = $context['detalleHorario'] ?? null;

        /*
         * Sin entrada o sin horario no podemos calcular retardo.
         */
        if (
            ! $entradaLaboral ||
            ! $plantillaHorario ||
            ! $detalleHorario ||
            (int) ($detalleHorario->labora ?? 0) !== 1 ||
            empty($detalleHorario->hora_entrada)
        ) {
            return;
        }

        $timezone = $plantillaHorario->timezone ?? data_get($entradaLaboral, 'timezone') ?? 'America/Mexico_City';

        $checkTimeValue = data_get(
            $entradaLaboral,
            'check_time'
        );

/*
 * Los datetime de checadas representan la hora operativa guardada.
 * Si Eloquent ya lo convirtió a Carbon, conservamos la hora visible
 * y la reinterpretamos usando la zona horaria de la plantilla.
 */
        $checkTimeString = $checkTimeValue instanceof \DateTimeInterface
            ? $checkTimeValue->format('Y-m-d H:i:s')
            : (string) $checkTimeValue;

        $entrada = Carbon::createFromFormat(
            'Y-m-d H:i:s',
            $checkTimeString,
            $timezone
        );

        $entradaProgramada = Carbon::parse(
            $payload['fecha'] . ' ' . $detalleHorario->hora_entrada,
            $timezone
        );

        $tolerancia = (int) (
            $plantillaHorario->tolerancia_entrada_min ?? 0
        );

        $limiteEntrada = $entradaProgramada
            ->copy()
            ->addMinutes($tolerancia);

        $minutosRetardo = $entrada->greaterThan($limiteEntrada)
            ? $limiteEntrada->diffInMinutes($entrada)
            : 0;

        if ($minutosRetardo <= 0) {
            $this->eliminarRetardosActivos($payload);

            return;
        }

        $this->crearOActualizarRetardo(
            $payload,
            $minutosRetardo
        );
    }

    private function eliminarRetardosActivos(array $payload): void
    {
        DB::connection($this->connection)
            ->table('calendario_eventos')
            ->where('id_portal', (int) $payload['id_portal'])
            ->where('id_cliente', (int) $payload['id_cliente'])
            ->where('id_empleado', (int) $payload['id_empleado'])
            ->where('id_tipo', 5)
            ->where('eliminado', 0)
            ->whereDate('inicio', '<=', $payload['fecha'])
            ->whereDate('fin', '>=', $payload['fecha'])
            ->update([
                'eliminado'  => 1,
                'updated_at' => now(),
            ]);
    }

    private function crearOActualizarRetardo(
        array $payload,
        int $minutosRetardo
    ): void {
        $query = DB::connection($this->connection)
            ->table('calendario_eventos')
            ->where('id_portal', (int) $payload['id_portal'])
            ->where('id_cliente', (int) $payload['id_cliente'])
            ->where('id_empleado', (int) $payload['id_empleado'])
            ->where('id_tipo', 5)
            ->where('eliminado', 0)
            ->whereDate('inicio', '<=', $payload['fecha'])
            ->whereDate('fin', '>=', $payload['fecha']);

        $eventoExistente = (clone $query)
            ->orderBy('id')
            ->first();

        $descripcion = "Retardo de {$minutosRetardo} minutos.";

        if ($eventoExistente) {
            DB::connection($this->connection)
                ->table('calendario_eventos')
                ->where('id', (int) $eventoExistente->id)
                ->update([
                    'id_usuario'  => (int) $payload['id_usuario'],
                    'descripcion' => $descripcion,
                    'estado'      => 2,
                    'eliminado'   => 0,
                    'updated_at'  => now(),
                ]);

            /*
             * Si existen retardos duplicados, conservamos uno
             * y marcamos los demás como eliminados.
             */
            DB::connection($this->connection)
                ->table('calendario_eventos')
                ->where('id_portal', (int) $payload['id_portal'])
                ->where('id_cliente', (int) $payload['id_cliente'])
                ->where('id_empleado', (int) $payload['id_empleado'])
                ->where('id_tipo', 5)
                ->where('eliminado', 0)
                ->whereDate('inicio', '<=', $payload['fecha'])
                ->whereDate('fin', '>=', $payload['fecha'])
                ->where('id', '!=', (int) $eventoExistente->id)
                ->update([
                    'eliminado'  => 1,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::connection($this->connection)
            ->table('calendario_eventos')
            ->insert([
                'id_usuario'           => (int) $payload['id_usuario'],
                'id_empleado'          => (int) $payload['id_empleado'],
                'id_portal'            => (int) $payload['id_portal'],
                'id_cliente'           => (int) $payload['id_cliente'],
                'inicio'               => $payload['fecha'],
                'fin'                  => $payload['fecha'],
                'dias_evento'          => 1,
                'descripcion'          => $descripcion,
                'archivo'              => null,
                'id_tipo'              => 5,
                'tipo_incapacidad_sat' => null,
                'eliminado'            => 0,
                'estado'               => 2,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
    }
}
