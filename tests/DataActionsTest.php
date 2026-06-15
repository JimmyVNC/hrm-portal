<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Application\DataActions;

final class DataActionsTest extends TestCase {
    public function testNormalizeHeaderValueDataHandlesWhitespace(): void {
        $this->assertSame('MÃ NV', DataActions::normalizeHeaderValueData("  MÃ   NV "));
    }

    public function testNormalizeEmployeeIdHandlesLeadingZerosAndExcelApostrophe(): void {
        $this->assertSame('458', DataActions::normalizeEmployeeId('00458'));
        $this->assertSame('458', DataActions::normalizeEmployeeId("'00458 "));
        $this->assertSame('458', DataActions::normalizeEmployeeId('458'));
    }

    public function testIsPeriodPublishedBlocksFuturePeriodForEmployee(): void {
        $period = [
            'label' => 'Tháng 05/2026',
            'publish_date' => '2099-01-01 08:00:00',
        ];
        $this->assertFalse(DataActions::isPeriodPublished($period, false, strtotime('2026-01-01 00:00:00')));
    }

    public function testIsPeriodPublishedAllowsFuturePeriodForAdmin(): void {
        $period = [
            'label' => 'Tháng 05/2026',
            'publish_date' => '2099-01-01 08:00:00',
        ];
        $this->assertTrue(DataActions::isPeriodPublished($period, true, strtotime('2026-01-01 00:00:00')));
    }

    public function testBuildPeriodUnavailableMessageIncludesPublishDate(): void {
        $period = [
            'label' => 'Tháng 05/2026',
            'publish_date' => '2026-05-25 08:00:00',
        ];
        $this->assertSame('Tháng 05/2026 sẽ được mở từ 2026-05-25 08:00:00.', DataActions::buildPeriodUnavailableMessage($period));
    }

    public function testIsPeriodPublishedSupportsDatetimeLocalFormat(): void {
        $period = [
            'label' => 'Tháng 05/2026',
            'publish_date' => '2026-05-25T08:00',
        ];
        $this->assertFalse(DataActions::isPeriodPublished($period, false, strtotime('2026-05-25 07:59:59')));
        $this->assertTrue(DataActions::isPeriodPublished($period, false, strtotime('2026-05-25 08:00:00')));
    }

    public function testBuildPeriodUnavailableMessageHandlesDisabledPeriod(): void {
        $period = [
            'label' => 'Tháng 05/2026',
            'enabled' => false,
        ];
        $this->assertSame('Tháng 05/2026 đang tạm tắt.', DataActions::buildPeriodUnavailableMessage($period));
    }
}
