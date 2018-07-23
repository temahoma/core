<?php
/**
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\Files_Sharing\Service;

use OCP\BackgroundJob\IJobList;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\Share\IShare;

class NotificationPublisher {

	/** @var \OCP\Notification\IManager */
	private $notificationManager;
	/** @var IGroupManager */
	private $groupManager;
	/** @var IUserManager */
	private $userManager;
	/** @var IURLGenerator */
	private $urlGenerator;
	private $joblist;

	public function __construct(
		\OCP\Notification\IManager $notificationManager,
		IUserManager $userManager,
		IGroupManager $groupManager,
		IURLGenerator $urlGenerator,
		IJobList $jobList
	) {
		$this->notificationManager = $notificationManager;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->urlGenerator = $urlGenerator;
		$this->joblist = $jobList;
	}

	private function getAffectedUsers(IShare $share) {
		$users = [];
		if ($share->getShareType() === \OCP\Share::SHARE_TYPE_GROUP) {
			// notify all group members
			$group = $this->groupManager->get($share->getSharedWith());
			// TODO: scale / chunk / ...
			foreach ($group->getUsers() as $user) {
				if ($user->getUID() !== $share->getShareOwner() && $user->getUID() !== $share->getSharedBy()) {
					yield $user->getUID();
				}
			}
		} elseif ($share->getShareType() === \OCP\Share::SHARE_TYPE_USER) {
			yield $share->getSharedWith();
		}
	}

	/**
	 * Send notification for accepting share
	 * The notification will be sent if the share type is either user or group (not link, for example)
	 * and only for pending shares (if the share has a different status the notification won't be sent)
	 *
	 * @param IShare $share share
	 */
	public function sendNotification(IShare $share) {
		if ($share->getShareType() !== \OCP\Share::SHARE_TYPE_USER &&
			$share->getShareType() !== \OCP\Share::SHARE_TYPE_GROUP) {
			return;
		}

		if ($share->getState() !== \OCP\Share::STATE_PENDING) {
			return;
		}

		$this->joblist->add('OCA\Files_Sharing\BackgroundJob\NotificationSender',
			[
				'shareId' => $share->getId(),
				'webroot' => \OC::$WEBROOT,
			]);
	}

	/**
	 * Discards all notification related to the given share.
	 * This is useful to cancel notifications in case said share
	 * is being deleted
	 *
	 * @param IShare $share share
	 */
	public function discardNotification(IShare $share) {
		foreach ($this->getAffectedUsers($share) as $userId) {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp('files_sharing')
				->setUser($userId)
				->setObject('local_share', $share->getFullId());

			$this->notificationManager->markProcessed($notification);
		}
	}

	/**
	 * Discards the notification related to the given share for the specific user.
	 * This is useful to remove the notification when the user has seen or processed it
	 *
	 * @param IShare $share share
	 * @param string $userId the user id (who should have been received the notification) that will
	 * have his notification discarded.
	 */
	public function discardNotificationForUser(IShare $share, $userId) {
		$notification = $this->notificationManager->createNotification();
		$notification->setApp('files_sharing')
			->setUser($userId)
			->setObject('local_share', $share->getFullId());

		$this->notificationManager->markProcessed($notification);
	}
}
