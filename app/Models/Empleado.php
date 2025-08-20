<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    use HasFactory;
    public $timestamps    = false;
    protected $table      = 'empleados';
    protected $connection = 'portal_main';

    protected $fillable = [
        'creacion',
        'edicion',
        'id_portal',
        'id_usuario',
        'id_cliente',
        'id_empleado',
        'id_domicilio_empleado',
        'nombre',
        'paterno',
        'materno',
        'telefono',
        'correo',
        'rfc',
        'curp',
        'nss',
        'departamento',
        'puesto',
        'foto',
        'fecha_nacimiento',
        'status',
        'eliminado',
    ];
    protected $casts = [
        'fecha_nacimiento' => 'date:Y-m-d',
    ];

    public function domicilioEmpleado()
    {
        return $this->belongsTo(DomicilioEmpleado::class, 'id_domicilio_empleado');
    }
    public function documentsEmpleado()
    {
        return $this->hasMany(DocumentEmpleado::class, 'employee_id', 'id_empleado');
    }
    public function laborales()
    {
        return $this->hasOne(LaboralesEmpleado::class, 'id_empleado');
    }

    public function preNominaEmpleados()
    {
        return $this->hasMany(PreNominaEmpleado::class, 'id_empleado');
    }
    public function camposExtra()
    {
        return $this->hasMany(EmpleadoCampoExtra::class, 'id_empleado');
    }

    public function cliente()
    {
        return $this->belongsTo(ClienteTalent::class, 'id_cliente');
    }

    /**
     * Obtener los datos del empleado junto con sus relaciones.
     *
     * @param  int  $id_empleado
     * @return \App\Models\Empleado|null
     */
    public static function obtenerEmpleadoConRelacionados($id_empleado)
    {
        return self::with(['laborales', 'preNominaEmpleados' => function ($query) {
            $query->orderBy('id', 'desc')->first(); // Ordena por id descendente
        }])
            ->find($id_empleado);
    }
    public function informacionMedica()
    {
        return $this->hasOne(MedicalInfo::class, 'id_empleado');
    }

}
