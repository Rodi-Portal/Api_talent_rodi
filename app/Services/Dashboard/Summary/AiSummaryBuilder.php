<?php

namespace App\Services\Dashboard\Summary;

class AiSummaryBuilder
{
    /**
     * Construye un resumen diario simple a partir de KPIs
     */
    public function build(array $kpis): string
    {
        $parts = [];

        if (isset($kpis['employees_active'])) {
            $parts[] = "Activos: {$kpis['employees_active']}.";
        }

        if (isset($kpis['absent_today'])) {
            $parts[] = "Ausentes hoy: {$kpis['absent_today']}.";
        }

        if (isset($kpis['vacations_today'])) {
            $parts[] = "Vacaciones hoy: {$kpis['vacations_today']}.";
        }

        if (isset($kpis['expiring_documents_count'])) {
            $parts[] = "Docs por vencer: {$kpis['expiring_documents_count']}.";
        }

        if (isset($kpis['expired_items_count'])) {
            $parts[] = "Docs vencidos: {$kpis['expired_items_count']}.";
        }

        if (isset($kpis['turnover_month_pct'])) {
            $parts[] = "Rotación del mes: {$kpis['turnover_month_pct']}%.";
        }

        return $parts
            ? implode(' ', $parts)
            : 'Sin indicadores disponibles.';
    }
}
