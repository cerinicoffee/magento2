<?php
/**
 * Initialize application object manager.
 *
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @copyright   Copyright (c) 2014 X.commerce, Inc. (http://www.magentocommerce.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
namespace Magento\App;

use Magento\App\Filesystem\DirectoryList;
use Magento\Profiler;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * Class ObjectManagerFactory
 * @package Magento\App
 */
class ObjectManagerFactory
{
    /**
     * Locator class name
     *
     * @var string
     */
    protected $_locatorClassName = 'Magento\ObjectManager\ObjectManager';

    /**
     * Config class name
     *
     * @var string
     */
    protected $_configClassName = 'Magento\Interception\ObjectManager\Config';

    /**
     * Factory
     *
     * @var \Magento\ObjectManager\Factory
     */
    protected $factory;

    /**
     * Create object manager
     *
     * @param string $rootDir
     * @param array $arguments
     * @return \Magento\ObjectManager\ObjectManager
     * @throws \Magento\BootstrapException
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function create($rootDir, array $arguments)
    {
        $directories = isset(
            $arguments[Filesystem::PARAM_APP_DIRS]
        ) ? $arguments[Filesystem::PARAM_APP_DIRS] : array();
        $directoryList = new DirectoryList($rootDir, $directories);

        \Magento\Autoload\IncludePath::addIncludePath(array($directoryList->getDir(Filesystem::GENERATION_DIR)));

        $appArguments = $this->createAppArguments($directoryList, $arguments);

        $definitionFactory = new \Magento\ObjectManager\DefinitionFactory(
            new \Magento\Filesystem\Driver\File(),
            $directoryList->getDir(Filesystem::DI_DIR),
            $directoryList->getDir(Filesystem::GENERATION_DIR),
            $appArguments->get('definition.format', 'serialized')
        );

        $definitions = $definitionFactory->createClassDefinition($appArguments->get('definitions'));
        $relations = $definitionFactory->createRelations();
        $configClass = $this->_configClassName;
        /** @var \Magento\ObjectManager\Config\Config $diConfig */
        $diConfig = new $configClass($relations, $definitions);
        $appMode = $appArguments->get(State::PARAM_MODE, State::MODE_DEFAULT);

        $booleanUtils = new \Magento\Stdlib\BooleanUtils();
        $argInterpreter = $this->createArgumentInterpreter($booleanUtils);

        $argumentMapper = new \Magento\ObjectManager\Config\Mapper\Dom($argInterpreter);
        $configData = $this->_loadPrimaryConfig($directoryList, $argumentMapper, $appMode);

        if ($configData) {
            $diConfig->extend($configData);
        }

        $this->factory = new \Magento\ObjectManager\Factory\Factory(
            $diConfig,
            null,
            $definitions,
            $appArguments->get()
        );

        $className = $this->_locatorClassName;

        $sharedInstances = [
            'Magento\App\Arguments' => $appArguments,
            'Magento\App\Filesystem\DirectoryList' => $directoryList,
            'Magento\Filesystem\DirectoryList' => $directoryList,
            'Magento\ObjectManager\Relations' => $relations,
            'Magento\Interception\Definition' => $definitionFactory->createPluginDefinition(),
            'Magento\ObjectManager\Config' => $diConfig,
            'Magento\ObjectManager\Definition' => $definitions,
            'Magento\Stdlib\BooleanUtils' => $booleanUtils,
            'Magento\ObjectManager\Config\Mapper\Dom' => $argumentMapper,
            $configClass => $diConfig
        ];

        /** @var \Magento\ObjectManager $objectManager */
        $objectManager = new $className($this->factory, $diConfig, $sharedInstances);

        $this->factory->setObjectManager($objectManager);
        ObjectManager::setInstance($objectManager);

        /** @var \Magento\App\Filesystem\DirectoryList\Verification $verification */
        $verification = $objectManager->get('Magento\App\Filesystem\DirectoryList\Verification');
        $verification->createAndVerifyDirectories();

        $diConfig->setCache($objectManager->get('Magento\App\ObjectManager\ConfigCache'));
        $objectManager->configure($objectManager->get('Magento\App\ObjectManager\ConfigLoader')->load('global'));
        $objectManager->get('Magento\Config\ScopeInterface')->setCurrentScope('global');
        $objectManager->get('Magento\App\Resource')->setCache($objectManager->get('Magento\App\CacheInterface'));
        $interceptionConfig = $objectManager->get('Magento\Interception\Config\Config');
        $diConfig->setInterceptionConfig($interceptionConfig);

        $this->configureDirectories($objectManager);

