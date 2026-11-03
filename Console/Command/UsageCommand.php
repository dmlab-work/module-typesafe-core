<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Console\Command;

use DmLab\TypeSafeCore\Exception\NotConfiguredException;
use DmLab\TypeSafeCore\Model\Config;
use DmLab\TypeSafeCore\Model\Provider\ProviderPool;
use DmLab\TypeSafeCore\Model\Provider\TypeSafe\TypeSafeProvider;
use DmLab\TypeSafeCore\Model\Usage\UsageLedger;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `dmlab:typesafe:usage` — per-consumer usage of a UTC month; estimated cost for TypeSafe only.
 */
class UsageCommand extends Command
{
    public const NAME = 'dmlab:typesafe:usage';
    public const OPTION_MONTH = 'month';
    private const MONTH_PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/';

    /**
     * @param UsageLedger $usageLedger
     * @param Config $config
     * @param DateTime $dateTime
     * @param ProviderPool $providerPool
     */
    public function __construct(
        private readonly UsageLedger $usageLedger,
        private readonly Config $config,
        private readonly DateTime $dateTime,
        private readonly ProviderPool $providerPool
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Shows AI client usage per consumer for a month (UTC).')
            ->addOption(
                self::OPTION_MONTH,
                null,
                InputOption::VALUE_REQUIRED,
                'Month as YYYY-MM; defaults to the current UTC month'
            );
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $currentMonth = (string)$this->dateTime->gmtDate('Y-m');
        $month = $input->getOption(self::OPTION_MONTH) ?? $currentMonth;
        if (!is_string($month) || !preg_match(self::MONTH_PATTERN, $month)) {
            $output->writeln('<error>Invalid --month; expected YYYY-MM.</error>');
            return Command::FAILURE;
        }

        $output->writeln('Active provider: ' . $this->getActiveProviderName());
        $rows = $this->usageLedger->getRows($month);
        if ($rows === []) {
            $output->writeln(sprintf('No usage recorded for %s.', $month));
            return Command::SUCCESS;
        }

        $total = ['requests' => 0, 'cache_hits' => 0, 'input_tokens' => 0, 'output_tokens' => 0];
        $table = new Table($output);
        $table->setHeaders(['Consumer', 'Requests', 'Cache hits', 'Input tokens', 'Output tokens']);
        foreach ($rows as $row) {
            $table->addRow([
                $row['consumer'],
                $row['requests'],
                $row['cache_hits'],
                $row['input_tokens'],
                $row['output_tokens'],
            ]);
            foreach ($total as $column => $sum) {
                $total[$column] = $sum + $row[$column];
            }
        }
        $table->addRow(['Total', ...array_values($total)]);
        $table->render();

        $output->writeln(
            $this->config->getProvider() === TypeSafeProvider::CODE
                ? sprintf(
                    'Estimated cost: $%.4f',
                    // Same base as the admin usage line (UsageInterface::getMonthTokens()).
                    ($total['input_tokens'] + $total['output_tokens']) * UsageLedger::USD_PER_MILLION_TOKENS / 1000000
                )
                : "Cost: see your provider's billing"
        );

        $budget = $this->config->getMonthlyTokenBudget();
        if ($month === $currentMonth && $budget > 0) {
            $output->writeln(sprintf(
                'Monthly budget: %d tokens, remaining %d',
                $budget,
                (int)$this->usageLedger->getRemainingBudget()
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Label and code of the active provider; the bare code when it is not registered.
     *
     * @return string
     */
    private function getActiveProviderName(): string
    {
        $code = $this->config->getProvider();
        try {
            return sprintf('%s (%s)', $this->providerPool->get($code)->getLabel(), $code);
        } catch (NotConfiguredException) {
            return $code;
        }
    }
}
