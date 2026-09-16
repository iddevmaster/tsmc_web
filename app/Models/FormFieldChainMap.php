<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormFieldChainMap extends Model
{
    use HasFactory;

    protected $fillable = [
        'chain_link_id',
        'source_field_id',
        'target_field_id',
    ];

    public function chainLink()
    {
        return $this->belongsTo(FormChainLink::class, 'chain_link_id');
    }

    public function sourceField()
    {
        return $this->belongsTo(FormField::class, 'source_field_id');
    }

    public function targetField()
    {
        return $this->belongsTo(FormField::class, 'target_field_id');
    }
}
