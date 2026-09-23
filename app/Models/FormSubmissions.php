<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FormSubmissions extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'submission_id',
        'form_id',
        'parent_submission_id',
        'user_id',
        'vehicle_id',
        'submitted_by',
        'status',
        'org',
        'created_at',
        'updated_at',
    ];

    public function getForm()
    {
        return $this->belongsTo(Form::class, 'form_id', 'id');
    }

    public function getUser()
    {
        return $this->belongsTo(User::class, 'user_id', 'id')->select('id');
    }

    public function getVehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'id');
    }

    public function submittedByUser()
    {
        return $this->belongsTo(User::class, 'submitted_by', 'id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org', 'id');
    }

    public function getSubmissionValues()
    {
        return $this->hasMany(FormSubmissionValue::class, 'submission_id');
    }

    public function getSubmissionHistory()
    {
        return $this->hasMany(FormSubmissionHistory::class, 'submission_id')->orderByDesc('created_at')->limit(10);
    }

    public function parentSubmission()
    {
        return $this->belongsTo(self::class, 'parent_submission_id');
    }

    public function childSubmissions()
    {
        return $this->hasMany(self::class, 'parent_submission_id');
    }

    public function getSubmissionValuesIsNull()
    {
        return $this->hasMany(FormSubmissionValue::class, 'submission_id')->whereNull('value');
    }

    public function getSubmissionValuesIsNotNull()
    {
        return $this->hasMany(FormSubmissionValue::class, 'submission_id')->whereNotNull('value')->count();
    }

    public function isSubmissionSuccessful()
    {
        return $this->getSubmissionValuesIsNull()->count() === 0;
    }

    public function countSubmissionValuesByField($fieldId)
    {
        return $this->getSubmissionValues()->where('field_id', $fieldId)->whereNotNull('value')->count();
    }

    public function countSubmissionValuesByFieldList($field_list)
    {
        return $this->getSubmissionValues()->whereIn('field_id', $field_list)->whereNotNull('value')->count() > 0 ? 1 : 0;
    }

    public function getFieldValue ($fieldId) {
        return $this->getSubmissionValues()->where('field_id', $fieldId)->first();
    }
}
