<?php

namespace App\Services\Portal;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PortalPaymentAccessService
{
    public function verificar(int $idPortal): string
    {
        // Portales exentos de pago
        $exentos = array_map(
            'intval',
            config('portal_access.payment.exempt_portals', [])
        );

        if (in_array($idPortal, $exentos, true)) {
            return 'exento';
        }

        if ($idPortal <= 0) {
            return 'pendiente_fuera_plazo';
        }

        if (! config('portal_access.payment.enabled', true)) {
            return 'pagado';
        }

        $hoy = Carbon::now('America/Mexico_City');
        $mesActual = $hoy->copy()->startOfMonth()->toDateString();
        $mesAnterior = $hoy->copy()->subMonthNoOverflow()
            ->startOfMonth()->toDateString();

        $pagos = DB::connection('portal_main')
            ->table('pagos_mensuales');

        // Pago del mes actual
        $pagoActual = (clone $pagos)
            ->where('id_portal', $idPortal)
            ->where('mes', $mesActual)
            ->first();

        if (
            $pagoActual &&
            $pagoActual->estado === 'pagado' &&
            ! empty($pagoActual->fecha_pago)
        ) {
            return 'pagado';
        }

        // Pago del mes anterior
        $pagoAnterior = (clone $pagos)
            ->where('id_portal', $idPortal)
            ->where('mes', $mesAnterior)
            ->where('estado', 'pagado')
            ->first();

        $anteriorPagado = $pagoAnterior &&
            ! empty($pagoAnterior->fecha_pago);

        $diasGracia = (int) config(
            'portal_access.payment.grace_days',
            5
        );

        if ($anteriorPagado && $hoy->day <= $diasGracia) {
            return 'pendiente_en_plazo';
        }

        return 'pendiente_fuera_plazo';
    }

    public function permiteAcceso(int $idPortal): bool
    {
        return in_array(
            $this->verificar($idPortal),
            ['exento', 'pagado', 'pendiente_en_plazo'],
            true
        );
    }
}