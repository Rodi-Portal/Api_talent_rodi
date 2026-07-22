<?php

namespace App\Models\Auth;

use Illuminate\Database\Eloquent\Model;

class PortalAuth extends Model
{
    protected $connection = 'portal_main';
    protected $table = 'portal';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [
        'id',
    ];

    protected $casts = [
        'id'          => 'integer',
        'status'      => 'integer',
        'bloqueado'   => 'integer',
        'com360'      => 'integer',
        'verificacion'=> 'integer',
    ];
}