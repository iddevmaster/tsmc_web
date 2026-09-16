<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormChainLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_form_id',
        'next_form_id',
        'copy_selected_user',
        'copy_selected_vehicle',
    ];

    protected $casts = [
        'copy_selected_user' => 'boolean',
        'copy_selected_vehicle' => 'boolean',
    ];

    public function sourceForm()
    {
        return $this->belongsTo(Form::class, 'source_form_id');
    }

    public function nextForm()
    {
        return $this->belongsTo(Form::class, 'next_form_id');
    }

    public function fieldMaps()
    {
        return $this->hasMany(FormFieldChainMap::class, 'chain_link_id');
    }
}