        return $objectManager;
    }

    /**
     * Create instance of application arguments
     *
     * @param DirectoryList $directoryList
     * @param array $arguments
     * @return Arguments
     */
    protected function createAppArguments(DirectoryList $directoryList, array $arguments)
    {
        return new Arguments(
            $arguments,
            new \Magento\App\Arguments\Loader(
                $directoryList,
                isset(
                    $arguments[\Magento\App\Arguments\Loader::PARAM_CUSTOM_FILE]
                ) ? $arguments[\Magento\App\Arguments\Loader::PARAM_CUSTOM_FILE] : null
            )
        );
    }

    /**
     * Return newly created instance on an argument interpreter, suitable for processing DI arguments
     *
     * @param \Magento\Stdlib\BooleanUtils $booleanUtils
     * @return \Magento\Data\Argument\InterpreterInterface
     */
    protected function createArgumentInterpreter(
        \Magento\Stdlib\BooleanUtils $booleanUtils
    ) {
        $constInterpreter = new \Magento\Data\Argument\Interpreter\Constant();
        $result = new \Magento\Data\Argument\Interpreter\Composite(
            [
                'boolean' => new \Magento\Data\Argument\Interpreter\Boolean($booleanUtils),
                'string' => new \Magento\Data\Argument\Interpreter\String($booleanUtils),
                'number' => new \Magento\Data\Argument\Interpreter\Number(),
                'null' => new \Magento\Data\Argument\Interpreter\NullType(),
                'object' => new \Magento\Data\Argument\Interpreter\Object($booleanUtils),
                'const' => $constInterpreter,
                'init_parameter' => new \Magento\App\Arguments\ArgumentInterpreter($constInterpreter)
            ],
            \Magento\ObjectManager\Config\Reader\Dom::TYPE_ATTRIBUTE
        );
        // Add interpreters that reference the composite
        $result->addInterpreter('array', new \Magento\Data\Argument\Interpreter\ArrayType($result));
        return $result;
    }

    /**
     * @param \Magento\ObjectManager $objectManager
     * @return void
     */
    protected function configureDirectories(\Magento\ObjectManager $objectManager)
    {
        $directoryList = $objectManager->get('Magento\App\Filesystem\DirectoryList');
        $directoryListConfig = $objectManager->get('Magento\App\Filesystem\DirectoryList\Configuration');
        $directoryListConfig->configure($directoryList);
    }

    /**
     * Load primary config
     *
     * @param DirectoryList $directoryList
     * @param mixed $argumentMapper
     * @param string $appMode
     * @return array
     * @throws \Magento\BootstrapException
     */
    protected function _loadPrimaryConfig(DirectoryList $directoryList, $argumentMapper, $appMode)
    {
        $configData = null;
        try {
            $fileResolver = new \Magento\App\Arguments\FileResolver\Primary(
                new \Magento\App\Filesystem(
                    $directoryList,
                    new \Magento\Filesystem\Directory\ReadFactory(),
                    new \Magento\Filesystem\Directory\WriteFactory()
                ),
                new \Magento\Config\FileIteratorFactory()
            );
            $schemaLocator = new \Magento\ObjectManager\Config\SchemaLocator();
            $validationState = new \Magento\App\Arguments\ValidationState($appMode);

            $reader = new \Magento\ObjectManager\Config\Reader\Dom(
                $fileResolver,
                $argumentMapper,
                $schemaLocator,
                $validationState
            );
            $configData = $reader->read('primary');
        } catch (\Exception $e) {
            throw new \Magento\BootstrapException($e->getMessage());
        }
        return $configData;
    }

    /**
     * Crete plugin list object
     *
     * @param \Magento\ObjectManager $objectManager
     * @param \Magento\ObjectManager\Relations $relations
     * @param \Magento\ObjectManager\DefinitionFactory $definitionFactory
     * @param \Magento\ObjectManager\Config\Config $diConfig
     * @param \Magento\ObjectManager\Definition $definitions
     * @return \Magento\Interception\PluginList\PluginList
     */
    protected function _createPluginList(
        \Magento\ObjectManager $objectManager,
        \Magento\ObjectManager\Relations $relations,
        \Magento\ObjectManager\DefinitionFactory $definitionFactory,
        \Magento\ObjectManager\Config\Config $diConfig,
        \Magento\ObjectManager\Definition $definitions
    ) {
        return $objectManager->create(
            'Magento\Interception\PluginList\PluginList',
            [
                'relations' => $relations,
                'definitions' => $definitionFactory->createPluginDefinition(),
                'omConfig' => $diConfig,
                'classDefinitions' => $definitions instanceof
                \Magento\ObjectManager\Definition\Compiled ? $definitions : null
            ]
        );
    }
}
