<?php

namespace App\Models;
use App\Models\Cliente;
use App\Models\Subcliente;
use App\Models\Usuario;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Proyecto extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $connection = 'rodi_main';



    protected $table = 'proyecto'; // Nombre de la tabla en la base de datos

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'internacional',
        'id_cliente',
        'id_subcliente',
        'nombre',
        'status',
        'eliminado',
    ];

    // Relación con Cliente
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }

    // Relación con Subcliente
    public function subcliente()
    {
        return $this->belongsTo(Subcliente::class, 'id_subcliente');
    }

    // Relación con Usuario
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }
}