<?php

namespace Drupal\ginvite\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\ginvite\GroupInvitationLoader;
use Drupal\ginvite\Plugin\GroupContentEnabler\GroupInvitation;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ginvite module event subscriber.
 *
 * @package Drupal\ginvite\EventSubscriber
 */
class GinviteSubscriber implements EventSubscriberInterface {

  /**
   * Group invitations loader.
   *
   * @var \Drupal\ginvite\GroupInvitationLoader
   */
  protected $groupInvitationLoader;

  /**
   * The current user's account object.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs GinviteSubscriber.
   *
   * @param \Drupal\ginvite\GroupInvitationLoader $invitation_loader
   *   Invitations loader service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(GroupInvitationLoader $invitation_loader, AccountInterface $current_user) {
    $this->groupInvitationLoader = $invitation_loader;
    $this->currentUser = $current_user;
  }

  /**
   * Notify user about Pending invitations.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The GetResponseEvent to process.
   */
  public function notifyAboutPendingInvitations(GetResponseEvent $event) {
    if ($this->groupInvitationLoader->loadByUser()) {
      $message = 'You have pending group invitations. <a href="@url">Visit your profile</a> to see them.';
      $replace = ['@url' => Url::fromRoute('view.my_invitations.page_1', ['user' => $this->currentUser->id()])->toString()];
      drupal_set_message(t($message, $replace), 'warning', FALSE);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = ['notifyAboutPendingInvitations'];
    return $events;
  }

}
