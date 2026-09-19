<?php

use App\Http\Controllers\Account\UserController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\AppData\PrefixController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExcelController;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\FormReportRuleController;
use App\Http\Controllers\MandatoryReportController;
use App\Http\Controllers\ImportDataController;
use App\Http\Controllers\LineController;
use App\Http\Controllers\LogBookController;
use App\Http\Controllers\Organization\OrgController;
use App\Http\Controllers\Organization\PositionController;
use App\Http\Controllers\Organization\VehicleController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\RenewalCodeController;
use App\Http\Controllers\TSMUserController;
use App\Http\Controllers\WorkRecordController;
use App\Http\Controllers\DashboardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/terms', function () {
    return view('terms');
})->name('terms');

Route::get('/line-login', function () {
    return view('auth.line_login');
})->name('line.login');

Route::get('/line-check-user/{userId}', [LineController::class, 'lineCheckUser'])->name('line.check.user')->withoutMiddleware(['auth']);
Route::post('/line-auth/{userId}', [LineController::class, 'lineAuth'])->name('line.auth')->withoutMiddleware(['auth']);

// Route::get('tsm/login', [TSMUserController::class, 'showLogin'])->name('tsm.login')->withoutMiddleware(['auth']);
Route::get('tsm/register', [TSMUserController::class, 'register'])->name('tsm.register')->withoutMiddleware(['auth']);
Route::post('tsm/store-user', [TSMUserController::class, 'store'])->name('tsm.register.new.user')->withoutMiddleware(['auth']);
// Route::post('tsm/login-user', [TSMUserController::class, 'login'])->name('tsm.login.user')->withoutMiddleware(['auth']);

Route::post('/register-new-user', [App\Http\Controllers\HomeController::class, 'registerNewUser'])->name('register.new.user')->withoutMiddleware(['auth']);

Auth::routes();

