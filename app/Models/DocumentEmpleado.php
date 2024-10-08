<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentEmpleado extends Model
{
    use HasFactory;

    // Establecer el nombre de la tabla
    protected $table = 'documents_empleado';

    // Desactivar las timestamps automáticas
    public $timestamps = false;
    protected $connection = 'portal_main';

    // Definir los campos que se pueden rellenar (fillable)
    protected $fillable = [
        'creacion',
        'edicion',
        'employee_id',
        'name',
        'id_opcion',
        'description',
        'upload_date',
        'expiry_date',
        'file_path',
        'expiry_reminder',
    ];

    // Relación con el modelo Empleado (si lo necesitas)
    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'employee_id', 'id');
    }


    public function documentOption()
{
    return $this->belongsTo(DocumentOption::class, 'id_opcion');
}
}