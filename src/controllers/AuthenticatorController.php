<?php
namespace arjanbrinkman\craftappauthenticator\controllers;

use Craft;
use craft\web\Controller;
use craft\helpers\Db;
use craft\elements\User;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use yii\web\Response;

class AuthenticatorController extends Controller
{
	// Allow anonymous access to these actions
	protected array|int|bool $allowAnonymous = [
		'login',
		'create-token',
		'validate',
		'login-with-token',
		'logout',
		'test'
	];

	/**
	 * POST /_app-authenticator/login
	 * Body: { username, password }
	 * Normal login (username/password) → issues a token
	 */
	public function actionLogin(): Response
	{
		$request  = Craft::$app->getRequest();
		$username = $request->getRequiredBodyParam('username');
		$password = $request->getRequiredBodyParam('password');

		$user = Craft::$app->getUsers()->getUserByUsernameOrEmail($username);

		if (!$user || !Craft::$app->getUsers()->validatePassword($user, $password)) {
			return $this->asJson(['success' => false, 'error' => 'Invalid credentials'])
				->setStatusCode(401);
		}

		return $this->issueToken($user->id);
	}

	/**
	 * GET /_app-authenticator/create-token
	 * Called from WebView after user has logged in via normal Craft session.
	 * Returns/reuses a token for that user.
	 */
	public function actionCreateToken(): Response
	{
		$user = Craft::$app->getUser()->getIdentity();
		if (!$user) {
			return $this->asJson(['success' => false, 'error' => 'Not logged in'])
				->setStatusCode(401);
		}

		// Try to reuse a valid token
		$row = (new \craft\db\Query())
			->from('{{%appauthenticator_tokens}}')
			->where(['userId' => $user->id])
			->andWhere(['>', 'expiresAt', Db::prepareDateForDb(new \DateTime())])
			->orderBy(['expiresAt' => SORT_DESC])
			->one();

		if ($row) {
			return $this->asJson([
				'success'   => true,
				'token'     => $row['token'],
				'expiresAt' => $row['expiresAt'],
				'userId'    => $user->id,
			]);
		}

		return $this->issueToken($user->id);
	}

	/**
	 * GET /_app-authenticator/validate
	 * Header: Authorization: Bearer {token}
	 * Validates if the token exists & is not expired.
	 */
	public function actionValidate(): Response
	{
		$request    = Craft::$app->getRequest();
		$authHeader = $request->getHeaders()->get('Authorization');

		if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
			return $this->asJson(['valid' => false, 'error' => 'Missing token'])
				->setStatusCode(401);
		}

		$token = substr($authHeader, 7);

		$row = (new \craft\db\Query())
			->from('{{%appauthenticator_tokens}}')
			->where(['token' => $token])
			->one();

		if (!$row) {
			return $this->asJson(['valid' => false, 'error' => 'Invalid token'])
				->setStatusCode(401);
		}

		// Check expiry
		if (new \DateTime() > new \DateTime($row['expiresAt'])) {
			// 🔥 Clean up expired token immediately
			Craft::$app->getDb()->createCommand()
				->delete('{{%appauthenticator_tokens}}', ['token' => $token])
				->execute();

			return $this->asJson(['valid' => false, 'error' => 'Token expired'])
				->setStatusCode(401);
		}

