<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Application\CheckActions;

final class CheckActionsTest extends TestCase {
    public function testIsModuleEnabledDefaultsToTrue(): void {
        $this->assertTrue(CheckActions::isModuleEnabled([]));
    }

    public function testIsModuleEnabledHonorsFalseFlag(): void {
        $this->assertFalse(CheckActions::isModuleEnabled(['check_enabled' => false]));
    }

    public function testGetApiUrlFallsBackToDefault(): void {
        $this->assertSame('http://webapi.thepvinhthanh.com/mitaco-api.aspx', CheckActions::getApiUrl([]));
    }

    public function testGetApiUrlUsesConfiguredValue(): void {
        $this->assertSame('https://example.test/api', CheckActions::getApiUrl(['check_api_url' => 'https://example.test/api']));
    }

    public function testParseMonthDaysNormalizesList(): void {
        $this->assertSame([1, 2, 28, 31], CheckActions::parseMonthDays('1, 2, 2, 28, 31, 40, x'));
    }

    public function testAvailabilityStateBlocksBeforeStart(): void {
        $availability = CheckActions::getAvailabilityState(
            ['check_available_from' => '2026-05-05 08:00:00'],
            strtotime('2026-05-05 07:59:59')
        );

        $this->assertFalse($availability['is_open']);
        $this->assertSame('before_start', $availability['reason']);
    }

    public function testAvailabilityStateBlocksAfterEnd(): void {
        $availability = CheckActions::getAvailabilityState(
            ['check_available_until' => '2026-05-05 17:00:00'],
            strtotime('2026-05-05 17:00:01')
        );

        $this->assertFalse($availability['is_open']);
        $this->assertSame('after_end', $availability['reason']);
    }

    public function testAvailabilityStateAllowsWithinWindow(): void {
        $availability = CheckActions::getAvailabilityState(
            [
                'check_available_from' => '2026-05-05 08:00:00',
                'check_available_until' => '2026-05-05 17:00:00',
            ],
            strtotime('2026-05-05 12:00:00')
        );

        $this->assertTrue($availability['is_open']);
        $this->assertSame('', $availability['reason']);
    }

    public function testAvailabilityStateBlocksWhenCurrentDayNotInMonthlyList(): void {
        $availability = CheckActions::getAvailabilityState(
            ['check_month_days' => '1,2,3'],
            strtotime('2026-05-05 12:00:00')
        );

        $this->assertFalse($availability['is_open']);
        $this->assertSame('month_day_closed', $availability['reason']);
    }

    public function testAvailabilityStateAllowsWhenCurrentDayInMonthlyList(): void {
        $availability = CheckActions::getAvailabilityState(
            ['check_month_days' => '1,5,10'],
            strtotime('2026-05-05 12:00:00')
        );

        $this->assertTrue($availability['is_open']);
        $this->assertSame('', $availability['reason']);
    }

    public function testExplicitWindowOverridesMonthlyList(): void {
        $availability = CheckActions::getAvailabilityState(
            [
                'check_available_from' => '2026-05-05 08:00:00',
                'check_available_until' => '2026-05-05 17:00:00',
                'check_month_days' => '1,2,3',
            ],
            strtotime('2026-05-05 12:00:00')
        );

        $this->assertTrue($availability['is_open']);
        $this->assertSame('', $availability['reason']);
    }

    public function testNormalizeShareExpiryTimestampRejectsPastAndClampsFarFuture(): void {
        $now = strtotime('2026-06-15 08:00:00');
        $this->assertNull(CheckActions::normalizeShareExpiryTimestamp('2026-06-15T07:59', $now));
        $this->assertSame(
            strtotime('2026-07-15 08:00:00'),
            CheckActions::normalizeShareExpiryTimestamp('2026-08-20T09:00', $now)
        );
    }

    public function testCreateAttendanceShareCanBeReadBackFromSharedView(): void {
        $state = [
            'employee_id' => 'NV001',
            'from_date' => '2026-06-01',
            'to_date' => '2026-06-15',
            'latest_update' => '15/06/2026 10:00:00',
            'formatted_update' => '10:00:00 - Ngày 15/06/2026',
            'employees' => [
                'NV001' => [
                    'info' => ['code' => 'NV001', 'name' => 'Nguyen Van A'],
                    'days' => ['2026-06-15' => ['08:00', '17:00']],
                ],
            ],
            'has_data' => true,
        ];

        $share = CheckActions::createAttendanceShare($state, time() + 7200);

        $this->assertIsArray($share);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) ($share['token'] ?? ''));

        $sharedState = CheckActions::buildSharedViewState([], (string) $share['token']);

        $this->assertTrue($sharedState['is_shared_view']);
        $this->assertTrue($sharedState['has_data']);
        $this->assertSame('NV001', $sharedState['employee_id']);
        $this->assertSame(['08:00', '17:00'], $sharedState['employees']['NV001']['days']['2026-06-15']);
    }
}
