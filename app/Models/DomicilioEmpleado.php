<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DomicilioEmpleado extends Model
{
    use HasFactory;

    protected $table = 'domicilios_empleados';
    protected $connection = 'portal_main';

    protected $fillable = [
        'pais',
        'estado',
        'ciudad',
        'colonia',
        'calle',
        'cp',
        'num_int',
        'num_ext',
    ];

    public $timestamps = false; 

    public function empleados()
    {
        return $this->hasMany(Empleado::class, 'id_domicilio_empleado');
    }
}

