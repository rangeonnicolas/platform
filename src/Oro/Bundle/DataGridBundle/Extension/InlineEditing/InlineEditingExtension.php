<?php

namespace Oro\Bundle\DataGridBundle\Extension\InlineEditing;

use Oro\Bundle\DataGridBundle\Extension\AbstractExtension;
use Oro\Bundle\DataGridBundle\Datagrid\Common\MetadataObject;
use Oro\Bundle\DataGridBundle\Datagrid\Common\DatagridConfiguration;
use Oro\Bundle\DataGridBundle\Extension\Formatter\Configuration as FormatterConfiguration;
use Oro\Bundle\DataGridBundle\Extension\Formatter\Property\PropertyInterface;
use Oro\Bundle\SecurityBundle\SecurityFacade;
use Oro\Bundle\EntityBundle\Tools\EntityClassNameHelper;

class InlineEditingExtension extends AbstractExtension
{
    /** @var InlineEditColumnOptionsGuesser */
    protected $guesser;

    /** @var SecurityFacade */
    protected $securityFacade;

    /** @var EntityClassNameHelper */
    protected $entityClassNameHelper;

    /**
     * @param InlineEditColumnOptionsGuesser $inlineEditColumnOptionsGuesser
     * @param SecurityFacade $securityFacade
     * @param EntityClassNameHelper $entityClassNameHelper
     */
    public function __construct(
        InlineEditColumnOptionsGuesser $inlineEditColumnOptionsGuesser,
        SecurityFacade $securityFacade,
        EntityClassNameHelper $entityClassNameHelper
    ) {
        $this->securityFacade = $securityFacade;
        $this->guesser = $inlineEditColumnOptionsGuesser;
        $this->entityClassNameHelper = $entityClassNameHelper;
    }

    /**
     * {@inheritdoc}
     */
    public function isApplicable(DatagridConfiguration $config)
    {
        return $config->offsetGetByPath(Configuration::ENABLED_CONFIG_PATH);
    }

    /**
     * Validate configs nad fill default values
     *
     * @param DatagridConfiguration $config
     */
    public function processConfigs(DatagridConfiguration $config)
    {
        $configItems   = $config->offsetGetOr(Configuration::BASE_CONFIG_KEY, []);
        $configuration = new Configuration(Configuration::BASE_CONFIG_KEY);

        $normalizedConfigItems = $this->validateConfiguration(
            $configuration,
            [Configuration::BASE_CONFIG_KEY => $configItems]
        );

        $isGranted = $this->securityFacade->isGranted('EDIT', 'entity:' . $configItems['entity_name']);
        //according to ACL disable inline editing for the whole grid
        if (!$isGranted) {
            $normalizedConfigItems[Configuration::CONFIG_KEY_ENABLE] = false;
        }

        // replace config values by normalized, extra keys passed directly
        $resultConfigItems = array_replace_recursive($configItems, $normalizedConfigItems);
        if (is_null($resultConfigItems['save_api_accessor']['default_route_parameters']['className'])) {
            $resultConfigItems['save_api_accessor']['default_route_parameters']['className'] =
                $this->entityClassNameHelper->getUrlSafeClassName($configItems['entity_name']);
        }
        $config->offsetSet(Configuration::BASE_CONFIG_KEY, $resultConfigItems);

        //add inline editing where it is possible, do not use ACL, because additional parameters for columns needed
        $columns = $config->offsetGetOr(FormatterConfiguration::COLUMNS_KEY, []);
        $blackList = $configuration->getBlackList();

        foreach ($columns as $columnName => &$column) {
            if (!in_array($columnName, $blackList)) {
                $newColumn = $this->guesser->getColumnOptions($columnName, $configItems['entity_name'], $column);

                //frontend type key must not be replaced with default value
                $typeKey = PropertyInterface::FRONTEND_TYPE_KEY;
                if (!empty($newColumn[$typeKey])) {
                    $column[$typeKey] = $newColumn[$typeKey];
                }

                $column = array_replace_recursive($newColumn, $column);
            }
        }

        $config->offsetSet(FormatterConfiguration::COLUMNS_KEY, $columns);
    }

    /**
     * {@inheritDoc}
     */
    public function visitMetadata(DatagridConfiguration $config, MetadataObject $data)
    {
        $data->offsetSet(
            Configuration::BASE_CONFIG_KEY,
            $config->offsetGetOr(Configuration::BASE_CONFIG_KEY, [])
        );
    }
}
