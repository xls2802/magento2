<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Indexer\Console\Command;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ObjectManagerFactory;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\Config\DependencyInfoProvider;
use Magento\Framework\Indexer\ConfigInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Indexer\StateInterface;
use Magento\Indexer\Model\Processor\MakeSharedIndexValid;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to run indexers
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class IndexerReindexCommand extends AbstractIndexerManageCommand
{
    /**
     * @var array
     */
    private $sharedIndexesComplete = [];

    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var IndexerRegistry
     */
    private $indexerRegistry;

    /**
     * @var DependencyInfoProvider|null
     */
    private $dependencyInfoProvider;

    /**
     * @var MakeSharedIndexValid|null
     */
    private $makeSharedValid;

    /**
     * @param ObjectManagerFactory $objectManagerFactory
     * @param IndexerRegistry|null $indexerRegistry
     * @param DependencyInfoProvider|null $dependencyInfoProvider
     * @param MakeSharedIndexValid|null $makeSharedValid
     */
    public function __construct(
        ObjectManagerFactory $objectManagerFactory,
        IndexerRegistry $indexerRegistry = null,
        DependencyInfoProvider $dependencyInfoProvider = null,
        MakeSharedIndexValid $makeSharedValid = null
    ) {
        $this->indexerRegistry = $indexerRegistry;
        $this->dependencyInfoProvider = $dependencyInfoProvider;
        $this->makeSharedValid = $makeSharedValid ?: ObjectManager::getInstance()->get(MakeSharedIndexValid::class);
        parent::__construct($objectManagerFactory);
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName('indexer:reindex')
            ->setDescription('Reindexes Data')
            ->setDefinition($this->getInputList());

        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $returnValue = Cli::RETURN_FAILURE;
        foreach ($this->getIndexers($input) as $indexer) {
            try {
                $this->validateIndexerStatus($indexer);

                $output->write($indexer->getTitle() . ' index ');

                $startTime = microtime(true);
                $indexerConfig = $this->getConfig()->getIndexer($indexer->getId());
                $sharedIndex = $indexerConfig['shared_index'] ?? null;

                // Skip indexers having shared index that was already complete
                if (!in_array($sharedIndex, $this->sharedIndexesComplete)) {
                    $indexer->reindexAll();
                    if (!empty($sharedIndex) && $this->makeSharedValid->execute($sharedIndex)) {
                        $this->sharedIndexesComplete[] = $sharedIndex;
                    }
                }
                $resultTime = microtime(true) - $startTime;

                $output->writeln(
                    __('has been rebuilt successfully in %time', ['time' => gmdate('H:i:s', $resultTime)])
                );
                $returnValue = Cli::RETURN_SUCCESS;
            } catch (LocalizedException $e) {
                $output->writeln(__('exception: %message', ['message' => $e->getMessage()]));
            } catch (\Exception $e) {
                $output->writeln('process unknown error:');
                $output->writeln($e->getMessage());

                $output->writeln($e->getTraceAsString(), OutputInterface::VERBOSITY_DEBUG);
            }
        }

        return $returnValue;
    }

    /**
     * @inheritdoc
     *
     * Returns the ordered list of specified indexers and related indexers.
     */
    protected function getIndexers(InputInterface $input)
    {
        $indexers = parent::getIndexers($input);
        $allIndexers = $this->getAllIndexers();
        if (!array_diff_key($allIndexers, $indexers)) {
            return $indexers;
        }

        $relatedIndexers = [[]];
        $dependentIndexers = [[]];

        foreach ($indexers as $indexer) {
            $relatedIndexers[] = $this->getRelatedIndexerIds($indexer->getId());
            $dependentIndexers[] = $this->getDependentIndexerIds($indexer->getId());
        }

        $relatedIndexers = $relatedIndexers ? array_unique(array_merge(...$relatedIndexers)) : [];
        $dependentIndexers = $dependentIndexers ? array_merge(...$dependentIndexers) : [];

        $invalidRelatedIndexers = [];
        foreach ($relatedIndexers as $relatedIndexer) {
            if ($allIndexers[$relatedIndexer]->isInvalid()) {
                $invalidRelatedIndexers[] = $relatedIndexer;
            }
        }

        return array_intersect_key(
            $allIndexers,
            array_flip(
                array_unique(
                    array_merge(
                        array_keys($indexers),
                        $invalidRelatedIndexers,
                        $dependentIndexers
                    )
                )
            )
        );
    }

    /**
     * Return all indexer Ids on which the current indexer depends (directly or indirectly).
     *
     * @param string $indexerId
     * @return array
     */
    private function getRelatedIndexerIds(string $indexerId): array
    {
        $relatedIndexerIds = [[]];
        foreach ($this->getDependencyInfoProvider()->getIndexerIdsToRunBefore($indexerId) as $relatedIndexerId) {
            $relatedIndexerIds[] = [$relatedIndexerId];
            $relatedIndexerIds[] = $this->getRelatedIndexerIds($relatedIndexerId);
        }
        $relatedIndexerIds = $relatedIndexerIds ? array_unique(array_merge(...$relatedIndexerIds)) : [];

        return $relatedIndexerIds;
    }

    /**
     * Return all indexer Ids which depend on the current indexer (directly or indirectly).
     *
     * @param string $indexerId
     * @return array
     */
    private function getDependentIndexerIds(string $indexerId): array
    {
        $dependentIndexerIds = [[]];
        foreach (array_keys($this->getConfig()->getIndexers()) as $id) {
            $dependencies = $this->getDependencyInfoProvider()->getIndexerIdsToRunBefore($id);
            if (array_search($indexerId, $dependencies) !== false) {
                $dependentIndexerIds[] = [$id];
                $dependentIndexerIds[] = $this->getDependentIndexerIds($id);
            }
        }
        $dependentIndexerIds = $dependentIndexerIds ? array_unique(array_merge(...$dependentIndexerIds)) : [];

        return $dependentIndexerIds;
    }

    /**
     * Validate that indexer is not locked
     *
     * @param IndexerInterface $indexer
     * @return void
     * @throws LocalizedException
     */
    private function validateIndexerStatus(IndexerInterface $indexer)
    {
        if ($indexer->getStatus() == StateInterface::STATUS_WORKING) {
            throw new LocalizedException(
                __(
                    '%1 index is locked by another reindex process. Skipping.',
                    $indexer->getTitle()
                )
            );
        }
    }

    /**
     * Get config
     *
     * @return ConfigInterface
     * @deprecated 100.1.0
     */
    private function getConfig()
    {
        if (!$this->config) {
            $this->config = $this->getObjectManager()->get(ConfigInterface::class);
        }
        return $this->config;
    }

    /**
     * Get dependency info provider
     *
     * @return DependencyInfoProvider
     * @deprecated 100.2.0
     */
    private function getDependencyInfoProvider()
    {
        if (!$this->dependencyInfoProvider) {
            $this->dependencyInfoProvider = $this->getObjectManager()->get(DependencyInfoProvider::class);
        }
        return $this->dependencyInfoProvider;
    }
}
