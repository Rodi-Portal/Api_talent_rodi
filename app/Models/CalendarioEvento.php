<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarioEvento extends Model
{
    // Nombre de la tabla
    protected $table = 'calendario_eventos';

    // Conexión personalizada
    protected $connection = 'portal_main';

    // Clave primaria
    protected $primaryKey = 'id';

    // Si NO usas incrementing para la clave primaria (por default es true)
    public $incrementing = true;

    // Si usas timestamps (created_at, updated_at)
    public $timestamps = true;

    // Campos que puedes asignar masivamente
    protected $fillable = [
        'id_usuario',
        'id_empleado',
        'id_periodo_nomina',
        'id_tipo',
        'inicio',
        'fin',
        'dias_evento',
        'descripcion',
        'archivo',
        'eliminado',

    ];

    // Relación con el modelo Empleado (ajusta el namespace si tu modelo es diferente)
    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado', 'id');
    }
    public function tipo()
    {
        return $this->belongsTo(EventosOption::class, 'id_tipo', 'id');
    }
}
