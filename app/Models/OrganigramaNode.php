<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganigramaNode extends Model
{
    protected $connection = 'portal_main';
    protected $table      = 'organigrama_nodes';

    protected $fillable = [
        'id_portal',
        'id_cliente',
        'parent_id',
        'empleado_id',
        'titulo_puesto',
        'activo',
    ];

    public $timestamps = false;

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }
}
