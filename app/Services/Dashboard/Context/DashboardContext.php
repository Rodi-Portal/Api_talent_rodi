<?php

namespace App\Services\Dashboard\Context;

use Carbon\Carbon;

class DashboardContext
{
    /** Conexión */
    public string $conn;

    /** Usuario / Portal */
    public int $portalId;
    public int $userId;
    public int $roleId;

    /** Scope */
    public array $allowedClients = [];
    public ?int $clientId = null;

    /** Fechas */
    public Carbon $today;
    public Carbon $rangeStart;
    public Carbon $rangeEnd;

    /** Parámetros */
    public int $days;
    public int $expireDays;
    public int $expiredDays;

    /** Módulos */
    public array $modules = [];
    public array $modulesUser = [];

    public function __construct(string $conn)
    {
        $this->conn  = $conn;
        $this->today = Carbon::today();
    }
}
