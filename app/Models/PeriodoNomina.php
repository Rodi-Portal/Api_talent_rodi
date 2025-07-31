<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PeriodoNomina extends Model
{
    use HasFactory;

    protected $table      = 'periodos_nomina';
    protected $primaryKey = 'id';
    protected $connection = 'portal_main';

    const CREATED_AT = 'creacion';
    const UPDATED_AT = 'edicion';
    public $timestamps = true;

    protected $fillable = [
        'creacion',
        'edicion',
        'id_cliente',
        'id_portal',
        'id_usuario',
        'fecha_inicio',
        'fecha_fin',
        'fecha_pago',
        'tipo_nomina',
        'periodicidad_objetivo',
        'descripcion',
        'estatus',
        'creado_por',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin'    => 'date',
        'fecha_pago'   => 'date',
    ];

    public function cliente()
    {
        return $this->belongsTo(ClienteTalent::class, 'id_cliente');
    }

    public function portal()
    {
        return $this->belongsTo(Portal::class, 'id_portal');
    }

    public function creador()
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function prenominaEmpleados()
    {
        return $this->hasMany(PreNominaEmpleado::class, 'id_periodo_nomina');
    }

    public function scopeActivos($query)
    {
        return $query->where('estatus', 'pendiente');
    }

    public function scopeDelCliente($query, $clienteId)
    {
        return $query->where('id_cliente', $clienteId);
    }
}
