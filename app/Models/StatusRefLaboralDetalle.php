<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StatusRefLaboralDetalle extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $connection = 'rodi_main';
    protected $table = 'status_ref_laboral_detalle'; // Nombre de la tabla en la base de datos

    protected $fillable = [
        'id_status_ref_laboral',
        'fecha',
        'comentarios',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    // Relationships
    public function statusRefLaboral()
    {
        return $this->belongsTo(StatusRefLaboral::class, 'id_status_ref_laboral');
    }

    public static function getDetalleVerificacion($id_candidato)
    {
        return self::select('status_ref_laboral_detalle.*')
            ->join('status_ref_laboral', 'status_ref_laboral.id', '=', 'status_ref_laboral_detalle.id_status_ref_laboral')
            ->where('status_ref_laboral.id_candidato', $id_candidato)
            ->get();
    }
}
