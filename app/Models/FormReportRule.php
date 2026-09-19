<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormReportRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_id',
        'item_code',
        'condition_field_id',
        'condition_values',
        'distinct_by',
        'distinct_field_id',
    ];

    protected $casts = [
        'condition_values' => 'array',
    ];

    public function form()
    {
        return $this->belongsTo(Form::class, 'form_id');
    }

    public function conditionField()
    {
        return $this->belongsTo(FormField::class, 'condition_field_id')->withTrashed();
    }

    public function distinctField()
    {
        return $this->belongsTo(FormField::class, 'distinct_field_id')->withTrashed();
    }
}
