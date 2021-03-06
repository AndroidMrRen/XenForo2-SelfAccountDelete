<?php

namespace LiamW\AccountDelete\Service;

use InvalidArgumentException;
use UnexpectedValueException;
use XF;
use XF\App;
use XF\ControllerPlugin\Login;
use XF\Entity\User;
use XF\Mvc\Controller;
use XF\Service\AbstractService;

class AccountDelete extends AbstractService
{
	/**
	 * @var User
	 */
	protected $user;
	protected $originalUsername;
	protected $originalEmail;

	/**
	 * @var \LiamW\AccountDelete\Entity\AccountDelete
	 */
	protected $accountDeletion;

	protected $controller;

	protected $renameTo;
	protected $banEmail;
	protected $removeEmail;
	protected $addUserGroup;

	protected $sendEmail;

	public function __construct(App $app, User $user, Controller $controller = null)
	{
		parent::__construct($app);

		$this->user = $user;
		$this->accountDeletion = $user->PendingAccountDeletion;
		$this->originalUsername = $user->username;
		$this->originalEmail = $user->email;
		$this->controller = $controller;
	}

	public function scheduleDeletion($reason, $sendEmail = true, $immediateExecution = true)
	{
		if (!$this->controller)
		{
			throw new InvalidArgumentException("Scheduling account deletion requires controller to be passed to service");
		}

		/** @var \LiamW\AccountDelete\Entity\AccountDelete $accountDeletion */
		$accountDeletion = $this->user->getRelationOrDefault('PendingAccountDeletion');
		$accountDeletion->reason = $reason;
		$this->user->save();

		/** @var Login $loginPlugin */
		$loginPlugin = $this->controller->plugin('XF:Login');
		$loginPlugin->logoutVisitor();

		if ($immediateExecution && $accountDeletion->end_date <= XF::$time)
		{
			XF::runLater(function () use ($accountDeletion)
			{
				$this->executeDeletion();
			});
		}
		else
		{
			$repository = $this->repository('LiamW\AccountDelete:AccountDelete');

			if ($repository->getNextRemindTime())
			{
				$this->app->jobManager()->enqueueLater('lwAccountDeleteReminder', $repository->getNextRemindTime(), 'LiamW\AccountDelete:SendDeleteReminders');
			}
			else
			{
				$this->app->jobManager()->cancelUniqueJob('lwAccountDeleteReminder');
			}

			$this->app->jobManager()->enqueueLater('lwAccountDeleteRunner', $repository->getNextDeletionTime(), 'LiamW\AccountDelete:DeleteAccounts');

			if ($sendEmail)
			{
				$this->sendScheduledEmail();
			}
		}
	}

	public function cancelDeletion($forced = false, $sendEmail = true)
	{
		if ($this->accountDeletion)
		{
			$this->accountDeletion->status = "cancelled";
			$this->accountDeletion->save();

			$repository = $this->repository('LiamW\AccountDelete:AccountDelete');

			if ($repository->getNextRemindTime())
			{
				$this->app->jobManager()->enqueueLater('lwAccountDeleteReminder', $repository->getNextRemindTime(), 'LiamW\AccountDelete:SendDeleteReminders');
			}
			else
			{
				$this->app->jobManager()->cancelUniqueJob('lwAccountDeleteReminder');
			}

			if ($repository->getNextDeletionTime())
			{
				$this->app->jobManager()->enqueueLater('lwAccountDeleteRunner', $repository->getNextDeletionTime(), 'LiamW\AccountDelete:DeleteAccounts');
			}
			else
			{
				$this->app->jobManager()->cancelUniqueJob('lwAccountDeleteRunner');
			}

			if ($sendEmail)
			{
				$this->sendCancelledEmail($forced);
			}
		}
	}

	public function executeDeletion($sendEmail = true)
	{
		if (!$this->accountDeletion || $this->accountDeletion->end_date > XF::$time)
		{
			return;
		}

		if (!$this->user->canDeleteSelf())
		{
			$this->cancelDeletion(true, $sendEmail);

			return;
		}

		$this->sendEmail = $sendEmail;

		$methodOption = XF::options()->liamw_accountdelete_deletion_method;

		if (XF::options()->liamw_accountdelete_randomise_username)
		{
			$this->renameTo($this->repository('LiamW\AccountDelete:AccountDelete')->getDeletedUserUsername($this->user));
		}

		switch ($methodOption['mode'])
		{
			case 'disable':
				$this->removeEmail($methodOption['disable_options']['remove_email']);
				$this->banEmail($methodOption['disable_options']['ban_email']);
				$this->addUserGroup($methodOption['disable_options']['disabled_group_id']);

				if ($methodOption['disable_options']['remove_password'])
				{
					$this->user->getRelationOrDefault('Auth')->setNoPassword();

					$userProfile = $this->user->getRelationOrDefault('Profile');

					foreach ($this->user->ConnectedAccounts AS $connectedAccount)
					{
						$connectedAccount->delete();

						/** @var XF\Entity\ConnectedAccountProvider $provider */
						$provider = $this->em()->find('XF:ConnectedAccountProvider', $connectedAccount->provider);
						if ($provider)
						{
							$storageState = $provider->getHandler()->getStorageState($provider, $this->user);
							$storageState->clearProviderData();
						}

						$profileConnectedAccounts = $userProfile->connected_accounts;
						unset($profileConnectedAccounts[$connectedAccount->provider]);
						$userProfile->connected_accounts = $profileConnectedAccounts;
					}
				}

				$this->doDisable();
				break;
			case 'delete':
				$this->banEmail($methodOption['delete_options']['ban_email']);

				$this->doDelete();
				break;
			default:
				throw new UnexpectedValueException('Unknown option value encountered during member deletion');
		}

		$this->finaliseDeleteDisable();
	}

