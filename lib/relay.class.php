<?php
	/**
	 *
	 *
	 * @author Simon Skrodal
	 * @since  November 2015
	 */

	require_once($BASE . '/lib/db/relaysqlconnection.class.php');

	// Some calls take a long while so increase timeout limit from def. 30
	set_time_limit(300);    // 5 mins
	// Have experienced fatal error - allowed memory size of 128M exhausted - thus increase
	ini_set('memory_limit', '350M');

	class Relay {
		protected $config;
		private $DEBUG = false;
		private $relaySQL;

		function __construct($config) {
			$this->config = $config;
			$this->relaySQL = new RelaySQLConnection($config);
		}

		########################################################
		# FUNCTIONS ACCESSIBLE BY ROUTES
		########################################################

		public function getRelayVersion() {
			$versionResponse = $this->relaySQL->query("SELECT * FROM tblVersion")[0];
			return $versionResponse['versValue'];
		}

		/**
		 * READ-ONLY
		 *
		 *
		 * @param $postData
		 *
		 * @return array
		 */
		public function verifyAccountList($postData) {
			// Get/set POST values
			$userList = isset($postData['user_list']) ? $postData['user_list'] : false;
			// Check that all required data is here
			if(!$userList) {
				Response::error(400, 'Missing one or more required data fields from POST. Cannot continue without required data...');
			}
			// To be sent back to client...
			$responseObj = array();
			// Loop all user pairs in the CSV
			foreach($userList as $userCurrentAndNew) {
				// Must be two columns only for each entry
				if(sizeof($userCurrentAndNew) !== 4) {
					Response::error(400, 'Malformed data structure. Cannot continue.');
				}

				// Check if old username has an account
				$currentLoginInfo = $this->_checkUserExists($userCurrentAndNew[0]);

				// If yes, we need to do more, otherwise skip to next user
				if($currentLoginInfo !== false) {
					// Check if the new username already has an account
					$newLoginInfo = $this->_checkUserExists($userCurrentAndNew[1]);
					// If yes, we have a situation (cannot move old to new, hence old account content will not be merged with new account)
					if($newLoginInfo !== false) {
						$responseObj['problem'][$userCurrentAndNew[0]]['message']              = 'Kan ikke migrere! Nytt brukernavn er allerede blitt tatt i bruk.';
						$responseObj['problem'][$userCurrentAndNew[0]]['account_info_current'] = $currentLoginInfo;
						$responseObj['problem'][$userCurrentAndNew[0]]['account_info_new']     = $newLoginInfo;
					} else {
						// Return old username and principal ID back to the client for final check before
						// it can make a MERGE request.
						$responseObj['ok'][$userCurrentAndNew[0]]['message']                      = 'Klar for fusjonering til nytt brukernavn!';
						$responseObj['ok'][$userCurrentAndNew[0]]['account_info_current']         = $currentLoginInfo;
						$responseObj['ok'][$userCurrentAndNew[0]]['account_info_new']['username'] = $userCurrentAndNew[1];
					}
				} // Users with no existing account on the service
				else {
					$responseObj['ignore'][$userCurrentAndNew[0]]['message']                          = 'Hopper over siden ingen konto er registrert for dette brukernavnet.';
					$responseObj['ignore'][$userCurrentAndNew[0]]['account_info_current']['username'] = $userCurrentAndNew[0];
					$responseObj['ignore'][$userCurrentAndNew[0]]['account_info_new']['username']     = $userCurrentAndNew[1];
				}
			}

			// Done :-)
			return ($responseObj);
		}

		/**
		 * Check if a user exists. Returns false if not, otherwise user metadata.
		 *
		 * @param $username
		 *
		 * @return array|bool
		 */
		private function _checkUserExists($username) {
			$this->_logger('(BEFORE)', __LINE__, __FUNCTION__);
			// Lookup account info for requested user
			$apiUserInfoResponse = $this->callConnectApi(
				array(
					'action'       => 'principal-list',
					'filter-login' => $username
				)
			);
			$this->_logger('(AFTER)', __LINE__, __FUNCTION__);
			// Exit on error
			if(strcasecmp((string)$apiUserInfoResponse->status['code'], "ok") !== 0) {
				Response::error(400, 'User lookup failed: ' . $username . ': ' . (string)$apiUserInfoResponse->status['subcode']);
			}
			// Ok search, but user does not exist (judged by missing metadata)
			if(!isset($apiUserInfoResponse->{'principal-list'}->principal)) {
				return false;
			}

			// Done :-)
			return array(
				'principal_id'  => (string)$apiUserInfoResponse->{'principal-list'}->principal['principal-id'],
				'username'      => (string)$apiUserInfoResponse->{'principal-list'}->principal->login,
				'response_full' => $apiUserInfoResponse
			);
		}

		private function _logger($text, $line, $function) {
			if($this->DEBUG) {
				error_log($function . '(' . $line . '): ' . $text);
			}
		}

		/**
		 * Utility function for AC API calls.
		 *
		 * @param array $params
		 * @param bool  $requireSession
		 *
		 * @return bool|SimpleXMLElement
		 */
		private function callConnectApi($params = array(), $requireSession = true) {

			if($requireSession) {
				$params['session'] = $this->getSessionAuthCookie();
			}

			$url = $this->apiurl . http_build_query($params);
			$xml = false;
			try {
				$xml = simplexml_load_file($url);
			} catch(Exception $e) {
				$this->_logger('Failed to get XML', __LINE__, __FUNCTION__);
				$this->_logger(json_encode($e), __LINE__, __FUNCTION__);
				Response::error(400, 'API request failed. Could be that the service is unavailable (503)');
			}

			if(!$xml) {
				Response::error(400, 'API request failed. Could be that the service is unavailable (503)');
			}
			$this->_logger('Got XML response', __LINE__, __FUNCTION__);
			$this->_logger(json_encode($xml), __LINE__, __FUNCTION__);

			return $xml;
		}

		/**
		 * Authenticate API user on AC service and grab returned cookie. If auth already in place, return cookie.
		 *
		 * @throws Exception
		 * @return array
		 */
		private function getSessionAuthCookie() {
			if($this->sessioncookie !== NULL) {
				$this->_logger('Have cookie, reusing', __LINE__, __FUNCTION__);

				return $this->sessioncookie;
			}

			$url  = $this->apiurl . 'action=login&login=' . $this->config['connect-api-userid'] . '&password=' . $this->config['connect-api-passwd'];
			$auth = get_headers($url, 1);

			if(!isset($auth['Set-Cookie'])) {
				$this->_logger('********** getSessionAuthCookie failed!', __LINE__, __FUNCTION__);
				Response::error(401, 'Error when authenticating to the Adobe Connect API using client API credentials. Set-Cookie not present in response.');
			}

			// Extract session cookie
			$acSessionCookie = substr($auth['Set-Cookie'], strpos($auth['Set-Cookie'], '=') + 1);
			$acSessionCookie = substr($acSessionCookie, 0, strpos($acSessionCookie, ';'));

			$this->sessioncookie = $acSessionCookie;
			$this->_logger('Returning new cookie', __LINE__, __FUNCTION__);

			return $this->sessioncookie;
		}

		/**
		 * WRITE TO TechSmith Relay DB
		 *
		 *
		 * @param $postData
		 *
		 * @return mixed
		 */
		public function migrateUserAccounts($postData) {
			// Get/set POST values
			$userList = isset($postData['user_list']) ? $postData['user_list'] : false;
			// Not used (yet)
			$token = isset($postData['token']) ? $postData['token'] : false;
			// Check that all required data is here
			if(!$userList) {
				Response::error(400, 'Missing one or more required data fields from POST. Cannot continue without required data...');
			}
			// Use sessioncookie passed from client
			if($token !== false) {
				$this->sessioncookie = $token;
			}
			// To be sent back to client...
			$responseObj = array();
			// Loop all user pairs in the CSV
			foreach($userList as $index => $userObj) {
				// Check for required user info in the object
				if(!$userObj['current_username'] || !$userObj['new_username'] || !$userObj['principal_id']) {
					Response::error(400, 'Malformed data structure. Cannot continue.');
				}

				// DO username change
				$usernameUpdateResponse = $this->_changeUsername($userObj['principal_id'], $userObj['new_username']);

				// If yes, we need to do more, otherwise skip to next user
				if($usernameUpdateResponse !== false) {
					$responseObj['ok'][$userObj['current_username']] ['message']                          = 'Brukernavn fusjonert!';
					$responseObj['ok'][$userObj['current_username']] ['account_info_old']['username']     = $userObj['current_username'];
					$responseObj['ok'][$userObj['current_username']] ['account_info_old']['principal_id'] = $userObj['principal_id'];
					$responseObj['ok'][$userObj['current_username']] ['account_info_new']                 = $usernameUpdateResponse;
				} else {
					$responseObj['problem'][$userObj['current_username']]['message']                           = 'Ukjent problem';
					$responseObj['problem'][$userObj['current_username']] ['account_info_old']['username']     = $userObj['current_username'];
					$responseObj['problem'][$userObj['current_username']] ['account_info_old']['principal_id'] = $userObj['principal_id'];
					$responseObj['problem'][$userObj['current_username']] ['account_info_new']['username']     = $userObj['new_username'];
					$responseObj['problem'][$userObj['current_username']] ['account_info_new']['principal_id'] = $userObj['principal_id'];
				}
			}

			// Done :-)
			return ($responseObj);
		}


		// ---------------------------- UTILS ----------------------------

		/**
		 * Change a username with the supplied principal_id.
		 *
		 * @param $principalId
		 * @param $newUsername
		 *
		 * @return array
		 */
		private function _changeUsername($principalId, $newUsername) {

			$this->_logger('(BEFORE)', __LINE__, __FUNCTION__);
			//Run the update call requested principalId
			$apiChangeUsernameResponse = $this->callConnectApi(
				array(
					'action'       => 'principal-update',
					'principal-id' => $principalId,
					'login'        => $newUsername
				)
			);
			$this->_logger('(AFTER)', __LINE__, __FUNCTION__);
			// Exit on error
			if(strcasecmp((string)$apiChangeUsernameResponse->status['code'], "ok") !== 0) {
				Response::error(400, 'User update failed: ' . $newUsername . ' (ID#' . $principalId . '): ' . (string)$apiChangeUsernameResponse->status['subcode']);
			}

			// NOTE: Poorly documented by Adobe (http://help.adobe.com/en_US/connect/9.0/webservices/WS5b3ccc516d4fbf351e63e3d11a171ddf77-7f54_SP1.html),
			// but principal-update only returns a status code if the command is *update*. Only when used to *create* a new principal does the endpoint
			// return metadata for the principal. Hence, we cannot pull info about the updated user from the response; only status code.

			// Given the note above, return the same ID and username as passed to this function. This is correct info, since the status code returned OK.


			return array(
				'principal_id'  => $principalId,
				'username'      => $newUsername,
				'response_full' => $apiChangeUsernameResponse
			);


			/* Dummy response - for testing
			return array(
				'principal_id' => $principalId,
				'username'     => $newUsername
			);
			*/

		}

		private function _responseToArray($response) {
			$newArr = Array();
			foreach($response as $child) {
				$newArr[] = $child;
			}

			return $newArr;
		}

		// ---------------------------- ./UTILS ----------------------------


	}



