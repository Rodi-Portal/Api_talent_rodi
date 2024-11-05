<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ComentarioFormerEmpleado extends Model
{
    use HasFactory;

    // Define el nombre de la tabla
    protected $table = 'comentarios_former_empleado';
    public $timestamps = false;
    protected $connection = 'portal_main';
    protected $primaryKey = 'id';

    // Define los campos que se pueden llenar
    protected $fillable = [
        'creacion', 
        'id_empleado', 
        'titulo', 
        'comentario', 
        'origen'
    ];

    // Define la relación con el modelo Empleado
    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }
}
