<?php

namespace Drupal\ginvite\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\ginvite\Plugin\GroupContentEnabler\GroupInvitation;
use Drupal\group\Entity\GroupContentInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Access\AccessResult;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\group\GroupMembershipLoader;

/**
 * Handles Accept/Decline operations and Access check for them.
 */
class InvitationOperations extends ControllerBase {

  /**
   * Group membership loader service.
   *
   * @var \Drupal\group\GroupMembershipLoader
   */
  protected $membershipLoader;

  /**
   * InvitationOperations constructor.
   *
   * @param \Drupal\group\GroupMembershipLoader $membershipLoader
   *   Group membership loader service.
   */
  public function __construct(GroupMembershipLoader $membershipLoader) {
    $this->membershipLoader = $membershipLoader;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('group.membership_loader'));
  }

  /**
   * Handles user actions with invitations.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request of the page.
   * @param \Drupal\group\Entity\GroupContentInterface $group_content
   *   Invitation entity.
   * @param string $op
   *   Operation, 'accept' or 'decline'.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirects user to destination or 'user.page'.
   */
  public function action(Request $request, GroupContentInterface $group_content, $op) {
    switch ($op) {
      case 'accept':
        $this->accept($group_content);
        break;

      case 'decline':
        $this->decline($group_content);
        break;
    }

    if ($request->query->has('destination')) {
      $dest = $request->get('destination');
      $path = Url::fromUserInput($dest)->setAbsolute()->toString();
      return new RedirectResponse($path);
    }

    return $this->redirect('user.page');
  }

  /**
   * Create user membership and change invitation status.
   *
   * @param \Drupal\group\Entity\GroupContentInterface $group_content
   *   Invitation entity.
   */
  protected function accept(GroupContentInterface $group_content) {
    $group = $group_content->getGroup();
    $bundle = $group->bundle();
    $values = [
      'type' => $bundle . '-group_membership',
      'entity_id' => $group_content->get('entity_id')->getString(),
      'content_plugin' => 'group_membership',
      'gid' => $group->id(),
      'uid' => $group_content->getOwnerId(),
      'group_roles' => $group_content->get('group_roles')->getValue(),
    ];

    $status = $this->entityTypeManager()->getStorage('group_content')
      ->create($values)
      ->save();

    if ($status) {
      $group_content->set('invitation_status', GroupInvitation::INVITATION_ACCEPTED)->save();
      drupal_set_message($this->t('You have accepted the group invitation.'));
    }
    else {
      drupal_set_message($this->t('Error accepting invitation.'), 'error');
    }
  }

  /**
   * Decline invitation. Change invitation status.
   *
   * @param \Drupal\group\Entity\GroupContentInterface $group_content
   *   Invitation entity.
   */
  protected function decline(GroupContentInterface $group_content) {
    $group_content->set('invitation_status', GroupInvitation::INVITATION_REJECTED)->save();
    drupal_set_message($this->t('You have declined the group invitation.'));
  }

  /**
   * Checks if this current has access to update invitation.
   *
   * @param \Drupal\group\Entity\GroupContentInterface $group_content
   *   Invitation entity.
   * @param string $op
   *   Operation, 'accept' or 'decline'.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Access check result.
   */
  public function checkAccess(GroupContentInterface $group_content, $op) {
    $invited = $group_content->get('entity_id')->getString();
    $group = $group_content->getGroup();
    $membership = $this->membershipLoader->load($group, $this->currentUser());

    // Only allow user accept/decline invitation addressed to him.
    if ($invited == $this->currentUser()->id() && !$membership) {
      return AccessResult::allowed();
    }

    return AccessResult::forbidden();
  }

}