		return $this->asJson([
			'valid'     => true,
			'userId'    => $row['userId'],
			'expiresAt' => $row['expiresAt'],
		]);
	}

	public function actionTest(): \yii\web\Response
	{
		return $this->asJson([
			'success' => false,
			'message' => 'test disabled',
		]);
		
		$this->requireAdmin();
		
		$deletedEntries = [];
		$deletedAssets = [];
		
		// Get 5 oldest entries (by ID ascending)
		$entries = Entry::find()
			->status(null)
			->orderBy(['id' => SORT_ASC])
			->limit(2000)
			->all();
		
		if (!$entries) {
			return $this->asJson([
				'success' => false,
				'message' => 'No entries found.',
			]);
		}
		
		foreach ($entries as $entry) {
			$entryInfo = [
				'id' => $entry->id,
				'title' => $entry->title,
				'section' => $entry->getSection()->handle ?? null,
			];
		
			// Collect asset references
			$assetsToCheck = [];
			foreach ($entry->getFieldLayout()->getCustomFields() as $field) {
				$value = $entry->getFieldValue($field->handle);
				if ($value instanceof \craft\elements\db\AssetQuery) {
					foreach ($value->all() as $asset) {
						$assetsToCheck[$asset->id] = $asset;
					}
				}
			}
		
			// Delete entry
			Craft::$app->elements->deleteElement($entry, true);
			$deletedEntries[] = $entryInfo;
		}
		
		return $this->asJson([
			'success' => true,
			'deletedEntries' => $deletedEntries
		]);

		//////
	
		$testUserId = 1; // adjust to a valid user ID
	
		$user = Craft::$app->getUsers()->getUserById($testUserId);
		if (!$user) {
			return $this->asJson([
				'success' => false,
				'error'   => "User with ID {$testUserId} not found",
			])->setStatusCode(404);
		}
	
		// ✅ Load global set "user"
		$userGlobals = GlobalSet::find()
			->handle('user')
			->one();
	
		$defaultAvatarUrl = null;
		if ($userGlobals) {
			$defaultAvatarAsset = $userGlobals->defaultAvatar->one();
			$defaultAvatarUrl   = $defaultAvatarAsset?->getUrl();
		}
	
		$userData = [
			'id'            => $user->id,
			'lastLoginDate' => $user->lastLoginDate?->format('Y-m-d H:i:s'),
			'isAdmin'       => $user->admin === true,
			'isRedactie'    => $user->isInGroup('redactie'),
			'userName'      => $user->username,
			'firstName'     => $user->firstName,
			'lastName'      => $user->lastName,
			'nickName'      => $user->nickName,
			'email'         => $user->email,
			'avatar'        => $user->getPhoto()?->getUrl() ?? $defaultAvatarUrl,
			'darkTheme'     => (Craft::$app->view->getTwig()->getGlobals()['darkTheme'] ?? false) ? true : false,
		];
	
		return $this->asJson([
			'success'  => true,
			'userData' => $userData,
		]);
	}

	/**
	 * GET /_app-authenticator/login-with-token?userId=123&token=abc
	 * Called from Flutter when restoring session.
	 * If valid → logs user back in, sets cookies in WebView.
	 */

	public function actionLoginWithToken(): Response
	{
		$request = Craft::$app->getRequest();
		$userId  = $request->getRequiredQueryParam('userId');
		$token   = $request->getRequiredQueryParam('token');
	
		$row = (new \craft\db\Query())
			->from('{{%appauthenticator_tokens}}')
			->where(['userId' => $userId, 'token' => $token])
			->one();
	
		if (!$row) {
			return $this->asJson(['success' => false, 'error' => 'Invalid token'])
				->setStatusCode(401);
		}
	
		// Check expiry
		if (new \DateTime() > new \DateTime($row['expiresAt'])) {
			return $this->asJson(['success' => false, 'error' => 'Token expired'])
				->setStatusCode(401);
		}
	
		/** @var User|null $user */
		$user = Craft::$app->getUsers()->getUserById((int) $userId);
		if (!$user) {
			return $this->asJson(['success' => false, 'error' => 'User not found'])
				->setStatusCode(404);
		}
	
		// ✅ Log the user back in and set cookies for WebView
		Craft::$app->getUser()->loginByUserId($user->id, 0);
	
		// ✅ Get fallback avatar from global set
		$userGlobals = GlobalSet::find()
			->handle('user')
			->one();
	
		$defaultAvatarUrl = null;
		if ($userGlobals) {
			$defaultAvatarAsset = $userGlobals->defaultAvatar->one();
			$defaultAvatarUrl   = $defaultAvatarAsset?->getUrl();
		}
	
		// ✅ Build user data payload (same as Twig)
		$userData = [
			'id'            => $user->id,
			'lastLoginDate' => $user->lastLoginDate?->format('Y-m-d H:i:s'),
			'isAdmin'       => $user->admin === true,
			'isRedactie'    => $user->isInGroup('redactie'),
			'userName'      => $user->username,
			'firstName'     => $user->firstName,
			'lastName'      => $user->lastName,
			'nickName'      => $user->nickName,
			'email'         => $user->email,
			'avatar'        => $user->getPhoto()?->getUrl() ?? $defaultAvatarUrl,
			'darkTheme'     => (Craft::$app->view->getTwig()->getGlobals()['darkTheme'] ?? false) ? true : false,
		];
	
		return $this->asJson([
			'success'   => true,
			'userId'    => $user->id,
			'expiresAt' => $row['expiresAt'],
			'userData'  => $userData,
		]);
	}


	/**
	 * POST /_app-authenticator/logout
	 * Header: Authorization: Bearer {token}
	 * Deletes the token from DB.
	 */
	public function actionLogout(): Response
	{
		$request    = Craft::$app->getRequest();
		$authHeader = $request->getHeaders()->get('Authorization');

		if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
			return $this->asJson(['success' => false, 'error' => 'Missing token'])
				->setStatusCode(400);
		}

		$token = substr($authHeader, 7);

		Craft::$app->getDb()->createCommand()
			->delete('{{%appauthenticator_tokens}}', ['token' => $token])
			->execute();

		return $this->asJson(['success' => true]);
	}

	/**
	 * Utility: issue a new token and save in DB
	 */
	private function issueToken(int $userId): Response
	{
		$token  = Craft::$app->getSecurity()->generateRandomString(64);
		$expiry = Db::prepareDateForDb(new \DateTime('+30 days'));

		Craft::$app->getDb()->createCommand()
			->insert('{{%appauthenticator_tokens}}', [
				'userId'    => $userId,
				'token'     => $token,
				'expiresAt' => $expiry,
				'createdAt' => Db::prepareDateForDb(new \DateTime()),
			])->execute();

		return $this->asJson([
			'success'   => true,
			'token'     => $token,
			'expiresAt' => $expiry,
			'userId'    => $userId,
		]);
	}
}
