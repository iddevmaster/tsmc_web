<?php

namespace Database\Seeders;

use App\Models\FieldOption;
use App\Models\Form;
use App\Models\FormChainLink;
use App\Models\FormField;
use App\Models\Form_category;
use App\Models\FormSubmissionHistory;
use App\Models\FormSubmissions;
use App\Models\FormSubmissionValue;
use App\Models\Organization;
use App\Models\Position;
use App\Models\PositionHasForm;
use App\Models\Position_has_permission;
use App\Models\Position_permission;
use App\Models\Prefix;
use App\Models\Tsm_has_Org;
use App\Models\User;
use App\Models\User_detail;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Local test data covering the core transport-safety workflow: an org with a position
 * tree + permissions, users, vehicles, and all 11 standard DLT forms (see
 * form_examples/*.xlsx) each with one example submission, including the rollcall
 * form chain (ก่อนงาน -> ระหว่างงาน -> หลังงาน). Opt-in only — run with
 * `php artisan db:seed --class=DemoDataSeeder`.
 */
class DemoDataSeeder extends Seeder
{
    private const PASSWORD = 'demo1234';

    private const READY_OPTIONS = ['พร้อมใช้งาน', 'ต้องปรับปรุง'];
    private const YES_NO_OPTIONS = ['ใช่', 'ไม่ใช่'];
    private const PASS_FAIL_OPTIONS = ['ผ่าน', 'ไม่ผ่าน'];
    private const HAS_OPTIONS = ['มี', 'ไม่มี'];
    private const ROLLCALL_METHOD_OPTIONS = ['ต่อหน้า', 'โทรศัพท์ / Video Call', 'อื่นๆ'];

    public function run(): void
    {
        $org = Organization::create([
            'org_id' => Str::uuid(),
            'name' => 'บริษัท ทดสอบขนส่ง จำกัด',
            'status' => 1,
        ]);

        $prefixMr = Prefix::where('name', 'นาย')->first();

        [$posExecutive, $posSupervisor, $posDriver] = $this->makePositions($org->id);

        $this->grantPermissions($posExecutive->id, $org->id, [
            'dashboard', 'can_post', 'can_check', 'can_access_table', 'can_manage_form',
            'can_assign_driver', 'can_export', 'can_manage_user', 'can_manage_org',
            'can_see_all_docs', 'can_record_work', 'work_record_table', 'car_ma', 'can_approve_table',
        ]);
        $this->grantPermissions($posSupervisor->id, $org->id, [
            'dashboard', 'can_check', 'can_access_table', 'can_see_all_docs', 'car_ma', 'can_approve_table',
        ]);
        $this->grantPermissions($posDriver->id, $org->id, [
            'can_check', 'can_record_work',
        ]);

        $manager = $this->makeUser('demo_manager', $prefixMr, 'สมศักดิ์', 'บริหารดี', $org->id, $posExecutive->id);
        $supervisor = $this->makeUser('demo_supervisor', $prefixMr, 'วิชัย', 'ควบคุมงาน', $org->id, $posSupervisor->id);
        $driver1 = $this->makeUser('demo_driver1', $prefixMr, 'สมชาย', 'ใจดี', $org->id, $posDriver->id, '1 7542 12001 80 5');
        $driver2 = $this->makeUser('demo_driver2', $prefixMr, 'ประเสริฐ', 'ขับดี', $org->id, $posDriver->id, '3 7711 21302 10 0');

        $tsm = User::create([
            'username' => 'demo_tsm',
            'user_id' => Str::uuid(),
            'password' => Hash::make(self::PASSWORD),
            'is_tsm' => true,
        ]);
        Tsm_has_Org::create(['tsm_id' => $tsm->id, 'org_id' => $org->id]);

        // vehicle1: passenger bus, matches the ROLL CALL (สำหรับรถโดยสาร) examples in form_examples/
        $vehicle1 = Vehicle::create([
            'license_category' => '10',
            'license_plate' => '2407',
            'registration_province' => 'กรุงเทพมหานคร',
            'brand' => 'Toyota',
            'model' => 'Coaster',
            'type' => 'รถโดยสาร ประจำทาง',
            'standard' => 'รถตู้',
            'ins_company' => 'บริษัท วิริยะประกันภัย จำกัด (มหาชน)',
            'ins_type' => 'ประกันภัย ชั้น 1',
            'org_id' => $org->id,
        ]);
        // vehicle2: cargo truck, matches the ROLL CALL (สำหรับรถบรรทุก) / accident examples
        $vehicle2 = Vehicle::create([
            'license_category' => '10',
            'license_plate' => '3202',
            'registration_province' => 'กรุงเทพมหานคร',
            'brand' => 'Benz',
            'model' => 'Actros',
            'type' => 'รถบรรทุก ไม่ประจำทาง',
            'standard' => 'พ่วง',
            'ins_company' => 'บริษัท กรุงเทพ ประกันภัย จำกัด (มหาชน)',
            'ins_type' => 'ประกันภัย ชั้น 1',
            'org_id' => $org->id,
        ]);
        VehicleAssignment::create(['vehicle_id' => $vehicle1->id, 'user_id' => $driver1->id]);
        VehicleAssignment::create(['vehicle_id' => $vehicle2->id, 'user_id' => $driver2->id]);

        $vehicleCategory = Form_category::where('name', 'การจัดการรถ')->first();
        $driverCategory = Form_category::where('name', 'การจัดการผู้ขับรถ')->first();
        $routeCategory = Form_category::where('name', 'การจัดการเดินรถ')->first();
        $cargoCategory = Form_category::where('name', 'การจัดการบรรทุกและโดยสาร')->first();
        $emergencyCategory = Form_category::where('name', 'การจัดการเหตุฉุกเฉิน')->first();

        [$formMaintenance, $maintenanceFields] = $this->makeMaintenancePlanForm($org->id, $vehicleCategory->id);
        [$formHealth, $healthFields] = $this->makeHealthCheckForm($org->id, $driverCategory->id);
        [$formBusReadiness, $busReadinessFields] = $this->makeBusReadinessForm($org->id, $cargoCategory->id);
        [$formTruckReadiness, $truckReadinessFields] = $this->makeTruckReadinessForm($org->id, $cargoCategory->id);
        [$formRollcallBeforeBus, $beforeBusFields] = $this->makeRollcallBeforeBusForm($org->id, $routeCategory->id);
        [$formRollcallBeforeTruck, $beforeTruckFields] = $this->makeRollcallBeforeTruckForm($org->id, $routeCategory->id);
        [$formRollcallDuring, $duringFields] = $this->makeRollcallDuringForm($org->id, $routeCategory->id);
        [$formRollcallAfter, $afterFields] = $this->makeRollcallAfterForm($org->id, $routeCategory->id);
        [$formRouteCheck, $routeCheckFields] = $this->makeRouteCheckForm($org->id, $routeCategory->id);
        [$formTraining, $trainingFields] = $this->makeTrainingPlanForm($org->id, $driverCategory->id);
        [$formEmergency, $emergencyFields] = $this->makeEmergencyForm($org->id, $emergencyCategory->id);

        $allForms = [
            $formMaintenance, $formHealth, $formBusReadiness, $formTruckReadiness,
            $formRollcallBeforeBus, $formRollcallBeforeTruck, $formRollcallDuring, $formRollcallAfter,
            $formRouteCheck, $formTraining, $formEmergency,
        ];
        foreach ($allForms as $form) {
            foreach ([$posExecutive, $posSupervisor, $posDriver] as $position) {
                PositionHasForm::create(['position_id' => $position->id, 'form_id' => $form->id]);
            }
        }

        // Rollcall trio chain: carry the shared destination field forward at every step,
        // so a driver doesn't have to retype it (feedback item 4).
        $this->linkChain($formRollcallBeforeBus, $formRollcallDuring, [$beforeBusFields['จุดหมายการขนส่ง']]);
        $this->linkChain($formRollcallBeforeTruck, $formRollcallDuring, [$beforeTruckFields['จุดหมายการขนส่ง']]);
        $this->linkChain($formRollcallDuring, $formRollcallAfter, [$duringFields['จุดหมายการขนส่ง']]);

        // 1. แผนบำรุงรักษารถ — submitted by the supervisor about vehicle1
        $this->submit($formMaintenance, $supervisor->id, null, $org->id, [
            $maintenanceFields['วันที่จัดทำแผน']->id => '2026-09-01',
            $maintenanceFields['เครื่องกำเนิดพลังงาน']->id => 'พร้อมใช้งาน',
            $maintenanceFields['ระบบไอเสีย']->id => 'พร้อมใช้งาน',
            $maintenanceFields['ระบบส่งกำลังงาน']->id => 'พร้อมใช้งาน',
            $maintenanceFields['ระบบบังคับเลี้ยว']->id => 'พร้อมใช้งาน',
            $maintenanceFields['ระบบห้ามล้อ']->id => 'พร้อมใช้งาน',
            $maintenanceFields['ระบบรองรับน้ำหนัก']->id => 'พร้อมใช้งาน',
            $maintenanceFields['เพลาล้อ กงล้อและยาง']->id => 'ต้องปรับปรุง',
            $maintenanceFields['ตัวถัง']->id => 'พร้อมใช้งาน',
            $maintenanceFields['ระบบเชื้อเพลิง']->id => 'พร้อมใช้งาน',
        ], $vehicle1->id);

        // 2. บันทึกการตรวจสุขภาพของผู้ประจำรถ — about driver1
        $this->submit($formHealth, $driver1->id, null, $org->id, [
            $healthFields['วันที่ตรวจสุขภาพ']->id => '2026-08-10',
            $healthFields['สถานที่ตรวจสุขภาพ']->id => 'โรงพยาบาลราชวิถี',
            $healthFields['รายละเอียด/ผลการตรวจสุขภาพ']->id => 'สุขภาพโดยรวมดี (ไขมันสูงกว่าเกณฑ์เล็กน้อย)',
        ]);

        // 3. การตรวจความพร้อมของรถและอุปกรณ์ (สำหรับรถโดยสาร) — driver1 + vehicle1
        $this->submit($formBusReadiness, $driver1->id, null, $org->id, [
            $busReadinessFields['วันที่ตรวจสอบ']->id => '2026-09-05',
            $busReadinessFields['สายที่']->id => '99 กรุงเทพฯ-เชียงใหม่',
            $busReadinessFields['เส้นทาง']->id => 'กรุงเทพฯ - นครสวรรค์ - เชียงใหม่',
            $busReadinessFields['หน้าที่ความรับผิดชอบของผู้ประจำรถ']->id => 'ควบคุมรถและดูแลความปลอดภัยของผู้โดยสารตลอดเส้นทาง',
            $busReadinessFields['แผนการทำงานของผู้ขับรถ']->id => 'ขับตามรอบเวลาที่กำหนด พักตามจุดที่กำหนดทุก 4 ชั่วโมง',
            $busReadinessFields['การจัดทำคู่มือการปฏิบัติงาน']->id => 'มี',
            $busReadinessFields['เครื่องยนต์']->id => 'พร้อมใช้งาน',
            $busReadinessFields['มาตรวัด']->id => 'พร้อมใช้งาน',
            $busReadinessFields['ตัวถังรถ']->id => 'พร้อมใช้งาน',
            $busReadinessFields['ตัวถังด้านหน้า']->id => 'พร้อมใช้งาน',
            $busReadinessFields['ตัวถังด้านหลัง']->id => 'พร้อมใช้งาน',
            $busReadinessFields['ที่นั่ง/อุปกรณ์ความปลอดภัย']->id => 'พร้อมใช้งาน',
            $busReadinessFields['ระบบไฟภายใน/ระบบแอร์']->id => 'พร้อมใช้งาน',
            $busReadinessFields['ยางรถ']->id => 'พร้อมใช้งาน',
            $busReadinessFields['กระจกและหน้าต่าง']->id => 'พร้อมใช้งาน',
            $busReadinessFields['ห้องน้ำ']->id => 'พร้อมใช้งาน',
            $busReadinessFields['การตรวจสอบความปลอดภัยในการบรรทุก']->id => 'ผ่าน',
        ], $vehicle1->id);

        // 4. การตรวจความพร้อมของรถและอุปกรณ์ (สำหรับรถบรรทุก) — driver2 + vehicle2
        $this->submit($formTruckReadiness, $driver2->id, null, $org->id, [
            $truckReadinessFields['หมายเลขงาน']->id => '2026-0002',
            $truckReadinessFields['วันที่ตรวจสอบ']->id => '2026-09-06',
            $truckReadinessFields['ประเภทสิ่งของที่บรรทุก']->id => 'สินค้าอุปโภคบริโภค',
            $truckReadinessFields['ปริมาณบรรทุก']->id => '12 ตัน',
            $truckReadinessFields['หน้าที่ความรับผิดชอบของผู้ประจำรถ']->id => 'ตรวจสอบสภาพรถและสินค้าให้ปลอดภัยก่อนออกเดินทาง',
            $truckReadinessFields['แผนการทำงานของผู้ขับรถ']->id => 'ขนส่งสินค้าไปยังคลังสินค้าปลายทางภายในเวลาที่กำหนด',
            $truckReadinessFields['การจัดทำคู่มือการปฏิบัติงาน']->id => 'มี',
            $truckReadinessFields['การตรวจเช็คระดับน้ำ/น้ำมัน']->id => 'พร้อมใช้งาน',
            $truckReadinessFields['การตรวจเช็คสภาพยาง']->id => 'พร้อมใช้งาน',
            $truckReadinessFields['การตรวจเช็คหางลาก']->id => 'พร้อมใช้งาน',
            $truckReadinessFields['การตรวจสอบภายในเก๋ง']->id => 'พร้อมใช้งาน',
            $truckReadinessFields['การตรวจสอบอุปกรณ์และเอกสารประจำรถ']->id => 'พร้อมใช้งาน',
            $truckReadinessFields['บริเวณรอบๆยานพาหนะ']->id => 'พร้อมใช้งาน',
            $truckReadinessFields['ผลการตรวจการจัดเรียงของสินค้าและอุปกรณ์ยึดตรึงสินค้า']->id => 'เรียบร้อย',
            $truckReadinessFields['ผลการตรวจสอบการรัดตรึงสินค้า']->id => 'แน่นหนา',
            $truckReadinessFields['การตรวจสอบความปลอดภัยในการบรรทุก']->id => 'ผ่าน',
        ], $vehicle2->id);

        // 5. ROLL CALL ก่อนปฏิบัติงาน (สำหรับรถโดยสาร) — driver1 + vehicle1; parent of the "during" submission below
        $submissionBefore = $this->submit($formRollcallBeforeBus, $driver1->id, null, $org->id, [
            $beforeBusFields['วันที่ปฏิบัติงาน']->id => '2026-09-10',
            $beforeBusFields['สภาพอากาศ']->id => 'แจ่มใส',
            $beforeBusFields['สำนักงาน / อู่ /โกดัง']->id => 'อู่จอดรถสำนักงานใหญ่ กรุงเทพฯ',
            $beforeBusFields['เส้นทาง']->id => 'กรุงเทพฯ - เชียงใหม่',
            $beforeBusFields['จำนวนผู้โดยสาร']->id => '32',
            $beforeBusFields['จำนวนสัมภาระ']->id => '40',
            $beforeBusFields['จำนวนวันในการปฏิบัติงาน']->id => '2',
            $beforeBusFields['จุดหมายการขนส่ง']->id => 'สถานีขนส่งเชียงใหม่ อาเขต',
            $beforeBusFields['วิธีการดำเนินการทำ Roll Call']->id => 'ต่อหน้า',
            $beforeBusFields['สถานที่ในการทำ ROLL CALL']->id => 'อู่จอดรถสำนักงานใหญ่',
            $beforeBusFields['เวลาที่ทำ ROLL CALL']->id => '06:30',
            $beforeBusFields['การใช้เครื่องตรวจวัดแอลกอฮอล์']->id => 'ใช่',
            $beforeBusFields['ผลตรวจความมึนเมา']->id => 'ผ่าน',
            $beforeBusFields['การตรวจสารเสพติดในร่างกาย']->id => 'ใช่',
            $beforeBusFields['ปริมาณสารเสพติด']->id => 'ไม่พบ',
            $beforeBusFields['การตรวจสอบยานพาหนะประจำวัน']->id => 'ผ่าน',
            $beforeBusFields['การตรวจสอบสุขภาพ / ความล้า']->id => 'ปกติ',
            $beforeBusFields['การตรวจสอบความพร้อมด้านจิตใจ']->id => 'พร้อม',
            $beforeBusFields['ข้อแนะนำ / ข้อชี้แจง']->id => 'ขับขี่ด้วยความระมัดระวัง พักผ่อนให้เพียงพอ',
            $beforeBusFields['ผู้รับผิดชอบ']->id => 'วิชัย ควบคุมงาน',
        ], $vehicle1->id);

        // 6. ROLL CALL ก่อนปฏิบัติงาน (สำหรับรถบรรทุก) — driver2 + vehicle2, standalone example
        $this->submit($formRollcallBeforeTruck, $driver2->id, null, $org->id, [
            $beforeTruckFields['หมายเลขงาน']->id => '2026-0001',
            $beforeTruckFields['วันที่ปฏิบัติงาน']->id => '2026-09-11',
            $beforeTruckFields['สภาพอากาศ']->id => 'แจ่มใส',
            $beforeTruckFields['สำนักงาน / อู่ /โกดัง']->id => 'ลานจอดรถบรรทุกสำนักงานใหญ่',
            $beforeTruckFields['ประเภทสิ่งของที่บรรทุก']->id => 'วัสดุก่อสร้าง',
            $beforeTruckFields['ปริมาณที่บรรทุก']->id => '15 ตัน',
            $beforeTruckFields['เวลาที่ออกเดินทาง']->id => '05:00',
            $beforeTruckFields['จุดหมายการขนส่ง']->id => 'คลังสินค้านิคมอุตสาหกรรมอมตะนคร ชลบุรี',
            $beforeTruckFields['วิธีการดำเนินการทำ Roll Call']->id => 'ต่อหน้า',
            $beforeTruckFields['สถานที่ในการทำ ROLL CALL']->id => 'ลานจอดรถสำนักงานใหญ่',
            $beforeTruckFields['เวลาที่ทำ ROLL CALL']->id => '04:45',
            $beforeTruckFields['การใช้เครื่องตรวจวัดแอลกอฮอล์']->id => 'ใช่',
            $beforeTruckFields['ผลตรวจความมึนเมา']->id => 'ผ่าน',
            $beforeTruckFields['การตรวจสารเสพติดในร่างกาย']->id => 'ใช่',
            $beforeTruckFields['ปริมาณสารเสพติด']->id => 'ไม่พบ',
            $beforeTruckFields['การตรวจสอบยานพาหนะประจำวัน']->id => 'ผ่าน',
            $beforeTruckFields['การตรวจสอบสุขภาพ / ความล้า']->id => 'ปกติ',
            $beforeTruckFields['การตรวจสอบความพร้อมด้านจิตใจ']->id => 'พร้อม',
            $beforeTruckFields['ข้อแนะนำ / ข้อชี้แจง']->id => 'ตรวจสอบแรงลมยางก่อนออกเดินทางไกล',
            $beforeTruckFields['ผู้รับผิดชอบ']->id => 'วิชัย ควบคุมงาน',
        ], $vehicle2->id);

        // 7. ROLL CALL ระหว่างปฏิบัติงาน — chain child of submission 5 (same driver/vehicle)
        $this->submit($formRollcallDuring, $driver1->id, $submissionBefore->id, $org->id, [
            $duringFields['จุดหมายการขนส่ง']->id => 'สถานีขนส่งเชียงใหม่ อาเขต',
            $duringFields['เวลาที่ถึงที่หมาย']->id => '16:45',
            $duringFields['เวลาที่ใช้จริง']->id => '10 ชม. 15 นาที',
            $duringFields['วิธีการดำเนินการรายงาน']->id => 'ต่อหน้า',
            $duringFields['สถานที่ในการทำ ROLL CALL']->id => 'จุดพักรถนครสวรรค์',
            $duringFields['เวลาที่ทำ ROLL CALL']->id => '13:00',
            $duringFields['การใช้เครื่องตรวจวัดแอลกอฮอล์']->id => 'ใช่',
            $duringFields['ผลตรวจความมึนเมา']->id => 'ผ่าน',
            $duringFields['การตรวจสารเสพติดในร่างกาย']->id => 'ใช่',
            $duringFields['ปริมาณสารเสพติด']->id => 'ไม่พบ',
            $duringFields['การตรวจสอบยานพาหนะประจำวัน']->id => 'ผ่าน',
            $duringFields['การตรวจสอบสุขภาพ / ความล้า']->id => 'ปกติ',
            $duringFields['ข้อแนะนำ / ข้อชี้แจง']->id => 'แวะพักตามจุดที่กำหนด ดื่มน้ำและพักสายตาเป็นระยะ',
            $duringFields['ผู้รับผิดชอบ']->id => 'วิชัย ควบคุมงาน',
        ], $vehicle1->id);
        // Note: form 8 (หลังปฏิบัติงาน) is deliberately NOT filled for this specific chain —
        // that leaves the "ทำฟอร์มต่อเนื่อง" button live on submission 7's detail page for testing.

        // 8. ROLL CALL หลังปฏิบัติงาน — standalone example for driver2's completed truck run
        $this->submit($formRollcallAfter, $driver2->id, null, $org->id, [
            $afterFields['จุดหมายการขนส่ง']->id => 'คลังสินค้านิคมอุตสาหกรรมอมตะนคร ชลบุรี',
            $afterFields['วิธีการดำเนินการรายงาน']->id => 'ต่อหน้า',
            $afterFields['สถานที่ในการทำ ROLL CALL']->id => 'ลานจอดรถสำนักงานใหญ่',
            $afterFields['เวลาที่ทำ ROLL CALL']->id => '20:30',
            $afterFields['การใช้เครื่องตรวจวัดแอลกอฮอล์']->id => 'ใช่',
            $afterFields['ผลตรวจความมึนเมา']->id => 'ผ่าน',
            $afterFields['จำนวนวันที่ปฏิบัติงาน(วัน)']->id => '1',
            $afterFields['สภาพยานพาหนะ / สภาพเส้นทาง']->id => 'ปกติดี ไม่มีความเสียหาย',
            $afterFields['รายการรายงานข้อมูล']->id => 'ส่งมอบสินค้าครบถ้วนตรงเวลา',
            $afterFields['ข้อแนะนำ / ข้อชี้แจง']->id => 'ควรตรวจเช็คลมยางเพิ่มเติมก่อนเดินทางเที่ยวถัดไป',
            $afterFields['ผู้รับผิดชอบ']->id => 'วิชัย ควบคุมงาน',
        ], $vehicle2->id);

        // 9. การตรวจสอบสภาพเส้นทาง การจราจรและสถานการณ์ — driver1 + vehicle1
        $this->submit($formRouteCheck, $driver1->id, null, $org->id, [
            $routeCheckFields['วันที่ตรวจสอบเส้นทาง']->id => '2026-09-09',
            $routeCheckFields['แผนการเดินทาง']->id => 'กรุงเทพฯ - นครสวรรค์ - ลำปาง - เชียงใหม่',
            $routeCheckFields['จุดหมายการขนส่ง']->id => 'สถานีขนส่งเชียงใหม่ อาเขต',
            $routeCheckFields['เวลาที่ถึงที่หมาย']->id => '16:30',
            $routeCheckFields['จุดพักรถ']->id => 'ปั๊มน้ำมัน ปตท. อ.เมือง จ.นครสวรรค์',
            $routeCheckFields['สภาพการจราจร']->id => 'คล่องตัว',
            $routeCheckFields['การพยากรณ์อากาศล่วงหน้า']->id => 'มีเมฆบางส่วน โอกาสฝนตกร้อยละ 20',
            $routeCheckFields['ความเร็วที่แนะนำ(กม./ชม.)']->id => '90',
            $routeCheckFields['การตรวจสอบและจัดการใช้ความเร็วของรถ']->id => 'ปฏิบัติตามที่กำหนด',
            $routeCheckFields['จุดเสี่ยงหรือจุดที่ต้องระวัง']->id => 'มี',
            $routeCheckFields['รายละเอียดจุดเสี่ยง']->id => 'ทางโค้งลาดชัน กม.ที่ 385 ถนนสายเอเชีย',
            $routeCheckFields['การจัดเก็บข้อมูลการดำเนินการขนส่ง']->id => 'มี',
        ], $vehicle1->id);

        // 10. การจัดแผนฝึกอบรมผู้ประจำรถ — about driver2
        $this->submit($formTraining, $driver2->id, null, $org->id, [
            $trainingFields['วันที่ฝึกอบรม']->id => '2026-08-20',
            $trainingFields['สถานที่ฝึกอบรม']->id => 'ศูนย์ฝึกอบรมขนส่งปลอดภัย กรมการขนส่งทางบก',
            $trainingFields['รายละเอียด / หัวข้อการอบรม']->id => 'การขับขี่ปลอดภัยเชิงป้องกันและการปฐมพยาบาลเบื้องต้น (Defensive Driving & First Aid)',
        ]);

        // 11. การจัดการเหตุฉุกเฉิน — driver2 + vehicle2, based on the DLT example row
        $this->submit($formEmergency, $driver2->id, null, $org->id, [
            $emergencyFields['วันที่เกิดเหตุ']->id => '2026-05-04',
            $emergencyFields['เวลาที่เกิดเหตุ']->id => '18:03',
            $emergencyFields['สถานที่เกิดเหตุ']->id => 'สี่แยกเซ็นทรัล จังหวัดขอนแก่น',
            $emergencyFields['จำนวนผู้โดยสารทั้งหมด']->id => '3',
            $emergencyFields['จำนวนผู้เสียชีวิต']->id => '0',
            $emergencyFields['ประเภทสินค้าที่ขนส่ง']->id => 'สินค้าอุปโภคบริโภค',
            $emergencyFields['ปริมาณที่ขนส่ง']->id => '10 ตัน',
            $emergencyFields['ลักษณะของถนนบริเวณที่เกิดอุบัติเหตุ']->id => 'สี่แยกไฟจราจร ผิวถนนลาดยาง',
            $emergencyFields['สภาพอากาศ ณ เวลาเกิดเหตุ']->id => 'แจ่มใส',
            $emergencyFields['ลักษณะการเกิดอุบัติเหตุ']->id => 'รถชนท้ายขณะหยุดรอสัญญาณไฟจราจร',
            $emergencyFields['รายละเอียดความเสียหาย']->id => 'กันชนหลังและไฟท้ายเสียหาย ไม่มีผู้บาดเจ็บสาหัส',
            $emergencyFields['สาเหตุ / ข้อสันนิษฐาน']->id => 'คู่กรณีขับตามระยะกระชั้นชิดเกินไป',
            $emergencyFields['แนวทางแก้ปัญหา']->id => 'อบรมทบทวนระยะห่างการขับขี่ที่ปลอดภัยให้พนักงานขับรถ',
            $emergencyFields['การจัดทำแผนการรับมืออุบัติเหตุหรือเหตุฉุกเฉิน']->id => 'มี',
            $emergencyFields['การบริหารจัดการและติดต่อประสานงานกรณีเกิดเหตุฉุกเฉิน']->id => 'มี',
            $emergencyFields['การจัดทำรายงานอุบัติเหตุ']->id => 'มี',
            $emergencyFields['การจัดทำรายงานวิเคราะห์ข้อมูลอุบัติเหตุ']->id => 'มี',
            $emergencyFields['การจัดทำรายงานวิเคราะห์และประเมินผลการจัดการความปลอดภัยในการขนส่ง']->id => 'มี',
        ], $vehicle2->id);

        $this->command?->info('Demo data created: org, position tree, 5 users, 2 vehicles, all 11 standard DLT forms with 1 example submission each.');
        $this->command?->table(['username', 'password', 'role'], [
            ['demo_manager', self::PASSWORD, 'ผู้บริหาร (org admin)'],
            ['demo_supervisor', self::PASSWORD, 'หัวหน้างานขนส่ง'],
            ['demo_driver1', self::PASSWORD, 'พนักงานขับรถรถโดยสาร (มีเอกสารก่อนงาน+ระหว่างงานให้ทดสอบปุ่มทำฟอร์มต่อเนื่อง)'],
            ['demo_driver2', self::PASSWORD, 'พนักงานขับรถบรรทุก'],
            ['demo_tsm', self::PASSWORD, 'TSM เชื่อมกับองค์กรนี้แล้ว'],
        ]);
    }

    /** @return array{Position, Position, Position} */
    private function makePositions(int $orgId): array
    {
        $executive = Position::create(['name' => 'ผู้บริหาร', 'created_by' => 'seeder', 'org' => (string) $orgId]);
        $executive->saveAsRoot();

        $supervisor = Position::create(['name' => 'หัวหน้างานขนส่ง', 'created_by' => 'seeder', 'org' => (string) $orgId]);
        $executive->appendNode($supervisor);

        $driver = Position::create(['name' => 'พนักงานขับรถ', 'created_by' => 'seeder', 'org' => (string) $orgId]);
        $supervisor->appendNode($driver);

        return [$executive, $supervisor, $driver];
    }

    private function grantPermissions(int $positionId, int $orgId, array $permNames): void
    {
        foreach ($permNames as $permName) {
            $perm = Position_permission::where('perm_name', $permName)->first();
            if (!$perm) {
                continue;
            }

            Position_has_permission::create([
                'position_id' => $positionId,
                'permission_id' => $perm->id,
                'user_id' => 1,
                'org' => $orgId,
                'status' => true,
            ]);
        }
    }

    private function makeUser(string $username, ?Prefix $prefix, string $fname, string $lname, int $orgId, int $positionId, ?string $citizenId = null): User
    {
        $user = User::create([
            'username' => $username,
            'user_id' => Str::uuid(),
            'password' => Hash::make(self::PASSWORD),
            'is_tsm' => false,
        ]);

        User_detail::create([
            'user_id' => $user->id,
            'prefix' => $prefix?->id,
            'fname' => $fname,
            'lname' => $lname,
            'citizen_id' => $citizenId,
            'org' => (string) $orgId,
            'position' => $positionId,
        ]);

        return $user;
    }

    private function makeForm(string $title, int $categoryId, int $orgId, bool $selectUser = true, bool $selectVehicle = true): Form
    {
        return Form::create([
            'form_id' => (string) Str::uuid(),
            'title' => $title,
            'category' => $categoryId,
            'select_user' => $selectUser,
            'select_vehicle' => $selectVehicle,
            'org' => (string) $orgId,
            'status' => true,
        ]);
    }

    /** Creates a field (with options, if given) and records it into $fields keyed by label. */
    private function addField(Form $form, array &$fields, string $label, string $type, int $order, array $options = []): FormField
    {
        $field = FormField::create([
            'form_id' => $form->id,
            'label' => $label,
            'type' => $type,
            'order_number' => $order,
        ]);

        foreach ($options as $value) {
            FieldOption::create(['field_id' => $field->id, 'value' => $value]);
        }

        $fields[$label] = $field;

        return $field;
    }

    /** @return array{Form, array<string, FormField>} */
    private function makeMaintenancePlanForm(int $orgId, int $categoryId): array
    {
        $form = $this->makeForm('แผนบำรุงรักษารถ', $categoryId, $orgId);
        $fields = [];
        $order = 0;

        $this->addField($form, $fields, 'วันที่จัดทำแผน', 'date', $order++);
        foreach ([
            'เครื่องกำเนิดพลังงาน', 'ระบบไอเสีย', 'ระบบส่งกำลังงาน', 'ระบบบังคับเลี้ยว',
            'ระบบห้ามล้อ', 'ระบบรองรับน้ำหนัก', 'เพลาล้อ กงล้อและยาง', 'ตัวถัง', 'ระบบเชื้อเพลิง',
        ] as $label) {
            $this->addField($form, $fields, $label, 'select', $order++, self::READY_OPTIONS);
        }

        return [$form, $fields];
    }

    /** @return array{Form, array<string, FormField>} */
    private function makeHealthCheckForm(int $orgId, int $categoryId): array
    {
        $form = $this->makeForm('บันทึกการตรวจสุขภาพของผู้ประจำรถ', $categoryId, $orgId, true, false);
        $fields = [];
        $order = 0;

        $this->addField($form, $fields, 'วันที่ตรวจสุขภาพ', 'date', $order++);
        $this->addField($form, $fields, 'สถานที่ตรวจสุขภาพ', 'text', $order++);
        $this->addField($form, $fields, 'รายละเอียด/ผลการตรวจสุขภาพ', 'text', $order++);

        return [$form, $fields];
    }

    /** @return array{Form, array<string, FormField>} */
    private function makeBusReadinessForm(int $orgId, int $categoryId): array
    {
        $form = $this->makeForm('การตรวจความพร้อมของรถและอุปกรณ์ (สำหรับรถโดยสาร)', $categoryId, $orgId);
        $fields = [];
        $order = 0;

        $this->addField($form, $fields, 'วันที่ตรวจสอบ', 'date', $order++);
        $this->addField($form, $fields, 'สายที่', 'text', $order++);
        $this->addField($form, $fields, 'เส้นทาง', 'text', $order++);
        $this->addField($form, $fields, 'หน้าที่ความรับผิดชอบของผู้ประจำรถ', 'text', $order++);
        $this->addField($form, $fields, 'แผนการทำงานของผู้ขับรถ', 'text', $order++);
        $this->addField($form, $fields, 'การจัดทำคู่มือการปฏิบัติงาน', 'select', $order++, self::HAS_OPTIONS);
        foreach ([
            'เครื่องยนต์', 'มาตรวัด', 'ตัวถังรถ', 'ตัวถังด้านหน้า', 'ตัวถังด้านหลัง',
            'ที่นั่ง/อุปกรณ์ความปลอดภัย', 'ระบบไฟภายใน/ระบบแอร์', 'ยางรถ', 'กระจกและหน้าต่าง', 'ห้องน้ำ',
        ] as $label) {
            $this->addField($form, $fields, $label, 'select', $order++, self::READY_OPTIONS);
        }
        $this->addField($form, $fields, 'การตรวจสอบความปลอดภัยในการบรรทุก', 'select', $order++, self::PASS_FAIL_OPTIONS);

        return [$form, $fields];
    }

    /** @return array{Form, array<string, FormField>} */
    private function makeTruckReadinessForm(int $orgId, int $categoryId): array
    {
        $form = $this->makeForm('การตรวจความพร้อมของรถและอุปกรณ์ (สำหรับรถบรรทุก)', $categoryId, $orgId);
        $fields = [];
        $order = 0;

        $this->addField($form, $fields, 'หมายเลขงาน', 'job_number', $order++);
        $this->addField($form, $fields, 'วันที่ตรวจสอบ', 'date', $order++);
        $this->addField($form, $fields, 'ประเภทสิ่งของที่บรรทุก', 'text', $order++);
        $this->addField($form, $fields, 'ปริมาณบรรทุก', 'text', $order++);
        $this->addField($form, $fields, 'หน้าที่ความรับผิดชอบของผู้ประจำรถ', 'text', $order++);
        $this->addField($form, $fields, 'แผนการทำงานของผู้ขับรถ', 'text', $order++);
        $this->addField($form, $fields, 'การจัดทำคู่มือการปฏิบัติงาน', 'select', $order++, self::HAS_OPTIONS);
        foreach ([
            'การตรวจเช็คระดับน้ำ/น้ำมัน', 'การตรวจเช็คสภาพยาง', 'การตรวจเช็คหางลาก',
            'การตรวจสอบภายในเก๋ง', 'การตรวจสอบอุปกรณ์และเอกสารประจำรถ', 'บริเวณรอบๆยานพาหนะ',
        ] as $label) {
            $this->addField($form, $fields, $label, 'select', $order++, self::READY_OPTIONS);
        }
        $this->addField($form, $fields, 'ผลการตรวจการจัดเรียงของสินค้าและอุปกรณ์ยึดตรึงสินค้า', 'select', $order++, ['เรียบร้อย', 'ต้องจัดใหม่']);
        $this->addField($form, $fields, 'ผลการตรวจสอบการรัดตรึงสินค้า', 'select', $order++, ['แน่นหนา', 'หลวม']);
        $this->addField($form, $fields, 'การตรวจสอบความปลอดภัยในการบรรทุก', 'select', $order++, self::PASS_FAIL_OPTIONS);

        return [$form, $fields];
    }

    /** Shared roll-call checklist fields (alcohol/drug/vehicle/health checks) appended after $order. */
    private function addRollcallChecklist(Form $form, array &$fields, int $order, bool $withMentalCheck): int
    {
        $this->addField($form, $fields, 'การใช้เครื่องตรวจวัดแอลกอฮอล์', 'select', $order++, self::YES_NO_OPTIONS);
        $this->addField($form, $fields, 'ผลตรวจความมึนเมา', 'select', $order++, self::PASS_FAIL_OPTIONS);
        $this->addField($form, $fields, 'การตรวจสารเสพติดในร่างกาย', 'select', $order++, self::YES_NO_OPTIONS);
        $this->addField($form, $fields, 'ปริมาณสารเสพติด', 'text', $order++);
        $this->addField($form, $fields, 'การตรวจสอบยานพาหนะประจำวัน', 'select', $order++, self::PASS_FAIL_OPTIONS);
        $this->addField($form, $fields, 'การตรวจสอบสุขภาพ / ความล้า', 'select', $order++, ['ปกติ', 'อ่อนเพลีย']);
        if ($withMentalCheck) {
            $this->addField($form, $fields, 'การตรวจสอบความพร้อมด้านจิตใจ', 'select', $order++, ['พร้อม', 'ไม่พร้อม']);
        }

        return $order;
    }

    /** @return array{Form, array<string, FormField>} */
    private function makeRollcallBeforeBusForm(int $orgId, int $categoryId): array
    {
        $form = $this->makeForm('การบันทึกการทำ ROLL CALL ก่อนปฏิบัติงาน (สำหรับรถโดยสาร)', $categoryId, $orgId);
        $fields = [];
        $order = 0;

        $this->addField($form, $fields, 'วันที่ปฏิบัติงาน', 'date', $order++);
        $this->addField($form, $fields, 'สภาพอากาศ', 'select', $order++, ['แจ่มใส', 'มีเมฆบางส่วน', 'ฝนตก', 'มีหมอก']);
        $this->addField($form, $fields, 'สำนักงาน / อู่ /โกดัง', 'text', $order++);
        $this->addField($form, $fields, 'เส้นทาง', 'text', $order++);
        $this->addField($form, $fields, 'จำนวนผู้โดยสาร', 'number', $order++);
        $this->addField($form, $fields, 'จำนวนสัมภาระ', 'number', $order++);
        $this->addField($form, $fields, 'จำนวนวันในการปฏิบัติงาน', 'number', $order++);
        $this->addField($form, $fields, 'จุดหมายการขนส่ง', 'text', $order++);
        $this->addField($form, $fields, 'วิธีการดำเนินการทำ Roll Call', 'select', $order++, self::ROLLCALL_METHOD_OPTIONS);
        $this->addField($form, $fields, 'สถานที่ในการทำ ROLL CALL', 'text', $order++);
        $this->addField($form, $fields, 'เวลาที่ทำ ROLL CALL', 'text', $order++);
        $order = $this->addRollcallChecklist($form, $fields, $order, true);
        $this->addField($form, $fields, 'ข้อแนะนำ / ข้อชี้แจง', 'text', $order++);
        $this->addField($form, $fields, 'ผู้รับผิดชอบ', 'text', $order++);

        return [$form, $fields];
    }

    /** @return array{Form, array<string, FormField>} */
    private function makeRollcallBeforeTruckForm(int $orgId, int $categoryId): array
    {
        $form = $this->makeForm('การบันทึกการทำ ROLL CALL ก่อนปฏิบัติงาน (สำหรับรถบรรทุก)', $categoryId, $orgId);
        $fields = [];
        $order = 0;

        $this->addField($form, $fields, 'หมายเลขงาน', 'job_number', $order++);
        $this->addField($form, $fields, 'วันที่ปฏิบัติงาน', 'date', $order++);
        $this->addField($form, $fields, 'สภาพอากาศ', 'select', $order++, ['แจ่มใส', 'มีเมฆบางส่วน', 'ฝนตก', 'มีหมอก']);
        $this->addField($form, $fields, 'สำนักงาน / อู่ /โกดัง', 'text', $order++);
        $this->addField($form, $fields, 'ประเภทสิ่งของที่บรรทุก', 'text', $order++);
        $this->addField($form, $fields, 'ปริมาณที่บรรทุก', 'text', $order++);
        $this->addField($form, $fields, 'เวลาที่ออกเดินทาง', 'text', $order++);
        $this->addField($form, $fields, 'จุดหมายการขนส่ง', 'text', $order++);
        $this->addField($form, $fields, 'วิธีการดำเนินการทำ Roll Call', 'select', $order++, self::ROLLCALL_METHOD_OPTIONS);
        $this->addField($form, $fields, 'สถานที่ในการทำ ROLL CALL', 'text', $order++);
        $this->addField($form, $fields, 'เวลาที่ทำ ROLL CALL', 'text', $order++);
        $order = $this->addRollcallChecklist($form, $fields, $order, true);
        $this->addField($form, $fields, 'ข้อแนะนำ / ข้อชี้แจง', 'text', $order++);
        $this->addField($form, $fields, 'ผู้รับผิดชอบ', 'text', $order++);

        return [$form, $fields];
    }

    /** @return array{Form, array<string, FormField>} */
    private function makeRollcallDuringForm(int $orgId, int $categoryId): array
    {
        $form = $this->makeForm('การบันทึกการทำ ROLL CALL ระหว่างปฏิบัติงาน', $categoryId, $orgId);
        $fields = [];
        $order = 0;

        $this->addField($form, $fields, 'จุดหมายการขนส่ง', 'text', $order++);
        $this->addField($form, $fields, 'เวลาที่ถึงที่หมาย', 'text', $order++);
        $this->addField($form, $fields, 'เวลาที่ใช้จริง', 'text', $order++);
        $this->addField($form, $fields, 'วิธีการดำเนินการรายงาน', 'select', $order++, self::ROLLCALL_METHOD_OPTIONS);
        $this->addField($form, $fields, 'สถานที่ในการทำ ROLL CALL', 'text', $order++);
        $this->addField($form, $fields, 'เวลาที่ทำ ROLL CALL', 'text', $order++);
        $order = $this->addRollcallChecklist($form, $fields, $order, false);
        $this->addField($form, $fields, 'ข้อแนะนำ / ข้อชี้แจง', 'text', $order++);
        $this->addField($form, $fields, 'ผู้รับผิดชอบ', 'text', $order++);

        return [$form, $fields];
    }

    /** @return array{Form, array<string, FormField>} */
    private function makeRollcallAfterForm(int $orgId, int $categoryId): array
    {
        $form = $this->makeForm('การบันทึกการทำ ROLL CALL หลังปฏิบัติงาน', $categoryId, $orgId);
        $fields = [];
        $order = 0;

        $this->addField($form, $fields, 'จุดหมายการขนส่ง', 'text', $order++);
        $this->addField($form, $fields, 'วิธีการดำเนินการรายงาน', 'select', $order++, self::ROLLCALL_METHOD_OPTIONS);
        $this->addField($form, $fields, 'สถานที่ในการทำ ROLL CALL', 'text', $order++);
        $this->addField($form, $fields, 'เวลาที่ทำ ROLL CALL', 'text', $order++);
        $this->addField($form, $fields, 'การใช้เครื่องตรวจวัดแอลกอฮอล์', 'select', $order++, self::YES_NO_OPTIONS);
        $this->addField($form, $fields, 'ผลตรวจความมึนเมา', 'select', $order++, self::PASS_FAIL_OPTIONS);
        $this->addField($form, $fields, 'จำนวนวันที่ปฏิบัติงาน(วัน)', 'number', $order++);
        $this->addField($form, $fields, 'สภาพยานพาหนะ / สภาพเส้นทาง', 'text', $order++);
        $this->addField($form, $fields, 'รายการรายงานข้อมูล', 'text', $order++);
        $this->addField($form, $fields, 'ข้อแนะนำ / ข้อชี้แจง', 'text', $order++);
        $this->addField($form, $fields, 'ผู้รับผิดชอบ', 'text', $order++);

        return [$form, $fields];
    }

    /** @return array{Form, array<string, FormField>} */
    private function makeRouteCheckForm(int $orgId, int $categoryId): array
    {
        $form = $this->makeForm('การตรวจสอบสภาพเส้นทาง การจราจรและสถานการณ์', $categoryId, $orgId);
        $fields = [];
        $order = 0;

        $this->addField($form, $fields, 'วันที่ตรวจสอบเส้นทาง', 'date', $order++);
        $this->addField($form, $fields, 'แผนการเดินทาง', 'text', $order++);
        $this->addField($form, $fields, 'จุดหมายการขนส่ง', 'text', $order++);
        $this->addField($form, $fields, 'เวลาที่ถึงที่หมาย', 'text', $order++);
        $this->addField($form, $fields, 'จุดพักรถ', 'text', $order++);
        $this->addField($form, $fields, 'สภาพการจราจร', 'select', $order++, ['คล่องตัว', 'หนาแน่นเล็กน้อย', 'ติดขัด']);
        $this->addField($form, $fields, 'การพยากรณ์อากาศล่วงหน้า', 'text', $order++);
        $this->addField($form, $fields, 'ความเร็วที่แนะนำ(กม./ชม.)', 'number', $order++);
        $this->addField($form, $fields, 'การตรวจสอบและจัดการใช้ความเร็วของรถ', 'select', $order++, ['ปฏิบัติตามที่กำหนด', 'เกินกำหนด']);
        $this->addField($form, $fields, 'จุดเสี่ยงหรือจุดที่ต้องระวัง', 'select', $order++, self::HAS_OPTIONS);
        $this->addField($form, $fields, 'รายละเอียดจุดเสี่ยง', 'text', $order++);
        $this->addField($form, $fields, 'การจัดเก็บข้อมูลการดำเนินการขนส่ง', 'select', $order++, self::HAS_OPTIONS);

        return [$form, $fields];
    }

    /** @return array{Form, array<string, FormField>} */
    private function makeTrainingPlanForm(int $orgId, int $categoryId): array
    {
        $form = $this->makeForm('การจัดแผนฝึกอบรมผู้ประจำรถ', $categoryId, $orgId, true, false);
        $fields = [];
        $order = 0;

        $this->addField($form, $fields, 'วันที่ฝึกอบรม', 'date', $order++);
        $this->addField($form, $fields, 'สถานที่ฝึกอบรม', 'text', $order++);
        $this->addField($form, $fields, 'รายละเอียด / หัวข้อการอบรม', 'text', $order++);

        return [$form, $fields];
    }

    /** @return array{Form, array<string, FormField>} */
    private function makeEmergencyForm(int $orgId, int $categoryId): array
    {
        $form = $this->makeForm('การจัดการเหตุฉุกเฉิน', $categoryId, $orgId);
        $fields = [];
        $order = 0;

        $this->addField($form, $fields, 'วันที่เกิดเหตุ', 'date', $order++);
        $this->addField($form, $fields, 'เวลาที่เกิดเหตุ', 'text', $order++);
        $this->addField($form, $fields, 'สถานที่เกิดเหตุ', 'text', $order++);
        $this->addField($form, $fields, 'จำนวนผู้โดยสารทั้งหมด', 'number', $order++);
        $this->addField($form, $fields, 'จำนวนผู้เสียชีวิต', 'number', $order++);
        $this->addField($form, $fields, 'ประเภทสินค้าที่ขนส่ง', 'text', $order++);
        $this->addField($form, $fields, 'ปริมาณที่ขนส่ง', 'text', $order++);
        $this->addField($form, $fields, 'ลักษณะของถนนบริเวณที่เกิดอุบัติเหตุ', 'text', $order++);
        $this->addField($form, $fields, 'สภาพอากาศ ณ เวลาเกิดเหตุ', 'select', $order++, ['แจ่มใส', 'ฝนตก', 'มีหมอก', 'มืด/กลางคืน']);
        $this->addField($form, $fields, 'ลักษณะการเกิดอุบัติเหตุ', 'text', $order++);
        $this->addField($form, $fields, 'รายละเอียดความเสียหาย', 'text', $order++);
        $this->addField($form, $fields, 'สาเหตุ / ข้อสันนิษฐาน', 'text', $order++);
        $this->addField($form, $fields, 'แนวทางแก้ปัญหา', 'text', $order++);
        $this->addField($form, $fields, 'การจัดทำแผนการรับมืออุบัติเหตุหรือเหตุฉุกเฉิน', 'select', $order++, self::HAS_OPTIONS);
        $this->addField($form, $fields, 'การบริหารจัดการและติดต่อประสานงานกรณีเกิดเหตุฉุกเฉิน', 'select', $order++, self::HAS_OPTIONS);
        $this->addField($form, $fields, 'การจัดทำรายงานอุบัติเหตุ', 'select', $order++, self::HAS_OPTIONS);
        $this->addField($form, $fields, 'การจัดทำรายงานวิเคราะห์ข้อมูลอุบัติเหตุ', 'select', $order++, self::HAS_OPTIONS);
        $this->addField($form, $fields, 'การจัดทำรายงานวิเคราะห์และประเมินผลการจัดการความปลอดภัยในการขนส่ง', 'select', $order++, self::HAS_OPTIONS);

        return [$form, $fields];
    }

    /** @param FormField[] $sourceFields Fields on $source whose value should carry over to $next by matching label. */
    private function linkChain(Form $source, Form $next, array $sourceFields): void
    {
        $link = FormChainLink::create([
            'source_form_id' => $source->id,
            'next_form_id' => $next->id,
            'copy_selected_user' => true,
            'copy_selected_vehicle' => true,
        ]);

        $targetsByLabel = FormField::where('form_id', $next->id)->get()->keyBy('label');

        foreach ($sourceFields as $sourceField) {
            if ($targetsByLabel->has($sourceField->label)) {
                $link->fieldMaps()->create([
                    'target_field_id' => $targetsByLabel[$sourceField->label]->id,
                    'source_field_id' => $sourceField->id,
                ]);
            }
        }
    }

    /** Creates a submission for $form and fills its values. Returns the created submission. */
    private function submit(Form $form, int $userId, ?int $parentSubmissionId, int $orgId, array $valuesByFieldId, ?int $vehicleId = null): FormSubmissions
    {
        $submission = FormSubmissions::create([
            'submission_id' => Str::uuid(),
            'form_id' => $form->id,
            'parent_submission_id' => $parentSubmissionId,
            'user_id' => $userId,
            'vehicle_id' => $vehicleId,
            'submitted_by' => $userId,
            'org' => (string) $orgId,
        ]);

        foreach ($valuesByFieldId as $fieldId => $value) {
            FormSubmissionValue::create([
                'submission_id' => $submission->id,
                'field_id' => $fieldId,
                'value' => $value,
                'submitted_by' => $userId,
            ]);
        }

        FormSubmissionHistory::create(['submission_id' => $submission->id, 'user_id' => $userId]);

        return $submission;
    }
}