Route::middleware(['auth'])->group(function () {
    Route::resource('tsms', TSMUserController::class);
    // Route::post('/tsm/logout', [TSMUserController::class, 'logout'])->name('tsm.logout');
    Route::get('/tsm/manage-org', [TSMUserController::class, 'manageOrg'])->name('tsm.manage-org');
    Route::post('/tsm-{user_id}/org/store', [TSMUserController::class, 'storeOrg'])->name('tsm.org.store');
    Route::post('/tsm/org-{org_id}/update', [TSMUserController::class, 'updateOrg'])->name('tsm.org.update');
    Route::delete('/tsm/org/{org_id}', [TSMUserController::class, 'destroyOrg'])->name('tsm.org.delete');
    Route::get('/tsm/connect-org-{org_id}', [TSMUserController::class, 'connectOrg'])->name('tsm.org.connect');

    // Log Book
    Route::get('/logbook/car-ma-detail/{repair_id}', [LogBookController::class, 'show'])->name('car.ma.detail');
    Route::get('/logbook/car-ma-table', [LogBookController::class, 'index'])->name('car.ma.table');
    Route::get('/logbook/car-ma-form', [LogBookController::class, 'create'])->name('car.ma.form');
    Route::post('/logbook/car-ma/store', [LogBookController::class, 'store'])->name('car.ma.store');

    Route::get('/logbook/show/{logbook_id}', [LogBookController::class, 'logbookShow'])->name('logbook.show');
    Route::get('/logbook/print/{logbook_id}', [LogBookController::class, 'logbookPrint'])->name('logbook.print');
    Route::get('/logbook/table', [LogBookController::class, 'logbookTable'])->name('logbook.table');
    Route::get('/logbook/create', [LogBookController::class, 'logbookCreate'])->name('logbook.create');
    Route::post('/logbook/store', [LogBookController::class, 'logbookStore'])->name('logbook.store');
    Route::post('/logbook/store/entry/{logbook_id}', [LogBookController::class, 'logbookStoreEntry'])->name('logbook.store.entry');

    Route::get('/', [App\Http\Controllers\HomeController::class, 'storeHistory']);
    Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/dashboard/accept-performance-reports', [DashboardController::class, 'acceptPerformanceReports'])->name('dashboard.accept-performance-reports');
    Route::get('/user-manual', [App\Http\Controllers\HomeController::class, 'usermanual'])->name('usermanual');
    Route::get('/login-history', [App\Http\Controllers\HomeController::class, 'loginHistoryTable'])->name('loginHistory');
    Route::get('/login-history/all', [App\Http\Controllers\HomeController::class, 'allLoginHistory'])->name('allLoginHistory');
    Route::get('/login-history/all/export', [App\Http\Controllers\HomeController::class, 'exportAllLoginHistory'])->name('allLoginHistory.export');

    // App data
    Route::resource('prefixes', PrefixController::class);

    Route::get('/renewal-codes', [RenewalCodeController::class, 'index'])->name('renewal_codes.index');
    Route::get('/renewal-code/{code}', [RenewalCodeController::class, 'showRenewalList'])->name('renewal_codes.show');
    Route::post('/renewal-code/store', [RenewalCodeController::class, 'store'])->name('renewal_codes.store');
    Route::post('/renewal-code/user/redeem', [RenewalCodeController::class, 'userRedeem'])->name('renewal_codes.user.redeem');
    Route::post('/renewal-code/org/redeem', [RenewalCodeController::class, 'orgRedeem'])->name('renewal_codes.org.redeem');

    Route::resource('organizations', OrgController::class);
    Route::post('/organizations/update/{organization}', [OrgController::class, 'update'])->name('org.update');
    Route::post('/organizations/store/branch', [OrgController::class, 'storeBranch'])->name('org.store.brn');
    Route::post('/organizations/update/branch/{brnId}', [OrgController::class, 'updateBranch'])->name('org.update.brn');
    Route::delete('/organizations/delete/branch/{brnId}', [OrgController::class, 'destroyBranch'])->name('org.delete.brn');

    Route::post('/organizations/store/department', [OrgController::class, 'storeDepartment'])->name('org.store.dpm');
    Route::post('/organizations/update/department/{dpmId}', [OrgController::class, 'updateDepartment'])->name('org.update.dpm');
    Route::delete('/organizations/delete/department/{dpmId}', [OrgController::class, 'destroyDepartment'])->name('org.delete.dpm');


    Route::resource('positions', PositionController::class);
    Route::post('/positions/update-data/{position}', [PositionController::class, 'update'])->name('positions.update.post');
    Route::get('/position-permission/manage', [PositionController::class, 'managePermission'])->name('posit.perm');
    Route::get('/position-permission/update/{positId}/{permId}/{status}/{checkType}', [PositionController::class, 'updatePermission'])->name('posit.perm.update');


    Route::resource('users', UserController::class);
    Route::get('/users/{user}/edit-my-profile', [UserController::class, 'editByOwn'])->name('users.editByOwn');
    Route::get('/user-list/export', [UserController::class, 'exportUsers'])->name('user.list.export');
    Route::post('/users/store-image', [UserController::class, 'storeImage'])->name('users.store.image');

    // Route::resource('driver-license-types', LicenseTypeController::class);

    Route::resource('vehicles', VehicleController::class);
    Route::post('vehicle/update/{id}', [VehicleController::class, 'updateData'])->name('vehicle.update');
    Route::get('/vehicle-assignment/table', [VehicleController::class, 'showVehicleAssignmentTable'])->name('vehicle.assignment.table');
    Route::post('/vehicle-assignment/store/{vehicle_id}', [VehicleController::class, 'storeVehicleAssignment'])->name('vehicles.assignment.store');
    Route::post('/vehicles/import', [VehicleController::class, 'importVehicles'])->name('vehicles.import');
    // Route::post('/cars/update-data/{car}', [CarController::class, 'update'])->name('cars.update.post');

    Route::resource('posts', PostController::class);
    Route::post('/posts/comment', [PostController::class, 'storeComment'])->name('posts.comment');
    Route::post('/posts/update/{post}', [PostController::class, 'update'])->name('posts.getUpdate');
    Route::delete('/posts/comment/{id}', [PostController::class, 'delComment'])->name('posts.comment.delete');

    Route::get('/forms/select-form-category', [FormController::class, 'selectFormCategory'])->name('form.select-form-category');
    Route::get('/forms/{form_category}/table', [FormController::class, 'showformTable'])->name('form.table');
    Route::get('/forms/{form_category}/create', [FormController::class, 'create'])->name('form.create');
    Route::get('/forms/{form_category}/edit/{id}', [FormController::class, 'edit'])->name('form.edit');
    Route::post('/forms/{form_category}/store', [FormController::class, 'store'])->name('form.store');
    Route::post('/forms/{form_category}/update/{form_id}', [FormController::class, 'update'])->name('form.update');
    Route::delete('/forms/{form_category}/form/{id}', [FormController::class, 'destroy'])->name('form.delete');
    Route::post('/forms/{form_category}/duplicate/{id}', [FormController::class, 'duplicate'])->name('form.duplicate');
    Route::get('/forms/{form_id}/permission', [FormController::class, 'formPerm'])->name('form.perm');
    Route::get('/forms/set-permission', [FormController::class, 'formSetPerm'])->name('form.perm.set');
    Route::get('/forms/{form_id}/chain', [FormController::class, 'formChainEdit'])->name('form.chain.edit');
    Route::post('/forms/{form_id}/chain-links', [FormController::class, 'storeFormChainLink'])->name('form.chain.links.store');
    Route::delete('/forms/{form_id}/chain-links/{chainLink}', [FormController::class, 'destroyFormChainLink'])->name('form.chain.links.destroy');
    Route::put('/forms/{form_id}/chain-links/{chainLink}/context', [FormController::class, 'updateFormChainContext'])->name('form.chain.context.update');
    Route::put('/forms/{form_id}/chain-links/{chainLink}/maps/{targetField}', [FormController::class, 'updateFormChainMap'])->name('form.chain.maps.update');
    Route::get('/forms/{form_id}/report-rules', [FormReportRuleController::class, 'edit'])->name('form.report-rules.edit');
    Route::post('/forms/{form_id}/report-rules', [FormReportRuleController::class, 'store'])->name('form.report-rules.store');
    Route::put('/forms/{form_id}/report-rules/{rule}', [FormReportRuleController::class, 'update'])->name('form.report-rules.update');
    Route::delete('/forms/{form_id}/report-rules/{rule}', [FormReportRuleController::class, 'destroy'])->name('form.report-rules.destroy');

    Route::get('/document/fill-out/select-form', [DocumentController::class, 'selectForm'])->name('document.fill-out.selectform');
    Route::get('/document/{form_id}/fill-out', [DocumentController::class, 'fillOutForm'])->name('document.fill-out');
    Route::post('/document/{form_id}/submit', [DocumentController::class, 'store'])->name('document.submit');
    Route::post('/document/{submission_id}/update', [DocumentController::class, 'update'])->name('document.update');
    Route::get('/document/table/select-form', [DocumentController::class, 'selectTableForm'])->name('document.table.selectform');
    Route::get('/document/{form_id}/table', [DocumentController::class, 'showDocTable'])->name('document.table');
    Route::get('/document/submission/{submission_id}/continue', [DocumentController::class, 'edit'])->name('document.submission.edit');
    Route::get('/document/submission/{submission_id}/detail', [DocumentController::class, 'show'])->name('document.submission.show');
    Route::get('/document/{form_id}/import-candidates', [DocumentController::class, 'importCandidates'])->name('document.import.candidates');
    Route::get('/document/import-data/{submission_id}', [DocumentController::class, 'importData'])->name('document.import.data');

    Route::get('/document/export/filter', [DocumentController::class, 'filterDocument'])->name('document.export.filter');
    Route::post('/export-document', [ExcelController::class, 'export']);
    Route::get('/performance-report', [ExcelController::class, 'performanceReport'])->name('performance.report');
    Route::get('/export-performance-report', [ExcelController::class, 'exportPerformanceReport'])->name('export.performance.report');
    Route::get('/submission-count', [ExcelController::class, 'submissionCount'])->name('submission.count');
    Route::get('/mandatory-report', [MandatoryReportController::class, 'index'])->name('mandatory.report');

    Route::get('/import-data', [ImportDataController::class, 'index'])->name('importdata.index');
    Route::get('/import-data/download-template', [ExcelController::class, 'downloadUserTemplate'])->name('importdata.template');
    Route::post('/import-data/preview-users', [ExcelController::class, 'previewImportUsers'])->name('importdata.preview');
    Route::post('/import-data/save-users', [ImportDataController::class, 'saveImportedUsers'])->name('importdata.save');

    Route::get('/e-learning', function () {
        return view('eLearning');
    })->name('elearning');

    // API routes
    Route::prefix('/api')->group(function () {
        Route::get('/form/getFormByCate/{form_cate}', [ApiController::class, 'getFormByCategory']);
        Route::get('/document/getDocs/', [ApiController::class, 'getDocs']);
    });

    // upload file route
    Route::post('/posts/file-upload', [FileUploadController::class, 'filepondUpload']);
    Route::delete('/filepond/delete', [FileUploadController::class, 'filepondDelete']);

    // Work Records
    Route::post('/work-record/store', [WorkRecordController::class, 'store'])->name('work-records.store');
    Route::get('/work-records/table', [WorkRecordController::class, 'showWorkRecordTable'])->name('work-records.table');
    Route::get('/work-records/geolocation-map/{workId}', [WorkRecordController::class, 'showGeoMap'])->name('work-records.geomap');
});
