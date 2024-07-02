<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VerificacionNoMencionados extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'verificacion_no_mencionados';
    public $timestamps = false;

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'no_mencionados',
        'resultado_no_mencionados',
        'notas_no_mencionados',
    ];

    /**
     * Get Verificacion No Mencionados by Candidato ID
     *
     * @param int $id
     * @return mixed
     */
    public function getById($id)
    {
        return $this->where('id_candidato', $id)->get()->toArray();
    }
}
