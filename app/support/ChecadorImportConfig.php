<?php

namespace App\Support;

class ChecadorImportConfig
{
    /**
     * Máximo de registros permitidos por importación.
     */
    public const MAX_IMPORT_ROWS = 500;

    /**
     * Tiempo de vida del preview en caché (minutos).
     */
    public const CACHE_MINUTES = 30;

    /**
     * Color por defecto para eventos creados automáticamente.
     */
    public const DEFAULT_EVENT_COLOR = '#293e89';

    /**
     * Estado por defecto del evento.
     */
    public const DEFAULT_EVENT_STATUS = 2;

    /**
     * Origen por defecto.
     */
    public const DEFAULT_EVENT_ORIGIN = 'integracion';
}