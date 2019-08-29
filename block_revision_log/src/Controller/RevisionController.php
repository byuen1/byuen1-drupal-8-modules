<?php

namespace Drupal\block_revision_log\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Utility\Xss;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\block_content\BlockContentInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Returns a response for a block entity route on revisions.
 */
class RevisionController extends ControllerBase {

  /**
   * Drupal's entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entity_manager;
  /**
   * Drupal's language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManager
   */
  protected $language_manager;
  /**
   * Drupal's current route match service.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected $current_route_match;
  /**
   * Drupal's database service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Creates the RevisionController object, extracts the services we need, and passes it to the constructor.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The services container.
   */
  public static function create(ContainerInterface $container) {
    $entity_manager = $container->get('entity.manager');
    $language_manager = $container->get('language_manager');
    $current_route_match = $container->get('current_route_match');
    $database = $container->get('database');
    return new static($entity_manager, $language_manager, $current_route_match, $database);
  }
  /**
   * Constructs a RevisionController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity manager service.
   * @param \Drupal\Core\Language\LanguageManager $language_manager
   *   The language manager service.
   * @param \Drupal\Core\Routing\CurrentRouteMatch $current_route_match
   *   The current route match service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database service.
   */
  public function __construct(EntityTypeManagerInterface $entity_manager, LanguageManager $language_manager, CurrentRouteMatch $current_route_match, Connection $database) {
    $this->entity_manager = $entity_manager;
    $this->language_manager = $language_manager;
    $this->current_route_match = $current_route_match;
    $this->database = $database;
  }

  /**
   * Generates an overview table of older revisions of a media.
   *
   * @return array
   *   An array as expected by \Drupal\Core\Render\RendererInterface::render().
   */
  public function revisionOverview() {
    // Gets loaded block object from path parameter.
    $block = $this->current_route_match->getParameter('block_content');
    // Gets the media ID.
    if ($block instanceof \Drupal\block_content\BlockContentInterface) {
      $bid = $block->id();
    }
    // Gets the cache tag for the corresponding media.
    $cache_tag = $block->getCacheTags();
    // Gets the language code the user is on.
    $language = $this->language_manager->getCurrentLanguage()->getId();
    // Gets the full language name the user is on.
    $langname = $this->language_manager->getCurrentLanguage()->getName();
    // Sets the title of the revision log for the media and indicates which language translation it is for.
  //  $build['title'] = $this->t('@langname revisions for %title', ['@langname' => $langname, '%title' => $block->label()]);
      $build[] = [
        'title'=> ['#markup'=>
          $this->t('@langname revisions for %title', ['@langname' => $langname, '%title' => $block->label()])
        ]
      ];
    // Sets the amount of columns for the table from the render array.
    $header = [$this->t('Revision')];
    // Creates a new storage instance of the Media entity.
    $block_storage = $this->entity_manager->getStorage('block_content');
    // Loops through the revision Ids of the media, filters relevant revisions, and populates the table through the $rows array.
    foreach ($this->getRevisionIds($block, $block_storage) as $vid) {
      // Gets the Media revision.
      $revision = $block_storage->loadRevision($vid);
   //   kint($revision);
      // Gets the timestamp of the revision.
      $changed = $this->getChangedTimestamp($bid, $revision, $language);
      // Filters revisions that are relevant to the language the user is on.
      if ($revision->hasTranslation($language) && $revision->getTranslation($language)->isRevisionTranslationAffected()) {
   //     $username = $revision->getRevisionUser()->realname;
    //    $username2 = $revision->revision_user->value;
        $username2 = $revision->get('revision_user')->getString();
        $bruce = $this->userName($username2);
        $date = date('m/d/Y - H:i', $changed);
        $row = [];
        $column = [
 		      'data' => [
 		        '#type' => 'inline_template',
 		        '#template' => '{% trans %}{{ date }} by {{ username }}{% endtrans %}{% if message %}<p class="revision-log">{{ message }}</p>{% endif %}',
 		        '#context' => [
 		          'date' => $date,
              // Replace $username2 with $username if the users on your site are configured with a realname field (e.g. 'Bruce Yuen' instead of 'bruce.yuen').
 		          'username' => $bruce,
 		          'message' => ['#markup' => $revision->revision_log->value, '#allowed_tags' => Xss::getHtmlTagList()],
 		        ],
 		      ],
        ];
        $row[] = $column;
        $rows[] = $row;
      }
    }
    // Define the render array
    $build['block_revisions_table'] = [
      '#theme' => 'table',
      '#rows' => $rows,
      '#header' => $header,
      '#attached' => [
        'library' => ['node/drupal.node.admin'],
      ],
      // Cache tag to invalidate the revision log when the corresponding block changes.
      '#cache' => [
        'tags' => [$cache_tag[0]],
      ],
      '#attribute' => ['class' => 'node-revision-table'],
    ];
    // Adds pagination.
    $build[] = ['#type' => 'pager'];
    return $build;
  }

  /**
   * Gets an array of all the revision Ids for a peticular media.
   */
  public function getRevisionIds(BlockContentInterface $block, EntityStorageInterface $block_storage) {
    $result = $block_storage->getQuery()
      ->allRevisions()
      ->condition($block->getEntityType()->getKey('id'), $block->id())
      ->sort($block->getEntityType()->getKey('revision'), 'DESC')
      ->pager(50)
      ->execute();
    return array_keys($result);
  }

  /**
   * Gets the correct timestamp for a revision.
   */
  public function getChangedTimestamp($bid, EntityInterface $revision, $language) {
    // Using query that wants revisions that match the media ID, revision ID, and language user is on.
  	$query = $this->database->select('block_content_field_revision', 'a')
  	  ->fields('a', ['changed'])
  	  ->condition('a.id', $bid, '=')
  	  ->condition('a.revision_id', $revision->revision_id->value, '=')
  	  ->condition('a.langcode', $language, '=')
  	  ->orderBy('a.revision_id', 'DESC');
    // Obtaining a MySQL object.
  	$results = $query->execute();
    // Get the timestamp from the 'changed' field of the media_field_revision table.
  	foreach ($results as $record) {
  	  $changed = $record->changed;
  	}
  	return $changed;
  }

  /**
   * Gets the username from the uid
   *
   * @return The username
   */
  public function userName($uid) {
    $userStorage = $this->entity_manager->getStorage('user');
    $account = $userStorage->load($uid);
 //   $account = \Drupal\user\Entity\User::load($uid);
    if ($account !== NULL) {
      $name = $account->getUsername();
      return $name;
    }
    else {
      $name = 'Unknown';
      return $name;
    }
  }
}

