<?php

namespace Drupal\ginvite\Plugin\GroupContentEnabler;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Plugin\GroupContentEnablerBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupContentInterface;
use Drupal\group\Access\GroupAccessResult;
use Drupal\Core\Url;

/**
 * Provides a content enabler for invitations.
 *
 * @GroupContentEnabler(
 *   id = "group_invitation",
 *   label = @Translation("Group Invitation"),
 *   description = @Translation("Creates invitations to group."),
 *   entity_type_id = "user",
 *   pretty_path_key = "invitee",
 *   reference_label = @Translation("Invitee"),
 *   reference_description = @Translation("Invited user."),
 * )
 */
class GroupInvitation extends GroupContentEnablerBase {

  /**
   * Invitation created and waiting for user's response.
   */
  const INVITATION_PENDING = 0;

  /**
   * Invitation accepted by user.
   */
  const INVITATION_ACCEPTED = 1;

  /**
   * Invitation rejected by user.
   */
  const INVITATION_REJECTED = 2;

  /**
   * {@inheritdoc}
   */
  public function getGroupOperations(GroupInterface $group) {
    $account = \Drupal::currentUser();
    $operations = [];

    if ($group->hasPermission('invite users to group', $account)) {
      $operations['invite-user'] = [
        'title' => $this->t('Invite user'),
        'url' => new Url('entity.group_content.add_form', ['group' => $group->id(), 'plugin_id' => 'group_invitation']),
        'weight' => 0,
      ];
    }

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupContentPermissions() {
    $permissions["invite users to group"] = [
      'title' => "Invite users to group",
      'description' => 'Allows users with permissions to invite new users to group.',
    ];
    $permissions["view group invitations"] = [
      'title' => "View group invitations",
      'description' => 'Allows users with permissions view created invitations.',
    ];
    $permissions["delete own invitations"] = [
      'title' => "Delete own invitations",
      'description' => 'Allows users with permissions to delete own invitations to group.',
    ];
    $permissions["delete any invitation"] = [
      'title' => "Delete any invitation",
      'description' => 'Allows users with permissions to delete any invitation to group.',
    ];

    return $permissions;
  }

  /**
   * {@inheritdoc}
   */
  public function createAccess(GroupInterface $group, AccountInterface $account) {
    return GroupAccessResult::allowedIfHasGroupPermission($group, $account, "invite users to group");
  }

  /**
   * {@inheritdoc}
   */
  protected function viewAccess(GroupContentInterface $group_content, AccountInterface $account) {
    $group = $group_content->getGroup();
    return GroupAccessResult::allowedIfHasGroupPermission($group, $account, "view group invitations");
  }

  /**
   * {@inheritdoc}
   */
  protected function updateAccess(GroupContentInterface $group_content, AccountInterface $account) {
    // Close access to edit group invitations.
    // It will not be supported for now.
    return GroupAccessResult::forbidden();
  }

  /**
   * {@inheritdoc}
   */
  protected function deleteAccess(GroupContentInterface $group_content, AccountInterface $account) {
    $group = $group_content->getGroup();

    // Allow members to delete their own group content.
    if ($group_content->getOwnerId() == $account->id()) {
      return GroupAccessResult::allowedIfHasGroupPermission($group, $account, "delete own invitations");
    }

    return GroupAccessResult::allowedIfHasGroupPermission($group, $account, "delete any invitation");
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'group_cardinality' => 0,
      'entity_cardinality' => 0,
      'use_creation_wizard' => 0,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function postInstall() {
    $group_content_type_id = $this->getContentTypeConfigId();

    // Add the group_roles field to the newly added group content type. The
    // field storage for this is defined in the config/install folder. The
    // default handler for 'group_role' target entities in the 'group_type'
    // handler group is GroupTypeRoleSelection.
    FieldConfig::create([
      'field_storage' => FieldStorageConfig::loadByName('group_content', 'group_roles'),
      'bundle' => $group_content_type_id,
      'label' => $this->t('Roles'),
      'settings' => [
        'handler' => 'group_type:group_role',
        'handler_settings' => [
          'group_type_id' => $this->getGroupTypeId(),
        ],
      ],
    ])->save();

    // Add email field.
    FieldConfig::create([
      'field_storage' => FieldStorageConfig::loadByName('group_content', 'invitee_mail'),
      'bundle' => $group_content_type_id,
      'label' => $this->t('Invitee mail'),
      'required' => TRUE,
    ])->save();

    // Add Status field.
    FieldConfig::create([
      'field_storage' => FieldStorageConfig::loadByName('group_content', 'invitation_status'),
      'bundle' => $group_content_type_id,
      'label' => $this->t('Invitation status'),
      'required' => TRUE,
      'default_value' => self::INVITATION_PENDING,
    ])->save();

    // Build the 'default' display ID for both the entity form and view mode.
    $default_display_id = "group_content.$group_content_type_id.default";

    // Build or retrieve the 'default' form mode.
    if (!$form_display = EntityFormDisplay::load($default_display_id)) {
      $form_display = EntityFormDisplay::create([
        'targetEntityType' => 'group_content',
        'bundle' => $group_content_type_id,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    }

    // Build or retrieve the 'default' view mode.
    if (!$view_display = EntityViewDisplay::load($default_display_id)) {
      $view_display = EntityViewDisplay::create([
        'targetEntityType' => 'group_content',
        'bundle' => $group_content_type_id,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    }

    // Assign widget settings for the 'default' form mode.
    $form_display
      ->setComponent('group_roles', [
        'type' => 'options_buttons',
      ])
      ->setComponent('invitee_mail', [
        'type' => 'email_default',
        'weight' => -1,
        'settings' => [
          'placeholder' => 'example@example.com',
        ],
      ])
      ->removeComponent('entity_id')
      ->removeComponent('path')
      ->save();

    // Assign display settings for the 'default' view mode.
    $view_display
      ->setComponent('group_roles', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'settings' => [
          'link' => 0,
        ],
      ])
      ->setComponent('invitee_mail', [
        'type' => 'email_mailto',
      ])
      ->setComponent('invitation_status', [
        'type' => 'number_integer',
      ])
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Disable the entity cardinality field as the functionality of this module
    // relies on a cardinality of 1. We don't just hide it, though, to keep a UI
    // that's consistent with other content enabler plugins.
    $info = $this->t("This field has been disabled by the plugin to guarantee the functionality that's expected of it.");
    $form['entity_cardinality']['#disabled'] = TRUE;
    $form['entity_cardinality']['#description'] .= '<br /><em>' . $info . '</em>';

    $form['group_cardinality']['#disabled'] = TRUE;
    $form['group_cardinality']['#description'] .= '<br /><em>' . $info . '</em>';

    return $form;
  }

}
