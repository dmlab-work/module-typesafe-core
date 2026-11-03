<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Console\Command;

use DmLab\TypeSafeCore\Exception\TypeSafeException;
use DmLab\TypeSafeCore\Model\Config;
use DmLab\TypeSafeCore\Model\Provider\ProviderPool;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use DmLab\TypeSafeCore\Model\Provider\TypeSafe\TypeSafeProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `dmlab:typesafe:ping [--provider=<code>]` — shows a provider's saved settings and lists its models.
 */
class PingCommand extends Command
{
    public const NAME = 'dmlab:typesafe:ping';
    public const OPTION_PROVIDER = 'provider';

    /**
     * @param Config $config
     * @param ProviderPool $providerPool
     * @param ProviderSettings $providerSettings
     */
    public function __construct(
        private readonly Config $config,
        private readonly ProviderPool $providerPool,
        private readonly ProviderSettings $providerSettings
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Checks an AI provider connection and lists the available models.')
            ->addOption(
                self::OPTION_PROVIDER,
                null,
                InputOption::VALUE_REQUIRED,
                'Provider code; defaults to the active provider'
            );
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $code = $input->getOption(self::OPTION_PROVIDER) ?? $this->config->getProvider();
        try {
            $provider = $this->providerPool->get((string)$code);
        } catch (TypeSafeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $active = $provider->getCode() === $this->config->getProvider();
        $output->writeln(sprintf(
            'Provider: %s (%s)%s',
            $provider->getLabel(),
            $provider->getCode(),
            $active ? '' : ', not active'
        ));
        $output->writeln('Base URL: ' . $this->providerSettings->getBaseUrl($provider->getCode()));
        $model = $provider->getModel();
        $output->writeln('Configured model: ' . $model);
        $output->writeln('Enabled: ' . ($this->config->isEnabled() ? 'Yes' : 'No'));

        try {
            $models = $provider->listModels();
        } catch (TypeSafeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('OK — models: ' . implode(', ', $models));
        if (!in_array($model, $models, true)) {
            $output->writeln($provider->getCode() === TypeSafeProvider::CODE
                ? 'The list shows aliases only; pinned versions such as jev-1.13.0 are accepted too.'
                : 'The configured model is not in the list; check its id.');
        }

        return Command::SUCCESS;
    }
}
