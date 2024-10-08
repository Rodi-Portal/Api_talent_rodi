<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $table = 'empleados';
    protected $connection = 'portal_main';

    protected $fillable = [
        'creacion',
        'edicion',
        'id_portal',
        'id_usuario',
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
        'foto',
        'fecha_nacimiento',
        'status',
        'eliminado',
    ];

    public function domicilioEmpleado()
    {
        return $this->belongsTo(DomicilioEmpleado::class, 'id_domicilio_empleado');
    }
    public function documentsEmpleado()
    {
        return $this->hasMany(DocumentEmpleado::class, 'employee_id', 'id_empleado');
    }
}
