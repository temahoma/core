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

namespace OCA\Files_Sharing\Tests\BackgroundJob;

use OC\Group\Group;
use OC\Notification\Action;
use OC\User\Manager;
use OCA\Files_Sharing\BackgroundJob\NotificationSender;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IGroup;
use OCP\Notification\INotification;
use OCP\Share\IShare;
use OCP\IURLGenerator;
use OCP\Share\IManager;
use OCP\Notification\IManager as NotificationManager;
use Test\TestCase;

class NotificationSenderTest extends TestCase {
	/** @var  IManager | \PHPUnit_Framework_MockObject_MockObject */
	private $shareManager;

	/** @var  IGroupManager | \PHPUnit_Framework_MockObject_MockObject */
	private $groupManager;

	/** @var  Manager | \PHPUnit_Framework_MockObject_MockObject */
	private $userManager;

	/** @var  NotificationManager | \PHPUnit_Framework_MockObject_MockObject */
	private $notificationManager;

	/** @var  IURLGenerator | \PHPUnit_Framework_MockObject_MockObject */
	private $urlgenerator;

	/** @var  IRequest | \PHPUnit_Framework_MockObject_MockObject */
	private $request;

	/** @var  NotificationSender */
	private $notificationSender;

	protected function setUp() {
		$this->shareManager = $this->createMock(IManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(Manager::class);
		$this->notificationManager = $this->createMock(NotificationManager::class);
		$this->urlgenerator = $this->createMock(IURLGenerator::class);
		$this->request = $this->createMock(IRequest::class);
		$this->notificationSender = new NotificationSender(
			$this->shareManager,
			$this->groupManager,
			$this->userManager,
			$this->notificationManager,
			$this->urlgenerator,
			$this->request);

		parent::setUp();
	}

	protected function tearDown() {
		parent::tearDown();
	}

	private function createShare() {
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn(4000);
		$node->method('getName')->willReturn('node-name');

		$share = $this->createMock(IShare::class);
		$share->method('getId')->willReturn(12300);
		$share->method('getShareOwner')->willReturn('shareOwner');
		$share->method('getSharedBy')->willReturn('sharedBy');
		$share->method('getNode')->willReturn($node);

		return $share;
	}

	private function makeGroup($groupName, $members) {
		$memberObjects = \array_map(function ($memberName) {
			$memberObject = $this->createMock(IUser::class);
			$memberObject->method('getUID')->willReturn($memberName);
			return $memberObject;
		}, $members);

		$group = $this->createMock(IGroup::class);
		$group->expects($this->once())
			->method('getUsers')
			->willReturn($memberObjects);

		$this->groupManager->expects($this->any())
			->method('get')
			->with($groupName)
			->willReturn($group);

		return $memberObjects;
	}

	public function testGroupShareNotification() {
		$share = $this->createShare();
		$share->expects($this->exactly(1))
			->method('getShareType')
			->willReturn(\OCP\Share::SHARE_TYPE_GROUP);
		$share->expects($this->once())
			->method('getSharedWith')
			->willReturn('group1');

		$userIds = [];
		for ($i = 1; $i <= 105; $i++) {
			$userIds[] = 'user' . (string) $i;
		}
		$iUsers = $this->makeGroup('group1', $userIds);

		$group = $this->createMock(Group::class);
		/*$group->expects($this->once())
			->method('getUsers')
			->willReturn($iUsers);*/
		$this->groupManager->expects($this->once())
			->method('get')
			->willReturn($group);

		$this->shareManager->expects($this->once())
			->method('getShareById')
			->willReturn($share);

		$this->request->expects($this->exactly(210))
			->method('getServerProtocol')
			->willReturn('http');
		$this->request->expects($this->exactly(210))
			->method('getServerHost')
			->willReturn('foo');

		$this->urlgenerator->expects($this->exactly(105))
			->method('linkToRouteAbsolute')
			->willReturn('http://foo/bar/index.php/f/22');
		$this->urlgenerator->expects($this->exactly(105))
			->method('imagePath');
		$this->urlgenerator->expects($this->exactly(105))
			->method('getAbsoluteURL')
			->willReturn('http://foo/bar/ocs/v1.php/apps/files_sharing/api/v1/shares/pending/' . $share->getId());

		$action = $this->createMock(Action::class);

		$inotification = $this->createMock(INotification::class);

		$inotification->expects($this->exactly(105))
			->method('setApp')
			->willReturn($inotification);
		$inotification->expects($this->exactly(105))
			->method('setUser')
			->willReturn($inotification);
		$inotification->expects($this->exactly(105))
			->method('setDateTime')
			->willReturn($inotification);
		$inotification->expects($this->exactly(105))
			->method('setObject')
			->willReturn($inotification);
		$inotification->expects($this->exactly(105))
			->method('setIcon');
		$inotification->expects($this->exactly(105))
			->method('setLink')
			->willReturn($inotification);
		$inotification->expects($this->exactly(105))
			->method('setSubject')
			->willReturn($inotification);
		$inotification->expects($this->exactly(105))
			->method('setMessage')
			->willReturn($inotification);
		$inotification->expects($this->exactly(210))
			->method('createAction')
			->willReturn($action);
		$inotification->expects($this->exactly(210))
			->method('addAction')
			->willReturnMap([
				[$action, $inotification],
			]);

		for ($i = 1; $i <= 105; $i++) {
			$action->expects($this->exactly(210))
				->method('setLabel')
				->willReturn($action);
			$action->expects($this->exactly(210))
				->method('setLink')
				->willReturn($action);

			$this->notificationManager->expects($this->exactly(105))
				->method('createNotification')
				->willReturn($inotification);
		}

		$this->notificationSender->sendNotify($share->getId());
	}

	public function testSingleUserShareNotification() {
		$share = $this->createShare();
		$share->expects($this->exactly(2))
			->method('getShareType')
			->willReturn(\OCP\Share::SHARE_TYPE_USER);

		$this->request->expects($this->exactly(2))
			->method('getServerProtocol')
			->willReturn('http');
		$this->request->expects($this->exactly(2))
			->method('getServerHost')
			->willReturn('foo');

		$this->urlgenerator->expects($this->once())
			->method('linkToRouteAbsolute')
			->willReturn('http://foo/bar/index.php/f/22');
		$this->urlgenerator->expects($this->once())
			->method('imagePath');
		$this->urlgenerator->expects($this->once())
			->method('getAbsoluteURL')
			->willReturn('http://foo/bar/ocs/v1.php/apps/files_sharing/api/v1/shares/pending/' . $share->getId());

		$acceptAction = $this->createMock(Action::class);
		$acceptAction->expects($this->once())
			->method('setLabel')
			->willReturn($acceptAction);
		$acceptAction->expects($this->once())
			->method('setLink')
			->willReturn($acceptAction);

		$declineAction = $this->createMock(Action::class);
		$declineAction->expects($this->once())
			->method('setLabel')
			->willReturn($declineAction);
		$declineAction->expects($this->once())
			->method('setLink')
			->willReturn($declineAction);

		$inotification = $this->createMock(INotification::class);
		$inotification->expects($this->once())
			->method('setApp')
			->willReturn($inotification);
		$inotification->expects($this->once())
			->method('setUser')
			->willReturn($inotification);
		$inotification->expects($this->once())
			->method('setDateTime')
			->willReturn($inotification);
		$inotification->expects($this->once())
			->method('setObject')
			->willReturn($inotification);
		$inotification->expects($this->once())
			->method('setIcon');
		$inotification->expects($this->once())
			->method('setLink')
			->willReturn($inotification);
		$inotification->expects($this->once())
			->method('setSubject')
			->willReturn($inotification);
		$inotification->expects($this->once())
			->method('setMessage')
			->willReturn($inotification);
		$inotification->expects($this->exactly(2))
			->method('createAction')
			->willReturnOnConsecutiveCalls($declineAction, $acceptAction);
		$inotification->expects($this->exactly(2))
			->method('addAction')
			->willReturnMap([
				[$declineAction, $inotification],
				[$acceptAction, $inotification]
			]);

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($inotification);
		$this->notificationManager->expects($this->once())
			->method('notify')
			->with($inotification);

		$user = $this->createMock(IUser::class);
		$this->userManager->expects($this->once())
			->method('get')
			->willReturn($user);

		$this->shareManager->expects($this->once())
			->method('getShareById')
			->willReturn($share);
		$this->notificationSender->sendNotify($share->getId());
	}
}
