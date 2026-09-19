<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FormField extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'form_id',
        'label',
        'type', // text, number, select, subform
        'subform_id',
        'required',
        'order_number',
        'is_default'
    ];

    protected $appends = ['options', 'subform', 'job_number_default'];

    public function getOptionsAttribute()
    {
        if (in_array($this->type, ['select', 'autocomplete'])) {
            return FieldOption::where('field_id', $this->id)->get();
        }
        return [];
    }

    public function getSubformAttribute()
    {
        if ($this->type == 'subform') {
            return Form::where('id', $this->subform_id)->first(['id', 'is_sub_form']);
        }
        return null;
    }

    public function getJobNumberDefaultAttribute()
    {
        if ($this->type !== 'job_number') {
            return null;
        }

        $prefix = now()->year . '-';

        $lastValue = FormSubmissionValue::where('field_id', $this->id)
            ->where('value', 'like', $prefix . '%')
            ->orderByDesc('value')
            ->value('value');

        $lastSeq = $lastValue ? (int) substr($lastValue, strlen($prefix)) : 0;

        return $prefix . str_pad($lastSeq + 1, 4, '0', STR_PAD_LEFT);
    }

    public function reportRules()
    {
        return $this->hasMany(FormReportRule::class, 'condition_field_id');
    }

    public function distinctReportRules()
    {
        return $this->hasMany(FormReportRule::class, 'distinct_field_id');
    }

}
