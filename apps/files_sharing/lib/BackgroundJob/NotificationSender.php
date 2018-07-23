<?php
/**
 * @author Sujith Haridasan <sharidasan@owncloud.com>
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

namespace OCA\Files_Sharing\BackgroundJob;

use OC\BackgroundJob\Job;
use OC\User\Manager;
use OCP\IGroupManager;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Notification\IManager as NotificationManager;

/**
 * Class NotificationSender
 *
 * @package OCA\Files_Sharing\BackgroundJob
 */
class NotificationSender extends Job {

	/** @var IManager  */
	private $shareManager;

	/** @var \OC\Group\Manager|IGroupManager  */
	private $groupManager;

	/** @var Manager  */
	private $userManager;

	/** @var NotificationManager  */
	private $notificationManager;

	/** @var IURLGenerator  */
	private $urlGenerator;

	/** @var IRequest  */
	private $request;

	public function __construct(IManager $shareManager = null,
								IGroupManager $groupManager = null,
								Manager $userManager = null,
								NotificationManager $notificationManager = null,
								IURLGenerator $urlGenerator,
								IRequest $request = null) {
		$this->shareManager = $shareManager ? $shareManager : \OC::$server->getShareManager();
		$this->groupManager = $groupManager ? $groupManager : \OC::$server->getGroupManager();
		$this->userManager = $userManager ? $userManager : \OC::$server->getUserManager();
		$this->notificationManager = $notificationManager ? $notificationManager : \OC::$server->getNotificationManager();
		$this->urlGenerator = $urlGenerator ? $urlGenerator : \OC::$server->getURLGenerator();
		$this->request = $request ? $request : \OC::$server->getRequest();
	}

	/**
	 * @param $notificationURL
	 * @return string
	 */
	public function fixURL($notificationURL) {
		$url = $this->request->getServerProtocol() . '://' . $this->request->getServerHost();
		$partURL = \explode($url, $notificationURL)[1];
		if (\strpos($partURL, $this->getArgument()['webroot']) === false) {
			$notificationURL = $url . $this->getArgument()['webroot'] . $partURL;
		}
		return $notificationURL;
	}

	/**
	 * @param $shareId
	 */
	public function sendNotify($shareId) {
		$notificationList = [];
		$maxCountSendNotification = 100;

		//Check if its an internal share or not
		try {
			$share = $this->shareManager->getShareById('ocinternal:' . $shareId);
		} catch (ShareNotFound $e) {
			$share = $this->shareManager->getShareById('ocFederatedSharing:' . $shareId);
		}

		$users = [];
		if ($share->getShareType() === \OCP\Share::SHARE_TYPE_GROUP) {
			//Notify all the group members
			$group = $this->groupManager->get($share->getSharedWith());
			$users = $group->getUsers();
		} elseif ($share->getShareType() === \OCP\Share::SHARE_TYPE_USER) {
			$users[] = $this->userManager->get($share->getSharedWith());
		}

		foreach ($users as $user) {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp('files_sharing')
				->setUser($user->getUID())
				->setDateTime(new \DateTime())
				->setObject('local_share', $share->getFullId());

			$notification->setIcon(
				$this->urlGenerator->imagePath('core', 'actions/shared.svg')
			);

			$fileLink = $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileId' => $share->getNode()->getId()]);

			$fileLink = $this->fixURL($fileLink);
			$notification->setLink($fileLink);

			$notification->setSubject('local_share', [$share->getShareOwner(), $share->getSharedBy(), $share->getNode()->getName()]);
			$notification->setMessage('local_share', [$share->getShareOwner(), $share->getSharedBy(), $share->getNode()->getName()]);

			$declineAction = $notification->createAction();
			$declineAction->setLabel('decline');
			$endPointUrl = $this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->linkTo('', 'ocs/v1.php/apps/files_sharing/api/v1/shares/pending/' . $share->getId())
			);
			$endPointUrl = $this->fixURL($endPointUrl);
			$declineAction->setLink($endPointUrl, 'DELETE');
			$notification->addAction($declineAction);

			$acceptAction = $notification->createAction();
			$acceptAction->setLabel('accept');
			$acceptAction->setLink($endPointUrl, 'POST');
			$acceptAction->setPrimary(true);
			$notification->addAction($acceptAction);

			if (\count($notificationList) < $maxCountSendNotification) {
				$notificationList[] = $notification;
			} else {
				foreach ($notificationList as $notificationDispatch) {
					$this->notificationManager->notify($notificationDispatch);
				}

				//Once users in notificationList recieve notification reset the list
				$notificationList = [$notification];
			}
		}

		if (\count($notificationList) < $maxCountSendNotification) {
			foreach ($notificationList as $notificationDispatch) {
				$this->notificationManager->notify($notificationDispatch);
			}
		}
	}

	public function execute($jobList, ILogger $logger = null) {
		$this->sendNotify($this->getArgument()['shareId']);
		$jobList->remove($this);
		parent::execute($jobList, $logger);
	}

	public function run($argument) {
		$this->sendNotify($this->getArgument()['shareId']);
	}
}
