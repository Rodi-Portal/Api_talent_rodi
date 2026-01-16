<?php

namespace App\Services\Dashboard\Widgets;

class AlertsWidget
{
    /**
     * Construye alertas a partir de KPIs y listas ya calculadas
     */
    public function build(
        array $kpis,
        array $lists,
        int $days,
        int $expireDays,
        int $expiredDays,
        int $limit = 6
    ): array {
        $alerts = [];

        // 1) Por vencer
        $expCount = (int) ($kpis['expiring_documents_count'] ?? 0);
        if ($expCount > 0) {
            $alerts[] = [
                'level' => 'urgent',
                'title' => "{$expCount} documentos vencen en los próximos {$expireDays} días",
                'hint'  => 'Docs / Exámenes / Cursos',
            ];
        }

        // 1.1) Vencidos
        $oldCount = (int) ($kpis['expired_items_count'] ?? 0);
        if ($oldCount > 0) {
            $alerts[] = [
                'level' => 'danger',
                'title' => "{$oldCount} documentos ya vencidos (últimos {$expiredDays} días)",
                'hint'  => 'Docs / Exámenes / Cursos',
            ];
        }

        // 2) Ausencias hoy
        $abs = (int) ($kpis['absent_today'] ?? 0);
        if ($abs > 0) {
            $alerts[] = [
                'level' => 'warning',
                'title' => "{$abs} ausencias registradas hoy",
                'hint'  => 'Incapacidad / Permiso / Falta',
            ];
        }

        // 3) Cumpleaños próximos
        $bdCount = is_array($lists['birthdays'] ?? null)
            ? count($lists['birthdays'])
            : 0;

        if ($bdCount > 0) {
            $alerts[] = [
                'level' => 'info',
                'title' => "{$bdCount} cumpleaños próximos",
                'hint'  => "Siguientes {$days} días",
            ];
        }

        // 4) Bajas del mes
        $termsMonth = (int) ($kpis['terminations_month'] ?? 0);
        if ($termsMonth > 0) {
            $alerts[] = [
                'level' => 'info',
                'title' => "{$termsMonth} bajas en el mes",
                'hint'  => 'Rotación',
            ];
        }

        return array_slice($alerts, 0, $limit);
    }
}
