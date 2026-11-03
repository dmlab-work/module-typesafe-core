<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Console\Command;

use DmLab\TypeSafeCore\Console\Command\UsageCommand;
use DmLab\TypeSafeCore\Model\Config;
use DmLab\TypeSafeCore\Model\Provider\ProviderPool;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeProvider;
use Magento\Framework\App\Config\ScopeConfigInterface;
use DmLab\TypeSafeCore\Model\Usage\UsageLedger;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class UsageCommandTest extends TestCase
{
    private const ROWS = [
        [
            'consumer' => 'catalog_enrich',
            'requests' => 10,
            'cache_hits' => 4,
            'input_tokens' => 1500000,
            'output_tokens' => 0,
        ],
        ['consumer' => 'other', 'requests' => 2, 'cache_hits' => 1, 'input_tokens' => 500000, 'output_tokens' => 0],
    ];

    /** @var string[] */
    private array $requestedPeriods = [];

    /** @var string active provider code */
    private string $provider = 'typesafe';

    /**
     * @param array<int, array<string, int|string>> $rows
     * @param int $budget
     * @param int|null $remaining
     * @return CommandTester
     */
    private function tester(array $rows, int $budget = 0, ?int $remaining = null): CommandTester
    {
        $ledger = $this->createStub(UsageLedger::class);
        $ledger->method('getRows')->willReturnCallback(function (string $period) use ($rows): array {
            $this->requestedPeriods[] = $period;
            return $rows;
        });
        $ledger->method('getRemainingBudget')->willReturn($remaining);

        $config = $this->createStub(Config::class);
        $config->method('getMonthlyTokenBudget')->willReturn($budget);
        $config->method('getProvider')->willReturnCallback(fn () => $this->provider);

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturnMap([['Y-m', null, '2026-10']]);

        $settings = new ProviderSettings($this->createStub(ScopeConfigInterface::class));
        $pool = new ProviderPool($settings, [
            'typesafe' => new FakeProvider('typesafe', $settings),
            'anthropic' => new FakeProvider('anthropic', $settings),
        ]);

        return new CommandTester(new UsageCommand($ledger, $config, $dateTime, $pool));
    }

    public function testDefaultsToCurrentMonthAndPrintsTableWithTotals(): void
    {
        $tester = $this->tester(self::ROWS);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame(['2026-10'], $this->requestedPeriods);
        $display = $tester->getDisplay(true);
        $this->assertStringStartsWith("Active provider: Fake typesafe (typesafe)\n", $display);
        $this->assertMatchesRegularExpression(
            '/Consumer\s+\|\s+Requests\s+\|\s+Cache hits\s+\|\s+Input tokens\s+\|\s+Output tokens/',
            $display
        );
        $this->assertMatchesRegularExpression(
            '/catalog_enrich\s+\|\s+10\s+\|\s+4\s+\|\s+1500000\s+\|\s+0\s+\|/',
            $display
        );
        $this->assertMatchesRegularExpression('/other\s+\|\s+2\s+\|\s+1\s+\|\s+500000\s+\|\s+0\s+\|/', $display);
        $this->assertMatchesRegularExpression('/Total\s+\|\s+12\s+\|\s+5\s+\|\s+2000000\s+\|\s+0\s+\|/', $display);
        $this->assertStringContainsString('Estimated cost: $0.0840', $display);
        $this->assertStringNotContainsString('Monthly budget', $display);
    }

    public function testCostIsFormattedToFourDecimals(): void
    {
        $tester = $this->tester([
            ['consumer' => 'c', 'requests' => 1, 'cache_hits' => 0, 'input_tokens' => 1234, 'output_tokens' => 0],
        ]);

        $tester->execute([]);

        $this->assertStringContainsString('Estimated cost: $0.0001', $tester->getDisplay(true));
    }

    public function testCostCountsOutputTokensLikeTheAdminUsageLine(): void
    {
        $tester = $this->tester([
            ['consumer' => 'c', 'requests' => 1, 'cache_hits' => 0, 'input_tokens' => 1000000,
                'output_tokens' => 1000000],
        ]);

        $tester->execute([]);

        $this->assertStringContainsString('Estimated cost: $0.0840', $tester->getDisplay(true));
    }

    public function testOtherProviderShowsOutputTokensAndNoCostEstimate(): void
    {
        $this->provider = 'anthropic';
        $tester = $this->tester([
            ['consumer' => 'a', 'requests' => 3, 'cache_hits' => 1, 'input_tokens' => 900, 'output_tokens' => 120],
            ['consumer' => 'b', 'requests' => 1, 'cache_hits' => 0, 'input_tokens' => 100, 'output_tokens' => 30],
        ]);

        $tester->execute([]);

        $display = $tester->getDisplay(true);
        $this->assertStringStartsWith("Active provider: Fake anthropic (anthropic)\n", $display);
        $this->assertMatchesRegularExpression('/a\s+\|\s+3\s+\|\s+1\s+\|\s+900\s+\|\s+120\s+\|/', $display);
        $this->assertMatchesRegularExpression('/Total\s+\|\s+4\s+\|\s+1\s+\|\s+1000\s+\|\s+150\s+\|/', $display);
        $this->assertStringContainsString("Cost: see your provider's billing", $display);
        $this->assertStringNotContainsString('Estimated cost', $display);
    }

    public function testBudgetLineForCurrentMonthWithBudget(): void
    {
        $tester = $this->tester(self::ROWS, 5000000, 3000000);

        $tester->execute(['--month' => '2026-10']);

        $this->assertStringContainsString(
            'Monthly budget: 5000000 tokens, remaining 3000000',
            $tester->getDisplay(true)
        );
    }

    public function testNoBudgetLineForPastMonth(): void
    {
        $tester = $this->tester(self::ROWS, 5000000, 3000000);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--month' => '2026-09']));
        $this->assertSame(['2026-09'], $this->requestedPeriods);
        $this->assertStringNotContainsString('Monthly budget', $tester->getDisplay(true));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidMonths(): array
    {
        return [
            'month 13' => ['2026-13'],
            'month 00' => ['2026-00'],
            'no padding' => ['2026-1'],
            'date' => ['2026-10-01'],
            'text' => ['last'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidMonths')]
    public function testInvalidMonthExitsOne(string $month): void
    {
        $tester = $this->tester(self::ROWS);

        $this->assertSame(Command::FAILURE, $tester->execute(['--month' => $month]));
        $this->assertStringContainsString('Invalid --month; expected YYYY-MM.', $tester->getDisplay(true));
        $this->assertSame([], $this->requestedPeriods);
    }

    public function testEmptyMonthPrintsNotice(): void
    {
        $tester = $this->tester([], 5000000, 5000000);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--month' => '2026-08']));
        $this->assertSame(
            "Active provider: Fake typesafe (typesafe)\nNo usage recorded for 2026-08.\n",
            $tester->getDisplay(true)
        );
    }

    public function testUnregisteredActiveProviderIsNamedByItsCode(): void
    {
        $this->provider = 'removed';
        $tester = $this->tester([]);

        $tester->execute([]);

        $this->assertStringStartsWith("Active provider: removed\n", $tester->getDisplay(true));
    }
}
