<?php

namespace App\Models\Comunicacion;

use Illuminate\Database\Eloquent\Model;

class ChecadorMapping extends Model
{
    protected $table = 'checador_mappings';
    protected $connection = 'portal_main';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    // Usas columnas de timestamp personalizadas:
    const CREATED_AT = 'creado_en';
    const UPDATED_AT = 'actualizado_en';
    public $timestamps = true;

    // Permitir asignación masiva
    protected $fillable = [
        'portal_id',
        'sucursal_id',
        'nombre',
        'headers_fingerprint',
        'config_json',
        'activo',
    ];

    // Valores por defecto
    protected $attributes = [
        'activo' => 1,
    ];

    // Casts (json → array, booleanos e ints)
    protected $casts = [
        'portal_id' => 'integer',
        'sucursal_id' => 'integer',
        'activo' => 'boolean',
        'config_json' => 'array',   // funciona tanto si la columna es JSON como si es texto con JSON válido
        'creado_en' => 'datetime',
        'actualizado_en' => 'datetime',
    ];

    /* Scopes útiles */
    public function scopePortal($q, int $portalId)
    {
        return $q->where('portal_id', $portalId);
    }

    public function scopeSucursal($q, ?int $sucursalId)
    {
        return $sucursalId ? $q->where('sucursal_id', $sucursalId) : $q;
    }

    public function scopeActivos($q)
    {
        return $q->where('activo', 1);
    }
}
