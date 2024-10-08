<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentOption extends Model
{
    use HasFactory;

    // Specify the table if it doesn't follow naming conventions
    protected $table = 'document_options';
    protected $connection = 'portal_main';

    // Specify the fields that are mass assignable
    protected $fillable = [
        'creacion',
        'name',
        'type',
        'id_portal',
    ];

    // If you are using timestamps
    public $timestamps = false;

    // Relationship with the Portal model
    public function portal()
    {
        return $this->belongsTo(Portal::class, 'id_portal');
    }

    // Query scope for ordering by name
    public function scopeOrdered($query)
    {
        return $query->orderBy('name');
    }
}