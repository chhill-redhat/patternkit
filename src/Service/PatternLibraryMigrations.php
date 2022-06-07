<?php

namespace Drupal\patternkit\Service;

use Drupal\block\BlockInterface;
use Drupal\Core\Block\BlockManager;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;
use Drupal\patternkit\Asset\LibraryInterface;
use Drupal\patternkit\Entity\Pattern;
use Drupal\patternkit\Plugin\Derivative\PatternkitBlock;

class PatternLibraryMigrations {
  use StringTranslationTrait;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * @var \Drupal\Core\Block\BlockManager
   */
  protected BlockManager $blockManager;

  /**
   * Block storage manager.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $blockStorage;

  /**
   * Pattern storage manager.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $patternStorage;

  /**
   * @var \Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface
   */
  protected SectionStorageManagerInterface $sectionStorageManager;

  /**
   * @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface
   */
  protected KeyValueExpirableFactoryInterface $keyValueFactory;

  /**
   * @var \Drupal\layout_builder\LayoutTempstoreRepositoryInterface
   */
  protected LayoutTempstoreRepositoryInterface $tempstoreRepository;

  /**
   * Asset Library manager.
   *
   * @var \Drupal\patternkit\Asset\LibraryInterface
   */
  protected LibraryInterface $library;

  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    ModuleHandlerInterface $module_handler,
    EntityTypeManagerInterface $entity_type_manager,
    BlockManager $block_manager,
    SectionStorageManagerInterface $section_storage_manager,
    LayoutTempstoreRepositoryInterface $tempstore_repository,
    KeyValueExpirableFactoryInterface $key_value_factory,
    LibraryInterface $library
  ) {
    $this->logger = $logger_factory->get('patternkit');
    $this->moduleHandler = $module_handler;
    $this->entityTypeManager = $entity_type_manager;
    $this->blockManager = $block_manager;

    $this->blockStorage = $entity_type_manager->getStorage('patternkit_block');
    $this->patternStorage = $entity_type_manager->getStorage('patternkit_pattern');
    $this->sectionStorageManager = $section_storage_manager;
    $this->tempstoreRepository = $tempstore_repository;
    $this->keyValueFactory = $key_value_factory;
    $this->library = $library;
  }

  /**
   * Update all patterns in a library.
   *
   * @return bool
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function doLibraryUpdates() {
    $lb_enabled = $this->moduleHandler->moduleExists('layout_builder');

    $entity_count = 0;
    $block_count = 0;

    $entity_type_manager = $this->entityTypeManager;
    $block_storage = $this->blockStorage;

    if ($lb_enabled) {
      $storage_definitions = $this->sectionStorageManager->getDefinitions();
      $section_storages = [];
      // Gather section storages from all entities and entity type layouts.
      /** @var \Drupal\Core\Config\Entity\ConfigEntityStorageInterface $view_mode_storage */
      $display_storage = $entity_type_manager->getStorage('entity_view_display');
      $displays = $display_storage->loadMultiple();
      /** @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $display */
      foreach ($displays as $display) {
        if (!$display instanceof LayoutBuilderEntityViewDisplay) {
          continue;
        }
        if (!$display->isLayoutBuilderEnabled()) {
          continue;
        }

        foreach ($storage_definitions as $section_storage_type => $storage_definition) {
          $contexts = [];
          $contexts['display'] = EntityContext::fromEntity($display);
          $contexts['view_mode'] = new Context(new ContextDefinition('string'), $display->getMode());
          // Gathers entity type layouts.
          if ($section_storage = $this->sectionStorageManager->load($section_storage_type, $contexts)) {
            $section_storages[] = $section_storage;
          }

          // Gathers entity layouts.
          $entity_storage = $entity_type_manager->getStorage($display->getTargetEntityTypeId());
          foreach ($entity_storage->loadMultiple() as $entity) {
            $contexts['entity'] = EntityContext::fromEntity($entity);
            $section_storages[] = $this->sectionStorageManager->findByContext($contexts, new CacheableMetadata());
          }
        }
      }

      // Gather section storages from the tempstore, to update layout drafts.
      foreach (array_keys($storage_definitions) as $section_storage_type) {
        $key_value = $this->keyValueFactory->get("tempstore.shared.layout_builder.section_storage.$section_storage_type");

        foreach ($key_value->getAll() as $key => $value) {
          $key = substr($key, 0, strpos($key, '.', strpos($key, '.') + 1));
          $contexts = $this->sectionStorageManager->loadEmpty($section_storage_type)
            ->deriveContextsFromRoute($key, [], '', []);
          $section_storages[] = $value->data['section_storage'];
          if ($section_storage = $this->sectionStorageManager->load($section_storage_type, $contexts)) {
            $section_storages[] = $section_storage;
          }
        }
      }

      foreach ($section_storages as $section_storage) {
        foreach ($section_storage->getSections() as $section_delta => $section) {
          /** @var \Drupal\block\BlockInterface $component */
          foreach ($section->getComponents() as $component_delta => $component) {
            if (!$configuration = $this->updateBlockComponentPluginPattern($component)) {
              continue;
            }
            $section_storage
              ->getSection($section_delta)
              ->getComponent($component->getUuid())
              ->setConfiguration($configuration);
            $block_count++;
          }
          $section_storage->save();
          $this->tempstoreRepository->set($section_storage);
          $entity_count++;
        }
      }
    }
    /** @var \Drupal\block\BlockInterface $block */
    foreach ($block_storage->loadMultiple() as $block) {
      if (!$block instanceof BlockInterface) {
        continue;
      }
      $plugin = $block->getPlugin();
      $this->updateBlockComponentPluginPattern($plugin);
      $block_count++;
    }

    $this->logger->notice($this->t('Parsed @entities entity layouts with @blocks Patternkit blocks.',
      ['@entities' => $entity_count, '@blocks' => $block_count]));
    $this->blockManager->clearCachedDefinitions();
    $entity_type_manager->clearCachedDefinitions();
    $this->logger->notice($this->t('Completed running Patternkit library updates.'));
    return true;
  }

  /**
   * Updates a Patternkit Block Component Plugin's Pattern to latest.
   *
   * @param \Drupal\block\BlockInterface|\Drupal\layout_builder\SectionComponent $component
   *   The block plugin or component to update.
   * @param null|string $library_name
   *   The name of the library to match against, or NULL to skip matching.
   *
   * @return false|array The updated configuration, or FALSE if it failed.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function updateBlockComponentPluginPattern($component, $library_name = NULL) {
    $block_storage = $this->blockStorage;
    $pattern_storage = $this->patternStorage;
    $library = $this->library;

    $plugin_id = $component->getPluginId();
    if (strpos($plugin_id, 'patternkit') === FALSE) {
      return FALSE;
    }
    $configuration = $component->get('configuration');
    if (!isset($configuration['patternkit_block_id'])
      && !((int) $configuration['patternkit_block_id'] > 0)) {
      return FALSE;
    }
    /** @var \Drupal\patternkit\Entity\PatternkitBlock $patternkit_block */
    if (isset($configuration['patternkit_block_rid'])
      && (int) $configuration['patternkit_block_rid'] > 0) {
      $patternkit_block = $block_storage->loadRevision($configuration['patternkit_block_rid']);
      if ($patternkit_block === NULL) {
        return FALSE;
      }
    } else {
      $patternkit_block = $block_storage->load($configuration['patternkit_block_id']);
      if ($patternkit_block === NULL) {
        return FALSE;
      }
      $configuration['patternkit_block_rid'] = $patternkit_block->getLoadedRevisionId();
    }
    $this->logger->notice($this->t('Updating block plugin with id @plugin:',
      ['@plugin' => $plugin_id]));
    try {
      $plugin = $component->getPlugin();

      $pattern_id = PatternkitBlock::derivativeToAssetId($plugin->getDerivativeId());
      /** @var \Drupal\patternkit\entity\PatternInterface $pattern */
      if (!empty($configuration['pattern'])) {
        $pattern = $pattern_storage->loadRevision($configuration['pattern']);
      } else {
        $pattern = $library->getLibraryAsset($pattern_id);
      }
    } catch (\Exception $exception) {
      $this->logger->error($this->t('Unable to load the pattern @pattern. Check the logs for more info.', ['@pattern' => $pattern_id ?? $plugin->getPluginId()]));
      return FALSE;
    }
    if ($library_name && $pattern->getLibrary() !== $library_name) {
      return FALSE;
    }
    if (!$asset = $library->getLibraryAsset($pattern_id)) {
      $this->logger->error($this->t("Failed to get library asset for @pattern.", ['@pattern' => $pattern_id]));
      return FALSE;
    }
    $base_pattern = Pattern::create($asset);
    if ($base_pattern === NULL) {
      return FALSE;
    }
    if ($base_pattern->getHash() === $pattern->getHash()) {
      return FALSE;
    }
    $this->logger->notice(t('Updating pattern from @old to @new.',
      ['@old' => $pattern->getVersion(), '@new' => $base_pattern->getVersion()]));
    $pattern->setNewRevision();
    $pattern->isDefaultRevision(TRUE);
    $pattern->setSchema($base_pattern->getSchema());
    $pattern->setTemplate(($base_pattern->getTemplate()));
    $pattern->setVersion($base_pattern->getVersion());
    $pattern->save();
    $configuration['pattern'] = $pattern->getRevisionId();
    /** @var \Drupal\patternkit\Entity\PatternkitBlock $patternkit_block */
    $patternkit_block = $block_storage->load($configuration['patternkit_block_id']);
    $configuration['patternkit_block_rid'] = $patternkit_block->getRevisionId();
    return $configuration;
  }

}
