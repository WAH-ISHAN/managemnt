<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ServicesTest extends TestCase {
    protected function setUp(): void {
        Db::init(':memory:'); $modules=Modules::discover(ROOT.'/app/Modules');
        Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',$modules)));
    }
    protected function tearDown(): void { Db::close(); }
    public function testTextInputsRejectNestedFieldsWithoutStoringArrayText(): void {
        $original=$_POST;
        try {
            foreach ([['unexpected'],'valid'."\0".'hidden'] as $invalid) {
                $_POST=['name'=>$invalid];
                try { text_input('name'); self::fail('Malformed text was accepted.'); }
                catch (InvalidArgumentException $e) { self::assertStringContainsString('valid name',$e->getMessage()); }
            }
            $_POST=['name'=>'  Student  ']; self::assertSame('Student',text_input('name'));
            $_POST=[]; self::assertSame('',text_input('institution',150,false));
        } finally { $_POST=$original; }
    }
    public function testDatesRejectMalformedFieldsAsValidationErrors(): void {
        foreach (['2026-02-30',"2026-10-25\0",['2026-10-25'],20261025] as $invalid) {
            try { optional_date($invalid); self::fail('Malformed date was accepted.'); }
            catch (InvalidArgumentException $e) { self::assertSame('Choose a valid date.',$e->getMessage()); }
        }
        self::assertNull(optional_date('')); self::assertNull(optional_date(null));
        self::assertSame('2026-10-25',optional_date('2026-10-25'));
    }
    public function testAllocationConservesEveryCent(): void {
        foreach (['0.01','0.02','0.99','100000.01'] as $amount) {
            $id=SalaryService::addIncome('2026-01-01','Test',$amount);
            $parts=Db::one('SELECT SUM(amount) n FROM allocations WHERE income_id=?',[$id]);
            self::assertSame(cents($amount),(int)round($parts['n']*100));
        }
    }
    public function testRoiIncludesWithdrawalsAndLaterCashFlows(): void {
        $id=InvestmentService::create('Business','business','2026-01-01','');
        InvestmentService::entry($id,'2026-01-01','deposit','50000','');
        InvestmentService::entry($id,'2026-01-02','valuation','65000','');
        self::assertEquals(30,InvestmentService::summary($id,'2026-01-02')['roi']);
        InvestmentService::entry($id,'2026-01-03','withdraw','10000','');
        InvestmentService::entry($id,'2026-01-04','valuation','60000','');
        $summary=InvestmentService::summary($id,'2026-01-04');
        self::assertEquals(20000,$summary['profit']); self::assertEquals(40,$summary['roi']);
    }
    public function testGpaUsesOnlyGradedCreditsAndCustomPoints(): void {
        Db::run("INSERT INTO uni_modules(name,credits,grade) VALUES ('A',4,'A'),('B',2,'B'),('Not graded',10,NULL)");
        self::assertSame(3.67,UniService::gpa()); UniService::savePoints('{"A":5,"B":3}'); self::assertSame(4.33,UniService::gpa());
    }
    public function testRepeatedFailuresAndTaskLessonLifecycle(): void {
        $id=LessonService::add(['area'=>'Uni','what_happened'=>'Late','action'=>'Start early']);
        LessonService::add(['area'=>'Uni','what_happened'=>'Late again']);
        self::assertSame(2,(int)LessonService::repeated()[0]['n']);
        $task=(int)Db::one('SELECT task_id FROM lessons WHERE id=?',[$id])['task_id']; LessonService::completeTask($task);
        self::assertSame('applied',Db::one('SELECT status FROM lessons WHERE id=?',[$id])['status']);
        Db::run("UPDATE lessons SET created_at=datetime('now','-91 days') WHERE id=?",[$id]); self::assertSame([],LessonService::repeated());
    }
    public function testInvalidRulesCannotPartiallyReplaceThePreviousSet(): void {
        $old=Db::all('SELECT * FROM allocation_rules');
        try { SalaryService::saveRules([['name'=>'A','percent'=>'60','account'=>1,'active'=>1],['name'=>'B','percent'=>'50','account'=>2,'active'=>1]]); self::fail('Over-allocation was accepted.'); }
        catch (InvalidArgumentException $e) { self::assertSame($old,Db::all('SELECT * FROM allocation_rules')); }
    }
    public function testAppointmentAlertsRespectAdvanceNoticeAndExcludeExpiredEvents(): void {
        $future=(new DateTimeImmutable('+3 days'))->format('Y-m-d');
        PlannerService::saveAppointment(null,['title'=>'Advance reminder','starts_at'=>$future.'T09:00','ends_at'=>$future.'T10:00','reminder_minutes'=>10080]);
        PlannerService::saveAppointment(null,['title'=>'Not due yet','starts_at'=>$future.'T11:00','ends_at'=>$future.'T12:00','reminder_minutes'=>15]);
        $past=(new DateTimeImmutable('-1 day'))->format('Y-m-d');
        PlannerService::saveAppointment(null,['title'=>'Expired meeting','starts_at'=>$past.'T09:00','ends_at'=>$past.'T10:00','reminder_minutes'=>10080]);
        $alerts=array_values(array_filter(InsightsService::alerts(),fn($a)=>$a['title']==='Appointment reminder'));
        self::assertCount(1,$alerts);
        self::assertStringContainsString('Advance reminder',$alerts[0]['body']);
        Db::run('INSERT INTO notification_dismissals(key) VALUES (?)',[$alerts[0]['id']]);
        self::assertSame([],InsightsService::alerts());
    }
    public function testFocusPauseResumeAndFinishPreserveOnlyActiveTime(): void {
        $id=FocusService::start('Study',25,'study',1000);
        FocusService::action($id,'pause',1060);
        self::assertSame(60,FocusService::elapsed(FocusService::active(),1600));
        FocusService::action($id,'resume',1600);
        FocusService::action($id,'finish',1690);
        $finished=Db::one('SELECT * FROM focus_sessions WHERE id=?',[$id]);
        self::assertSame('complete',$finished['status']);
        self::assertSame(150,(int)$finished['elapsed_seconds']);
        self::assertNull(FocusService::active());
        FocusService::action($id,'finish',1800);
        self::assertSame($finished,Db::one('SELECT * FROM focus_sessions WHERE id=?',[$id]));
    }
    public function testMonthlyFocusInsightsUseTheLocalMonthBoundary(): void {
        $zone=new DateTimeZone('Asia/Colombo');
        $start=(new DateTimeImmutable('2026-10-01 00:00:00',$zone))->getTimestamp();
        $before=FocusService::start('September',1,'study',$start-120);
        FocusService::action($before,'finish',$start-60);
        $inside=FocusService::start('October',1,'study',$start-30);
        FocusService::action($inside,'finish',$start+30);
        self::assertSame(1.0,InsightsService::monthly('2026-10')['focus_minutes']);
    }
}
