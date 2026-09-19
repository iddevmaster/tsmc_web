<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Form extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'form_id',
        'title',
        'category',
        'select_user',
        'select_vehicle',
        'has_approve',
        'org',
        'created_by',
        'status',
        'is_sub_form',
        'is_default'
    ];

    public function formCategory()
    {
        return $this->belongsTo(Form_category::class, 'category');
    }

    public function formFields()
    {
        return $this->hasMany(FormField::class, 'form_id')->orderBy('order_number');
    }

    protected $appends = ['subformfields'];

    public function getSubformfieldsAttribute()
    {
        if ($this->is_sub_form) {
            return FormField::where('form_id', $this->id)->get();
        }
        return [];
    }

    public function hasPosition() {
        return $this->hasMany(PositionHasForm::class, 'form_id');
    }

    public function reportRules()
    {
        return $this->hasMany(FormReportRule::class, 'form_id');
    }

    public function hasThisPosition($positionId = null)
    {
        return $this->hasPosition()->where('position_id', $positionId)->exists();
    }

    public function nextChainLinks()
    {
        return $this->hasMany(FormChainLink::class, 'source_form_id');
    }

    public function previousChainLinks()
    {
        return $this->hasMany(FormChainLink::class, 'next_form_id');
    }

    public function countFromSubmissionByQuarter($quarter, $form_id)
    {
        $quarter_start_date = now()->startOfYear()->addMonths(($quarter - 1) * 3);
        $quarter_end_date = now()->startOfYear()->addMonths((($quarter - 1) * 3) + 3)->subDay();
        if (Auth()->user()->is_tsm) {
            $org_id = session('connected_org') ?? '';
        } else {
            $org_id = Auth::user()->userDetail->org ?? '';
        }
        // Fetch submissions and filter by isSuccessful accessor if needed
        $query = $this->hasMany(FormSubmissions::class, 'form_id')
            ->where('org', $org_id)
            ->where('form_id', $form_id)
            ->where('created_at', '>=', $quarter_start_date)
            ->where('created_at', '<=', $quarter_end_date);

        // If isSuccessful is an accessor or relationship, filter in PHP
        // return $query->get()->filter(function ($submission) {
        //     return $submission->isSubmissionSuccessful();
        // })->count();
        return $query->get()->count();
    }

    public function countFromSubmissionFieldByQuarterAndFieldId($quarter, $form_id, $field_id, $isSubform = false, $subform_id = null)
    {
        $quarter_start_date = now()->startOfYear()->addMonths(($quarter - 1) * 3);
        $quarter_end_date = now()->startOfYear()->addMonths((($quarter - 1) * 3) + 3)->subDay();
        if (Auth()->user()->is_tsm) {
            $org_id = session('connected_org') ?? '';
        } else {
            $org_id = Auth::user()->userDetail->org ?? '';
        }
        // Fetch submissions and filter by isSuccessful accessor if needed
        $query = FormSubmissions::where('org', $org_id)
            ->where('form_id', $form_id)
            ->where('created_at', '>=', $quarter_start_date)
            ->where('created_at', '<=', $quarter_end_date);

        if ($isSubform) {
            $field_list = FormField::where('form_id', $subform_id)
                ->pluck('id');
            // return $field_list;
            return $query->get()->sum(function ($submission) use ($field_list) {
                return $submission->countSubmissionValuesByFieldList($field_list);
            });
        } else {
            // If isSuccessful is an accessor or relationship, filter in PHP
            return $query->get()->sum(function ($submission) use ($field_id) {
                return $submission->countSubmissionValuesByField($field_id);
            });
        }
    }

    public function countVehicleFromSubmissionByQuarter($quarter)
    {
        $quarter_start_date = now()->startOfYear()->addMonths(($quarter - 1) * 3);
        $quarter_end_date = now()->startOfYear()->addMonths((($quarter - 1) * 3) + 3)->subDay();
        if (Auth()->user()->is_tsm) {
            $org_id = session('connected_org') ?? '';
        } else {
            $org_id = Auth::user()->userDetail->org ?? '';
        }
        return $this->hasMany(FormSubmissions::class, 'form_id')
            ->where('org', $org_id)
            ->where('created_at', '>=', $quarter_start_date)
            ->where('created_at', '<=', $quarter_end_date)
            ->whereNotNull('vehicle_id')
            ->distinct('vehicle_id')
            ->count('vehicle_id');
    }

    public function exportPerformanceReports()
    {
        return $this->hasMany(ExportPerformanceReport::class, 'form_id');
    }

    public function countExportByQuarter($quarter) {
        $quarter_start_date = now()->startOfYear();
        $quarter_end_date = now()->endOfYear();
        if (Auth()->user()->is_tsm) {
            $org_id = session('connected_org') ?? '';
        } else {
            $org_id = Auth::user()->userDetail->org ?? '';
        }

        return $this->hasMany(ExportPerformanceReport::class, 'form_id')
            ->where('org', $org_id)
            ->where('created_at', '>=', $quarter_start_date)
            ->where('created_at', '<=', $quarter_end_date)
            ->where('quarter', $quarter)
            ->count();
    }
}
