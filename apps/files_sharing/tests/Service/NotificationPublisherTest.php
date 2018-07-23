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
namespace OCA\Files_Sharing\Tests\API;

use OCP\BackgroundJob\IJobList;
use Test\TestCase;
use OCP\Notification\INotification;
use OCA\Files_Sharing\Service\NotificationPublisher;
use OCP\Share\IShare;
use OCP\Files\Node;
use OCP\IGroup;
use OCP\IUser;

/**
 * Class Share20OCSTest
 *
 * @package OCA\Files_Sharing\Tests\Service
 * @group DB
 */
class NotificationPublisherTest extends TestCase {

	/** @var IGroupManager | \PHPUnit_Framework_MockObject_MockObject */
	private $groupManager;

	/** @var IUserManager | \PHPUnit_Framework_MockObject_MockObject */
	private $userManager;

	/** @var \OCP\Notification\IManager | \PHPUnit_Framework_MockObject_MockObject */
	private $notificationManager;

	/** @var IURLGenerator */
	private $urlGenerator;
	private $joblist;

	/** @var NotificationPublisher */
	private $publisher;

	protected function setUp() {
		$this->groupManager = $this->createMock('OCP\IGroupManager');
		$this->userManager = $this->createMock('OCP\IUserManager');
		$this->notificationManager = $this->createMock(\OCP\Notification\IManager::class);
		$this->urlGenerator = $this->createMock('OCP\IURLGenerator');
		$this->joblist = $this->createMock(IJobList::class);

		$this->publisher = new NotificationPublisher(
			$this->notificationManager,
			$this->userManager,
			$this->groupManager,
			$this->urlGenerator,
			$this->joblist
		);

		$this->urlGenerator->expects($this->any())
			->method('linkToRouteAbsolute')
			->with('files.viewcontroller.showFile', ['fileId' => 4000])
			->willReturn('/owncloud/f/4000');

		$this->urlGenerator->expects($this->any())
			->method('linkTo')
			->with('', $this->stringStartsWith('ocs/v1.php/apps/files_sharing/api/v1/shares/pending/'))
			->will($this->returnArgument(1));

		$this->urlGenerator->expects($this->any())
			->method('getAbsoluteUrl')
			->will($this->returnArgument(0));
	}

	public function tearDown() {
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

	private function createExpectedNotification($messageId, $messageParams, $userId, $shareId, $link) {
		$notification = $this->createMock(INotification::class);
		$notification->expects($this->once())
			->method('setApp')
			->with('files_sharing')
			->will($this->returnSelf());
		$notification->expects($this->once())
			->method('setUser')
			->with($userId)
			->will($this->returnSelf());
		$notification->expects($this->once())
			->method('setLink')
			->with($link)
			->will($this->returnSelf());
		$notification->expects($this->once())
			->method('setDateTime')
			->will($this->returnSelf());
		$notification->expects($this->once())
			->method('setObject')
			->with('local_share', $shareId)
			->will($this->returnSelf());
		$notification->expects($this->once())
			->method('setSubject')
			->with($messageId, $messageParams)
			->will($this->returnSelf());
		$notification->expects($this->once())
			->method('setMessage')
			->will($this->returnSelf());

		return $notification;
	}

	public function providesShareTypeAndState() {
		return [
			[\OCP\Share::SHARE_TYPE_LINK, \OCP\Share::STATE_REJECTED],
			[\OCP\Share::SHARE_TYPE_USER, \OCP\Share::STATE_ACCEPTED],
			[\OCP\Share::SHARE_TYPE_GROUP, \OCP\Share::STATE_REJECTED],
			[\OCP\Share::SHARE_TYPE_GROUP, \OCP\Share::STATE_PENDING],
		];
	}

	/**
	 * @dataProvider providesShareTypeAndState
	 */
	public function testSendNotification($shareType, $shareState) {
		$share = $this->createShare();

		$share->method('getShareType')->willReturn($shareType);
		$share->method('getState')->willReturn($shareState);
		if ((($shareType !== \OCP\Share::SHARE_TYPE_USER) &&
			($shareType !== \OCP\Share::SHARE_TYPE_GROUP)) ||
			($shareState !== \OCP\Share::STATE_PENDING)) {
			$this->assertNull($this->publisher->sendNotification($share));
		} else {
			$this->joblist->expects($this->once())
				->method('add')
				->with('OCA\Files_Sharing\BackgroundJob\NotificationSender', ['shareId' => 12300, 'webroot' => \OC::$WEBROOT]);
			$this->publisher->sendNotification($share);
		}
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

	public function testDiscardNotification() {
		$notifications = \array_map(function ($userId) {
			$notification = $this->createMock(INotification::class);
			$notification->expects($this->once())
				->method('setApp')
				->with('files_sharing')
				->will($this->returnSelf());
			$notification->expects($this->once())
				->method('setUser')
				->with($userId)
				->will($this->returnSelf());
			$notification->expects($this->once())
				->method('setObject')
				->with('local_share', 12300)  // it must match the share fullId
				->will($this->returnSelf());

			return $notification;
		}, ['groupMember1', 'groupMember2']);

		$this->notificationManager->expects($this->exactly(2))
			->method('createNotification')
			->will($this->onConsecutiveCalls($notifications[0], $notifications[1]));

		$this->notificationManager->expects($this->exactly(2))
			->method('markProcessed')
			->withConsecutive($notifications[0], $notifications[1]);

		$share = $this->createShare();
		$share->method('getShareType')->willReturn(\OCP\Share::SHARE_TYPE_GROUP);
		$share->method('getSharedWith')->willReturn('group1');
		$share->method('getState')->willReturn(\OCP\Share::STATE_ACCEPTED);
		$share->method('getFullId')->willReturn(12300);

		$this->makeGroup('group1', ['groupMember1', 'groupMember2', 'shareOwner', 'sharedBy']);

		$this->publisher->discardNotification($share);
	}
}