	protected function renameTo($name)
	{
		if ($name === $this->user->username)
		{
			$this->renameTo = null;
		}
		else
		{
			$this->renameTo = $name;
		}
	}

	protected function banEmail($option)
	{
		$this->banEmail = $option;
	}

	protected function removeEmail($option)
	{
		$this->removeEmail = $option;
	}

	/**
	 * @param int|null $userGroupId
	 */
	protected function addUserGroup($userGroupId)
	{
		$this->addUserGroup = $userGroupId;
	}

	protected function doRename()
	{
		if ($this->renameTo)
		{
			$this->user->setTrusted('username', $this->renameTo);
			$this->user->save();
		}
	}

	protected function doDelete()
	{
		$this->user->setOption('liamw_accountdelete_log_manual', false);
		$this->user->setOption('enqueue_rename_cleanup', false);
		$this->user->setOption('enqueue_delete_cleanup', false);

		$this->doRename();

		$this->user->delete();
	}

	protected function doDisable()
	{
		$this->user->setOption('liamw_accountdelete_log_manual', false);
		$this->user->setOption('enqueue_rename_cleanup', false);

		$this->doRename();

		$this->user->user_state = 'disabled';

		if ($this->addUserGroup)
		{
			$secondaryGroups = $this->user->secondary_group_ids;
			if (!in_array($this->addUserGroup, $secondaryGroups))
			{
				$secondaryGroups[] = $this->addUserGroup;
				$this->user->secondary_group_ids = $secondaryGroups;
			}
		}

		$this->user->save();
	}

	protected function finaliseDeleteDisable()
	{
		if ($this->sendEmail)
		{
			$this->sendCompletedEmail();
		}

		// Remove email address after sending the completion email
		if ($this->originalEmail && $this->removeEmail && $this->user->exists())
		{
			// setTrusted bypasses validations, allowing us to sent an empty email
			$this->user->setTrusted('email', '');
			$this->user->save();
		}

		if ($this->originalEmail && $this->banEmail)
		{
			if (!$this->repository('XF:Banning')->isEmailBanned($this->originalEmail, XF::app()->get('bannedEmails')))
			{
				$this->repository('XF:Banning')->banEmail($this->originalEmail, \XF::phrase('liamw_accountdelete_automated_ban_user_deleted_self'), $this->user);
			}
		}

		$this->accountDeletion->completion_date = XF::$time;
		$this->accountDeletion->status = "complete";
		$this->accountDeletion->save();

		$this->runPostDeleteJobs();
	}

	protected function runPostDeleteJobs()
	{
		$user = $this->user;

		$jobList = [];
		if ($this->renameTo)
		{
			$jobList[] = [
				'XF:UserRenameCleanUp',
				[
					'originalUserId' => $user->user_id,
					'originalUserName' => $this->originalUsername,
					'newUserName' => $this->renameTo
				]
			];
		}

		if (!$user->exists())
		{
			$jobList[] = [
				'XF:UserDeleteCleanUp',
				[
					'userId' => $user->user_id,
					'username' => $this->renameTo
				]
			];
		}

		if ($jobList)
		{
			$this->app->jobManager()->enqueueUnique('selfAccountDeleteCleanup' . $user->user_id, 'XF:Atomic', [
				'execute' => $jobList
			]);
		}
	}

	public function sendScheduledEmail()
	{
		if (!$this->user->email || $this->user->user_state != 'valid')
		{
			return;
		}

		$mail = XF::mailer()->newMail();
		$mail->setToUser($this->user);
		$mail->setTemplate('liamw_accountdelete_delete_scheduled');
		$mail->send();
	}

	public function sendReminderEmail()
	{
		if (!$this->user->email || $this->user->user_state != 'valid' || $this->accountDeletion->reminder_sent)
		{
			return;
		}

		XF::db()->beginTransaction();

		$mail = XF::mailer()->newMail();
		$mail->setToUser($this->user);
		$mail->setTemplate('liamw_accountdelete_delete_imminent');
		$mail->queue();

		/** @var \LiamW\AccountDelete\Entity\AccountDelete $pendingDeletion */
		$pendingDeletion = $this->accountDeletion;
		$pendingDeletion->reminder_sent = 1;
		$pendingDeletion->save(true, false);

		XF::db()->commit();
	}

	public function sendCancelledEmail($forced = false)
	{
		if (!$this->user->email || $this->user->user_state != 'valid')
		{
			return;
		}

		$mail = XF::mailer()->newMail();
		$mail->setToUser($this->user);
		$mail->setTemplate('liamw_accountdelete_delete_cancelled', ['forced' => $forced]);
		$mail->send();
	}

	public function sendCompletedEmail()
	{
		if (!$this->originalEmail)
		{
			return;
		}

		$mail = XF::mailer()->newMail();
		$mail->setTo($this->originalEmail, $this->originalUsername);
		$mail->setLanguage(\XF::app()->language($this->user->language_id));
		$mail->setTemplate('liamw_accountdelete_delete_completed', ['time' => XF::$time]);
		$mail->send();
	}
}