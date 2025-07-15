<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlantillaAdjunto extends Model
{
    use HasFactory;

    protected $table = 'plantilla_archivos';
    protected $connection = 'portal_main';

    protected $fillable = [
        'id_plantilla',
        'nombre_original',
        'archivo',
    ];

    public function plantilla()
    {
        return $this->belongsTo(Plantilla::class, 'id_plantilla');
    }
}
