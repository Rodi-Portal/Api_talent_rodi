<?php

namespace App\Models;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StatusRefLaboral extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $connection = 'rodi_main';
    protected $table = 'status_ref_laboral'; // Nombre de la tabla en la base de datos

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'fecha_solicitud',
        'finalizado',
        'fecha_finalizado',
        'status',
    ];

    protected $casts = [
        'creacion' => 'datetime',
        'edicion' => 'datetime',
        'fecha_solicitud' => 'datetime',
        'fecha_finalizado' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

       /**
     * Get the status reference laboral by id_candidato
     *
     * @param int $idCandidato
     * @return StatusRefLaboral|null
     */
    public static function getByIdCandidato($idCandidato)
    {
        return self::where('id_candidato', $idCandidato)->first();
    }
}