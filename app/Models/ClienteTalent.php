<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClienteTalent extends Model
{
    use HasFactory;

    protected $connection = 'portal_main';
    protected $table      = 'cliente';
    public $timestamps    = false;

    protected $fillable = [
        'id_portal',
        'id_usuario',
        'id_datos_facturacion',
        'id_domicilios',
        'id_datos_generales',
        'nombre',
        'ingles',
        'clave',
        'icono',
        'url',
        'constancia_cliente',
        'habilitado',
        'candidatos_acceso',
        'bloqueado',
        'status',
        'eliminado',
    ];

    // Relación con empleados
    public function empleados()
    {
        return $this->hasMany(Empleado::class, 'id_cliente');
    }

    // Relación con cursos a través de empleados
    public function cursos()
    {
        return $this->hasManyThrough(CursoEmpleado::class, Empleado::class, 'id_cliente', 'employee_id');
    }
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }
    public function periodos()
    {
        return $this->hasMany(PeriodoNomina::class, 'id_cliente');
    }
}
